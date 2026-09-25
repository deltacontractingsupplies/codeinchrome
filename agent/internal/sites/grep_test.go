package sites

import (
	"context"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func grepSite(t *testing.T) (*Manager, string) {
	t.Helper()
	m, id := historyManager(t)
	ctx := context.Background()
	files := map[string]string{
		"/routes/web.php":                 "<?php\nRoute::get('/', fn () => view('home'));\nRoute::post('/cart', CartController::class);\n",
		"/app/Models/Cart.php":            "<?php\nclass Cart extends Model {}\n// cartography\n",
		"/resources/views/home.blade.php": "<h1>Cart</h1>\n",
		"/vendor/acme/lib/Cart.php":       "<?php class VendorCart {}\n",
		"/node_modules/x/cart.js":         "cart\n",
	}
	for p, c := range files {
		if err := m.WriteFile(ctx, id, p, c); err != nil {
			t.Fatal(err)
		}
	}
	os.WriteFile(filepath.Join(m.appDir(id), ".env"), []byte("CART_KEY=secret"), 0o640)
	return m, id
}

func TestGrepSpeaksGrepsFlags(t *testing.T) {
	m, id := grepSite(t)
	ctx := context.Background()
	count := func(o GrepOptions) int {
		t.Helper()
		r, err := m.Grep(ctx, id, o)
		if err != nil {
			t.Fatalf("%+v: %v", o, err)
		}
		return len(r.Hits)
	}

	// Case matters unless -i; secrets, vendor and node_modules are not searched.
	if n := count(GrepOptions{Pattern: "Cart"}); n != 3 {
		t.Fatalf("grep -r Cart: want 3 hits, got %d", n)
	}
	if n := count(GrepOptions{Pattern: "cart", IgnoreCase: true}); n != 4 {
		t.Fatalf("grep -ri cart: want 4 hits, got %d", n)
	}
	// -w: "cartography" is not the word "cart".
	if n := count(GrepOptions{Pattern: "cart", IgnoreCase: true, Word: true}); n != 3 {
		t.Fatalf("grep -riw cart: want 3 hits, got %d", n)
	}
	// -E: a real regular expression, not a literal.
	if n := count(GrepOptions{Pattern: `Route::(get|post)`, Regex: true}); n != 2 {
		t.Fatalf("grep -E: want 2 hits, got %d", n)
	}
	if n := count(GrepOptions{Pattern: `Route::(get|post)`}); n != 0 {
		t.Fatalf("without -E the pattern is literal, got %d hits", n)
	}
	// --include and a folder.
	if n := count(GrepOptions{Pattern: "cart", IgnoreCase: true, Include: []string{"*.blade.php"}}); n != 1 {
		t.Fatalf("--include=*.blade.php: want 1 hit, got %d", n)
	}
	if n := count(GrepOptions{Pattern: "cart", IgnoreCase: true, Under: "/app"}); n != 2 {
		t.Fatalf("grep -ri cart app: want 2 hits, got %d", n)
	}
	// A dependency folder asked for by name IS searched.
	if n := count(GrepOptions{Pattern: "VendorCart", Under: "/vendor/acme"}); n != 1 {
		t.Fatalf("grep -r VendorCart vendor/acme: want 1 hit, got %d", n)
	}
	// One file.
	if n := count(GrepOptions{Pattern: "Route", Under: "/routes/web.php"}); n != 2 {
		t.Fatalf("grep Route routes/web.php: want 2 hits, got %d", n)
	}
	// .env is never read, even when named.
	if n := count(GrepOptions{Pattern: "secret", Under: "/.env"}); n != 0 {
		t.Fatalf("grep read .env: %d hits", n)
	}
	// Limit, and the result says it stopped.
	r, _ := m.Grep(ctx, id, GrepOptions{Pattern: "cart", IgnoreCase: true, Limit: 2})
	if len(r.Hits) != 2 || !r.Truncated {
		t.Fatalf("limit 2: got %d hits, truncated %v", len(r.Hits), r.Truncated)
	}

	for _, bad := range []GrepOptions{
		{Pattern: ""},
		{Pattern: "(unclosed", Regex: true},
		{Pattern: "x", Include: []string{"[bad"}},
		{Pattern: "x", Under: "/../../etc"},
		{Pattern: "x", Under: "/missing"},
	} {
		if _, err := m.Grep(ctx, id, bad); err == nil {
			t.Fatalf("%+v was accepted", bad)
		}
	}
}

func TestFindSpeaksFindsFlags(t *testing.T) {
	m, id := grepSite(t)
	ctx := context.Background()
	find := func(o FindOptions) FindResult {
		t.Helper()
		r, err := m.Find(ctx, id, o)
		if err != nil {
			t.Fatalf("%+v: %v", o, err)
		}
		return r
	}
	paths := func(r FindResult) map[string]bool {
		out := map[string]bool{}
		for _, e := range r.Entries {
			out[e.Path] = true
		}
		return out
	}

	top := find(FindOptions{Name: "*.php"})
	if len(top.Skipped) != 2 || top.Skipped[0] != "/node_modules" || top.Skipped[1] != "/vendor" {
		t.Fatalf("skipped: %v", top.Skipped)
	}
	all := paths(find(FindOptions{}))
	// Dependency folders are listed, not entered.
	if !all["/vendor"] || all["/vendor/acme"] || !all["/node_modules"] || all["/node_modules/x/cart.js"] {
		t.Fatalf("find . should list vendor and node_modules without entering them: %v", all)
	}
	if !all["/app/Models/Cart.php"] || !all["/.env"] {
		t.Fatalf("find . missed files: %v", all)
	}

	// -name, -iname, -type.
	if got := paths(find(FindOptions{Name: "Cart.php"})); len(got) != 1 || !got["/app/Models/Cart.php"] {
		t.Fatalf("-name Cart.php: %v", got)
	}
	if got := paths(find(FindOptions{Name: "cart*", IgnoreCase: true, Type: "f"})); len(got) != 1 {
		t.Fatalf("-iname 'cart*' -type f: %v", got)
	}
	if got := paths(find(FindOptions{Type: "d", Under: "/app"})); len(got) != 1 || !got["/app/Models"] {
		t.Fatalf("find app -type d: %v", got)
	}
	// -maxdepth 1 lists only the top level.
	for p := range paths(find(FindOptions{MaxDepth: 1})) {
		if filepath.Dir(p) != "/" {
			t.Fatalf("-maxdepth 1 listed %s", p)
		}
	}
	// A dependency folder named, or All, is entered.
	if got := paths(find(FindOptions{Under: "/vendor"})); !got["/vendor/acme/lib/Cart.php"] {
		t.Fatalf("find vendor: %v", got)
	}
	whole := find(FindOptions{All: true})
	if !paths(whole)["/node_modules/x/cart.js"] || whole.Files != 6 {
		t.Fatalf("find with All: %d files, %v", whole.Files, paths(whole))
	}
	// -newer: only what changed after a moment.
	past := time.Now().Add(-time.Hour)
	os.Chtimes(filepath.Join(m.appDir(id), "routes/web.php"), past, past)
	newer := paths(find(FindOptions{Type: "f", NewerThan: time.Now().Add(-time.Minute)}))
	if newer["/routes/web.php"] || !newer["/app/Models/Cart.php"] {
		t.Fatalf("-newer: %v", newer)
	}

	for _, bad := range []FindOptions{{Type: "l"}, {Name: "[bad"}, {Under: "/missing"}, {Under: "/../../etc"}} {
		if _, err := m.Find(ctx, id, bad); err == nil {
			t.Fatalf("%+v was accepted", bad)
		}
	}
}

// A symlink planted by the site's own code is never followed out of it.
func TestGrepAndFindDoNotFollowSymlinks(t *testing.T) {
	m, id := grepSite(t)
	ctx := context.Background()
	outside := t.TempDir()
	os.WriteFile(filepath.Join(outside, "host.txt"), []byte("HOST-SECRET"), 0o644)
	os.Symlink(outside, filepath.Join(m.appDir(id), "escape"))

	if r, _ := m.Grep(ctx, id, GrepOptions{Pattern: "HOST-SECRET"}); len(r.Hits) != 0 {
		t.Fatalf("grep followed a symlink: %+v", r.Hits)
	}
	if _, err := m.Grep(ctx, id, GrepOptions{Pattern: "HOST-SECRET", Under: "/escape"}); err == nil {
		t.Fatal("grep searched a symlink named as the folder")
	}
	r, _ := m.Find(ctx, id, FindOptions{All: true})
	for _, e := range r.Entries {
		if e.Path == "/escape/host.txt" {
			t.Fatal("find followed a symlink")
		}
	}
	if _, err := m.Find(ctx, id, FindOptions{Under: "/escape"}); err == nil {
		t.Fatal("find walked a symlink named as the folder")
	}
}

// du reads the totals: they must count every file, not stop at the entry limit.
func TestFindCountsPastItsEntryLimit(t *testing.T) {
	m, id := grepSite(t)
	r, err := m.Find(context.Background(), id, FindOptions{All: true, Limit: 1})
	if err != nil {
		t.Fatal(err)
	}
	if len(r.Entries) != 1 || !r.Truncated || r.Files != 6 || r.Bytes == 0 {
		t.Fatalf("limit 1: %d entries, truncated %v, %d files, %d bytes", len(r.Entries), r.Truncated, r.Files, r.Bytes)
	}
}

// grep prints a line as it is; the editor's search box trims it.
func TestGrepKeepsIndentationAndSearchTrims(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "/app/x.php", "<?php\n    return 1;\n")
	g, _ := m.Grep(ctx, id, GrepOptions{Pattern: "return"})
	s, _ := m.Search(ctx, id, "return", 10)
	if len(g.Hits) != 1 || g.Hits[0].Text != "    return 1;" || len(s) != 1 || s[0].Text != "return 1;" {
		t.Fatalf("grep %+v, search %+v", g.Hits, s)
	}
}
