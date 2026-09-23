package sites

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func historyManager(t *testing.T) (*Manager, string) {
	t.Helper()
	root := t.TempDir()
	m := &Manager{cfg: Config{Root: root}}
	id := "shop"
	if err := os.MkdirAll(m.appDir(id), 0o755); err != nil {
		t.Fatal(err)
	}
	return m, id
}

func TestEverySaveIsACommitAndOldVersionsCanBeRead(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()

	if err := m.WriteFile(ctx, id, "routes/web.php", "<?php // one"); err != nil {
		t.Fatal(err)
	}
	if err := m.WriteFile(ctx, id, "routes/web.php", "<?php // two"); err != nil {
		t.Fatal(err)
	}

	log, err := m.History(ctx, id, "routes/web.php", 10)
	if err != nil {
		t.Fatal(err)
	}
	if len(log) != 2 {
		t.Fatalf("want 2 versions, got %d: %+v", len(log), log)
	}
	// The editor's form of the path (leading slash = app root) works too.
	if log2, _ := m.History(ctx, id, "/routes/web.php", 10); len(log2) != 2 {
		t.Fatalf("a leading slash must mean the app root")
	}
	old, err := m.FileAt(ctx, id, log[1].Commit, "routes/web.php")
	if err != nil || old != "<?php // one" {
		t.Fatalf("the first version should be readable, got %q (%v)", old, err)
	}
}

func TestSecretsNeverEnterHistoryEvenIfTheSiteAsksForThem(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	app := m.appDir(id)
	// A customer's .gitignore trying to put .env back in.
	os.WriteFile(filepath.Join(app, ".gitignore"), []byte("!.env\n!storage/\n"), 0o644)
	os.WriteFile(filepath.Join(app, ".env"), []byte("DB_PASSWORD=secret"), 0o640)
	os.MkdirAll(filepath.Join(app, "storage/logs"), 0o755)
	os.WriteFile(filepath.Join(app, "storage/logs/laravel.log"), []byte("log"), 0o644)

	if err := m.WriteFile(ctx, id, "app.php", "x"); err != nil {
		t.Fatal(err)
	}
	files, err := m.git(ctx, id, "ls-files")
	if err != nil {
		t.Fatal(err)
	}
	for _, f := range []string{".env", "storage/logs/laravel.log"} {
		if strings.Contains(files, f) {
			t.Errorf("%s must never be committed; tracked files:\n%s", f, files)
		}
	}
	if !strings.Contains(files, "app.php") {
		t.Errorf("app.php should be tracked:\n%s", files)
	}
}

func TestAHookPlantedInTheSiteNeverRuns(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	if err := m.WriteFile(ctx, id, "a.txt", "1"); err != nil { // creates the history
		t.Fatal(err)
	}
	marker := filepath.Join(t.TempDir(), "pwned")
	hook := filepath.Join(m.historyDir(id), "hooks", "pre-commit")
	os.MkdirAll(filepath.Dir(hook), 0o755)
	os.WriteFile(hook, []byte("#!/bin/sh\ntouch "+marker+"\n"), 0o755)

	if err := m.WriteFile(ctx, id, "a.txt", "2"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("a hook ran: hooks must be disabled for every git call")
	}
}

func TestADeletedFileIsInTheBinAndCanBeRestored(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	if err := m.WriteFile(ctx, id, "resources/views/home.blade.php", "<h1>Home</h1>"); err != nil {
		t.Fatal(err)
	}
	if err := m.DeleteFile(ctx, id, "resources/views/home.blade.php"); err != nil {
		t.Fatal(err)
	}

	bin, err := m.Deleted(ctx, id, 50)
	if err != nil || len(bin) != 1 || bin[0].Path != "resources/views/home.blade.php" {
		t.Fatalf("the deleted file should be in the bin, got %+v (%v)", bin, err)
	}
	if err := m.Restore(ctx, id, bin[0].From, bin[0].Path); err != nil {
		t.Fatal(err)
	}
	got, _ := os.ReadFile(filepath.Join(m.appDir(id), "resources/views/home.blade.php"))
	if string(got) != "<h1>Home</h1>" {
		t.Fatalf("restored content %q", got)
	}
	// The restore is itself a new version, never a rewrite of history.
	log, _ := m.History(ctx, id, "resources/views/home.blade.php", 10)
	if len(log) != 3 || !strings.HasPrefix(log[0].Message, "restore ") {
		t.Fatalf("want create, delete, restore; got %+v", log)
	}
}

func TestHistoryRefusesBadRevisionsAndPaths(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "a.txt", "1")
	for _, rev := range []string{"--output=/tmp/x", "HEAD", "abc", "main;rm -rf /"} {
		if _, err := m.FileAt(ctx, id, rev, "a.txt"); err == nil {
			t.Errorf("revision %q should be refused", rev)
		}
	}
	log, _ := m.History(ctx, id, "a.txt", 1)
	for _, p := range []string{"../../etc/passwd", "/etc/passwd", ".env"} {
		if _, err := m.FileAt(ctx, id, log[0].Commit, p); err == nil {
			t.Errorf("path %q should be refused", p)
		}
	}
}

// The case the first version failed on every live site: Laravel's own
// .gitignore ignores .env and vendor, and `git add` with our exclusions then
// exited 1 on every commit - silently, since a failed commit is only logged.
// Also: an excluded file must never even be hashed into the object store.
func TestHistoryWorksWithLaravelsOwnGitignore(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	app := m.appDir(id)
	os.WriteFile(filepath.Join(app, ".gitignore"), []byte("/node_modules\n/public/hot\n/public/storage\n/storage/*.key\n/vendor\n.env\n.env.backup\n.phpunit.result.cache\n"), 0o644)
	os.WriteFile(filepath.Join(app, ".env"), []byte("APP_KEY=base64:topsecret"), 0o640)
	os.MkdirAll(filepath.Join(app, "vendor/laravel"), 0o755)
	os.WriteFile(filepath.Join(app, "vendor/laravel/x.php"), []byte("vendor"), 0o644)

	if err := m.WriteFile(ctx, id, "/routes/web.php", "<?php // one"); err != nil {
		t.Fatal(err)
	}
	if err := m.WriteFile(ctx, id, "/routes/web.php", "<?php // two"); err != nil {
		t.Fatal(err)
	}
	versions, err := m.History(ctx, id, "/routes/web.php", 10)
	if err != nil || len(versions) != 2 {
		t.Fatalf("want 2 versions of routes/web.php, got %d (%v)", len(versions), err)
	}
	// A deletion is recorded too.
	if err := m.DeleteFile(ctx, id, "/routes/web.php"); err != nil {
		t.Fatal(err)
	}
	if v, _ := m.History(ctx, id, "/routes/web.php", 10); len(v) != 3 {
		t.Fatalf("the deletion was not recorded: %d versions", len(v))
	}
	// No blob anywhere in the object store holds the secret.
	objects, _ := m.git(ctx, id, "cat-file", "--batch-all-objects", "--batch-check=%(objectname)")
	for _, o := range strings.Fields(objects) {
		body, _ := m.git(ctx, id, "cat-file", "-p", o)
		if strings.Contains(body, "topsecret") {
			t.Fatalf("the .env content was hashed into history as %s", o)
		}
	}
}
