package sites

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func replaceSite(t *testing.T) (*Manager, string, string, *fakeBackups) {
	t.Helper()
	m, id := historyManager(t)
	app := m.appDir(id)
	for p, c := range map[string]string{
		".env": "APP_KEY=keep-me", "storage/app/upload.jpg": "photo", "routes/web.php": "<?php // old",
		"app/Old.php": "<?php class Old {}", "artisan": "old",
	} {
		os.MkdirAll(filepath.Dir(filepath.Join(app, p)), 0o755)
		os.WriteFile(filepath.Join(app, p), []byte(c), 0o644)
	}
	f := &fakeBackups{}
	f.install(t)
	return m, id, app, f
}

func TestReplacingTheSiteWithACloneKeepsEnvAndStorageAfterABackup(t *testing.T) {
	m, id, app, f := replaceSite(t)
	fakeGitHub(t, map[string]string{
		"artisan": "#!/usr/bin/env php", "composer.json": `{"name":"acme/demo"}`,
		"routes/web.php": "<?php // new", "app/New.php": "<?php class NewOne {}",
		"storage/app/.gitignore": "*", ".env.example": "APP_KEY=",
	})
	if _, err := m.StartReplaceWithClone(context.Background(), id, "acme/demo", "", false); !errors.Is(err, ErrNeedsConfirm) {
		t.Fatalf("without confirm: %v", err)
	}
	if _, err := m.StartReplaceWithClone(context.Background(), id, "acme/demo", "", true); err != nil {
		t.Fatal(err)
	}
	op := wait(t, m, id)
	// composer cannot run here (no Docker): the swap is done, and the answer
	// says so and names the backup to restore.
	if op.SavedAs == "" || !strings.Contains(op.Message, "composer install failed") || !strings.Contains(op.Message, op.SavedAs) {
		t.Fatalf("op %+v", op)
	}
	if !strings.Contains(strings.Join(f.calls, "|"), "run") {
		t.Fatal("no backup was taken first")
	}
	read := func(p string) string { b, _ := os.ReadFile(filepath.Join(app, p)); return string(b) }
	if read(".env") != "APP_KEY=keep-me" || read("storage/app/upload.jpg") != "photo" {
		t.Fatal("the site's .env or storage was not kept")
	}
	if read("routes/web.php") != "<?php // new" || read("app/New.php") == "" {
		t.Fatal("the repository's files are not in place")
	}
	if _, err := os.Stat(filepath.Join(app, "app/Old.php")); err == nil {
		t.Fatal("the old app's files are still there")
	}
	if _, err := os.Stat(filepath.Join(m.volume(id), "clone-staging")); err == nil {
		t.Fatal("the staging folder was left behind")
	}
	if !m.FromCloneUnchanged(id, "/app/New.php") {
		t.Fatal("the cloned files were not recorded")
	}
}

func TestNothingChangesWithoutABackupOrForANonLaravelRepository(t *testing.T) {
	m, id, app, f := replaceSite(t)
	fakeGitHub(t, map[string]string{"README.md": "not an app"})
	// Not an app: refused in the request, before any backup.
	if _, err := m.StartReplaceWithClone(context.Background(), id, "acme/demo", "", true); !errors.Is(err, ErrNotLaravel) {
		t.Fatalf("not Laravel: %v", err)
	}
	if len(f.calls) != 0 {
		t.Fatalf("a refused repository started a backup: %v", f.calls)
	}
	// An app, but the backup fails: nothing changes.
	fakeGitHub(t, map[string]string{"artisan": "x", "composer.json": "{}", "app/X.php": "<?php"})
	f.failRun = true
	if _, err := m.StartReplaceWithClone(context.Background(), id, "acme/demo", "", true); err != nil {
		t.Fatal(err)
	}
	if op := wait(t, m, id); op.State != "failed" || !strings.Contains(op.Message, "nothing was changed") {
		t.Fatalf("backup failed: %+v", op)
	}
	if b, _ := os.ReadFile(filepath.Join(app, "app/Old.php")); string(b) != "<?php class Old {}" {
		t.Fatal("a refused replace changed the site")
	}
}

func TestAReplaceWithMalwareChangesNothing(t *testing.T) {
	m, id, app, _ := replaceSite(t)
	fakeGitHub(t, map[string]string{"artisan": "x", "composer.json": "{}", "public/s.php": "<?php eval(base64_decode($_POST['x']));"})
	_, err := m.StartReplaceWithClone(context.Background(), id, "acme/demo", "", true)
	var bad *ErrMalware
	if !errors.As(err, &bad) {
		t.Fatalf("want a malware refusal in the request, got %v", err)
	}
	if _, err := os.Stat(m.stagingDir(id)); err == nil {
		t.Fatal("the staging folder was left behind")
	}
	if b, _ := os.ReadFile(filepath.Join(app, "routes/web.php")); string(b) != "<?php // old" {
		t.Fatal("a refused replace changed the site")
	}
}
