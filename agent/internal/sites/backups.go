package sites

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"
)

// A site's nightly backups, for its owner: list them, take one now, restore
// one. The work is done by cic-backup (infra/cic-backup: restic, encrypted,
// to the backup server); this file runs it and keeps track.
//
// A restore replaces the site's files AND database. So before it starts, the
// site is backed up as it is now: a restore is always undoable by restoring
// the backup it took first. It runs in the background - it stops the site's
// container and can take minutes - and its progress is kept in memory and in
// the site's host directory, so an agent restarted mid-way still says what
// happened rather than nothing.

const backupTool = "/opt/codeinchrome/bin/cic-backup"

// backupCommand runs cic-backup. A variable so the tests need no restic.
var backupCommand = func(ctx context.Context, args ...string) *exec.Cmd {
	return exec.CommandContext(ctx, backupTool, args...)
}

var snapshotID = regexp.MustCompile(`^[0-9a-f]{8}$`)

// ErrBackupRunning means a backup or restore of this site is already running.
var ErrBackupRunning = errors.New("a backup or restore of this site is already running")

type Backup struct {
	ID          string    `json:"id"` // the files snapshot; its database pair goes with it
	Time        time.Time `json:"time"`
	HasDatabase bool      `json:"hasDatabase"`
}

type BackupOp struct {
	Kind     string     `json:"kind"` // "backup" or "restore"
	Snapshot string     `json:"snapshot,omitempty"`
	SavedAs  string     `json:"savedAs,omitempty"` // the backup taken before a restore
	State    string     `json:"state"`             // "running", "done", "failed"
	Message  string     `json:"message,omitempty"`
	Started  time.Time  `json:"started"`
	Finished *time.Time `json:"finished,omitempty"`
}

var (
	backupOpsMu sync.Mutex
	backupOps   = map[string]*BackupOp{}
)

func (m *Manager) backupOpFile(id string) string { return filepath.Join(m.dir(id), "backup-op.json") }

// Backups lists the site's backups, newest first.
func (m *Manager) Backups(ctx context.Context, id string) ([]Backup, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	out, err := backupCommand(ctx, "list", id).Output()
	if err != nil {
		return nil, fmt.Errorf("the backup server did not answer; try again in a minute")
	}
	var snaps []struct {
		ShortID string    `json:"short_id"`
		Time    time.Time `json:"time"`
		Tags    []string  `json:"tags"`
	}
	if err := json.Unmarshal(out, &snaps); err != nil {
		return nil, fmt.Errorf("unreadable answer from the backup server")
	}
	has := func(tags []string, t string) bool {
		for _, x := range tags {
			if x == t {
				return true
			}
		}
		return false
	}
	var files, dbs []time.Time
	var list []Backup
	for _, s := range snaps {
		switch {
		case has(s.Tags, "kind:files"):
			files = append(files, s.Time)
			list = append(list, Backup{ID: s.ShortID, Time: s.Time})
		case has(s.Tags, "kind:db"):
			dbs = append(dbs, s.Time)
		}
	}
	sort.Slice(files, func(i, j int) bool { return files[i].Before(files[j]) })
	// The pairing rule cic-backup restore uses: the first database snapshot
	// at or after the files one - and, here, before the next files snapshot,
	// or it belongs to a later backup.
	for i := range list {
		next := time.Time{}
		for _, f := range files {
			if f.After(list[i].Time) {
				next = f
				break
			}
		}
		for _, d := range dbs {
			if !d.Before(list[i].Time) && (next.IsZero() || d.Before(next)) {
				list[i].HasDatabase = true
				break
			}
		}
	}
	sort.Slice(list, func(i, j int) bool { return list[i].Time.After(list[j].Time) })
	return list, nil
}

// BackupStatus is the site's current or last backup operation, if any.
func (m *Manager) BackupStatus(id string) *BackupOp {
	if ValidID(id) != nil {
		return nil
	}
	backupOpsMu.Lock()
	defer backupOpsMu.Unlock()
	if op, ok := backupOps[id]; ok {
		c := *op
		return &c
	}
	b, err := os.ReadFile(m.backupOpFile(id))
	if err != nil {
		return nil
	}
	var op BackupOp
	if json.Unmarshal(b, &op) != nil {
		return nil
	}
	if op.State == "running" {
		// Recorded as running, but no longer in memory: the agent restarted.
		op.State, op.Message = "failed", "interrupted: the agent restarted while this was running. Check the site, and restore again if it is not as it should be."
	}
	return &op
}

