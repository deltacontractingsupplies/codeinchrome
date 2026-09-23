package api

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/codeinchrome/agent/internal/sites"
)

// A real Manager over a temporary tree, behind the real routes: these test
// what the HTTP layer adds - status codes, confirmations, headers - on top
// of the sites package, which has its own tests for the rules themselves.
func server(t *testing.T) (*httptest.Server, string) {
	t.Helper()
	root := t.TempDir()
	mgr, err := sites.New(sites.Config{Root: filepath.Join(root, "customers"), CaddyDir: filepath.Join(root, "caddy"), HostID: "h9"})
	if err != nil {
		t.Fatal(err)
	}
	app := filepath.Join(root, "customers", "shop", "vol", "app")
	if err := os.MkdirAll(app, 0o755); err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewServer(Routes(mgr, "test"))
	t.Cleanup(srv.Close)
	return srv, app
}

func call(t *testing.T, method, url, body string) (*http.Response, map[string]any) {
	t.Helper()
	req, _ := http.NewRequest(method, url, strings.NewReader(body))
	res, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer res.Body.Close()
	var j map[string]any
	_ = json.NewDecoder(res.Body).Decode(&j)
	return res, j
}

func TestAWriteThenReadRoundTripsWithARevision(t *testing.T) {
	srv, _ := server(t)
	res, j := call(t, "PUT", srv.URL+"/v1/sites/shop/files", `{"path":"routes/web.php","content":"<?php // hi"}`)
	if res.StatusCode != 200 || j["ok"] != true {
		t.Fatalf("write: %d %v", res.StatusCode, j)
	}
	_, j = call(t, "GET", srv.URL+"/v1/sites/shop/files?read=1&path=routes/web.php", "")
	if j["content"] != "<?php // hi" || j["revision"] == "" {
		t.Fatalf("read back %v", j)
	}
	// A stale revision is a 409 and writes nothing.
	res, _ = call(t, "PUT", srv.URL+"/v1/sites/shop/files", `{"path":"routes/web.php","content":"x","expect":"stale"}`)
	if res.StatusCode != http.StatusConflict {
		t.Fatalf("stale write: %d, want 409", res.StatusCode)
	}
}

func TestTraversalAndAbsolutePathsNeverLeaveTheSite(t *testing.T) {
	srv, app := server(t)
	outside := filepath.Join(filepath.Dir(filepath.Dir(filepath.Dir(app))), "secret.txt")
	os.WriteFile(outside, []byte("host secret"), 0o600)
	for _, p := range []string{"../../../secret.txt", "/../../../secret.txt", "..%2f..%2f..%2fsecret.txt"} {
		_, j := call(t, "GET", srv.URL+"/v1/sites/shop/files?read=1&path="+p, "")
		if j["content"] == "host secret" {
			t.Fatalf("read OUTSIDE the site via %q", p)
		}
	}
	res, j := call(t, "GET", srv.URL+"/v1/sites/..%2f..%2fetc/files?read=1&path=passwd", "")
	if res.StatusCode == 200 || j["ok"] == true {
		t.Fatalf("a site id with a path in it was accepted: %d %v", res.StatusCode, j)
	}
}

func TestDownloadsAreAttachmentsThatCannotBeSniffed(t *testing.T) {
	srv, app := server(t)
	os.WriteFile(filepath.Join(app, "page.html"), []byte("<script>alert(1)</script>"), 0o644)
	res, err := http.Get(srv.URL + "/v1/sites/shop/download?path=page.html")
	if err != nil {
		t.Fatal(err)
	}
	res.Body.Close()
	if !strings.HasPrefix(res.Header.Get("Content-Disposition"), "attachment") ||
		res.Header.Get("X-Content-Type-Options") != "nosniff" ||
		res.Header.Get("Content-Type") != "application/octet-stream" {
		t.Fatalf("download headers %v", res.Header)
	}
}

func TestADatabaseImportNeedsConfirmBeforeAnythingIsRead(t *testing.T) {
	srv, _ := server(t)
	res, j := call(t, "PUT", srv.URL+"/v1/sites/shop/db/import", "DROP TABLE users;")
	if res.StatusCode != http.StatusConflict || j["error"] != "needs_confirm" {
		t.Fatalf("import without confirm: %d %v", res.StatusCode, j)
	}
}

func TestAnUnknownRouteIsAJSONNotFound(t *testing.T) {
	srv, _ := server(t)
	res, j := call(t, "GET", srv.URL+"/v2/anything", "")
	if res.StatusCode != 404 || j["ok"] != false || j["error"] != "no_such_route" {
		t.Fatalf("unknown route: %d %v", res.StatusCode, j)
	}
}
