// Package sites owns everything that exists on disk or in Docker for a
// customer. Nothing else in the agent touches the filesystem or the daemon.
package sites

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"
)

// Config is fixed at start-up; a site manager never rewrites it.
type Config struct {
	Root     string // /srv/customers
	CaddyDir string // /opt/codeinchrome/caddy/sites
	HostID   string

	// Root password for the host's MySQL, from /opt/codeinchrome/etc/mysql.env.
	// Empty means this host has no database server, and creating a site fails
	// with that reason rather than producing a site without a database.
	MySQLPassword string

	// Sites under PlatformDomain (name.<PlatformDomain>) are reached through
	// Cloudflare's proxy and served with the Cloudflare Origin CA certificate
	// at OriginCert/OriginKey: one wildcard, trusted only by Cloudflare, so no
	// per-site ACME order and no public CA rate limit (Let's Encrypt allows
	// 50 certificates a week per registered domain, and the fleet hit it).
	// Empty OriginCert keeps every name on on-demand ACME, as before.
	PlatformDomain string
	OriginCert     string
	OriginKey      string
}

type Manager struct {
	cfg Config
	mu  sync.Mutex // serialises writes to a customer's directory and to Caddy
}

// Site is what the agent knows about one customer site. Every field is read
// from the system at call time; nothing here is cached and then reported as
// current, because a cached "running" is a claim the mechanism cannot support.
type Site struct {
	ID        string    `json:"id"`
	Domain    string    `json:"domain"`
	Container string    `json:"container"`
	State     string    `json:"state"` // running | stopped | absent
	Root      string    `json:"root"`
	CreatedAt time.Time `json:"createdAt"`
	CPULimit  string    `json:"cpuLimit"`
	MemLimit  string    `json:"memLimit"`

	// Port is FIXED at creation and published explicitly on the host loopback.
	//
	// It used to be an ephemeral port picked by Docker, and that was a latent
	// outage: `docker restart` hands out a different ephemeral port, while the
	// Caddy vhost still names the old one. One restart turned a working site
	// into a 502, so a host reboot - with restart=unless-stopped bringing every
	// container back - would have 502'd every site on the host at once.
	Port int `json:"port"`

	// The site's database and MySQL user. The password is deliberately NOT a
	// field here: this struct is what the API returns. It lives in db.secret,
	// beside site.json, readable by root only.
	Database string `json:"database,omitempty"`
	DBUser   string `json:"dbUser,omitempty"`

	// DiskGB is the size of the site's own filesystem. The usage fields are
	// read from that filesystem at call time and never stored.
	// Aliases are verified custom domains served by the same site. The control
	// plane only sends a domain here after its owner has proved control of it
	// with a DNS TXT record; this list is also what /tls-ask approves.
	Aliases []string `json:"aliases,omitempty"`

	// Background processes run beside Apache (cic-start in the image), each
	// switched on by the customer: a queue worker, the scheduler, and Laravel
	// Reverb for WebSockets. WSPort is Reverb's loopback port, allocated the
	// first time Reverb is switched on and fixed from then on, like Port.
	Queue     bool `json:"queue,omitempty"`
	Scheduler bool `json:"scheduler,omitempty"`
	Reverb    bool `json:"reverb,omitempty"`
	WSPort    int  `json:"wsPort,omitempty"`

	DiskGB        int   `json:"diskGb"`
	DiskUsedBytes int64 `json:"diskUsedBytes"`
	DiskSizeBytes int64 `json:"diskSizeBytes"`
}

