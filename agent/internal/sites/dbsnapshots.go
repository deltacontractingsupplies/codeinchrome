package sites

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"
)

// Database snapshots (owner, 2026-09-26: "the database should be backed up
// ... never ever anything lost").
//
// Taken automatically before everything that rewrites a database wholesale -
// an import, and the migrations and seeders an agent runs (migrate,
// migrate:rollback, migrate:fresh, db:seed) - and restorable in one call; a
// restore snapshots what it replaces first, so it is undoable too. They are
// kept in the site's host directory, outside its app volume, where the
// site's own code can neither read nor delete them: the newest 10, and at
// most 1 GB together (the newest is always kept). The nightly backups keep
// the long history.

const (
	snapshotDir      = "db-snapshots"
	keepSnapshots    = 10
	maxSnapshotBytes = 1 << 30
)

var snapshotName = regexp.MustCompile(`^(\d{8}T\d{6}Z)-([a-z0-9-]{1,40})\.sql\.gz$`)

// snapshotBefore: the artisan commands a snapshot is taken before.
var snapshotBefore = map[string]bool{"migrate": true, "migrate:rollback": true, "migrate:fresh": true, "db:seed": true}

// snapshotNow is the clock; a variable so tests can step it.
var snapshotNow = time.Now

// DBSnapshot is one saved copy of a site's database.
type DBSnapshot struct {
	Name   string    `json:"name"`
	Reason string    `json:"reason"`
	At     time.Time `json:"at"`
	Bytes  int64     `json:"bytes"`
}

var (
	reasonUnsafe = regexp.MustCompile(`[^a-z0-9-]+`)
	reasonCount  = regexp.MustCompile(`-\d+$`) // "-2": a second one in the same second
)

// SnapshotDB saves the site's database now, as gzipped SQL, and drops the
// oldest past the limits.
func (m *Manager) SnapshotDB(ctx context.Context, id, reason string) (DBSnapshot, error) {
	if err := ValidID(id); err != nil {
		return DBSnapshot{}, err
	}
	reason = strings.Trim(reasonUnsafe.ReplaceAllString(strings.ToLower(reason), "-"), "-")
	if reason == "" {
		reason = "snapshot"
	}
	if len(reason) > 40 {
		reason = reason[:40]
	}
	dir := filepath.Join(m.dir(id), snapshotDir)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return DBSnapshot{}, err
	}
	at := snapshotNow().UTC()
	name := at.Format("20060102T150405Z") + "-" + reason + ".sql.gz"
	for n := 2; ; n++ { // two in the same second
		if _, err := os.Stat(filepath.Join(dir, name)); os.IsNotExist(err) {
			break
		}
		name = fmt.Sprintf("%s-%s-%d.sql.gz", at.Format("20060102T150405Z"), strings.TrimSuffix(reason[:min(len(reason), 37)], "-"), n)
	}
	tmp, err := os.CreateTemp(dir, ".snap-*")
	if err != nil {
		return DBSnapshot{}, err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return DBSnapshot{}, err
	}
	if err := m.ExportDB(ctx, id, tmp); err != nil {
		tmp.Close()
		return DBSnapshot{}, err
	}
	if err := tmp.Close(); err != nil {
		return DBSnapshot{}, err
	}
	if err := os.Rename(tmp.Name(), filepath.Join(dir, name)); err != nil {
		return DBSnapshot{}, err
	}
	m.pruneSnapshots(id)
	info, _ := os.Stat(filepath.Join(dir, name))
	snap := DBSnapshot{Name: name, Reason: reason, At: at.Truncate(time.Second)}
	if info != nil {
		snap.Bytes = info.Size()
	}
	return snap, nil
}

// DBSnapshots lists the site's snapshots, newest first.
func (m *Manager) DBSnapshots(id string) ([]DBSnapshot, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	entries, err := os.ReadDir(filepath.Join(m.dir(id), snapshotDir))
	if os.IsNotExist(err) {
		return []DBSnapshot{}, nil
	}
	if err != nil {
		return nil, err
	}
	out := []DBSnapshot{}
	for _, e := range entries {
		match := snapshotName.FindStringSubmatch(e.Name())
		if e.IsDir() || match == nil {
			continue
		}
		at, err := time.Parse("20060102T150405Z", match[1])
		if err != nil {
			continue
		}
		info, err := e.Info()
		if err != nil {
			continue
		}
		reason := reasonCount.ReplaceAllString(match[2], "")
		out = append(out, DBSnapshot{Name: e.Name(), Reason: reason, At: at, Bytes: info.Size()})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name > out[j].Name })
	return out, nil
}

func (m *Manager) pruneSnapshots(id string) {
	list, err := m.DBSnapshots(id)
	if err != nil {
		return
	}
	var total int64
	for i, s := range list {
		total += s.Bytes
		if i > 0 && (i >= keepSnapshots || total > maxSnapshotBytes) {
			os.Remove(filepath.Join(m.dir(id), snapshotDir, s.Name))
		}
	}
}

// hasDB: the site has a database of its own (every site made by the platform).
func (m *Manager) hasDB(id string) bool {
	_, err := os.Stat(filepath.Join(m.dir(id), "db.secret"))
	return err == nil
}

// openSnapshot opens one of the site's snapshots by its exact name.
func (m *Manager) openSnapshot(id, name string) (*os.File, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	if !snapshotName.MatchString(name) {
		return nil, fmt.Errorf("no such snapshot")
	}
	f, err := os.Open(filepath.Join(m.dir(id), snapshotDir, name))
	if err != nil {
		return nil, fmt.Errorf("no such snapshot")
	}
	return f, nil
}

// RestoreDBSnapshot loads a snapshot back. Like any import it snapshots the
// database it replaces first, so the restore can be undone the same way.
func (m *Manager) RestoreDBSnapshot(ctx context.Context, id, name string) error {
	f, err := m.openSnapshot(id, name)
	if err != nil {
		return err
	}
	defer f.Close()
	return m.ImportDB(ctx, id, f)
}
