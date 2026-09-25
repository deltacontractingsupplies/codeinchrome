package sites

import (
	"context"
	"fmt"
	"net"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
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
	// The connection cap for this site's size (sizing.go), not a flat 20.
	if err := m.setDBConnections(ctx, o.ID, o.MemLimit, 0); err != nil {
		_ = m.dropDatabase(context.Background(), o.ID)
		cleanup()
		return Site{}, fmt.Errorf("size the database connections: %w", err)
	}
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
		if s, err := m.load(e.Name()); err == nil {
			if s.Port != 0 {
				used[s.Port] = true
			}
			if s.WSPort != 0 {
				used[s.WSPort] = true
			}
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
	previous := map[string][]byte{} // vhost file -> what it held before this pass
	// Each running site's answer through Caddy BEFORE anything is rewritten,
	// so a rewrite that breaks it can be told apart from a site that was
	// already failing.
	before := map[string]int{}
	roots := m.edgeRoots()
	for _, s := range list {
		if s.State == "running" && !s.Suspended && s.Domain != "" {
			before[s.Domain] = edgeStatus(ctx, s.Domain, roots)
		}
	}
	probe := map[string]int{} // rewritten sites -> their status before
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
			if s.Suspended {
				changed = append(changed, s.ID+": disk mounted (suspended; left stopped)")
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
		// Sites created before sizing.go had a flat cap of 20 connections;
		// bring each in line with its memory. Idempotent.
		if s.MemLimit != "" && m.cfg.MySQLPassword != "" {
			if err := m.setDBConnections(ctx, s.ID, s.MemLimit, s.background()); err != nil {
				changed = append(changed, s.ID+": database connection cap not set: "+err.Error())
			}
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
		previous[m.caddyFile(s.ID)] = have
		probe[s.Domain] = before[s.Domain]
		changed = append(changed, s.ID+": vhost rewritten to match the running container")
	}
	if len(changed) > 0 {
		if err := m.ReloadProxy(ctx); err != nil {
			// A rewritten vhost Caddy refuses would stay on disk and block
			// every later reload, for every site on the host: put each back
			// as it was, and reload that.
			for path, data := range previous {
				_ = os.WriteFile(path, data, 0o644)
			}
			_ = m.ReloadProxy(context.Background())
			return append(changed, "vhosts restored: caddy refused the rewrite"), err
		}
		// Caddy accepting a config is not the same as serving with it.
		after := map[string]int{}
		for domain := range probe {
			after[domain] = edgeStatus(ctx, domain, roots)
		}
		if broken := brokenByReload(probe, after); len(broken) > 0 {
			for path, data := range previous {
				_ = os.WriteFile(path, data, 0o644)
			}
			if err := m.ReloadProxy(context.Background()); err != nil {
				return append(changed, "vhosts restored after the rewrite broke "+strings.Join(broken, ", ")+", but the reload failed"), err
			}
			return append(changed, "vhosts restored: the rewrite broke "+strings.Join(broken, ", ")), fmt.Errorf("the new vhosts answered 5xx for %s; the previous ones are back", strings.Join(broken, ", "))
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
	if _, err := run(ctx, 2*time.Minute, "docker", m.runArgs(s)...); err != nil {
		return fmt.Errorf("start container for %s: %w", s.ID, err)
	}
	return nil
}

// runArgs is the whole `docker run` for a site: pure, so it can be tested.
func (m *Manager) runArgs(s Site) []string {
	args := []string{
		"run", "-d",
		"--name", s.Container,
		"--restart", "unless-stopped",
		"--network", m.network(s.ID),
		"--label", "codeinchrome.site=" + s.ID,
		"--label", "codeinchrome.host=" + m.cfg.HostID,
		"--label", "codeinchrome.runspec=" + m.runSpec(),

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
		// The background processes cic-start runs (see background.go).
		"--env", "CIC_QUEUE=" + boolEnv(s.Queue),
		"--env", "CIC_SCHEDULER=" + boolEnv(s.Scheduler),
		"--env", "CIC_REVERB=" + boolEnv(s.Reverb && s.WSPort != 0),
		// Laravel's debug page shows a visitor the request, the stack and the
		// values around the failure - on a public site that is how secrets
		// leak. A real environment variable wins over .env (Laravel's dotenv
		// is immutable), so APP_DEBUG=true written into .env - by the owner,
		// or by an agent chasing an error - cannot switch it on. Errors are
		// read in the editor instead (cic.logs, Boost's last-error). Apache
		// passes it to PHP with PassEnv (the image's cic-env.conf).
		"--env", "APP_DEBUG=false",
	}
	// Disk I/O ceilings (diskio.go).
	args = append(args, m.ioArgs()...)
	if s.PHP != (PHPSettings{}) {
		// The owner's PHP settings: mounted read-only over the image's, so
		// the site's own code cannot change or widen them (php.go).
		args = append(args, "-v", m.phpIni(s.ID)+":/usr/local/etc/php/conf.d/zz-site.ini:ro")
	}
	if s.Reverb && s.WSPort != 0 {
		// Reverb's WebSocket port, loopback only; Caddy routes /app/* to it.
		args = append(args, "--publish", fmt.Sprintf("127.0.0.1:%d:8081", s.WSPort))
		// One open file per connection. The host's default (daemon.json:
		// 1024 soft, 4096 hard) is right for a web-only site and held Reverb
		// to ~1000 connections on every plan - measured by tests/load/ws.sh,
		// where 2000 connections left exactly 1011 subscribed. What a site
		// can actually hold stays bounded by its memory limit: socket
		// buffers are charged to the container's cgroup.
		args = append(args, "--ulimit", fmt.Sprintf("nofile=%d:%d", wsOpenFiles, wsOpenFiles))
	}
	return append(args, laravelImage)
}

// wsOpenFiles is the open-file limit of a site with WebSockets on.
const wsOpenFiles = 65536

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

// deletedLogDir holds the access logs of deleted sites for 30 days.
var deletedLogDir = caddyLogDir + "/deleted" // a variable for the tests

// keepDeletedLog moves a deleted site's access log aside, stamped with the
// time, so a site made again under the same name cannot mix with it.
func keepDeletedLog(logPath, id string) error {
	if _, err := os.Stat(logPath); os.IsNotExist(err) {
		return nil
	}
	if err := os.MkdirAll(deletedLogDir, 0o750); err != nil {
		return err
	}
	return os.Rename(logPath, filepath.Join(deletedLogDir, id+"-"+time.Now().UTC().Format("20060102T150405Z")+".log"))
}

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
	if s.Suspended {
		// A stopped container publishes no port, and none is needed.
		return caddyConfig(m.cfg, s, ""), nil
	}
	port, err := m.Port(ctx, s.ID)
	if err != nil {
		return "", err
	}
	return caddyConfig(m.cfg, s, port), nil
}

// onPlatform reports whether name is a direct subdomain of the platform
// domain - the only names the origin wildcard covers.
func onPlatform(cfg Config, name string) bool {
	if cfg.PlatformDomain == "" {
		return false
	}
	label, ok := strings.CutSuffix(name, "."+cfg.PlatformDomain)
	return ok && label != "" && !strings.Contains(label, ".")
}

// caddyConfig renders a site's vhost. validDomain has already refused braces,
// quotes, whitespace and newlines in every name, so none can close a block
// and open another.
//
// With an origin certificate configured, the platform name and the customer's
// own domains are separate blocks: the platform name is served with the
// origin certificate, custom domains keep an on-demand certificate of their
// own (see /tls-ask). Both proxy to the same container and log to the same file.
func caddyConfig(cfg Config, s Site, port string) string {
	var platform, custom []string
	for _, name := range append([]string{s.Domain}, s.Aliases...) {
		if cfg.OriginCert != "" && onPlatform(cfg, name) {
			platform = append(platform, name)
		} else {
			custom = append(custom, name)
		}
	}

	// Reverb's WebSocket endpoint (/app/{key}) goes to its own port; its
	// /apps/* HTTP API, which the app uses to publish events, is NOT routed:
	// the app reaches it inside the container, and it stays private.
	route := guardAbuseMatchers(cfg, s) + appProxy(port, "\t")
	if s.Reverb && s.WSPort != 0 {
		route = guardAbuseMatchers(cfg, s) + fmt.Sprintf("	handle /app/* {\n		reverse_proxy 127.0.0.1:%d\n	}\n	handle {\n", s.WSPort) +
			appProxy(port, "\t\t") + "\t}\n"
	}
	if s.Suspended {
		route = suspendedBody
	}
	body := guardSecrets(route) + fmt.Sprintf(`	encode gzip zstd
	header {
		-Server
		Strict-Transport-Security "max-age=31536000; includeSubDomains"
		X-Content-Type-Options "nosniff"
		X-Frame-Options "SAMEORIGIN"
		Referrer-Policy "strict-origin-when-cross-origin"%s
		# A Refresh header sends the visitor elsewhere like a redirect, and
		# was not checked (audit, 2026-09-25). No Laravel app needs it.
		-Refresh
	}
	log {
		output file /var/log/caddy/%s.log
		format json
	}
`, robotsHeader(s), s.ID)

	out := fmt.Sprintf("# codeinchrome site %s - generated, do not edit by hand\n", s.ID)
	if len(platform) > 0 {
		out += fmt.Sprintf(`%s {
	# Reached through Cloudflare; the origin certificate is trusted by
	# Cloudflare only. No ACME order, no public CA rate limit.
	tls %s %s
%s}
`, strings.Join(platform, ", "), cfg.OriginCert, cfg.OriginKey, body)
	}
	if len(custom) > 0 {
		out += fmt.Sprintf(`%s {
	# Certificate requested on first connection, not at load; see /tls-ask.
	tls {
		on_demand
	}
%s}
`, strings.Join(custom, ", "), body)
	}
	return out
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
	_, err := run(ctx, 30*time.Second, "systemctl", "reload", "caddy")
	if err != nil {
		// One retry after the grace period. A reload can fail transiently
		// while an old server is still draining its last connections; the
		// config itself was already validated above, so a second attempt is
		// safe and does not mask a real configuration error.
		time.Sleep(11 * time.Second)
		if _, err2 := run(ctx, 30*time.Second, "systemctl", "reload", "caddy"); err2 != nil {
			return fmt.Errorf("caddy reload (after one retry): %w", err2)
		}
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
	m.LSPCloseSite(id)
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
	// Kept, not deleted: a phishing site is often deleted within hours of
	// its first victim, and its access log is the evidence an abuse report
	// or the police will ask for. Moved aside, pruned after 30 days
	// (install-agent.sh's cic-evidence-prune).
	err = keepDeletedLog(logPath, id)
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
			if o.MemLimit != "" {
				// The database cap follows the memory (see sizing.go), and
				// the container restarts so Apache re-sizes its workers to
				// the new limit - a few seconds, on a plan change only.
				if err := m.setDBConnections(ctx, id, o.MemLimit, site.background()); err != nil {
					applied["dbConnections"] = "failed: " + err.Error()
				} else {
					applied["dbConnections"] = strconv.Itoa(ConnectionsFor(o.MemLimit, site.background()))
				}
				if _, err := run(ctx, 60*time.Second, "docker", "restart", "-t", "10", m.container(id)); err != nil {
					applied["workers"] = "restart failed: " + err.Error()
				} else {
					applied["workers"] = strconv.Itoa(WorkersFor(o.MemLimit, site.background()))
				}
			}
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

// secretPath is what the edge never serves, whatever the site does: dotfiles
// and dot-directories (.env, .git, .htaccess), secrets and dumps by extension,
// and the project files a misplaced document root would expose.
//
// It is enforced HERE, in Caddy, and not only in the container's Apache,
// because the container's rules are the tenant's to undo: Apache honours the
// site's own .htaccess (AllowOverride All, which Laravel's routing needs), and
// one line in it - written by the owner, or by an AI agent asked to "fix a
// 403" - would grant /.env again. Nothing inside the site can change this
// file. The path is matched after Caddy has decoded it (%2e is a dot) and
// case-insensitively; .well-known stays reachable for domain verification.
//
// Two matchers, not one: Caddy ANDs everything inside a matcher block, so a
// single block with "not path /.well-known/*" exempted .well-known from the
// WHOLE list - a dump.sql or a key under /.well-known/ was served (found in
// review). Only the dotfile rule needs the exemption; the names never do.
const secretDot = `(?i)/\.`

const secretPath = `(?i)(\.(env|sql|sqlite|sqlite3|db|log|bak|old|orig|swp|save|pem|key)$|/(composer\.(json|lock)|package(-lock)?\.json|artisan|phpunit\.xml|auth\.json)$|/\.env)`

// guardSecrets wraps a site's routes so the secret check runs first. A route
// block keeps directives in the order written: at the top level Caddy sorts
// them, and a handle block (the WebSocket routing) would run before respond.
// What a customer's app may not hand a visitor (owner's decision, 2026-09-25,
// while every site is free): a program or archive download, or a redirect to
// another site - the two ways a free site becomes a malware or phishing relay.
// Enforced here, on the app's RESPONSE, whatever its PHP does. Proved with an
// isolated Caddy on a host before it shipped: an .exe by type or by
// Content-Disposition, and redirects to another site - plain, "//host",
// "/\host", with leading space, "javascript:", "allowed.com.evil" and
// "allowed.com@evil" - were all refused; Stripe, Google sign-in, relative and
// same-site redirects passed untouched, and so did a CSV download.
//
// A plain link on a page cannot be stopped here - no browser policy covers
// navigation - so those are the scanner's job.
// Every rule is a case-insensitive RE2 regexp, used as is by Caddy's CEL
// matches() and by the Go tests. Lists of Caddy header values were
// case-sensitive and exact: "setup.EXE", "Application/X-MSDownload",
// filename*=UTF-8”setup%2Eexe and a dozen unlisted types walked past them
// (the second security audit, 2026-09-25, reproduced each one live).
const (
	// A program, installer, disk image or archive, by Content-Type.
	downloadTypeRE = `(?i)^\s*application/(x-msdownload|x-msdos-program|x-msi|x-ms-installer|vnd\.microsoft\.portable-executable|x-dosexec|x-executable|x-elf|x-sh|x-shellscript|x-bat|x-msdos-windows|vnd\.android\.package-archive|x-apple-diskimage|java-archive|x-java-archive|x-iso9660-image|x-raw-disk-image|x-cd-image|zip|x-zip|x-zip-compressed|x-rar|x-rar-compressed|vnd\.rar|x-7z-compressed|gzip|x-gzip|x-tar|x-gtar|x-xz|x-bzip2|x-compress|vnd\.ms-cab-compressed|hta|x-silverlight|vnd\.debian\.binary-package|x-debian-package|x-rpm|x-redhat-package-manager|msix|appx|x-msix|x-appx|vnd\.ms-appx|x-ms-shortcut|x-apple-installer|vnd\.apple\.installer\+xml)\s*(;|$)`
	// The same, by the file name a download is given (filename= or filename*=).
	downloadNameRE = `(?i)filename\*?\s*=[^;]*?(\.|%2e)(exe|msi|msix|msixbundle|appx|appxbundle|apk|xapk|aab|dmg|pkg|mpkg|scr|pif|cpl|bat|cmd|ps1|psm1|vbs|vbe|js|jse|wsf|wsh|hta|lnk|jar|reg|iso|img|vhd|vhdx|cab|zip|rar|7z|gz|tgz|tar|xz|bz2|deb|rpm|appimage|sh|run|bin|elf|dll|sys|msp|mst|application|gadget|inf|chm|url)\b`
	// The same, by the path requested: files in public/ are served by Apache
	// with whatever type it guesses, often application/octet-stream. Not
	// .js (sites need it) and not .com (an address in a path ends with it).
	execPathRE = `(?i)\.(exe|msi|msix|msixbundle|appx|appxbundle|apk|xapk|aab|dmg|pkg|mpkg|scr|pif|cpl|bat|cmd|ps1|psm1|vbs|vbe|jse|wsf|wsh|hta|lnk|jar|reg|iso|img|vhd|vhdx|cab|zip|rar|7z|gz|tgz|tar|xz|bz2|deb|rpm|appimage|dll|msp|mst|application|gadget|chm)$`
)

// Where a site may send its visitors besides itself: payment and sign-in.
// Lemon Squeezy only to a checkout: anyone can open a store under
// lemonsqueezy.com, so the bare subdomain was a redirect to anywhere.
var redirectAllowHosts = []string{
	`checkout\.stripe\.com`, `billing\.stripe\.com`, `connect\.stripe\.com`,
	`(www\.)?paypal\.com`, `www\.sandbox\.paypal\.com`,
	`accounts\.google\.com`, `appleid\.apple\.com`,
}

const lemonSqueezyCheckout = `https://[a-z0-9-]+\.lemonsqueezy\.com/(checkout|buy)/`

const (
	blockedDownloadMsg = "codeinchrome does not serve program or archive downloads from free sites."
	blockedRedirectMsg = "This site tried to send you to another website. codeinchrome does not allow that on free sites, except to payment and sign-in pages."
)

// celString is s as a CEL string literal, for a Caddy expression matcher.
func celString(s string) string { return `"` + strings.ReplaceAll(s, `\`, `\\`) + `"` }

// guardAbuseMatchers defines what appProxy refuses: @cic_download (a program
// or archive, by type or by name) and @cic_redirect_bad (a Location that
// leaves this site for anywhere but an allowed provider).
func guardAbuseMatchers(cfg Config, s Site) string {
	abs, scheme, double, ctrl, multi := redirectRules(cfg, s)
	// A header the response does not carry is null to CEL, and matches() on
	// null is an error ("no such overload") that Caddy answers with a 502 -
	// which took two sites down on a host for three minutes (2026-09-25). So
	// every header is tested for null first.
	has := func(h, re string) string { return fmt.Sprintf("(%s != null && %s.matches(%s))", h, h, celString(re)) }
	loc := "{rp.header.Location}"
	ok := fmt.Sprintf("(%s || (!%s && !%s))", has(loc, abs), has(loc, scheme), has(loc, double))
	return fmt.Sprintf("\t@cic_download expression `%s || %s`\n",
		has("{rp.header.Content-Type}", downloadTypeRE), has("{rp.header.Content-Disposition}", downloadNameRE)) +
		fmt.Sprintf("\t@cic_redirect_bad expression `%s != null && (!%s || %s || %s)`\n",
			loc, ok, has(loc, ctrl), has(loc, multi))
}

// redirectRules: a Location is allowed when it matches abs (an absolute URL
// to this site or an allowed provider), or when it matches neither scheme (it
// has none) nor double (it does not start "//" or "/\", which browsers read as
// another host) - and, either way, when it matches neither ctrl (a control
// character anywhere: browsers drop tabs and newlines, so "/<TAB>/evil" and
// "ht<TAB>tps://evil" were both offsite) nor multi (several Location headers
// arrive joined by a comma; the second one was never checked).
func redirectRules(cfg Config, s Site) (abs, scheme, double, ctrl, multi string) {
	hosts := append([]string{}, redirectAllowHosts...)
	for _, name := range append([]string{s.Domain}, s.Aliases...) {
		hosts = append(hosts, `(www\.)?`+regexp.QuoteMeta(strings.ToLower(name)))
	}
	if cfg.PlatformDomain != "" {
		hosts = append(hosts, regexp.QuoteMeta("app."+cfg.PlatformDomain))
	}
	abs = `(?i)^[\x00-\x20]*(https?://(` + strings.Join(hosts, "|") + `)(:[0-9]+)?([/?#]|$)|` + lemonSqueezyCheckout + `)`
	scheme = `(?i)^[\x00-\x20]*[a-z][a-z0-9+.-]*:`
	double = `^[\x00-\x20]*[/\\][/\\]`
	ctrl = `[\x00-\x1f\x7f]`
	multi = `,\s*([a-zA-Z][a-zA-Z0-9+.-]*:|[/\\]{2})`
	return abs, scheme, double, ctrl, multi
}

// appProxy is the reverse_proxy to the site's app, with every response
// checked. One handle_response for all of them: Caddy's response matchers
// compare header values exactly, so the checks are CEL expressions
// (guardAbuseMatchers), evaluated here with the response's headers.
func appProxy(port, indent string) string {
	var b strings.Builder
	w := func(depth int, line string) { b.WriteString(indent + strings.Repeat("\t", depth) + line + "\n") }
	w(0, "reverse_proxy 127.0.0.1:"+port+" {")
	// The visitor's real address, which Caddy knows (Cloudflare's
	// CF-Connecting-IP, trusted from Cloudflare only): the container's Apache
	// makes it PHP's REMOTE_ADDR (mod_remoteip), so an app's per-IP limits
	// and bans tell visitors apart - they all looked like the Docker gateway
	// (the second security audit, 2026-09-25). Overwrites whatever a visitor
	// sent under the same name.
	w(1, "header_up X-Real-IP {client_ip}")
	w(1, "handle_response {")
	w(2, "handle @cic_download {")
	w(3, fmt.Sprintf("respond %q 403", blockedDownloadMsg))
	w(2, "}")
	w(2, "handle @cic_redirect_bad {")
	w(3, fmt.Sprintf("respond %q 403", blockedRedirectMsg))
	w(2, "}")
	w(2, "handle {")
	// copy_response alone: the headers are already on the response here, and
	// copy_response_headers sent every one of them twice - Location and
	// Set-Cookie included (found on production by the link scanner).
	w(3, "copy_response")
	w(2, "}")
	w(1, "}")
	w(0, "}")
	return b.String()
}

// robotsHeader keeps search engines away from a site marked NoIndex.
func robotsHeader(s Site) string {
	if !s.NoIndex {
		return ""
	}
	return "\n\t\tX-Robots-Tag \"noindex, nofollow\""
}

// SetNoIndex marks a site as not to be indexed (or no longer), and applies it.
func (m *Manager) SetNoIndex(ctx context.Context, id string, noIndex bool) error {
	if err := ValidID(id); err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	site, err := m.load(id)
	if err != nil {
		return fmt.Errorf("no such site %q", id)
	}
	if site.NoIndex == noIndex {
		return nil
	}
	site.NoIndex = noIndex
	if err := m.save(site); err != nil {
		return err
	}
	return m.writeCaddy(ctx, site)
}

func guardSecrets(route string) string {
	var b strings.Builder
	b.WriteString("\troute {\n")
	b.WriteString("\t\t@cic_secret_name path_regexp cic_secret_name `" + secretPath + "`\n")
	b.WriteString("\t\trespond @cic_secret_name 404\n")
	b.WriteString("\t\t@cic_secret_dot {\n\t\t\tpath_regexp cic_secret_dot `" + secretDot + "`\n\t\t\tnot path /.well-known/*\n\t\t}\n")
	b.WriteString("\t\trespond @cic_secret_dot 404\n")
	// Programs and archives by path, whatever type Apache serves them with
	// (and /x.php/setup.exe, which Chrome names after the path).
	b.WriteString("\t\t@cic_exec_path path_regexp cic_exec_path `" + execPathRE + "`\n")
	b.WriteString(fmt.Sprintf("\t\trespond @cic_exec_path %q 403\n", blockedDownloadMsg))
	// No Service Workers: a worker answers the site's requests inside the
	// browser, so its downloads and redirects never pass this edge. Browsers
	// send this header on every worker script fetch.
	b.WriteString("\t\t@cic_worker header Service-Worker script\n")
	b.WriteString("\t\trespond @cic_worker \"codeinchrome does not allow service workers on free sites.\" 403\n")
	for _, line := range strings.Split(strings.TrimRight(route, "\n"), "\n") {
		b.WriteString("\t" + line + "\n")
	}
	b.WriteString("\t}\n")
	return b.String()
}
