package sites

import (
	"context"
	"fmt"
	"net"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// CreateOpts is what the control plane sends to stand a site up.
type CreateOpts struct {
	ID       string `json:"id"`
	Domain   string `json:"domain"`
	CPULimit string `json:"cpuLimit"` // docker --cpus, e.g. "0.5"
	MemLimit string `json:"memLimit"` // docker --memory, e.g. "512m"
}

const (
	// www-data inside the php:8.3-apache image.
	wwwUID = 33
	wwwGID = 33

	defaultCPU = "0.5"
	defaultMem = "512m"

	// One shared base image per stack. Every site adds only its own code, so
	// fifty Laravel sites cost one image plus fifty thin layers rather than
	// fifty 800 MB images — the reason we build these rather than using a
	// per-app auto-builder.
	laravelImage = "codeinchrome/laravel:8.3"

	// Stable published ports live here. Chosen above the ephemeral range Docker
	// and the kernel hand out, so an allocation cannot collide with one.
	portMin = 20000
	portMax = 29999
)

// Create stands up a site: directory, container, Caddy vhost.
//
// It is not atomic across all three, so it cleans up after itself on failure.
// A half-created site that still answers on a domain is worse than none.
func (m *Manager) Create(ctx context.Context, o CreateOpts) (Site, error) {
	if err := ValidID(o.ID); err != nil {
		return Site{}, err
	}
	if err := validDomain(o.Domain); err != nil {
		return Site{}, err
	}
	if o.CPULimit == "" {
		o.CPULimit = defaultCPU
	}
	if o.MemLimit == "" {
		o.MemLimit = defaultMem
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	dir := m.dir(o.ID)
	if _, err := os.Stat(dir); err == nil {
		return Site{}, fmt.Errorf("site %q already exists on this host", o.ID)
	}

	// app/ holds the customer's code; public/ is the ONLY thing ever served.
	// The document root cannot be moved above it, which is the whole point:
	// the misconfiguration that exposes .env is not available here.
	for _, sub := range []string{"app", "app/public"} {
		path := filepath.Join(dir, sub)
		if err := os.MkdirAll(path, 0o750); err != nil {
			return Site{}, fmt.Errorf("mkdir %s: %w", sub, err)
		}
		// Apache runs as www-data (uid 33) inside the container. A root-owned
		// 0750 volume is unreadable to it and every request 403s. Owning the
		// tree as 33:33 keeps it unreadable to other host users while letting
		// the one container that mounts it serve and write.
		if err := os.Chown(path, wwwUID, wwwGID); err != nil {
			return Site{}, fmt.Errorf("chown %s: %w", sub, err)
		}
	}

	cleanup := func() {
		_, _ = run(context.Background(), 30*time.Second, "docker", "rm", "-f", m.container(o.ID))
		_, _ = run(context.Background(), 30*time.Second, "docker", "network", "rm", m.network(o.ID))
		_ = os.RemoveAll(m.caddyFile(o.ID))
		_ = os.RemoveAll(dir)
	}

	port, err := m.allocatePort(ctx)
	if err != nil {
		_ = os.RemoveAll(dir)
		return Site{}, err
	}

	site := Site{
		Port:      port,
		ID:        o.ID,
		Domain:    o.Domain,
		Container: m.container(o.ID),
		Root:      dir,
		CreatedAt: time.Now().UTC(),
		CPULimit:  o.CPULimit,
		MemLimit:  o.MemLimit,
	}
	if err := m.save(site); err != nil {
		cleanup()
		return Site{}, err
	}

	if err := m.ensureNetwork(ctx, o.ID); err != nil {
		cleanup()
		return Site{}, err
	}
	if err := m.startContainer(ctx, site); err != nil {
		cleanup()
		return Site{}, err
	}
	if err := m.seedApp(ctx, site); err != nil {
		cleanup()
		return Site{}, err
	}
	if err := m.writeCaddy(ctx, site); err != nil {
		cleanup()
		return Site{}, err
	}

	site.State = "running"
	return site, nil
}

// allocatePort picks the lowest free port in the reserved range. Caller holds
// the lock. It checks BOTH what we have recorded and what is actually bound:
// a port recorded nowhere but held by some other process is still taken.
func (m *Manager) allocatePort(ctx context.Context) (int, error) {
	used := map[int]bool{}
	entries, err := os.ReadDir(m.cfg.Root)
	if err != nil {
		return 0, fmt.Errorf("read %s: %w", m.cfg.Root, err)
	}
	for _, e := range entries {
		if !e.IsDir() {
			continue
		}
		if s, err := m.load(e.Name()); err == nil && s.Port != 0 {
			used[s.Port] = true
		}
	}
	for p := portMin; p <= portMax; p++ {
		if used[p] {
			continue
		}
		l, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", p))
		if err != nil {
			continue // someone is bound to it, recorded or not
		}
		_ = l.Close()
		return p, nil
	}
	return 0, fmt.Errorf("no free port in %d-%d: this host is full", portMin, portMax)
}

// Reconcile rewrites every vhost from what Docker reports right now, then
// reloads once. Run at start-up so a host that rebooted, or drifted for any
// other reason, heals itself instead of serving 502s until someone notices.
// It reports what it changed; "reconciled" with an empty list is a real claim,
// "reconciled" with no detail is not.
func (m *Manager) Reconcile(ctx context.Context) ([]string, error) {
	list, err := m.List(ctx)
	if err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	var changed []string
	for _, s := range list {
		if s.State != "running" {
			continue
		}
		want, err := m.renderCaddy(ctx, s)
		if err != nil {
			changed = append(changed, s.ID+": "+err.Error())
			continue
		}
		have, _ := os.ReadFile(m.caddyFile(s.ID))
		if string(have) == want {
			continue
		}
		if err := ensureCaddyLog(filepath.Join(caddyLogDir, s.ID+".log")); err != nil {
			changed = append(changed, s.ID+": "+err.Error())
			continue
		}
		if err := os.WriteFile(m.caddyFile(s.ID), []byte(want), 0o644); err != nil {
			changed = append(changed, s.ID+": "+err.Error())
			continue
		}
		changed = append(changed, s.ID+": vhost rewritten to match the running container")
	}
	if len(changed) > 0 {
		if err := m.ReloadProxy(ctx); err != nil {
			return changed, err
		}
	}
	return changed, nil
}

func (m *Manager) network(id string) string { return "cic-net-" + id }

// ensureNetwork gives the site a bridge of its own.
//
// Belt to icc=false's braces, and the sturdier of the two. On the shared
// default bridge one tenant fetched another tenant's live app directly from
// 172.17.0.2:8080 - 70,403 bytes, straight past Caddy. Docker enforces
// separation between user-defined bridges in its DOCKER-ISOLATION-STAGE
// chains, so a per-site network survives someone flipping a daemon-wide flag.
// The claim is not taken on trust: install-agent.sh proves one container
// cannot reach another before declaring the host ready.
func (m *Manager) ensureNetwork(ctx context.Context, id string) error {
	name := m.network(id)
	if out, err := run(ctx, 15*time.Second, "docker", "network", "inspect", name, "--format", "{{.Name}}"); err == nil {
		if strings.TrimSpace(out) == name {
			return nil
		}
	}
	if _, err := run(ctx, 60*time.Second, "docker", "network", "create",
		"--driver", "bridge",
		"--label", "codeinchrome.site="+id,
		"--opt", "com.docker.network.bridge.enable_icc=false",
		name,
	); err != nil {
		return fmt.Errorf("create network for %s: %w", id, err)
	}
	return nil
}

// startContainer runs the customer's container with every restriction we can
// apply without breaking a normal Laravel app.
func (m *Manager) startContainer(ctx context.Context, s Site) error {
	args := []string{
		"run", "-d",
		"--name", s.Container,
		"--restart", "unless-stopped",
		"--network", m.network(s.ID),
		"--label", "codeinchrome.site=" + s.ID,
		"--label", "codeinchrome.host=" + m.cfg.HostID,

		// Resource ceilings. Mining stops being a policing problem and becomes
		// arithmetic: capped at half a core, it earns cents, and sustained load
		// at the ceiling is an obvious alarm.
		"--cpus", s.CPULimit,
		"--memory", s.MemLimit,
		"--memory-swap", s.MemLimit, // no swap escape hatch
		"--pids-limit", "256",

		// Privilege. Nothing in a customer container needs any of this.
		"--security-opt", "no-new-privileges",
		"--cap-drop", "ALL",
		"--cap-add", "CHOWN",
		"--cap-add", "SETUID",
		"--cap-add", "SETGID",

		// Writable only where a Laravel app genuinely writes.
		"--read-only",
		"--tmpfs", "/tmp:rw,noexec,nosuid,size=64m",
		"--tmpfs", "/run:rw,noexec,nosuid,size=16m",

		"-v", filepath.Join(s.Root, "app") + ":/var/www/html:rw",
		// Fixed, loopback-only. Not ephemeral: see Site.Port for the 502 that
		// taught us the difference.
		"--publish", fmt.Sprintf("127.0.0.1:%d:8080", s.Port),
		laravelImage,
	}
	if _, err := run(ctx, 2*time.Minute, "docker", args...); err != nil {
		return fmt.Errorf("start container for %s: %w", s.ID, err)
	}
	return nil
}

// seedApp copies the baked-in Laravel skeleton into the new site and gives it
// an application key of its own.
//
// Copied from the image rather than installed per site: `composer
// create-project` is ninety seconds and several hundred network requests, and
// it fails when packagist does. This is a local copy of a directory that is
// already on disk.
//
// The key is generated PER SITE. A key shared across the platform would let
// anyone holding it forge session cookies and decrypt encrypted columns for
// every other customer, so this is the one thing here that must not be baked
// into the image.
func (m *Manager) seedApp(ctx context.Context, s Site) error {
	// Copied AS www-data, not as root.
	//
	// Root inside these containers has every capability dropped, including
	// CAP_DAC_OVERRIDE - the one that lets root ignore file permissions. So
	// uid 0 could not read into the 33:33-owned volume at all and the copy
	// failed with `cannot stat '/var/www/html/.': Permission denied`. Running
	// as the user that owns the destination needs no capability, and the
	// skeleton in the image is world-readable. Copying as root would have
	// meant handing these containers back a capability, which is a far worse
	// trade than changing a uid.
	if _, err := run(ctx, 3*time.Minute, "docker", "exec", "-u", "33:33", s.Container,
		"sh", "-c", "cp -a /opt/cic-skeleton/. /var/www/html/",
	); err != nil {
		return fmt.Errorf("seed the app for %s: %w", s.ID, err)
	}

	if _, err := run(ctx, 60*time.Second, "docker", "exec", "-u", "33:33", s.Container,
		"sh", "-c", "cd /var/www/html && cp .env.example .env && php artisan key:generate --force --no-interaction",
	); err != nil {
		return fmt.Errorf("generate an app key for %s: %w", s.ID, err)
	}

	// Production posture from the first request. APP_DEBUG=true would put a
	// stack trace with paths, queries and sometimes credentials in front of
	// anyone who can trigger a 500.
	if _, err := run(ctx, 30*time.Second, "docker", "exec", "-u", "33:33", s.Container,
		"sh", "-c", `cd /var/www/html && sed -i 's/^APP_ENV=.*/APP_ENV=production/; s/^APP_DEBUG=.*/APP_DEBUG=false/' .env`,
	); err != nil {
		return fmt.Errorf("set production defaults for %s: %w", s.ID, err)
	}

	// Observed, not assumed: a key that did not land means every session on
	// this site is unsigned, and that must fail creation rather than ship.
	out, err := run(ctx, 30*time.Second, "docker", "exec", "-u", "33:33", s.Container,
		"sh", "-c", "grep -c '^APP_KEY=base64:' /var/www/html/.env")
	if err != nil || strings.TrimSpace(out) != "1" {
		return fmt.Errorf("site %s has no application key after seeding; refusing to serve it", s.ID)
	}

	return nil
}

// Port asks Docker which loopback port this site landed on. Read at call time,
// never cached: a stale port silently proxies one customer to another.
func (m *Manager) Port(ctx context.Context, id string) (string, error) {
	out, err := run(ctx, 15*time.Second, "docker", "port", m.container(id), "8080/tcp")
	if err != nil {
		return "", err
	}
	line := strings.TrimSpace(strings.Split(strings.TrimSpace(out), "\n")[0])
	i := strings.LastIndex(line, ":")
	if i < 0 {
		return "", fmt.Errorf("cannot parse docker port output %q", line)
	}
	return line[i+1:], nil
}

const caddyLogDir = "/var/log/caddy"

// ensureCaddyLog creates a per-site access log the caddy user can write.
// Idempotent, and deliberately does not chown a file that already exists: an
// operator who has repointed a log somewhere has a reason.
func ensureCaddyLog(path string) error {
	if _, err := os.Stat(path); err == nil {
		return nil
	}
	uid, gid, err := caddyIDs()
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
		return err
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY, 0o640)
	if err != nil {
		return fmt.Errorf("create access log %s: %w", path, err)
	}
	_ = f.Close()
	if err := os.Chown(path, uid, gid); err != nil {
		return fmt.Errorf("chown access log %s to caddy: %w", path, err)
	}
	return nil
}

func caddyIDs() (int, int, error) {
	u, err := user.Lookup("caddy")
	if err != nil {
		return 0, 0, fmt.Errorf("look up the caddy user: %w", err)
	}
	uid, err := strconv.Atoi(u.Uid)
	if err != nil {
		return 0, 0, fmt.Errorf("caddy uid %q is not numeric: %w", u.Uid, err)
	}
	gid, err := strconv.Atoi(u.Gid)
	if err != nil {
		return 0, 0, fmt.Errorf("caddy gid %q is not numeric: %w", u.Gid, err)
	}
	return uid, gid, nil
}

func (m *Manager) caddyFile(id string) string {
	return filepath.Join(m.cfg.CaddyDir, id+".caddy")
}

// renderCaddy builds the vhost from the port Docker ACTUALLY published, not
// from the port we recorded. The two agree now that ports are fixed, but
// Docker is the one serving traffic, so Docker is the one that decides.
func (m *Manager) renderCaddy(ctx context.Context, s Site) (string, error) {
	port, err := m.Port(ctx, s.ID)
	if err != nil {
		return "", err
	}
	return fmt.Sprintf(`# codeinchrome site %s - generated, do not edit by hand
%s {
	reverse_proxy 127.0.0.1:%s
	encode gzip zstd
	header {
		-Server
		Strict-Transport-Security "max-age=31536000; includeSubDomains"
		X-Content-Type-Options "nosniff"
		X-Frame-Options "SAMEORIGIN"
		Referrer-Policy "strict-origin-when-cross-origin"
	}
	log {
		output file /var/log/caddy/%s.log
		format json
	}
}
`, s.ID, s.Domain, port, s.ID), nil
}

func (m *Manager) writeCaddy(ctx context.Context, s Site) error {
	conf, err := m.renderCaddy(ctx, s)
	if err != nil {
		return err
	}

	// Create the access log OWNED BY CADDY before anything validates this file.
	//
	// ReloadProxy runs `caddy validate` as root, and validate does not merely
	// parse - it provisions, which opens the log writer and CREATES the file as
	// root with mode 0600. The caddy service then runs as user caddy, cannot
	// open its own log, and refuses the whole config with `permission denied`.
	// The validation step was causing the exact failure it exists to prevent,
	// and it took down the reload for every other site on the host with it.
	// Pre-creating the file means validate finds it and leaves it alone.
	if err := ensureCaddyLog(filepath.Join(caddyLogDir, s.ID+".log")); err != nil {
		return err
	}

	if err := os.WriteFile(m.caddyFile(s.ID), []byte(conf), 0o644); err != nil {
		return err
	}
	return m.ReloadProxy(ctx)
}

// ReloadProxy validates before it reloads. Caddy will refuse a bad config, but
// checking first means a broken vhost never takes every other site on the host
// down with it.
func (m *Manager) ReloadProxy(ctx context.Context) error {
	if _, err := run(ctx, 30*time.Second, "caddy", "validate", "--config", "/etc/caddy/Caddyfile"); err != nil {
		return fmt.Errorf("caddy config invalid, NOT reloaded: %w", err)
	}
	if _, err := run(ctx, 30*time.Second, "systemctl", "reload", "caddy"); err != nil {
		return fmt.Errorf("caddy reload: %w", err)
	}
	return nil
}

// Delete removes the container, the vhost and the data. It is deliberately
// explicit about what it removed, because "deleted: true" with a container
// still running is exactly the kind of claim this codebase refuses to make.
func (m *Manager) Delete(ctx context.Context, id string) (map[string]bool, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	done := map[string]bool{}
	_, err := run(ctx, 60*time.Second, "docker", "rm", "-f", m.container(id))
	done["container"] = err == nil

	err = os.Remove(m.caddyFile(id))
	done["vhost"] = err == nil || os.IsNotExist(err)

	err = os.RemoveAll(m.dir(id))
	done["data"] = err == nil

	// The access log is outside the site directory, so RemoveAll above misses
	// it. Left behind, a re-created site with the same id silently appends to
	// the previous tenant's log - which is a data leak between customers.
	err = os.Remove(filepath.Join(caddyLogDir, id+".log"))
	done["log"] = err == nil || os.IsNotExist(err)

	// Networks are not removed by `docker rm`. Left behind they exhaust the
	// address pool, and a re-created site would silently rejoin a network the
	// previous tenant's containers might still be attached to.
	_, err = run(ctx, 30*time.Second, "docker", "network", "rm", m.network(id))
	done["network"] = err == nil || func() bool {
		_, e := run(ctx, 15*time.Second, "docker", "network", "inspect", m.network(id))
		return e != nil // already gone is success
	}()

	if done["vhost"] {
		_ = m.ReloadProxy(ctx)
	}
	return done, nil
}

func validDomain(d string) error {
	if d == "" {
		return fmt.Errorf("domain is required")
	}
	if len(d) > 253 || strings.ContainsAny(d, " \t\n\r{}\"'\\") {
		return fmt.Errorf("invalid domain %q", d)
	}
	if !strings.Contains(d, ".") {
		return fmt.Errorf("invalid domain %q: needs at least one dot", d)
	}
	return nil
}
