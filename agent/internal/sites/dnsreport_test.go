package sites

import (
	"context"
	"os"
	"path/filepath"
	"strconv"
	"testing"
	"time"
)

func TestTheDNSReportNamesWhichSiteLookedUpWhat(t *testing.T) {
	dir := t.TempDir()
	orig, origAddrs := dnsLogPath, siteAddresses
	t.Cleanup(func() { dnsLogPath, siteAddresses = orig, origAddrs })
	dnsLogPath = filepath.Join(dir, "queries.log")
	siteAddresses = func(context.Context) (map[string]string, error) {
		return map[string]string{"172.20.0.50": "kit", "172.21.0.2": "shop"}, nil
	}
	now := time.Now().Unix()
	os.WriteFile(dnsLogPath+".1", []byte(
		`{"ts":`+itoa(now-10)+`,"src":"172.20.0.50","name":"api.telegram.org","type":1}`+"\n"), 0o600)
	os.WriteFile(dnsLogPath, []byte(
		`{"ts":`+itoa(now-5)+`,"src":"172.20.0.50","name":"api.telegram.org","type":1}`+"\n"+
			`{"ts":`+itoa(now-5)+`,"src":"172.21.0.2","name":"api.stripe.com","type":1}`+"\n"+
			`{"ts":`+itoa(now-9999)+`,"src":"172.21.0.2","name":"old.example","type":1}`+"\n"+
			`{"ts":`+itoa(now-1)+`,"src":"10.9.9.9","name":"nobody.example","type":1}`+"\n"+
			"not json\n"), 0o600)
	got, err := (&Manager{}).DNSReport(context.Background(), time.Unix(now-60, 0))
	if err != nil {
		t.Fatal(err)
	}
	if got["kit"]["api.telegram.org"] != 2 || got["shop"]["api.stripe.com"] != 1 {
		t.Fatalf("report %v", got)
	}
	if _, old := got["shop"]["old.example"]; old {
		t.Fatal("a question from before `since` was counted")
	}
	if len(got) != 2 {
		t.Fatalf("an address that is no site's was counted: %v", got)
	}
}

func itoa(n int64) string { return strconv.FormatInt(n, 10) }
