package sites

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"testing"
)

// A hand edit into vendor/ gets the rules; normal code there is fine.
func TestAHandEditIntoVendorGetsTheRulesButIsNoBan(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	err := m.WriteFile(ctx, id, "/vendor/acme/x/shell.php", "<?php eval($_POST['c']);")
	var dep *ErrDependencyEdit
	if !errors.As(err, &dep) {
		t.Fatalf("want a dependency refusal, got %v", err)
	}
	if _, bad := IsMalware(err); bad {
		t.Fatal("a vendor edit was treated as malware (a ban)")
	}
	if err := m.WriteFile(ctx, id, "/vendor/acme/x/Ok.php", "<?php class Ok {}"); err != nil {
		t.Fatalf("plain code in vendor/ was refused: %v", err)
	}
	if err := m.WriteFile(ctx, id, "/vendor/acme/x/readme.md", "eval(anything)"); err != nil {
		t.Fatalf("a non-PHP file was refused: %v", err)
	}
}

// The scheduled scan exempts only what composer installed.
func TestTheScanExemptsOnlyWhatComposerInstalled(t *testing.T) {
	m, id := historyManager(t)
	app := m.appDir(id)
	put := func(rel, s string) {
		os.MkdirAll(filepath.Dir(filepath.Join(app, rel)), 0o755)
		os.WriteFile(filepath.Join(app, rel), []byte(s), 0o644)
	}
	// As a real vendor/ does: Laravel itself uses eval.
	put("vendor/laravel/framework/Onceable.php", "<?php eval('return 1;');")
	put("vendor/acme/lib/Clean.php", "<?php class Clean {}")
	m.rememberVendor(id)

	kinds := func() map[string]string {
		found, err := m.ScanSite(context.Background(), id)
		if err != nil {
			t.Fatal(err)
		}
		out := map[string]string{}
		for _, f := range found {
			out[f.Path] = f.Kind
		}
		return out
	}
	if got := kinds(); len(got) != 0 {
		t.Fatalf("composer's own files were flagged: %v", got)
	}
	// Planted afterwards, or changed since: the rules apply, for review.
	put("vendor/acme/lib/Hidden.php", "<?php eval($_POST['c']);")
	put("vendor/laravel/framework/Onceable.php", "<?php eval($_GET['x']);")
	put("node_modules/x/s.php", "<?php eval($_POST['c']);")
	got := kinds()
	for _, p := range []string{"/vendor/acme/lib/Hidden.php", "/vendor/laravel/framework/Onceable.php", "/node_modules/x/s.php"} {
		if got[p] != "unverified_dependency" {
			t.Fatalf("%s: %q (all: %v)", p, got[p], got)
		}
	}
	// The record lives out of the site's reach.
	if _, err := os.Stat(filepath.Join(app, "vendor.json")); err == nil {
		t.Fatal("the record is inside the app directory")
	}
}

// Sites from before the record: the first scan records vendor/ as it is and
// flags none of it; what is planted afterwards is seen.
func TestTheFirstScanRecordsAnExistingVendorAndFlagsNothing(t *testing.T) {
	m, id := historyManager(t)
	app := m.appDir(id)
	os.MkdirAll(filepath.Join(app, "vendor/laravel/framework"), 0o755)
	os.WriteFile(filepath.Join(app, "vendor/laravel/framework/Onceable.php"), []byte("<?php eval('return 1;');"), 0o644)
	found, err := m.ScanSite(context.Background(), id)
	if err != nil || len(found) != 0 {
		t.Fatalf("first scan: %v %v", found, err)
	}
	os.WriteFile(filepath.Join(app, "vendor/laravel/framework/Planted.php"), []byte("<?php eval($_POST['c']);"), 0o644)
	found, _ = m.ScanSite(context.Background(), id)
	if len(found) != 1 || found[0].Kind != "unverified_dependency" {
		t.Fatalf("a later plant: %v", found)
	}
}
