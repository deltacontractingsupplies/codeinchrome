package sites

import (
	"context"
	"os"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// HostStats is the host's health, read from the kernel at call time.
type HostStats struct {
	DiskFreeBytes  int64   `json:"diskFreeBytes"`
	DiskTotalBytes int64   `json:"diskTotalBytes"`
	MemAvailBytes  int64   `json:"memAvailableBytes"`
	MemTotalBytes  int64   `json:"memTotalBytes"`
	SwapUsedBytes  int64   `json:"swapUsedBytes"`
	Load1          float64 `json:"load1"`
	CPUs           int     `json:"cpus"`
	UptimeSeconds  int64   `json:"uptimeSeconds"`
	MySQLUp        bool    `json:"mysqlUp"`
	CaddyUp        bool    `json:"caddyUp"`
	// Sites whose container is not running, and sites whose disk is not
	// mounted - each a customer who is down right now.
	SitesNotRunning []string `json:"sitesNotRunning"`
	DisksUnmounted  []string `json:"disksUnmounted"`
}

func (m *Manager) Stats(ctx context.Context) HostStats {
	var st HostStats

	var fs syscall.Statfs_t
	if syscall.Statfs(m.cfg.Root, &fs) == nil {
		st.DiskTotalBytes = int64(fs.Blocks) * int64(fs.Bsize)
		st.DiskFreeBytes = int64(fs.Bavail) * int64(fs.Bsize)
	}

	if b, err := os.ReadFile("/proc/meminfo"); err == nil {
		kb := map[string]int64{}
		for _, line := range strings.Split(string(b), "\n") {
			f := strings.Fields(line)
			if len(f) >= 2 {
				n, _ := strconv.ParseInt(f[1], 10, 64)
				kb[strings.TrimSuffix(f[0], ":")] = n
			}
		}
		st.MemTotalBytes = kb["MemTotal"] << 10
		st.MemAvailBytes = kb["MemAvailable"] << 10
		st.SwapUsedBytes = (kb["SwapTotal"] - kb["SwapFree"]) << 10
	}

	if b, err := os.ReadFile("/proc/loadavg"); err == nil {
		if f := strings.Fields(string(b)); len(f) > 0 {
			st.Load1, _ = strconv.ParseFloat(f[0], 64)
		}
	}
	if b, err := os.ReadFile("/proc/uptime"); err == nil {
		if f := strings.Fields(string(b)); len(f) > 0 {
			u, _ := strconv.ParseFloat(f[0], 64)
			st.UptimeSeconds = int64(u)
		}
	}
	if b, err := os.ReadFile("/proc/cpuinfo"); err == nil {
		st.CPUs = strings.Count(string(b), "\nprocessor") + 1
	}

	if db, err := m.rootDB(); err == nil {
		pctx, cancel := context.WithTimeout(ctx, 3*time.Second)
		st.MySQLUp = db.PingContext(pctx) == nil
		cancel()
		db.Close()
	}
	_, err := run(ctx, 5*time.Second, "systemctl", "is-active", "--quiet", "caddy")
	st.CaddyUp = err == nil

	st.SitesNotRunning, st.DisksUnmounted = []string{}, []string{}
	if list, err := m.List(ctx); err == nil {
		for _, s := range list {
			if s.State != "running" {
				st.SitesNotRunning = append(st.SitesNotRunning, s.ID)
			}
			if _, err := os.Stat(m.diskImage(s.ID)); err == nil && !isMounted(m.volume(s.ID)) {
				st.DisksUnmounted = append(st.DisksUnmounted, s.ID)
			}
		}
	}
	return st
}
