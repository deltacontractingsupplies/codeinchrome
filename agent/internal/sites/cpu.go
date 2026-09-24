package sites

import (
	"context"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// SiteCPU is one site's CPU counter, for spotting a miner: mining is the
// abuse Hetzner bans outright, and it looks like a container held at its CPU
// limit for hours. The control plane turns two readings into a utilisation
// (App\Abuse\CpuWatch); a cumulative counter needs no sampling window here.
type SiteCPU struct {
	Site      string  `json:"site"`
	UsageUsec int64   `json:"usage_usec"` // CPU time used since the container started
	QuotaCPUs float64 `json:"quota_cpus"` // its limit, in CPUs (0: none set)
	Started   string  `json:"started"`    // a restart resets the counter
}

// cgroupRoot is where cgroup v2 keeps Docker's containers under systemd; a
// variable so tests can point it at a fake tree.
var cgroupRoot = "/sys/fs/cgroup/system.slice"

// CPU reports every running site's counter. A site whose cgroup cannot be
// read is left out, not reported as idle.
func (m *Manager) CPU(ctx context.Context) ([]SiteCPU, error) {
	out, err := run(ctx, 15*time.Second, "docker", "ps", "--no-trunc", "--filter", "name=^cic-",
		"--format", "{{.ID}} {{.Names}} {{.RunningFor}}")
	if err != nil {
		return nil, err
	}
	var res []SiteCPU
	for _, line := range strings.Split(strings.TrimSpace(out), "\n") {
		f := strings.Fields(line)
		if len(f) < 2 || f[1] == mysqlContainer || !strings.HasPrefix(f[1], "cic-") {
			continue
		}
		id := strings.TrimPrefix(f[1], "cic-")
		if ValidID(id) != nil {
			continue
		}
		dir := filepath.Join(cgroupRoot, "docker-"+f[0]+".scope")
		usage, ok := readCPUUsage(filepath.Join(dir, "cpu.stat"))
		if !ok {
			continue
		}
		res = append(res, SiteCPU{Site: id, UsageUsec: usage, QuotaCPUs: readCPUQuota(filepath.Join(dir, "cpu.max")),
			Started: containerStarted(ctx, f[1])})
	}
	return res, nil
}

func readCPUUsage(path string) (int64, bool) {
	b, err := os.ReadFile(path)
	if err != nil {
		return 0, false
	}
	for _, line := range strings.Split(string(b), "\n") {
		if v, ok := strings.CutPrefix(line, "usage_usec "); ok {
			n, err := strconv.ParseInt(strings.TrimSpace(v), 10, 64)
			return n, err == nil
		}
	}
	return 0, false
}

// readCPUQuota reads "50000 100000" (quota, period) as 0.5; "max" as 0.
func readCPUQuota(path string) float64 {
	b, err := os.ReadFile(path)
	if err != nil {
		return 0
	}
	f := strings.Fields(string(b))
	if len(f) != 2 || f[0] == "max" {
		return 0
	}
	q, err1 := strconv.ParseFloat(f[0], 64)
	p, err2 := strconv.ParseFloat(f[1], 64)
	if err1 != nil || err2 != nil || p == 0 {
		return 0
	}
	return q / p
}

func containerStarted(ctx context.Context, name string) string {
	out, err := run(ctx, 10*time.Second, "docker", "inspect", "-f", "{{.State.StartedAt}}", name)
	if err != nil {
		return ""
	}
	return strings.TrimSpace(out)
}