func (m *Manager) begin(id string, op *BackupOp) error {
	backupOpsMu.Lock()
	defer backupOpsMu.Unlock()
	if cur, ok := backupOps[id]; ok && cur.State == "running" {
		return ErrBackupRunning
	}
	backupOps[id] = op
	m.saveOpLocked(id, op)
	return nil
}

func (m *Manager) saveOpLocked(id string, op *BackupOp) {
	b, _ := json.Marshal(op)
	tmp := m.backupOpFile(id) + ".tmp"
	if os.WriteFile(tmp, b, 0o600) == nil {
		_ = os.Rename(tmp, m.backupOpFile(id))
	}
}

func (m *Manager) update(id string, f func(*BackupOp)) {
	backupOpsMu.Lock()
	defer backupOpsMu.Unlock()
	op := backupOps[id]
	f(op)
	m.saveOpLocked(id, op)
}

// runBackup takes a backup now and returns the new files snapshot's id.
func (m *Manager) runBackup(ctx context.Context, id string) (string, error) {
	before := map[string]bool{}
	if list, err := m.Backups(ctx, id); err == nil {
		for _, b := range list {
			before[b.ID] = true
		}
	}
	out, err := backupCommand(ctx, "run", id).CombinedOutput()
	if err != nil {
		return "", fmt.Errorf("backup failed: %s", lastLine(string(out)))
	}
	list, err := m.Backups(ctx, id)
	if err != nil {
		return "", err
	}
	for _, b := range list {
		if !before[b.ID] {
			return b.ID, nil
		}
	}
	return "", fmt.Errorf("the backup ran but no new snapshot appeared")
}

// StartBackup takes a backup now, in the background.
func (m *Manager) StartBackup(id string) (*BackupOp, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	op := &BackupOp{Kind: "backup", State: "running", Started: time.Now().UTC()}
	if err := m.begin(id, op); err != nil {
		return nil, err
	}
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Hour)
		defer cancel()
		snap, err := m.runBackup(ctx, id)
		m.update(id, func(op *BackupOp) {
			now := time.Now().UTC()
			op.Finished = &now
			if err != nil {
				op.State, op.Message = "failed", err.Error()
				return
			}
			op.State, op.Snapshot, op.Message = "done", snap, "backed up as "+snap
		})
	}()
	c := *op
	return &c, nil
}

// StartRestore backs the site up as it is, then restores snapshot - files
// and database - in the background.
func (m *Manager) StartRestore(ctx context.Context, id, snapshot string) (*BackupOp, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	if !snapshotID.MatchString(snapshot) {
		return nil, fmt.Errorf("no such backup")
	}
	list, err := m.Backups(ctx, id)
	if err != nil {
		return nil, err
	}
	found := false
	for _, b := range list {
		if b.ID == snapshot {
			if !b.HasDatabase {
				return nil, fmt.Errorf("that backup has no database copy, so it cannot be restored as a whole")
			}
			found = true
		}
	}
	if !found {
		// Only this site's own snapshots are listed: another site's id is
		// "no such backup" here, never a restore of someone else's data.
		return nil, fmt.Errorf("no such backup")
	}

	op := &BackupOp{Kind: "restore", Snapshot: snapshot, State: "running", Started: time.Now().UTC(),
		Message: "backing up the site as it is now, so this restore can be undone"}
	if err := m.begin(id, op); err != nil {
		return nil, err
	}
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Hour)
		defer cancel()
		finish := func(state, msg string) {
			m.update(id, func(op *BackupOp) {
				now := time.Now().UTC()
				op.Finished, op.State, op.Message = &now, state, msg
			})
		}
		saved, err := m.runBackup(ctx, id)
		if err != nil {
			finish("failed", "nothing was restored: the site could not be backed up first ("+err.Error()+")")
			return
		}
		m.update(id, func(op *BackupOp) {
			op.SavedAs, op.Message = saved, "restoring files and database; the site is offline until this finishes"
		})
		out, err := backupCommand(ctx, "restore", id, snapshot).CombinedOutput()
		if err != nil {
			finish("failed", fmt.Sprintf("restore failed: %s. The site as it was is backup %s; restore that to undo.", lastLine(string(out)), saved))
			return
		}
		finish("done", fmt.Sprintf("restored backup %s. The site as it was before is backup %s.", snapshot, saved))
	}()
	c := *op
	return &c, nil
}

func lastLine(s string) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		if l := strings.TrimSpace(lines[i]); l != "" {
			return l
		}
	}
	return "no output"
}
