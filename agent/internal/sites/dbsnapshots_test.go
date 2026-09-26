package sites

import (
	"compress/gzip"
	"context"
	"os/exec"
	"strings"
	"testing"
	"time"
)

func stepClock(t *testing.T) {
	t.Helper()
	at := time.Date(2026, 9, 26, 10, 0, 0, 0, time.UTC)
	old := snapshotNow
	snapshotNow = func() time.Time { at = at.Add(time.Minute); return at }
	t.Cleanup(func() { snapshotNow = old })
}

func TestSnapshotsKeepTheNewestTenNewestFirst(t *testing.T) {
	m, id := withDB(t)
	fakeMySQL(t, "-- data\n", false)
	stepClock(t)
	for i := 0; i < 12; i++ {
		if _, err := m.SnapshotDB(context.Background(), id, "before-migrate"); err != nil {
			t.Fatal(err)
		}
	}
	list, _ := m.DBSnapshots(id)
	if len(list) != keepSnapshots {
		t.Fatalf("%d kept, want %d", len(list), keepSnapshots)
	}
	if !list[0].At.After(list[len(list)-1].At) || list[0].Reason != "before-migrate" || list[0].Bytes == 0 {
		t.Fatalf("order or fields: %+v", list[0])
	}
}

func TestTwoSnapshotsInOneSecondBothSurvive(t *testing.T) {
	m, id := withDB(t)
	fakeMySQL(t, "-- data\n", false)
	old := snapshotNow
	snapshotNow = func() time.Time { return time.Date(2026, 9, 26, 10, 0, 0, 0, time.UTC) }
	defer func() { snapshotNow = old }()
	a, _ := m.SnapshotDB(context.Background(), id, "before-migrate")
	b, _ := m.SnapshotDB(context.Background(), id, "before-migrate")
	list, _ := m.DBSnapshots(id)
	if a.Name == b.Name || len(list) != 2 || list[0].Reason != "before-migrate" || list[1].Reason != "before-migrate" {
		t.Fatalf("%s %s %+v", a.Name, b.Name, list)
	}
}

func TestARestoreLoadsTheSnapshotAndSavesWhatItReplaced(t *testing.T) {
	m, id := withDB(t)
	fakeMySQL(t, "-- the data then\n", false)
	stepClock(t)
	snap, err := m.SnapshotDB(context.Background(), id, "before-migrate")
	if err != nil {
		t.Fatal(err)
	}
	if err := m.RestoreDBSnapshot(context.Background(), id, snap.Name); err != nil {
		t.Fatal(err)
	}
	if got := fedReader(); got != "-- the data then\n" {
		t.Fatalf("mysql was fed %q", got)
	}
	list, _ := m.DBSnapshots(id)
	if len(list) != 2 || list[0].Reason != "before-import" {
		t.Fatalf("the replaced database was not saved first: %+v", list)
	}
	// Only a snapshot of this site, by its exact name.
	for _, bad := range []string{"../db.secret", "x.sql.gz", "20260926T100000Z-a.sql", ""} {
		if err := m.RestoreDBSnapshot(context.Background(), id, bad); err == nil {
			t.Fatalf("%q restored", bad)
		}
	}
}

func TestAMigrationRunsOnlyAfterTheDatabaseIsSaved(t *testing.T) {
	m, id := withDB(t)
	stepClock(t)

	// The dump fails: nothing runs, and the answer says why.
	orig := mysqlTool
	mysqlTool = func(ctx context.Context, password string, stdin bool, argv ...string) *exec.Cmd {
		return exec.CommandContext(ctx, "sh", "-c", "echo 'mysqldump: Got error: 2002' >&2; exit 2")
	}
	_, err := m.RunCommand(context.Background(), id, "artisan", []string{"migrate"}, false)
	mysqlTool = orig
	if err == nil || !strings.Contains(err.Error(), "nothing was run") {
		t.Fatalf("a migration ran without a saved database: %v", err)
	}

	// The dump works: a snapshot named for the command is there before it runs
	// (the command itself cannot run here - there is no Docker in a test).
	fakeMySQL(t, "-- before the migration\n", false)
	m.RunCommand(context.Background(), id, "artisan", []string{"migrate"}, false)
	list, _ := m.DBSnapshots(id)
	if len(list) != 1 || list[0].Reason != "before-migrate" {
		t.Fatalf("snapshots: %+v", list)
	}
	f, _ := m.openSnapshot(id, list[0].Name)
	defer f.Close()
	zr, _ := gzip.NewReader(f)
	b := new(strings.Builder)
	buf := make([]byte, 64)
	n, _ := zr.Read(buf)
	b.Write(buf[:n])
	if b.String() != "-- before the migration\n" {
		t.Fatalf("snapshot holds %q", b.String())
	}

	// Read-only commands take none.
	m.RunCommand(context.Background(), id, "artisan", []string{"migrate:status"}, false)
	if again, _ := m.DBSnapshots(id); len(again) != 1 {
		t.Fatalf("migrate:status took a snapshot: %+v", again)
	}
}
