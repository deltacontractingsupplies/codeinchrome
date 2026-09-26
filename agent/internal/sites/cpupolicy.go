package sites

import (
	"context"
	"log/slog"
	"os"
	"strconv"
	"time"
)

// CPU: every site may burst into the host's idle cores, and a weight decides
// who gets them when the host is busy (owner, 2026-09-26: a free site must feel
// like the real thing, and nothing idle should go unused).
//
// Before, a site's plan CPU (0.5) was a hard cap: measured on h1, a read-only
// `artisan route:list` took ~500 ms at 0.5 CPU and ~230 ms at 2 CPUs, on a host
// at load 0.3 - the site waited while three cores sat idle, and its container
// had been throttled 1,176 times. Now the cap is the larger of the plan's CPU
// and Config.CPUBurst, and --cpu-shares weights the contention: the kernel
// hands idle CPU to whoever asks and, only when the host is full, splits it by
// weight - paid 1024, free lower - so a free site never slows a paid one.
// The rebalancing is the kernel's, instant; nothing here polls.
//
// The plan's CPU stays the site's nominal share: capacity is sold on it
// (control Stock), and the abuse CPU watch measures use against the cap the
// container really has, so a miner pinned at the burst cap reads as 100%.

const defaultCPUWeight = 1024 // docker's own default, and a paid plan's weight

// runDocker runs a docker command; a variable so tests can see the commands.
var runDocker = func(ctx context.Context, d time.Duration, args ...string) (string, error) {
	return run(ctx, d, "docker", args...)
}

// cpuWeight is a valid --cpu-shares value: 0 (unset) and out-of-range values
// are the default, never an error that stops a site.
func cpuWeight(w int) int {
	if w < 2 || w > 262144 {
		return defaultCPUWeight
	}
	return w
}

// cpuCap is the --cpus value: the plan's CPU, raised to the burst if one is set.
func (m *Manager) cpuCap(nominal string) string {
	n, err := strconv.ParseFloat(nominal, 64)
	if err != nil {
		return nominal // docker refuses it with its own message
	}
	if m.cfg.CPUBurst > n {
		return strconv.FormatFloat(m.cfg.CPUBurst, 'f', -1, 64)
	}
	return nominal
}

// cpuArgs are docker's CPU flags for a site, for run and for update alike.
func (m *Manager) cpuArgs(nominal string, weight int) []string {
	return []string{"--cpus", m.cpuCap(nominal), "--cpu-shares", strconv.Itoa(cpuWeight(weight))}
}

// ApplyCPUPolicy brings every site's running container to the policy, live:
// docker update needs no restart, so a new burst reaches every site as the
// agent starts, without recreating one. Returns how many were updated.
func (m *Manager) ApplyCPUPolicy(ctx context.Context) int {
	entries, err := os.ReadDir(m.cfg.Root)
	if err != nil {
		return 0
	}
	n := 0
	for _, e := range entries {
		if !e.IsDir() || ValidID(e.Name()) != nil {
			continue
		}
		s, err := m.load(e.Name())
		if err != nil || s.Suspended || s.CPULimit == "" {
			continue
		}
		args := append([]string{"update"}, m.cpuArgs(s.CPULimit, s.CPUWeight)...)
		if _, err := runDocker(ctx, 30*time.Second, append(args, m.container(s.ID))...); err != nil {
			slog.Warn("cpu policy not applied", "site", s.ID, "err", err)
			continue
		}
		n++
	}
	return n
}
