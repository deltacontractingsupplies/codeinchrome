package sites

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestQuickOpenListsTheAppNotItsDependencies(t *testing.T) {
	m, id := historyManager(t)
	app := m.appDir(id)
	for _, f := range []string{"app/Models/Item.php", "routes/web.php", "vendor/laravel/x.php", "node_modules/a/b.js", "storage/framework/views/c.php", "storage/app/keep.txt", ".env"} {
		os.MkdirAll(filepath.Dir(filepath.Join(app, f)), 0o755)
		os.WriteFile(filepath.Join(app, f), []byte("x"), 0o644)
	}
	paths, truncated, err := m.Paths(context.Background(), id)
	if err != nil || truncated {
		t.Fatal(err, truncated)
	}
	want := map[string]bool{"/.env": true, "/app/Models/Item.php": true, "/routes/web.php": true, "/storage/app/keep.txt": true}
	if len(paths) != len(want) {
		t.Fatalf("got %v", paths)
	}
	for _, p := range paths {
		if !want[p] {
			t.Fatalf("unexpected %s in %v", p, paths)
		}
	}
}
