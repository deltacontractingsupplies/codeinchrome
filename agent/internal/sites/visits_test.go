package sites

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func accessLine(ts int64, status int, method, ua string) string {
	return fmt.Sprintf(`{"level":"info","ts":%d.5,"logger":"http.log.access","msg":"handled request","request":{"remote_ip":"203.0.113.9","method":%q,"host":"shop.codeinchrome.com","uri":"/","headers":{"User-Agent":[%q]}},"status":%d,"size":10}`,
		ts, method, ua, status)
}

func TestTheLastVisitIsTheNewestRequestAPersonMadeThatTheSiteAnswered(t *testing.T) {
	chrome := "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36"
	lines := []string{
		accessLine(1000, 200, "GET", chrome), // the person
		accessLine(2000, 200, "GET", "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"),
		accessLine(2100, 200, "GET", "codeinchrome-monitor"),
		accessLine(2200, 200, "GET", "codeinchrome-safety (+https://codeinchrome.com/report)"),
		accessLine(2300, 404, "GET", chrome), // a probe for /.env
		accessLine(2400, 200, "GET", "curl/8.5.0"),
		accessLine(2500, 200, "GET", ""),
		accessLine(2600, 200, "OPTIONS", chrome),
		accessLine(2700, 200, "GET", "facebookexternalhit/1.1"),
		`not json at all`,
	}
	path := filepath.Join(t.TempDir(), "shop.log")
	if err := os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if got, _ := lastVisit(path, nil); got != 1000 {
		t.Fatalf("last visit %d, want 1000: only the first line is a person", got)
	}
}

func TestALongLogIsReadBackwardsAcrossChunkBoundariesAndOnlySoFar(t *testing.T) {
	var b strings.Builder
	b.WriteString(accessLine(500, 200, "GET", "Mozilla/5.0 (iPhone) Safari/604.1") + "\n")
	// ~2 MB of crawler lines after it, so the person is several chunks back
	// and lines straddle every 256 KB boundary.
	for i := 0; b.Len() < 2<<20; i++ {
		b.WriteString(accessLine(int64(600+i), 200, "GET", "Mozilla/5.0 (compatible; bingbot/2.0)") + "\n")
	}
	path := filepath.Join(t.TempDir(), "shop.log")
	if err := os.WriteFile(path, []byte(b.String()), 0o644); err != nil {
		t.Fatal(err)
	}
	if got, _ := lastVisit(path, nil); got != 500 {
		t.Fatalf("last visit %d, want 500", got)
	}

	defer func(v int64) { visitScanBytes = v }(visitScanBytes)
	visitScanBytes = 1 << 20
	if got, _ := lastVisit(path, nil); got != 0 {
		t.Fatalf("beyond the scan limit: %d, want 0 (unknown - control keeps what it knew)", got)
	}
}

func TestNoLogMeansNoVisit(t *testing.T) {
	if got, err := lastVisit(filepath.Join(t.TempDir(), "none.log"), nil); got != 0 || err == nil {
		t.Fatalf("%d %v", got, err)
	}
}

// The person's line is the one cut in two by the 256 KB chunk boundary:
// found only if the two halves are joined back together.
func TestALineSplitByAChunkBoundaryIsJoinedBackTogether(t *testing.T) {
	const chunk = 256 << 10
	human := accessLine(700, 200, "GET", "Mozilla/5.0 (Windows NT 10.0) Firefox/141.0")
	var s strings.Builder
	for bot := accessLine(800, 200, "GET", "Mozilla/5.0 (compatible; bingbot/2.0)") + "\n"; s.Len()+len(bot) < chunk-50; {
		s.WriteString(bot)
	}
	s.WriteString(strings.Repeat("x", chunk-50-s.Len()-1) + "\n") // after it: exactly chunk-50 bytes
	content := accessLine(100, 200, "GET", "Mozilla/5.0 (X11) Firefox/141.0") + "\n" + human + "\n" + s.String()
	path := filepath.Join(t.TempDir(), "shop.log")
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
	if got, _ := lastVisit(path, nil); got != 700 {
		t.Fatalf("last visit %d, want 700 (the line straddling the boundary)", got)
	}
}

func TestThePlatformsOwnBrowserLikeCheckIsNotAVisit(t *testing.T) {
	line := `{"ts":1000.5,"request":{"method":"GET","headers":{"User-Agent":["Mozilla/5.0 (Windows NT 10.0) Chrome/141.0"],"X-Cic-Probe":["1"]}},"status":200}`
	if _, ok := humanVisit(line, nil); ok {
		t.Fatal("a request marked X-Cic-Probe is the link check, not a person")
	}
	if _, ok := humanVisit(strings.Replace(line, `,"X-Cic-Probe":["1"]`, "", 1), nil); !ok {
		t.Fatal("the same request without the mark is a person")
	}
}

// The fleet's own renderer reads a site like a browser, from a fleet host:
// the control plane names those addresses, and they are not visitors.
func TestTheFleetsOwnRendererIsNotAVisit(t *testing.T) {
	line := `{"ts":1000.5,"request":{"method":"GET","client_ip":"203.0.113.9","headers":{"User-Agent":["Mozilla/5.0 (X11; Linux x86_64) Chrome/141.0"]}},"status":200}`
	skip := map[string]bool{"203.0.113.9": true}
	if _, ok := humanVisit(line, skip); ok {
		t.Fatal("a request from a fleet host counted as a visit")
	}
	if _, ok := humanVisit(line, nil); !ok {
		t.Fatal("the same request from anyone else is a visit")
	}
	// An IPv4 address logged in its IPv6-mapped form is the same address.
	mapped := strings.Replace(line, `"203.0.113.9"`, `"::ffff:203.0.113.9"`, 1)
	if _, ok := humanVisit(mapped, skip); ok {
		t.Fatal("an IPv6-mapped fleet address counted as a visit")
	}
}
