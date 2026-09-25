package sites

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestACacheOlderThanWhatItCachesIsCleared(t *testing.T) {
	root := t.TempDir()
	m := &Manager{cfg: Config{Root: root}}
	app := m.appDir("shop")
	write := func(rel string, at time.Time) {
		p := filepath.Join(app, rel)
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, []byte("x"), 0o644); err != nil {
			t.Fatal(err)
		}
		if err := os.Chtimes(p, at, at); err != nil {
			t.Fatal(err)
		}
	}
	old, cached, edited := time.Now().Add(-3*time.Hour), time.Now().Add(-2*time.Hour), time.Now().Add(-time.Hour)
	write("routes/web.php", old)
	write("config/app.php", old)
	write("app/Listeners/SendMail.php", old)
	for _, c := range []string{"bootstrap/cache/routes-v7.php", "bootstrap/cache/config.php", "bootstrap/cache/events.php", "bootstrap/cache/packages.php"} {
		write(c, cached)
	}
	if got := m.ClearStaleBootstrapCaches("shop"); len(got) != 0 {
		t.Fatalf("nothing is stale yet, cleared %v", got)
	}
	write("routes/web.php", edited) // the agent saves a route
	got := m.ClearStaleBootstrapCaches("shop")
	if len(got) != 1 || got[0] != "bootstrap/cache/routes-v7.php" {
		t.Fatalf("cleared %v, want only the route cache", got)
	}
	for _, keep := range []string{"bootstrap/cache/config.php", "bootstrap/cache/events.php", "bootstrap/cache/packages.php"} {
		if _, err := os.Stat(filepath.Join(app, keep)); err != nil {
			t.Errorf("%s must stay: %v", keep, err)
		}
	}
	write(".env", edited)
	if got := m.ClearStaleBootstrapCaches("shop"); len(got) != 1 || got[0] != "bootstrap/cache/config.php" {
		t.Fatalf("an .env change clears the config cache, got %v", got)
	}
}
