package sites

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeBackups stands in for cic-backup: a snapshot list that grows by one
// files+db pair on every "run", and a log of every call in order.
type fakeBackups struct {
	mu        sync.Mutex
	snaps     []string // JSON objects
	calls     []string
	failRun   bool
	failRest  bool
	restoreIn time.Duration
	n         int
}

func (f *fakeBackups) add(t time.Time) string {
	f.n++
	id := fmt.Sprintf("%08x", 0xa0000000+f.n)
	f.snaps = append(f.snaps,
		fmt.Sprintf(`{"short_id":%q,"time":%q,"tags":["site:shop","kind:files"]}`, id, t.Format(time.RFC3339)),
		fmt.Sprintf(`{"short_id":"%08x","time":%q,"tags":["site:shop","kind:db"]}`, 0xd0000000+f.n, t.Add(5*time.Second).Format(time.RFC3339)))
	return id
}

func (f *fakeBackups) install(t *testing.T) {
	orig := backupCommand
	t.Cleanup(func() { backupCommand = orig })
	backupCommand = func(ctx context.Context, args ...string) *exec.Cmd {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.calls = append(f.calls, strings.Join(args, " "))
		switch args[0] {
		case "list":
			return exec.CommandContext(ctx, "printf", "%s", "["+strings.Join(f.snaps, ",")+"]")
		case "run":
			if f.failRun {
				return exec.CommandContext(ctx, "sh", "-c", "echo 'cic-backup: shop: disk is not mounted' >&2; exit 1")
			}
			f.add(time.Now().UTC())
			return exec.CommandContext(ctx, "true")
		case "restore":
			if f.failRest {
				return exec.CommandContext(ctx, "sh", "-c", "echo 'cic-backup: restore of shop FAILED during: swapping' >&2; exit 1")
			}
			return exec.CommandContext(ctx, "sleep", fmt.Sprint(f.restoreIn.Seconds()))
		}
		return exec.CommandContext(ctx, "false")
	}
}

func wait(t *testing.T, m *Manager, id string) *BackupOp {
	t.Helper()
	for i := 0; i < 200; i++ {
		if op := m.BackupStatus(id); op != nil && op.State != "running" {
			return op
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("operation never finished")
	return nil
}

func backupSite(t *testing.T) (*Manager, string, *fakeBackups) {
	m, id := historyManager(t)
	os.MkdirAll(m.dir(id), 0o700)
	t.Cleanup(func() { backupOpsMu.Lock(); delete(backupOps, id); backupOpsMu.Unlock() })
	f := &fakeBackups{}
	f.install(t)
	return m, id, f
}

func TestBackupsAreListedNewestFirstWithTheirDatabasePair(t *testing.T) {
	m, id, f := backupSite(t)
	old := f.add(time.Date(2026, 9, 20, 2, 0, 0, 0, time.UTC))
	newer := f.add(time.Date(2026, 9, 21, 2, 0, 0, 0, time.UTC))
	// A files snapshot whose database dump never landed.
	f.snaps = append(f.snaps, `{"short_id":"bbbbbbbb","time":"2026-09-22T02:00:00Z","tags":["site:shop","kind:files"]}`)

	list, err := m.Backups(context.Background(), id)
	if err != nil {
		t.Fatal(err)
	}
	if len(list) != 3 || list[0].ID != "bbbbbbbb" || list[1].ID != newer || list[2].ID != old {
		t.Fatalf("order: %+v", list)
	}
	if list[0].HasDatabase || !list[1].HasDatabase || !list[2].HasDatabase {
		t.Fatalf("database pairing: %+v", list)
	}
}

func TestARestoreBacksUpTheSiteFirstThenRestores(t *testing.T) {
	m, id, f := backupSite(t)
	target := f.add(time.Now().Add(-48 * time.Hour).UTC())

	op, err := m.StartRestore(context.Background(), id, target)
	if err != nil || op.State != "running" {
		t.Fatalf("start: %v %+v", err, op)
	}
	done := wait(t, m, id)
	if done.State != "done" || done.SavedAs == "" || !strings.Contains(done.Message, done.SavedAs) {
		t.Fatalf("finished as %+v", done)
	}
	var order []string
	for _, c := range f.calls {
		if !strings.HasPrefix(c, "list") {
			order = append(order, c)
		}
	}
	if strings.Join(order, " | ") != "run shop | restore shop "+target {
		t.Fatalf("calls in order: %v - the backup must come BEFORE the restore", order)
	}
}

func TestNothingIsRestoredIfTheSafetyBackupFails(t *testing.T) {
	m, id, f := backupSite(t)
	target := f.add(time.Now().Add(-48 * time.Hour).UTC())
	f.failRun = true
	m.StartRestore(context.Background(), id, target)
	done := wait(t, m, id)
	if done.State != "failed" || !strings.Contains(done.Message, "nothing was restored") || !strings.Contains(done.Message, "disk is not mounted") {
		t.Fatalf("got %+v", done)
	}
	for _, c := range f.calls {
		if strings.HasPrefix(c, "restore") {
			t.Fatal("restore ran although the safety backup failed")
		}
	}
}

func TestAFailedRestoreNamesTheBackupThatUndoesIt(t *testing.T) {
	m, id, f := backupSite(t)
	target := f.add(time.Now().Add(-48 * time.Hour).UTC())
	f.failRest = true
	m.StartRestore(context.Background(), id, target)
	done := wait(t, m, id)
	if done.State != "failed" || done.SavedAs == "" || !strings.Contains(done.Message, "restore that to undo") {
		t.Fatalf("got %+v", done)
	}
}

func TestOnlyThisSitesOwnBackupsCanBeRestored(t *testing.T) {
	m, id, f := backupSite(t)
	f.add(time.Now().UTC())
	for _, snap := range []string{"cafebabe", "../../etc", "", "A0000001"} {
		if _, err := m.StartRestore(context.Background(), id, snap); err == nil {
			t.Fatalf("restore of %q was accepted", snap)
		}
	}
	for _, c := range f.calls {
		if strings.HasPrefix(c, "run") || strings.HasPrefix(c, "restore") {
			t.Fatalf("something ran for a refused restore: %v", f.calls)
		}
	}
}

func TestOneOperationAtATimeAndAnInterruptedOneIsReported(t *testing.T) {
	m, id, f := backupSite(t)
	target := f.add(time.Now().Add(-48 * time.Hour).UTC())
	f.restoreIn = 300 * time.Millisecond
	if _, err := m.StartRestore(context.Background(), id, target); err != nil {
		t.Fatal(err)
	}
	if _, err := m.StartBackup(id); err != ErrBackupRunning {
		t.Fatalf("second operation: %v, want ErrBackupRunning", err)
	}
	wait(t, m, id)

	// An agent restart forgets memory; the file says "running".
	os.WriteFile(filepath.Join(m.dir(id), "backup-op.json"), []byte(`{"kind":"restore","state":"running","started":"2026-09-23T10:00:00Z"}`), 0o600)
	backupOpsMu.Lock()
	delete(backupOps, id)
	backupOpsMu.Unlock()
	if op := m.BackupStatus(id); op == nil || op.State != "failed" || !strings.Contains(op.Message, "interrupted") {
		t.Fatalf("after a restart: %+v", op)
	}
}
