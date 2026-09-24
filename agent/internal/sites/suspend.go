package sites

import (
	"context"
	"fmt"
	"log/slog"
	"time"
)

// Suspension: a site that is kept but not served. Used when a free trial
// ends unpaid - the container is stopped, so it holds no memory or CPU, and
// the vhost answers every request with a plain 503 page instead of a proxy
// error. The disk, the database and the files are untouched: resuming is a
// container start away, and deletion is the control plane's separate call.
//
// Suspended is stored in site.json, so a host reboot does not bring the site
// back (Docker's restart=unless-stopped honours a stop), Reconcile leaves its
// vhost alone (it only rewrites running sites), and no other path starts a
// new container for it (replaceContainer refuses).

// SetSuspended suspends or resumes a site. Idempotent both ways.
func (m *Manager) SetSuspended(ctx context.Context, id string, suspended bool) (map[string]string, error) {
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

	if suspended {
		site.Suspended = true
		if err := m.save(site); err != nil {
			return nil, err
		}
		m.LSPCloseSite(id)
		if _, err := run(ctx, 60*time.Second, "docker", "stop", m.container(id)); err != nil {
			applied["container"] = "failed: " + err.Error()
		} else {
			applied["container"] = "stopped"
		}
	} else {
		if !isMounted(m.volume(id)) {
			return nil, fmt.Errorf("the disk for %s is not mounted; refusing to start a container on an empty directory", id)
		}
		if _, err := run(ctx, 60*time.Second, "docker", "start", m.container(id)); err != nil {
			return nil, fmt.Errorf("start container: %w", err)
		}
		if err := waitForHTTP(ctx, site.Port, 60*time.Second); err != nil {
			return nil, fmt.Errorf("the container did not answer after starting: %w", err)
		}
		// Saved only once it serves: a resume that failed leaves the site
		// suspended, and the suspended page up, rather than a dead proxy.
		site.Suspended = false
		if err := m.save(site); err != nil {
			return nil, err
		}
		applied["container"] = "running"
	}

	if err := m.writeCaddy(ctx, site); err != nil {
		slog.Warn("vhost after suspension change", "site", id, "err", err)
		applied["proxy"] = "failed: " + err.Error()
	} else {
		applied["proxy"] = "reloaded"
	}
	return applied, nil
}

// suspendedBody is what a suspended vhost serves in place of the proxy. No
// site name, no owner: the page says only that the site is not available.
const suspendedBody = `	header Content-Type "text/html; charset=utf-8"
	header Cache-Control "no-store"
	respond "<!doctype html><meta charset=utf-8><meta name=viewport content='width=device-width,initial-scale=1'><title>Site paused</title><body style='font:16px system-ui;max-width:32rem;margin:15vh auto;padding:0 1rem;color:#333'><h1 style='font-size:1.4rem'>This site is paused</h1><p>It is not available right now. If it is yours, sign in at codeinchrome.com to bring it back.</p></body>" 503
`
