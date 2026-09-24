package sites

import (
	"fmt"
	"strings"
	"testing"
)

func TestEgressCountsWhatEachSiteOpenedToPublicHosts(t *testing.T) {
	ips := map[string]string{"172.20.0.2": "shop", "172.21.0.2": "scanner"}
	var b strings.Builder
	// shop: two APIs, one reached twice; and its own database (private, not counted).
	b.WriteString("tcp 6 100 ESTABLISHED src=172.20.0.2 dst=140.82.112.5 sport=40000 dport=443 src=140.82.112.5 dst=203.0.113.1 sport=443 dport=40000 [ASSURED]\n")
	b.WriteString("tcp 6 100 TIME_WAIT src=172.20.0.2 dst=140.82.112.5 sport=40001 dport=443 src=140.82.112.5 dst=203.0.113.1 sport=443 dport=40001 [ASSURED]\n")
	b.WriteString("tcp 6 100 ESTABLISHED src=172.20.0.2 dst=151.101.1.1 sport=40002 dport=443 src=151.101.1.1 dst=203.0.113.1 sport=443 dport=40002 [ASSURED]\n")
	b.WriteString("tcp 6 100 TIME_WAIT src=172.20.0.2 dst=172.20.0.1 sport=40003 dport=3306 src=172.20.0.1 dst=172.20.0.2 sport=3306 dport=40003 [ASSURED]\n")
	// scanner: 250 distinct hosts on port 22.
	for i := 0; i < 250; i++ {
		fmt.Fprintf(&b, "tcp 6 110 SYN_SENT src=172.21.0.2 dst=45.33.%d.%d sport=%d dport=22 [UNREPLIED] src=45.33.%d.%d dst=203.0.113.1 sport=22 dport=%d\n", i/200, i%200+1, 30000+i, i/200, i%200+1, 30000+i)
	}
	// Traffic TO a site (Caddy reaching it) is not the site reaching out.
	b.WriteString("tcp 6 100 TIME_WAIT src=127.0.0.1 dst=127.0.0.1 sport=41260 dport=20000 src=127.0.0.1 dst=127.0.0.1 sport=20000 dport=41260 [ASSURED]\n")

	got := map[string]SiteEgress{}
	for _, e := range egressFrom(b.String(), ips) {
		got[e.Site] = e
	}
	if s := got["shop"]; s.Connections != 3 || s.DistinctHosts != 2 || s.DistinctPorts != 1 {
		t.Errorf("shop: %+v", s)
	}
	if s := got["scanner"]; s.DistinctHosts != 250 || s.DistinctPorts != 1 {
		t.Errorf("scanner: %+v", s)
	}
	if len(got) != 2 {
		t.Errorf("only sites that reached out: %v", got)
	}
}
