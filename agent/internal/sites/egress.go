package sites

import (
	"bufio"
	"context"
	"net/netip"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// SiteEgress is how widely one site is reaching out, from the host's
// connection-tracking table (the connections open now and those closed in the
// last two minutes). A port scan or a vulnerability sweep reaches hundreds of
// distinct hosts; an ordinary app talks to a handful of APIs. The egress
// policy (bootstrap.sh cic-egress) slows a scan down; this is what notices one.
type SiteEgress struct {
	Site          string `json:"site"`
	Connections   int    `json:"connections"`
	DistinctHosts int    `json:"distinct_hosts"` // public destinations only
	DistinctPorts int    `json:"distinct_ports"`
	// Connections the egress policy refused in the last ten minutes, as
	// logged (at most 6 a minute per container, bootstrap.sh CIC-REJECT). A
	// brute force or a scan against one target shows here, not in the
	// distinct counts (the second security audit, 2026-09-25).
	Refusals int `json:"refusals"`
	// Bytes the site has sent out since its bridge was created (its
	// bridge's received bytes: container egress arrives there). A counter:
	// the control plane takes the difference between readings (A20).
	SentBytes uint64 `json:"sent_bytes"`
}

// sysClassNet is where interface counters are; a variable for tests.
var sysClassNet = "/sys/class/net"

// bridgeSent maps each site to its bridge's received-bytes counter.
func (m *Manager) bridgeSent(ctx context.Context) map[string]uint64 {
	out, err := run(ctx, 15*time.Second, "docker", "network", "ls", "--filter", "name=^cic-net-", "--format", "{{.ID}} {{.Name}}")
	if err != nil {
		return nil
	}
	res := map[string]uint64{}
	for _, line := range strings.Split(strings.TrimSpace(out), "\n") {
		f := strings.Fields(line)
		if len(f) != 2 || len(f[0]) < 12 {
			continue
		}
		site := strings.TrimPrefix(f[1], "cic-net-")
		if ValidID(site) != nil {
			continue
		}
		b, err := os.ReadFile(filepath.Join(sysClassNet, "br-"+f[0][:12], "statistics", "rx_bytes"))
		if err != nil {
			continue
		}
		if n, err := strconv.ParseUint(strings.TrimSpace(string(b)), 10, 64); err == nil {
			res[site] = n
		}
	}
	return res
}

// kernelLog is the last ten minutes of the kernel log; a variable for tests.
var kernelLog = func(ctx context.Context) (string, error) {
	return run(ctx, 20*time.Second, "journalctl", "-k", "--since", "-10min", "--no-pager", "-o", "cat")
}

// refusalsFrom counts the policy's logged refusals per source address.
func refusalsFrom(log string) map[string]int {
	out := map[string]int{}
	for _, line := range strings.Split(log, "\n") {
		if !strings.Contains(line, "cic-egress: ") {
			continue
		}
		for _, f := range strings.Fields(line) {
			if strings.HasPrefix(f, "SRC=") {
				out[f[4:]]++
				break
			}
		}
	}
	return out
}

// conntrackList reads the table; a variable so the tests need no kernel.
var conntrackList = func(ctx context.Context) (string, error) {
	return run(ctx, 20*time.Second, "conntrack", "-L", "-f", "ipv4")
}

// Egress reports every running site's outbound spread.
func (m *Manager) Egress(ctx context.Context) ([]SiteEgress, error) {
	ips, err := m.containerIPs(ctx)
	if err != nil {
		return nil, err
	}
	table, err := conntrackList(ctx)
	if err != nil {
		return nil, err
	}
	res := egressFrom(table, ips)
	sent := m.bridgeSent(ctx)
	for i := range res {
		res[i].SentBytes = sent[res[i].Site]
		delete(sent, res[i].Site)
	}
	for site, n := range sent { // sending without an open connection in the table
		res = append(res, SiteEgress{Site: site, SentBytes: n})
	}
	if log, err := kernelLog(ctx); err == nil {
		per := map[string]int{}
		for ip, n := range refusalsFrom(log) {
			if site, ok := ips[ip]; ok {
				per[site] += n
			}
		}
		for i := range res {
			res[i].Refusals = per[res[i].Site]
			delete(per, res[i].Site)
		}
		for site, n := range per { // refused everything: no open connection at all
			res = append(res, SiteEgress{Site: site, Refusals: n})
		}
	}
	return res, nil
}

// containerIPs maps each site container's address to its site id.
func (m *Manager) containerIPs(ctx context.Context) (map[string]string, error) {
	out, err := run(ctx, 15*time.Second, "docker", "ps", "--filter", "name=^cic-", "--format", "{{.Names}}")
	if err != nil {
		return nil, err
	}
	var names []string
	for _, n := range strings.Fields(out) {
		if n != mysqlContainer && strings.HasPrefix(n, "cic-") && ValidID(strings.TrimPrefix(n, "cic-")) == nil {
			names = append(names, n)
		}
	}
	ips := map[string]string{}
	if len(names) == 0 {
		return ips, nil
	}
	out, err = run(ctx, 15*time.Second, "docker", append([]string{"inspect", "-f", "{{.Name}} {{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}"}, names...)...)
	if err != nil {
		return nil, err
	}
	for _, line := range strings.Split(strings.TrimSpace(out), "\n") {
		f := strings.Fields(line)
		if len(f) < 2 {
			continue
		}
		id := strings.TrimPrefix(strings.TrimPrefix(f[0], "/"), "cic-")
		for _, ip := range f[1:] {
			ips[ip] = id
		}
	}
	return ips, nil
}

// egressFrom counts, per site, the connections a container ORIGINATED (the
// first src= of an entry is the side that opened it) to public addresses.
func egressFrom(table string, ips map[string]string) []SiteEgress {
	type acc struct {
		conns int
		hosts map[string]bool
		ports map[string]bool
	}
	per := map[string]*acc{}
	sc := bufio.NewScanner(strings.NewReader(table))
	sc.Buffer(make([]byte, 0, 64<<10), 1<<20)
	for sc.Scan() {
		var src, dst, dport string
		for _, f := range strings.Fields(sc.Text()) {
			switch {
			case src == "" && strings.HasPrefix(f, "src="):
				src = f[4:]
			case dst == "" && strings.HasPrefix(f, "dst="):
				dst = f[4:]
			case dport == "" && strings.HasPrefix(f, "dport="):
				dport = f[6:]
			}
		}
		site, ok := ips[src]
		if !ok {
			continue
		}
		addr, err := netip.ParseAddr(dst)
		if err != nil || !addr.IsGlobalUnicast() || addr.IsPrivate() {
			continue // the host's MySQL, the bridge gateway: not reaching out
		}
		a := per[site]
		if a == nil {
			a = &acc{hosts: map[string]bool{}, ports: map[string]bool{}}
			per[site] = a
		}
		a.conns++
		a.hosts[dst] = true
		if dport != "" {
			a.ports[dport] = true
		}
	}
	res := []SiteEgress{}
	for site, a := range per {
		res = append(res, SiteEgress{Site: site, Connections: a.conns, DistinctHosts: len(a.hosts), DistinctPorts: len(a.ports)})
	}
	return res
}
