package sites

import (
	"context"
	"strings"
	"testing"
)

func TestFirstWeekEgressIsAppliedOnceToTheSitesOwnBridgeAndLifted(t *testing.T) {
	m, id := historyManager(t)
	if err := m.save(Site{ID: id, Domain: id + ".codeinchrome.com"}); err != nil {
		t.Fatal(err)
	}
	rules := map[string]bool{}
	var calls []string
	origRun, origBridge := iptablesRun, bridgeOf
	t.Cleanup(func() { iptablesRun, bridgeOf = origRun, origBridge })
	bridgeOf = func(_ context.Context, network string) (string, error) {
		if network != "cic-net-"+id {
			t.Fatalf("asked for the bridge of %s", network)
		}
		return "br-0123456789ab", nil
	}
	iptablesRun = func(_ context.Context, args ...string) error {
		calls = append(calls, strings.Join(args, " "))
		key := strings.Join(args[len(args)-4:], " ") // -i br -j chain
		switch args[0] {
		case "-C":
			if !rules[key] {
				return context.Canceled // "no such rule"
			}
		case "-I":
			rules[key] = true
		case "-D":
			delete(rules, key)
		}
		return nil
	}
	ctx := context.Background()
	if err := m.SetRestrictedEgress(ctx, id, true); err != nil {
		t.Fatal(err)
	}
	if got, _ := m.load(id); !got.RestrictedEgress {
		t.Fatal("not recorded")
	}
	if !rules["-i br-0123456789ab -j CIC-RESTRICT"] {
		t.Fatalf("not applied: %v", calls)
	}
	if !strings.Contains(strings.Join(calls, "\n"), "-I DOCKER-USER 1 -i br-0123456789ab -j CIC-RESTRICT") {
		t.Fatalf("not first in DOCKER-USER: %v", calls)
	}
	// Idempotent, as at every agent start.
	n := len(calls)
	m.ApplyAllRestrictedEgress(ctx)
	if len(calls) == n || !strings.HasPrefix(calls[n], "-C") {
		t.Fatalf("the start-up pass did not check the site: %v", calls[n:])
	}
	for _, c := range calls[n:] {
		if strings.HasPrefix(c, "-I") {
			t.Fatalf("applied twice: %v", calls[n:])
		}
	}
	// And after a reboot cleared the firewall, it is put back.
	delete(rules, "-i br-0123456789ab -j CIC-RESTRICT")
	m.ApplyAllRestrictedEgress(ctx)
	if !rules["-i br-0123456789ab -j CIC-RESTRICT"] {
		t.Fatal("not re-applied at start")
	}
	if err := m.SetRestrictedEgress(ctx, id, false); err != nil {
		t.Fatal(err)
	}
	if len(rules) != 0 {
		t.Fatalf("not lifted: %v", rules)
	}
}
