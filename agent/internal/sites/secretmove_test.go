package sites

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

// A move can rename a file to anything, so the edge's name check cannot catch
// .env moved to public/config.txt. The file operations refuse it instead.
func TestAnEnvFileNeverMovesOrCopiesIntoPublic(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	app := m.appDir(id)
	for rel, body := range map[string]string{".env": "DB_PASSWORD=secret", "public/index.php": "<?php", "config/app.php": "<?php", "keys/.env.production": "X=1"} {
		os.MkdirAll(filepath.Dir(filepath.Join(app, rel)), 0o755)
		os.WriteFile(filepath.Join(app, rel), []byte(body), 0o644)
	}

	for _, c := range [][2]string{{"/.env", "/public/config.txt"}, {"/.env", "/public/.env"}, {"/keys", "/public/keys"}} {
		if err := m.Copy(ctx, id, c[0], c[1]); err == nil {
			t.Errorf("copy %s -> %s was allowed", c[0], c[1])
		}
		if err := m.Rename(ctx, id, c[0], c[1]); err == nil {
			t.Errorf("move %s -> %s was allowed", c[0], c[1])
		}
	}
	if _, err := os.Stat(filepath.Join(app, "public/config.txt")); err == nil {
		t.Fatal("the .env reached public/")
	}

	// Everything else still works: a backup beside it, other folders into public/.
	if err := m.Copy(ctx, id, "/.env", "/.env.backup"); err != nil {
		t.Errorf("a backup of .env outside public/ must be allowed: %v", err)
	}
	if err := m.Copy(ctx, id, "/config", "/public/config-copy"); err != nil {
		t.Errorf("a folder without secrets must copy into public/: %v", err)
	}
}
