package sites

import (
	"context"
	"errors"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
)

func TestABatchIsAllOrNothingAndOneVersion(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "routes/web.php", "<?php // v1")
	before, _ := m.History(ctx, id, "", 100)

	// The second file conflicts: nothing at all is written.
	_, err := m.WriteMany(ctx, id, []FileWrite{
		{Path: "app/Models/Item.php", Content: "<?php class Item {}"},
		{Path: "routes/web.php", Content: "<?php // v2", Expect: "absent"},
	}, "")
	if !errors.Is(err, ErrConflict) {
		t.Fatalf("want a conflict, got %v", err)
	}
	if _, err := os.Stat(filepath.Join(m.appDir(id), "app/Models/Item.php")); err == nil {
		t.Fatal("file 1 was written although file 2 failed its check")
	}

	out, err := m.WriteMany(ctx, id, []FileWrite{
		{Path: "app/Models/Item.php", Content: "<?php class Item {}"},
		{Path: "routes/web.php", Content: "<?php // v2"},
		{Path: "resources/views/items/index.blade.php", Content: "<ul></ul>"},
	}, "inventory: items")
	if err != nil || len(out) != 3 || out[1].Revision != Revision([]byte("<?php // v2")) {
		t.Fatalf("batch: %v %+v", err, out)
	}
	after, _ := m.History(ctx, id, "", 100)
	if len(after) != len(before)+1 || after[0].Message != "inventory: items" {
		t.Fatalf("want exactly one new version named by the caller, got %d -> %d: %+v", len(before), len(after), after[0])
	}

	if _, err := m.WriteMany(ctx, id, []FileWrite{{Path: "a.txt", Content: "1"}, {Path: "/a.txt", Content: "2"}}, ""); err == nil {
		t.Fatal("the same file twice in one batch was accepted")
	}
	// ".." cannot climb out: it is cleaned against the site root.
	if _, err := m.WriteMany(ctx, id, []FileWrite{{Path: "../../escape.txt", Content: "x"}}, ""); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(m.appDir(id), "escape.txt")); err != nil {
		t.Fatal("../../escape.txt must land at the site root, inside the site")
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(m.appDir(id)), "escape.txt")); err == nil {
		t.Fatal("a batch wrote outside the site")
	}
}

func TestAnEditReplacesExactlyWhatItNames(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "config/app.php", "'name' => 'Old',\n'debug' => false,\n'x' => 1,\n'x' => 1,\n")

	if _, err := m.EditFile(ctx, id, "config/app.php", []Edit{{Find: "'x' => 1,", Replace: "'x' => 2,"}}, ""); err == nil || !strings.Contains(err.Error(), "2 times") {
		t.Fatalf("an ambiguous find must be refused, got %v", err)
	}
	if _, err := m.EditFile(ctx, id, "config/app.php", []Edit{{Find: "'name' => 'Old'", Replace: "'name' => 'New'"}, {Find: "missing", Replace: "y"}}, ""); err == nil {
		t.Fatal("a missing find must be refused")
	}
	got, _ := m.ReadFile(ctx, id, "config/app.php")
	if strings.Contains(got, "New") {
		t.Fatal("a failed edit list must write nothing, not the edits before the failure")
	}
	out, err := m.EditFile(ctx, id, "config/app.php", []Edit{{Find: "'name' => 'Old'", Replace: "'name' => 'New'"}, {Find: "'x' => 1,", Replace: "'x' => 2,", All: true}}, "")
	if err != nil {
		t.Fatal(err)
	}
	got, _ = m.ReadFile(ctx, id, "config/app.php")
	if got != "'name' => 'New',\n'debug' => false,\n'x' => 2,\n'x' => 2,\n" || out.Revision != Revision([]byte(got)) {
		t.Fatalf("edited to %q", got)
	}
	if _, err := m.EditFile(ctx, id, "config/app.php", []Edit{{Find: "New", Replace: "Z"}}, "stale-revision"); !errors.Is(err, ErrConflict) {
		t.Fatalf("a stale expect must conflict, got %v", err)
	}
}

