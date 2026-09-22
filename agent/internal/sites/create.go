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
	DiskGB   int    `json:"diskGb"`   // size of the site's own filesystem
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

	if err := os.MkdirAll(dir, 0o750); err != nil {
		return Site{}, fmt.Errorf("mkdir %s: %w", dir, err)
	}
	if o.DiskGB == 0 {
		o.DiskGB = defaultDisk
	}
	if err := m.createDisk(ctx, o.ID, o.DiskGB); err != nil {
		_ = m.releaseDisk(context.Background(), o.ID)
		_ = os.RemoveAll(dir)
		return Site{}, err
	}

	// app/ holds the customer's code; public/ is the ONLY thing ever served.
	// The document root cannot be moved above it, which is the whole point:
	// the misconfiguration that exposes .env is not available here.
	for _, path := range []string{m.appDir(o.ID), filepath.Join(m.appDir(o.ID), "public")} {
		if err := os.MkdirAll(path, 0o750); err != nil {
			_ = m.releaseDisk(context.Background(), o.ID)
			_ = os.RemoveAll(dir)
			return Site{}, fmt.Errorf("mkdir %s: %w", path, err)
		}
		// Apache runs as www-data (uid 33) inside the container. A root-owned
		// 0750 volume is unreadable to it and every request 403s. Owning the
		// tree as 33:33 keeps it unreadable to other host users while letting
		// the one container that mounts it serve and write.
		if err := os.Chown(path, wwwUID, wwwGID); err != nil {
			_ = m.releaseDisk(context.Background(), o.ID)
			_ = os.RemoveAll(dir)
			return Site{}, fmt.Errorf("chown %s: %w", path, err)
		}
	}

	cleanup := func() {
		_, _ = run(context.Background(), 30*time.Second, "docker", "rm", "-f", m.container(o.ID))
		_, _ = run(context.Background(), 30*time.Second, "docker", "network", "rm", m.network(o.ID))
		_ = os.RemoveAll(m.caddyFile(o.ID))
		// Never RemoveAll through a live mount: that deletes the files on the
		// image and then fails on the mountpoint. Unmount, and only if that
		// held, remove the directory.
		if m.releaseDisk(context.Background(), o.ID) == nil {
			_ = os.RemoveAll(dir)
		}
	}

	port, err := m.allocatePort(ctx)
	if err != nil {
		cleanup()
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
		DiskGB:    o.DiskGB,
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

	password, err := m.createDatabase(ctx, o.ID)
	if err != nil {
		cleanup()
		return Site{}, err
	}
	if err := os.WriteFile(filepath.Join(dir, "db.secret"), []byte(password), 0o600); err != nil {
		_ = m.dropDatabase(context.Background(), o.ID)
		cleanup()
		return Site{}, fmt.Errorf("store database credentials: %w", err)
	}
	site.Database, site.DBUser = DBName(o.ID), DBUser(o.ID)
	if err := m.save(site); err != nil {
		_ = m.dropDatabase(context.Background(), o.ID)
		cleanup()
		return Site{}, err
	}
	if err := m.configureAppDatabase(ctx, site, password); err != nil {
		_ = m.dropDatabase(context.Background(), o.ID)
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
		// A container must never run without its disk. If the boot-time
		// mount failed, docker has already started it on a bind mount of an
		// EMPTY directory: it serves nothing, and anything it writes lands on
		// the host's own disk, outside the quota. Try to mount; if that does
		// not hold, stop the container and say so.
		if _, err := os.Stat(m.diskImage(s.ID)); err == nil && !isMounted(m.volume(s.ID)) {
			if err := m.mountOp(ctx, "mount", s.ID); err != nil || !isMounted(m.volume(s.ID)) {
				_, _ = run(ctx, 60*time.Second, "docker", "stop", m.container(s.ID))
				changed = append(changed, s.ID+": DISK NOT MOUNTED - container stopped")
				continue
			}
			// Mounted now, but the running container still holds the empty
			// directory from before. Restart it onto the real disk.
			_, _ = run(ctx, 60*time.Second, "docker", "restart", m.container(s.ID))
			changed = append(changed, s.ID+": disk was not mounted; mounted and container restarted")
		}
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

		"-v", m.appDir(s.ID) + ":/var/www/html:rw",
		// The host's MySQL, which binds the docker gateway and nothing public.
		"--add-host", dbHostForSites + ":host-gateway",
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
	// Every name the site answers to. validDomain has already refused braces,
	// quotes, whitespace and newlines in each of them, so none can close this
	// block and open another.
	names := append([]string{s.Domain}, s.Aliases...)
	return fmt.Sprintf(`# codeinchrome site %s - generated, do not edit by hand
%s {
	# Certificate requested on first connection, not at load; see /tls-ask.
	tls {
		on_demand
	}
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
`, s.ID, strings.Join(names, ", "), port, s.ID), nil
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

// Delete removes the container, the vhost, the data, the log and the network.
//
// Each part reports what was OBSERVED, not what was attempted:
//
//	"removed" - it was there, and now it is not
//	"absent"  - it was not there to begin with
//	"failed"  - it was there, and it still is
//
// Booleans were not enough. `docker rm -f` exits 0 for a container that does
// not exist, so a delete aimed at the wrong host reported `container: true`
// and the caller read it as "removed" - a claim about work that never
// happened. Distinguishing absent from removed costs one stat call and stops
// the API asserting something it cannot see.
func (m *Manager) Delete(ctx context.Context, id string) (map[string]string, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	done := map[string]string{}

	// Observe FIRST. After the removal there is no way to tell the two apart.
	_, containerErr := run(ctx, 15*time.Second, "docker", "container", "inspect", m.container(id), "--format", "{{.Id}}")
	hadContainer := containerErr == nil
	_, dirErr := os.Stat(m.dir(id))
	hadData := dirErr == nil
	_, vhostErr := os.Stat(m.caddyFile(id))
	hadVhost := vhostErr == nil
	logPath := filepath.Join(caddyLogDir, id+".log")
	_, logErr := os.Stat(logPath)
	hadLog := logErr == nil
	_, netErr := run(ctx, 15*time.Second, "docker", "network", "inspect", m.network(id), "--format", "{{.Id}}")
	hadNetwork := netErr == nil
	hadDB, hadDBUser, dbErr := m.databaseExists(ctx, id)

	verdict := func(present bool, removeErr error, stillThere func() bool) string {
		if !present {
			return "absent"
		}
		if removeErr != nil && stillThere() {
			return "failed"
		}
		if stillThere() {
			return "failed"
		}
		return "removed"
	}

	_, err := run(ctx, 60*time.Second, "docker", "rm", "-f", m.container(id))
	done["container"] = verdict(hadContainer, err, func() bool {
		_, e := run(ctx, 15*time.Second, "docker", "container", "inspect", m.container(id))
		return e == nil
	})

	err = os.Remove(m.caddyFile(id))
	done["vhost"] = verdict(hadVhost, err, func() bool {
		_, e := os.Stat(m.caddyFile(id))
		return e == nil
	})

	// Unmount the site's disk BEFORE removing anything: RemoveAll through a
	// live mount deletes the files on the image and then fails on the
	// mountpoint, leaving a half-deleted site that still has a disk.
	switch {
	case !hadData: // observed at the top, before anything was removed
		done["data"] = "absent"
	case m.releaseDisk(ctx, id) != nil:
		done["data"] = "failed"
	default:
		_ = os.RemoveAll(m.dir(id))
		if _, err := os.Stat(m.dir(id)); err == nil {
			done["data"] = "failed"
		} else {
			done["data"] = "removed"
		}
	}

	// The access log sits outside the site directory, so RemoveAll misses it.
	// Left behind, a re-created site with the same id appends to the previous
	// tenant's log, which is a leak between customers.
	err = os.Remove(logPath)
	done["log"] = verdict(hadLog, err, func() bool {
		_, e := os.Stat(logPath)
		return e == nil
	})

	// Networks are not removed by `docker rm`. Left behind they exhaust the
	// address pool and a re-created site silently rejoins a network the
	// previous tenant's containers may still be attached to.
	_, err = run(ctx, 30*time.Second, "docker", "network", "rm", m.network(id))
	done["network"] = verdict(hadNetwork, err, func() bool {
		_, e := run(ctx, 15*time.Second, "docker", "network", "inspect", m.network(id))
		return e == nil
	})

	// The database and its user. "failed" if we could not even look: a
	// database we cannot see is not a database we can claim is gone.
	switch {
	case dbErr != nil && m.cfg.MySQLPassword == "":
		done["database"] = "absent" // no server on this host, so nothing to remove
	case dbErr != nil:
		done["database"] = "failed"
	case !hadDB && !hadDBUser:
		done["database"] = "absent"
	default:
		_ = m.dropDatabase(ctx, id)
		stillDB, stillUser, err := m.databaseExists(ctx, id)
		if err != nil || stillDB || stillUser {
			done["database"] = "failed"
		} else {
			done["database"] = "removed"
		}
	}

	if done["vhost"] == "removed" {
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

// Limits changes a running site's ceilings, for a plan change.
//
// CPU and memory apply immediately (docker update). The disk only ever GROWS:
// shrinking a filesystem that holds a customer's files is not something to do
// automatically on a billing event, and the downgrade rule elsewhere is the
// same - a customer keeps what they have and cannot add more. The answer says
// which parts were applied, so a refused shrink is never reported as done.
type LimitsOpts struct {
	CPULimit string `json:"cpuLimit"`
	MemLimit string `json:"memLimit"`
	DiskGB   int    `json:"diskGb"`
}

func (m *Manager) SetLimits(ctx context.Context, id string, o LimitsOpts) (map[string]string, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	site, err := m.load(id)
	if err != nil {
		return nil, fmt.Errorf("no such site %q", id)
	}
	applied := map[string]string{}

	if o.CPULimit != "" || o.MemLimit != "" {
		args := []string{"update"}
		if o.CPULimit != "" {
			args = append(args, "--cpus", o.CPULimit)
		}
		if o.MemLimit != "" {
			// --memory-swap equal to --memory: no swap escape hatch, as at creation.
			args = append(args, "--memory", o.MemLimit, "--memory-swap", o.MemLimit)
		}
		args = append(args, m.container(id))
		if _, err := run(ctx, 60*time.Second, "docker", args...); err != nil {
			applied["cpuMemory"] = "failed: " + err.Error()
		} else {
			if o.CPULimit != "" {
				site.CPULimit = o.CPULimit
			}
			if o.MemLimit != "" {
				site.MemLimit = o.MemLimit
			}
			applied["cpuMemory"] = "applied"
		}
	}

	if o.DiskGB > 0 {
		current := site.DiskGB
		if current == 0 {
			current = defaultDisk
		}
		switch {
		case o.DiskGB == current:
			applied["disk"] = "unchanged"
		case o.DiskGB < current:
			applied["disk"] = fmt.Sprintf("kept at %d GB: disks are never shrunk automatically", current)
		default:
			if err := m.growDisk(ctx, id, o.DiskGB); err != nil {
				applied["disk"] = "failed: " + err.Error()
			} else {
				site.DiskGB = o.DiskGB
				applied["disk"] = "grown"
			}
		}
	}

	if err := m.save(site); err != nil {
		return applied, err
	}
	return applied, nil
}

// MaxAliases bounds the vhost and the certificates one site can make us hold.
const MaxAliases = 10

// SetAliases replaces the site's custom domains and reloads the proxy.
//
// The whole list, not add/remove: the control plane is the record of which
// domains are verified, and sending the full set means a missed call can
// never leave a stale domain being served.
func (m *Manager) SetAliases(ctx context.Context, id string, aliases []string) (Site, error) {
	if err := ValidID(id); err != nil {
		return Site{}, err
	}
	if len(aliases) > MaxAliases {
		return Site{}, fmt.Errorf("at most %d custom domains per site", MaxAliases)
	}
	seen := map[string]bool{}
	clean := []string{}
	for _, a := range aliases {
		a = strings.ToLower(strings.TrimSuffix(strings.TrimSpace(a), "."))
		if err := validDomain(a); err != nil {
			return Site{}, err
		}
		if seen[a] {
			continue
		}
		seen[a] = true
		clean = append(clean, a)
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	site, err := m.load(id)
	if err != nil {
		return Site{}, fmt.Errorf("no such site %q", id)
	}
	for _, a := range clean {
		if strings.EqualFold(a, site.Domain) {
			return Site{}, fmt.Errorf("%s is already the site's own address", a)
		}
	}
	// Refuse a domain another site on this host already serves: two vhosts
	// claiming one name is a Caddy config error that would block every reload.
	entries, _ := os.ReadDir(m.cfg.Root)
	for _, e := range entries {
		other, err := m.load(e.Name())
		if err != nil || other.ID == id {
			continue
		}
		for _, a := range clean {
			if strings.EqualFold(a, other.Domain) || containsFold(other.Aliases, a) {
				return Site{}, fmt.Errorf("%s is already served by another site on this host", a)
			}
		}
	}

	previous := site.Aliases
	site.Aliases = clean
	if err := m.save(site); err != nil {
		return Site{}, err
	}
	if err := m.writeCaddy(ctx, site); err != nil {
		// Put the record back so it matches the vhost that is still live.
		site.Aliases = previous
		_ = m.save(site)
		_ = m.writeCaddy(context.Background(), site)
		return Site{}, err
	}
	return site, nil
}

func containsFold(list []string, s string) bool {
	for _, x := range list {
		if strings.EqualFold(x, s) {
			return true
		}
	}
	return false
}
