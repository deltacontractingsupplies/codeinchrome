package sites

import (
	"context"
	"fmt"
	"log/slog"
)

// Background processes: a queue worker, the scheduler and Laravel Reverb,
// run beside Apache in the site's own container by cic-start (see the image).
// Each is switched on per site; switching changes the container's environment,
// so the container is replaced (a few seconds), the database connection cap
// is re-derived (sizing.go: every process may hold a connection and takes
// memory from the web workers), and the vhost gains or loses the WebSocket route.

func boolEnv(b bool) string {
	if b {
		return "1"
	}
	return "0"
}

func (s Site) background() int {
	n := 0
	for _, on := range []bool{s.Queue, s.Scheduler, s.Reverb} {
		if on {
			n++
		}
	}
	return n
}

// Background is what a site runs beside Apache.
type Background struct {
	Queue     bool `json:"queue"`
	Scheduler bool `json:"scheduler"`
	Reverb    bool `json:"reverb"`
}

// SetBackground switches a site's background processes and applies it.
func (m *Manager) SetBackground(ctx context.Context, id string, b Background) (map[string]string, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	site, err := m.load(id)
	if err != nil {
		return nil, fmt.Errorf("no such site %q", id)
	}
	if site.Queue == b.Queue && site.Scheduler == b.Scheduler && site.Reverb == b.Reverb {
		return map[string]string{"changed": "no"}, nil
	}
	site.Queue, site.Scheduler, site.Reverb = b.Queue, b.Scheduler, b.Reverb
	if site.Reverb && site.WSPort == 0 {
		p, err := m.allocatePort(ctx)
		if err != nil {
			return nil, err
		}
		site.WSPort = p
	}
	if err := m.save(site); err != nil {
		return nil, err
	}

	applied := map[string]string{"changed": "yes"}
	if err := m.setDBConnections(ctx, id, site.MemLimit, site.background()); err != nil {
		applied["dbConnections"] = "failed: " + err.Error()
	} else {
		applied["dbConnections"] = fmt.Sprint(ConnectionsFor(site.MemLimit, site.background()))
	}
	if err := m.replaceContainer(ctx, site); err != nil {
		return applied, err
	}
	applied["workers"] = fmt.Sprint(WorkersFor(site.MemLimit, site.background()))
	// Adds or removes the WebSocket route; writeCaddy validates and reloads.
	if err := m.writeCaddy(ctx, site); err != nil {
		slog.Warn("vhost after background change", "site", id, "err", err)
		applied["proxy"] = "failed: " + err.Error()
	}
	return applied, nil
}
