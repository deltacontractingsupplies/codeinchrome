package sites

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// A site with no background processes switched on runs Apache - and, while
// its editor is open, the PHP language server - and nothing else for long.
// Anything else still running after the longest command the agent allows
// (composer, 10 minutes) was left behind: a command's docker exec was killed
// on its timeout but not the process inside, or the site's own code started
// one (a miner, a bot) to outlive its request. Found by the security audit
// (2026-09-25): the free plan's "no background processes" was a UI setting.
// Sites with a queue, scheduler or Reverb are left alone: their processes
// are theirs to run.
const strayAfter = composerTimeout + 2*time.Minute

// strayProcess: may this process, running for secs, be killed? Judged by
// its real executable (exe: /proc/<pid>/exe), never by its argv, which the
// process writes itself: cli_set_process_title("apache2 -DFOREGROUND") or an
// argument "phpactor" kept anything alive (the second security audit,
// 2026-09-25). Apache stays; the PHP language server stays only while the
// site has a language-server session open (lspOpen).
func strayProcess(args string, secs int64, exe string, lspOpen bool) bool {
	if secs < int64(strayAfter/time.Second) {
		return false
	}
	bin := filepath.Base(strings.TrimSuffix(exe, " (deleted)"))
	if bin == "apache2" && strings.HasPrefix(exe, "/usr/sbin/") {
		return false
	}
	if lspOpen && strings.HasPrefix(bin, "php") && strings.Contains(args, "phpactor") {
		return false
	}
	return true
}

// procExe is where a process's executable really is (a variable for the tests).
var procExe = func(pid int) string {
	p, _ := os.Readlink(fmt.Sprintf("/proc/%d/exe", pid))
	return p
}

// lspOpen: does the site have a live language-server session?
func lspOpen(site string) bool {
	lspMu.Lock()
	defer lspMu.Unlock()
	for k, s := range lspSessions {
		if strings.HasPrefix(k, site+"/") {
			select {
			case <-s.done:
			default:
				return true
			}
		}
	}
	return false
}

// killProcess is a variable so the tests kill nothing.
var killProcess = func(pid int) error { return syscall.Kill(pid, syscall.SIGKILL) }

// ReapStrays kills left-behind processes in every running site that has no
// background processes switched on, and says what it killed.
func (m *Manager) ReapStrays(ctx context.Context) []string {
	list, err := m.List(ctx)
	if err != nil {
		return nil
	}
	var killed []string
	for _, s := range list {
		if s.State != "running" || s.Suspended || s.Queue || s.Scheduler || s.Reverb {
			continue
		}
		init, _ := run(ctx, 10*time.Second, "docker", "inspect", "-f", "{{.State.Pid}}", m.container(s.ID))
		out, err := run(ctx, 15*time.Second, "docker", "top", m.container(s.ID), "-eo", "pid,etimes,args")
		if err != nil {
			continue
		}
		open := lspOpen(s.ID)
		for _, p := range parseTop(out) {
			if strconv.Itoa(p.pid) == strings.TrimSpace(init) || !strayProcess(p.args, p.secs, procExe(p.pid), open) {
				continue
			}
			if killProcess(p.pid) == nil {
				killed = append(killed, fmt.Sprintf("%s: %s (running %ds)", s.ID, truncate(p.args, 120), p.secs))
			}
		}
	}
	for _, k := range killed {
		slog.Warn("stray process killed", "what", k)
	}
	return killed
}

type topProcess struct {
	pid  int
	secs int64
	args string
}

// parseTop reads `docker top ... -eo pid,etimes,args` (a header, then rows).
func parseTop(out string) []topProcess {
	var ps []topProcess
	for i, line := range strings.Split(strings.TrimSpace(out), "\n") {
		if i == 0 {
			continue
		}
		f := strings.Fields(line)
		if len(f) < 3 {
			continue
		}
		pid, err1 := strconv.Atoi(f[0])
		secs, err2 := strconv.ParseInt(f[1], 10, 64)
		if err1 != nil || err2 != nil {
			continue
		}
		ps = append(ps, topProcess{pid: pid, secs: secs, args: strings.Join(f[2:], " ")})
	}
	return ps
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "..."
}