func TestARequestReachesOnlyThisSiteUnderItsOwnName(t *testing.T) {
	m, id := historyManager(t)
	ln, _ := net.Listen("tcp", "127.0.0.1:0")
	var gotHost, gotCookie string
	srv := &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotHost, gotCookie = r.Host, r.Header.Get("Cookie")
		http.SetCookie(w, &http.Cookie{Name: "a", Value: "1"})
		http.SetCookie(w, &http.Cookie{Name: "b", Value: "2"})
		if r.URL.Path == "/login" {
			http.Redirect(w, r, "/dashboard", http.StatusFound)
			return
		}
		w.Write([]byte("items: 3"))
	})}
	go srv.Serve(ln)
	defer srv.Close()
	port, _ := strconv.Atoi(strings.Split(ln.Addr().String(), ":")[1])
	m.save(Site{ID: id, Domain: "shop.codeinchrome.com", Port: port})

	res, err := m.Request(context.Background(), id, SiteRequest{Path: "/items?page=1", Headers: map[string]string{"Cookie": "s=1"}})
	if err != nil || res.Status != 200 || res.Body != "items: 3" {
		t.Fatalf("%v %+v", err, res)
	}
	if gotHost != "shop.codeinchrome.com" || gotCookie != "s=1" {
		t.Fatalf("the site saw host %q cookie %q", gotHost, gotCookie)
	}
	if !strings.Contains(res.Headers["set-cookie"], "\n") {
		t.Fatalf("two cookies must stay two: %q", res.Headers["set-cookie"])
	}
	res, _ = m.Request(context.Background(), id, SiteRequest{Method: "POST", Path: "/login"})
	if res.Status != 302 || res.Headers["location"] != "/dashboard" {
		t.Fatalf("a redirect is reported, not followed: %+v", res)
	}

	for _, bad := range []SiteRequest{
		{Path: "http://169.254.169.254/"}, {Path: "//evil.example/"}, {Path: "items"},
		{Path: "/x", Method: "CONNECT"}, {Path: "/x", Headers: map[string]string{"Host": "other.codeinchrome.com"}},
		{Path: "/x", Headers: map[string]string{"X-Forwarded-For": "1.2.3.4"}},
	} {
		if _, err := m.Request(context.Background(), id, bad); err == nil {
			t.Errorf("%+v was allowed", bad)
		}
	}
	m.save(Site{ID: id, Domain: "shop.codeinchrome.com", Port: port, Suspended: true})
	if _, err := m.Request(context.Background(), id, SiteRequest{Path: "/"}); err == nil {
		t.Error("a paused site was requested")
	}
}

func TestPHPFilesAreLintedInOneRunAndTheErrorNamesTheLine(t *testing.T) {
	m, id := historyManager(t)
	var calls [][]string
	orig := lintCommand
	t.Cleanup(func() { lintCommand = orig })
	lintCommand = func(ctx context.Context, container string, files []string) *exec.Cmd {
		calls = append(calls, files)
		// What php 8.3 -l prints for one good and one broken file.
		return exec.CommandContext(ctx, "sh", "-c", `echo 'No syntax errors detected in /var/www/html/app/Good.php'; echo 'Errors parsing /var/www/html/app/Bad.php'; echo 'PHP Parse error:  syntax error, unexpected token ";" in /var/www/html/app/Bad.php on line 3' >&2; exit 255`)
	}
	out, err := m.WriteMany(context.Background(), id, []FileWrite{
		{Path: "app/Good.php", Content: "<?php echo 1;"},
		{Path: "/app/Bad.php", Content: "<?php\n\necho ;"},
		{Path: "resources/views/x.blade.php", Content: "{{ $x }}"},
		{Path: "public/app.css", Content: "a{}"},
	}, "")
	if err != nil {
		t.Fatal(err)
	}
	if len(calls) != 1 || len(calls[0]) != 2 {
		t.Fatalf("want one php -l run over the two PHP files (not Blade, not CSS), got %v", calls)
	}
	if out[0].Lint != "ok" || out[1].Lint != `line 3: syntax error, unexpected token ";"` || out[2].Lint != "" || out[3].Lint != "" {
		t.Fatalf("lint results: %q %q %q %q", out[0].Lint, out[1].Lint, out[2].Lint, out[3].Lint)
	}
}
