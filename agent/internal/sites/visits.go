package sites

import (
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// SiteVisit is when a person last loaded a site, from its access log. Free
// sites nobody visits or edits for 30 days are paused (the owner's decision,
// 2026-09-25; control's sites:idle), and this is the "visits" half of that.
type SiteVisit struct {
	Site string `json:"site"`
	// Unix seconds of the newest human request found, 0 if none was found in
	// the part of the log read (control then keeps what it already knew).
	Last int64 `json:"last"`
}

// visitScanBytes bounds how far back one site's log is read: a site whose
// last 16 MB of requests are all crawlers and scanners has had no visitor
// lately, and reading further would make one busy log cost the whole host.
var visitScanBytes int64 = 16 << 20

// notAVisitor matches the user agents that are not a person: crawlers,
// link previews, uptime checkers, scripts. The platform's own probes all
// begin "codeinchrome" (monitor, safety, explore, exposure-check).
var notAVisitor = regexp.MustCompile(`(?i)^codeinchrome|bot\b|bot/|crawl|spider|slurp|preview|facebookexternalhit|` +
	`headless|lighthouse|pingdom|uptime|monitor|scanner|^curl/|^wget/|^python|^go-http-client|^java/|^okhttp|` +
	`^php|^guzzle|^axios|^node-fetch|^libwww|^apache-httpclient|^ruby|^scrapy|^masscan|^zgrab|^nuclei`)

// LastVisits reports every site on this host.
func (m *Manager) LastVisits() ([]SiteVisit, error) {
	entries, err := os.ReadDir(m.cfg.Root)
	if err != nil {
		return nil, err
	}
	out := []SiteVisit{}
	for _, e := range entries {
		if !e.IsDir() || ValidID(e.Name()) != nil {
			continue
		}
		if _, err := m.load(e.Name()); err != nil {
			continue // not a site
		}
		last, _ := lastVisit(filepath.Join(caddyLogDir, e.Name()+".log"))
		out = append(out, SiteVisit{Site: e.Name(), Last: last})
	}
	return out, nil
}

// lastVisit reads the log from its end, a chunk at a time, and returns the
// time of the newest request that a person made and the site answered.
func lastVisit(path string) (int64, error) {
	f, err := os.Open(path)
	if err != nil {
		return 0, err
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil {
		return 0, err
	}
	const chunk = 256 << 10
	end := info.Size()
	stop := max(0, end-visitScanBytes)
	var carry []byte // the start of a line cut by the previous chunk boundary
	for end > stop {
		start := max(stop, end-chunk)
		buf := make([]byte, end-start, end-start+int64(len(carry)))
		if _, err := f.ReadAt(buf, start); err != nil && err != io.EOF {
			return 0, err
		}
		buf = append(buf, carry...)
		lines := strings.Split(string(buf), "\n")
		// The first piece may be a partial line unless this is the file's start.
		first := 0
		if start > 0 {
			carry, first = []byte(lines[0]), 1
		}
		for i := len(lines) - 1; i >= first; i-- {
			if ts, ok := humanVisit(lines[i]); ok {
				return ts, nil
			}
		}
		end = start
	}
	return 0, nil
}

// humanVisit decides one Caddy JSON access-log line.
func humanVisit(line string) (int64, bool) {
	if !strings.Contains(line, `"request"`) {
		return 0, false
	}
	var e struct {
		TS      float64 `json:"ts"`
		Status  int     `json:"status"`
		Request struct {
			Method  string              `json:"method"`
			Headers map[string][]string `json:"headers"`
		} `json:"request"`
	}
	if json.Unmarshal([]byte(line), &e) != nil || e.TS <= 0 {
		return 0, false
	}
	// A 4xx/5xx is a probe for /wp-login.php or /.env, not someone using the site.
	if e.Status < 200 || e.Status >= 400 || (e.Request.Method != "GET" && e.Request.Method != "POST") {
		return 0, false
	}
	if len(e.Request.Headers["X-Cic-Probe"]) > 0 {
		return 0, false // the platform's own check, looking like a browser
	}
	ua := strings.TrimSpace(strings.Join(e.Request.Headers["User-Agent"], " "))
	if ua == "" || notAVisitor.MatchString(ua) {
		return 0, false
	}
	return int64(e.TS), true
}
