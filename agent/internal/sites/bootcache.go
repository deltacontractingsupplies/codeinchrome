package sites

import (
	"io/fs"
	"os"
	"path/filepath"
	"time"
)

// Laravel's caches of routes, config and events (artisan optimize,
// route:cache, config:cache - all allowed) make later edits to what they
// cache silently do nothing: an agent saves routes/web.php and the site keeps
// the old routes (the second security audit, 2026-09-25). After any change
// the agent makes to a site, a cache older than a file it was built from is
// deleted - which is exactly what `artisan optimize:clear` would do for it.
var bootstrapCaches = map[string][]string{
	"bootstrap/cache/routes-v7.php": {"routes", "app/Providers", "bootstrap/app.php"},
	"bootstrap/cache/config.php":    {"config", ".env", "app/Providers", "bootstrap/app.php", "composer.json"},
	"bootstrap/cache/events.php":    {"app/Listeners", "app/Events", "app/Providers", "bootstrap/app.php"},
}

// ClearStaleBootstrapCaches deletes each cache that is older than a file it
// was built from, and names what it deleted.
func (m *Manager) ClearStaleBootstrapCaches(id string) []string {
	if ValidID(id) != nil {
		return nil
	}
	root := m.appDir(id)
	var cleared []string
	for cache, sources := range bootstrapCaches {
		fi, err := os.Lstat(filepath.Join(root, cache))
		if err != nil || !fi.Mode().IsRegular() {
			continue
		}
		if newestUnder(root, sources).After(fi.ModTime()) {
			if os.Remove(filepath.Join(root, cache)) == nil {
				cleared = append(cleared, cache)
			}
		}
	}
	return cleared
}

// newestUnder is the latest modification time among the given files and
// every file under the given folders (symlinks not followed).
func newestUnder(root string, rels []string) time.Time {
	var newest time.Time
	for _, rel := range rels {
		_ = filepath.WalkDir(filepath.Join(root, rel), func(_ string, d fs.DirEntry, err error) error {
			if err != nil || !d.Type().IsRegular() {
				return nil // folders too: their times move with anything inside
			}
			if info, err := d.Info(); err == nil && info.ModTime().After(newest) {
				newest = info.ModTime()
			}
			return nil
		})
	}
	return newest
}
