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

func TestARestoreNeedsConfirmBeforeAnythingRuns(t *testing.T) {
	srv, _ := server(t)
	res, j := call(t, "POST", srv.URL+"/v1/sites/shop/backups/restore", `{"snapshot":"a0000001"}`)
	if res.StatusCode != http.StatusConflict || j["error"] != "needs_confirm" {
		t.Fatalf("restore without confirm: %d %v", res.StatusCode, j)
	}
}

// The success path reloads Caddy, which a test machine does not run; the
// sites package tests the header itself. Here: nothing but a real site and a
// well-formed body gets through.
func TestIndexingRefusesABadBodyAnInvalidIdAndAnUnknownSite(t *testing.T) {
	srv, _ := server(t)
	for _, c := range []struct{ url, body, want string }{
		{"/v1/sites/shop/indexing", `not json`, "bad_json"},
		{"/v1/sites/..%2Fetc/indexing", `{"noIndex":true}`, "cannot_apply"},
		{"/v1/sites/no-such-site/indexing", `{"noIndex":true}`, "cannot_apply"},
	} {
		res, j := call(t, "PUT", srv.URL+c.url, c.body)
		if res.StatusCode != http.StatusBadRequest || j["error"] != c.want {
			t.Fatalf("%s %s: %d %v, want 400 %s", c.url, c.body, res.StatusCode, j, c.want)
		}
	}
}

// cic.sh's grep and find: the flags reach the sites package, and a bad
// pattern or a newer that is not a time is a 400, not an empty answer.
func TestGrepAndFindCarryTheirFlags(t *testing.T) {
	srv, app := server(t)
	os.MkdirAll(filepath.Join(app, "routes"), 0o755)
	os.WriteFile(filepath.Join(app, "routes", "web.php"), []byte("<?php\nRoute::get('/a');\nRoute::post('/b');\n"), 0o644)

	_, j := call(t, "GET", srv.URL+"/v1/sites/shop/grep?pattern=Route::(get|post)&regex=1&include=*.php&under=/routes", "")
	if hits, _ := j["hits"].([]any); len(hits) != 2 {
		t.Fatalf("grep -E: %v", j)
	}
	_, j = call(t, "GET", srv.URL+"/v1/sites/shop/grep?pattern=ROUTE&icase=1&include=*.css", "")
	if hits, _ := j["hits"].([]any); len(hits) != 0 {
		t.Fatalf("--include=*.css matched a .php file: %v", j)
	}
	if res, _ := call(t, "GET", srv.URL+"/v1/sites/shop/grep?pattern=(&regex=1", ""); res.StatusCode != 400 {
		t.Fatalf("a broken regex: %d, want 400", res.StatusCode)
	}

	_, j = call(t, "GET", srv.URL+"/v1/sites/shop/find?name=*.php&type=f", "")
	entries, _ := j["entries"].([]any)
	if len(entries) != 1 || entries[0].(map[string]any)["path"] != "/routes/web.php" {
		t.Fatalf("find -name *.php: %v", j)
	}
	if res, _ := call(t, "GET", srv.URL+"/v1/sites/shop/find?newer=yesterday", ""); res.StatusCode != 400 {
		t.Fatalf("newer=yesterday: %d, want 400", res.StatusCode)
	}
}

// git clone: a body that is not JSON, or a repository that is not GitHub's,
// is a 400 and fetches nothing.
func TestCloneRefusesABadBodyAndANonGitHubRepository(t *testing.T) {
	srv, app := server(t)
	if res, _ := call(t, "POST", srv.URL+"/v1/sites/shop/clone", "not json"); res.StatusCode != 400 {
		t.Fatalf("bad body: %d", res.StatusCode)
	}
	res, j := call(t, "POST", srv.URL+"/v1/sites/shop/clone", `{"repository":"https://gitlab.com/a/b","into":"/x"}`)
	if res.StatusCode != 400 || j["error"] != "cannot_clone" {
		t.Fatalf("gitlab: %d %v", res.StatusCode, j)
	}
	if _, err := os.Stat(filepath.Join(app, "x")); err == nil {
		t.Fatal("a refused clone made its folder")
	}
}