// A site id is used as a directory name, a container name and a DNS label, so
// it is restricted to what is safe in all three. Rejecting early is cheaper
// than discovering a path-traversal in the container runtime.
var idRe = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$`)

func ValidID(id string) error {
	if !idRe.MatchString(id) {
		return fmt.Errorf("invalid id %q: 3-40 chars, lowercase letters, digits and hyphens, not starting or ending with a hyphen", id)
	}
	if strings.Contains(id, "--") {
		return fmt.Errorf("invalid id %q: no consecutive hyphens", id)
	}
	return nil
}

func New(cfg Config) (*Manager, error) {
	for _, d := range []string{cfg.Root, cfg.CaddyDir} {
		if err := os.MkdirAll(d, 0o750); err != nil {
			return nil, fmt.Errorf("mkdir %s: %w", d, err)
		}
	}
	if cfg.HostID == "" {
		return nil, fmt.Errorf("empty host id")
	}
	return &Manager{cfg: cfg}, nil
}

func (m *Manager) HostID() string { return m.cfg.HostID }

func (m *Manager) dir(id string) string { return filepath.Join(m.cfg.Root, id) }

func (m *Manager) container(id string) string { return "cic-" + id }

// List reports every site this host holds, with state read from Docker rather
// than from any record we keep. If Docker disagrees with our directory, the
// answer is Docker's.
func (m *Manager) List(ctx context.Context) ([]Site, error) {
	entries, err := os.ReadDir(m.cfg.Root)
	if err != nil {
		return nil, fmt.Errorf("read %s: %w", m.cfg.Root, err)
	}
	running, err := m.runningContainers(ctx)
	if err != nil {
		return nil, err
	}
	var out []Site
	for _, e := range entries {
		if !e.IsDir() {
			continue
		}
		s, err := m.load(e.Name())
		if err != nil {
			continue // a directory we did not write; not a site
		}
		s.State = "stopped"
		if running[m.container(s.ID)] {
			s.State = "running"
		}
		s.DiskUsedBytes, s.DiskSizeBytes, _ = m.DiskUsage(s.ID)
		out = append(out, s)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out, nil
}

func (m *Manager) Get(ctx context.Context, id string) (Site, error) {
	if err := ValidID(id); err != nil {
		return Site{}, err
	}
	s, err := m.load(id)
	if err != nil {
		return Site{}, err
	}
	running, err := m.runningContainers(ctx)
	if err != nil {
		return Site{}, err
	}
	s.State = "stopped"
	if running[m.container(id)] {
		s.State = "running"
	}
	s.DiskUsedBytes, s.DiskSizeBytes, _ = m.DiskUsage(id)
	return s, nil
}

func (m *Manager) load(id string) (Site, error) {
	b, err := os.ReadFile(filepath.Join(m.dir(id), "site.json"))
	if err != nil {
		return Site{}, err
	}
	var s Site
	if err := json.Unmarshal(b, &s); err != nil {
		return Site{}, fmt.Errorf("site.json for %s is unreadable: %w", id, err)
	}
	return s, nil
}

func (m *Manager) save(s Site) error {
	b, err := json.MarshalIndent(s, "", "  ")
	if err != nil {
		return err
	}
	tmp := filepath.Join(m.dir(s.ID), "site.json.tmp")
	final := filepath.Join(m.dir(s.ID), "site.json")
	if err := os.WriteFile(tmp, b, 0o640); err != nil {
		return err
	}
	return os.Rename(tmp, final) // atomic; a half-written record is worse than none
}

func (m *Manager) runningContainers(ctx context.Context) (map[string]bool, error) {
	out, err := run(ctx, 15*time.Second, "docker", "ps", "--format", "{{.Names}}")
	if err != nil {
		return nil, fmt.Errorf("docker ps: %w", err)
	}
	set := map[string]bool{}
	for _, l := range strings.Split(strings.TrimSpace(out), "\n") {
		if l != "" {
			set[l] = true
		}
	}
	return set, nil
}

func run(ctx context.Context, d time.Duration, name string, args ...string) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, d)
	defer cancel()
	cmd := exec.CommandContext(ctx, name, args...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return string(out), fmt.Errorf("%s %s: %w: %s", name, strings.Join(args, " "), err, strings.TrimSpace(string(out)))
	}
	return string(out), nil
}

// Hosts reports whether this host serves the given domain.
//
// Read from the site records at call time, never cached: Caddy asks this
// before requesting a certificate, and a stale "yes" for a deleted site would
// keep issuing certificates for a domain we no longer serve.
func (m *Manager) Hosts(domain string) bool {
	domain = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(domain), "."))
	if domain == "" {
		return false
	}
	entries, err := os.ReadDir(m.cfg.Root)
	if err != nil {
		return false
	}
	for _, e := range entries {
		if !e.IsDir() {
			continue
		}
		s, err := m.load(e.Name())
		if err != nil {
			continue
		}
		if strings.EqualFold(s.Domain, domain) {
			return true
		}
		for _, a := range s.Aliases {
			if strings.EqualFold(a, domain) {
				return true
			}
		}
	}
	return false
}
