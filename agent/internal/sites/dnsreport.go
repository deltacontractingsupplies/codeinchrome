package sites

import (
	"bufio"
	"context"
	"encoding/json"
	"os"
	"os/exec"
	"strings"
	"time"

	"github.com/codeinchrome/agent/internal/dnsfwd"
)

// Which names each site looked up (audit A21), from the host's DNS
// forwarder's log (internal/dnsfwd), which records the asking container's
// address: mapped here to its site. What the control plane does with it -
// a review for a site asking for api.telegram.org, say - is its own call.

// dnsLogPath is where cic-dns writes; a variable for tests.
var dnsLogPath = "/var/log/cic-dns/queries.log"

const maxNamesPerSite = 200

// siteAddresses maps each running site container's address to its site id.
var siteAddresses = func(ctx context.Context) (map[string]string, error) {
	ids, err := exec.CommandContext(ctx, "docker", "ps", "-q", "--filter", "label=codeinchrome.site").Output()
	if err != nil {
		return nil, err
	}
	list := strings.Fields(string(ids))
	out := map[string]string{}
	if len(list) == 0 {
		return out, nil
	}
	args := append([]string{"inspect", "-f", `{{index .Config.Labels "codeinchrome.site"}}{{range .NetworkSettings.Networks}} {{.IPAddress}}{{end}}`}, list...)
	res, err := exec.CommandContext(ctx, "docker", args...).Output()
	if err != nil {
		return nil, err
	}
	for _, line := range strings.Split(string(res), "\n") {
		f := strings.Fields(line)
		for _, ip := range f[min(1, len(f)):] {
			out[ip] = f[0]
		}
	}
	return out, nil
}

// DNSReport counts, per site, the names looked up since `since`.
func (m *Manager) DNSReport(ctx context.Context, since time.Time) (map[string]map[string]int, error) {
	addrs, err := siteAddresses(ctx)
	if err != nil {
		return nil, err
	}
	out := map[string]map[string]int{}
	for _, path := range []string{dnsLogPath + ".1", dnsLogPath} {
		f, err := os.Open(path)
		if err != nil {
			continue
		}
		sc := bufio.NewScanner(f)
		sc.Buffer(make([]byte, 0, 4096), 64<<10)
		for sc.Scan() {
			var q dnsfwd.Question
			if json.Unmarshal(sc.Bytes(), &q) != nil || q.TS < since.Unix() {
				continue
			}
			site := addrs[q.Src]
			if site == "" {
				continue
			}
			names := out[site]
			if names == nil {
				names = map[string]int{}
				out[site] = names
			}
			if _, seen := names[q.Name]; seen || len(names) < maxNamesPerSite {
				names[q.Name]++
			}
		}
		f.Close()
	}
	return out, nil
}
