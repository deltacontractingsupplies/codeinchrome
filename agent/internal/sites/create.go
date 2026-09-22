package sites

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
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
		_ = os.RemoveAll(m.caddyFile(o.ID))
		_ = os.RemoveAll(dir)
	}

	site := Site{
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

	if err := m.startContainer(ctx, site); err != nil {
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

// startContainer runs the customer's container with every restriction we can
// apply without breaking a normal Laravel app.
func (m *Manager) startContainer(ctx context.Context, s Site) error {
	args := []string{
		"run", "-d",
		"--name", s.Container,
		"--restart", "unless-stopped",
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
		"--publish", "127.0.0.1::8080", // ephemeral host port, loopback only
		laravelImage,
	}
	if _, err := run(ctx, 2*time.Minute, "docker", args...); err != nil {
		return fmt.Errorf("start container for %s: %w", s.ID, err)
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

func (m *Manager) caddyFile(id string) string {
	return filepath.Join(m.cfg.CaddyDir, id+".caddy")
}

func (m *Manager) writeCaddy(ctx context.Context, s Site) error {
	port, err := m.Port(ctx, s.ID)
	if err != nil {
		return err
	}
	conf := fmt.Sprintf(`# codeinchrome site %s - generated, do not edit by hand
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
`, s.ID, s.Domain, port, s.ID)

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
