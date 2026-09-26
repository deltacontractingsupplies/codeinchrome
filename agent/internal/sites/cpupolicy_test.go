package sites

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestCPUBurstRaisesTheCapAndTheWeightDecidesContention(t *testing.T) {
	cases := []struct {
		burst   float64
		nominal string
		weight  int
		want    string
	}{
		{0, "0.5", 0, "--cpus 0.5 --cpu-shares 1024"},    // no burst: the plan is a hard cap, as before
		{2, "0.5", 256, "--cpus 2 --cpu-shares 256"},     // free: bursts, lowest in line
		{2, "0.5", 1024, "--cpus 2 --cpu-shares 1024"},   // paid: bursts, first in line
		{2, "3", 1024, "--cpus 3 --cpu-shares 1024"},     // a plan above the burst keeps its own
		{2, "0.5", -5, "--cpus 2 --cpu-shares 1024"},     // nonsense weight: the default, not an error
		{2, "0.5", 999999, "--cpus 2 --cpu-shares 1024"}, // above docker's range: the default
	}
	for _, c := range cases {
		m := &Manager{cfg: Config{CPUBurst: c.burst}}
		if got := strings.Join(m.cpuArgs(c.nominal, c.weight), " "); got != c.want {
			t.Errorf("burst %v nominal %s weight %d: %q, want %q", c.burst, c.nominal, c.weight, got, c.want)
		}
	}
	s := Site{ID: "shop", Container: "cic-shop", CPULimit: "0.5", CPUWeight: 256, MemLimit: "384m", Port: 20001}
	args := strings.Join((&Manager{cfg: Config{HostID: "h9", CPUBurst: 2}}).runArgs(s), " ")
	if !strings.Contains(args, "--cpus 2 --cpu-shares 256 --memory 384m --memory-swap 384m") {
		t.Errorf("a new container: %s", args)
	}
}

// fakeDocker records docker commands instead of running them.
func fakeDocker(t *testing.T) *[]string {
	t.Helper()
	var mu sync.Mutex
	var calls []string
	old := runDocker
	runDocker = func(_ context.Context, _ time.Duration, args ...string) (string, error) {
		mu.Lock()
		defer mu.Unlock()
		calls = append(calls, strings.Join(args, " "))
		return "", nil
	}
	t.Cleanup(func() { runDocker = old })
	return &calls
}

func TestAStartingAgentAppliesThePolicyToEveryRunningSiteLive(t *testing.T) {
	m, _, _ := newTestManager(t)
	m.cfg.CPUBurst = 2
	for _, s := range []Site{
		{ID: "free1", CPULimit: "0.5", CPUWeight: 256},
		{ID: "paid1", CPULimit: "0.5"},
		{ID: "paused1", CPULimit: "0.5", CPUWeight: 256, Suspended: true},
	} {
		saveSite(t, m, s)
	}
	calls := fakeDocker(t)
	if n := m.ApplyCPUPolicy(context.Background()); n != 2 {
		t.Fatalf("updated %d, want 2 (a paused site is left alone)", n)
	}
	got := strings.Join(*calls, "\n")
	for _, want := range []string{
		"update --cpus 2 --cpu-shares 256 " + m.container("free1"),
		"update --cpus 2 --cpu-shares 1024 " + m.container("paid1"),
	} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in:\n%s", want, got)
		}
	}
	if strings.Contains(got, "paused1") {
		t.Error("a paused site's container was touched")
	}
}

func TestAPlanChangeMovesTheWeightLiveAndRemembersIt(t *testing.T) {
	m, _, _ := newTestManager(t)
	m.cfg.CPUBurst = 2
	saveSite(t, m, Site{ID: "shop", CPULimit: "0.5", CPUWeight: 256})
	calls := fakeDocker(t)
	// Upgraded: only the weight changes, and no restart is asked for.
	if _, err := m.SetLimits(context.Background(), "shop", LimitsOpts{CPUWeight: 1024}); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(*calls, "\n"); got != "update --cpus 2 --cpu-shares 1024 "+m.container("shop") {
		t.Fatalf("docker calls:\n%s", got)
	}
	s, _ := m.load("shop")
	if s.CPUWeight != 1024 || s.CPULimit != "0.5" {
		t.Fatalf("record %+v", s)
	}
}

func saveSite(t *testing.T, m *Manager, s Site) {
	t.Helper()
	if err := os.MkdirAll(filepath.Join(m.cfg.Root, s.ID), 0o750); err != nil {
		t.Fatal(err)
	}
	if err := m.save(s); err != nil {
		t.Fatal(err)
	}
}

// Re-applying the same plan (fleet:apply-limits does, to every site) changes
// nothing a container needs restarting for: found 2026-09-26, when giving
// every site its CPU weight restarted all seven. Only a new memory limit
// restarts it (Apache sizes its workers to the memory).
func TestTheSameMemoryIsNotARestart(t *testing.T) {
	m, _, _ := newTestManager(t)
	m.cfg.CPUBurst = 2
	saveSite(t, m, Site{ID: "shop", CPULimit: "0.5", CPUWeight: 256, MemLimit: "384m"})
	calls := fakeDocker(t)
	if _, err := m.SetLimits(context.Background(), "shop", LimitsOpts{CPULimit: "0.5", CPUWeight: 1024, MemLimit: "384m"}); err != nil {
		t.Fatal(err)
	}
	got := strings.Join(*calls, "\n")
	if strings.Contains(got, "restart") {
		t.Fatalf("restarted for an unchanged memory limit:\n%s", got)
	}
	if !strings.Contains(got, "update --cpus 2 --cpu-shares 1024 --memory 384m --memory-swap 384m "+m.container("shop")) {
		t.Fatalf("the limits were not applied live:\n%s", got)
	}

	// A new memory limit does restart it: Apache sizes its workers to it.
	*calls = nil
	if _, err := m.SetLimits(context.Background(), "shop", LimitsOpts{MemLimit: "512m"}); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(*calls, "\n"); !strings.Contains(got, "restart -t 10 "+m.container("shop")) {
		t.Fatalf("a changed memory limit did not restart:\n%s", got)
	}
}
