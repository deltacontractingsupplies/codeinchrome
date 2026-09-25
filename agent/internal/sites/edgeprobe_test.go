package sites

import (
	"context"
	"crypto/x509"
	"os"
	"reflect"
	"sort"
	"testing"
)

func TestOnlyASiteThatWasFineAndNowFailsCountsAsBrokenByAReload(t *testing.T) {
	before := map[string]int{"ok.example": 200, "redirects.example": 302, "was-down.example": 502, "slow.example": 200, "new.example": 0, "broken.example": 200, "missing.example": 404}
	after := map[string]int{"ok.example": 200, "redirects.example": 502, "was-down.example": 502, "slow.example": 0, "new.example": 500, "broken.example": 503, "missing.example": 404}
	got := brokenByReload(before, after)
	sort.Strings(got)
	if want := []string{"broken.example", "redirects.example"}; !reflect.DeepEqual(got, want) {
		t.Fatalf("broken = %v, want %v", got, want)
	}
}

// Run on a host only (CIC_EDGE_LIVE=<a site's domain>, CIC_ORIGIN_CERT=<its
// origin certificate>): the probe must reach Caddy over VERIFIED TLS, or the
// rollback it guards could never trigger.
func TestTheEdgeProbeReachesThisHostsCaddyOverVerifiedTLS(t *testing.T) {
	domain := os.Getenv("CIC_EDGE_LIVE")
	if domain == "" {
		t.Skip("host-only test")
	}
	m := &Manager{cfg: Config{OriginCert: os.Getenv("CIC_ORIGIN_CERT"), OriginClientCA: os.Getenv("CIC_ORIGIN_CLIENT_CA"),
		ProbeCert: os.Getenv("CIC_PROBE_CERT"), ProbeKey: os.Getenv("CIC_PROBE_KEY")}}
	if got := edgeStatus(context.Background(), domain, m.edgeRoots(), m.edgeClient()); got == 0 || got >= 500 {
		t.Fatalf("%s: status %d through verified TLS", domain, got)
	}
	if got := edgeStatus(context.Background(), domain, x509.NewCertPool(), m.edgeClient()); got != 0 {
		t.Fatalf("an empty trust pool must fail the handshake, got %d", got)
	}

	// With origin pulls authenticated, no client certificate: refused.
	if m.cfg.OriginClientCA != "" {
		if got := edgeStatus(context.Background(), domain, m.edgeRoots(), nil); got != 0 {
			t.Fatalf("without a client certificate the edge answered %d", got)
		}
	}
}
