package sites

import (
	"bufio"
	"compress/gzip"
	"context"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

// Database export and import: a site's whole database as a .sql.gz file, and
// a .sql or .sql.gz file loaded back in.
//
// Both run mysqldump / mysql INSIDE the cic-mysql container (the hosts have no
// MySQL client of their own), connecting over 127.0.0.1 as the SITE'S OWN
// user - the same account the database browser uses. So an export can only
// ever contain this site's database, and an import can do nothing the site's
// own code could not already do with the credentials in its .env.
//
// The password travels in the MYSQL_PWD environment variable, passed to
// `docker exec -e MYSQL_PWD` by NAME, so it is never in any process's argv
// where `ps` on the host would show it.
//
// An import replaces data, so before loading anything the current database is
// saved as a snapshot (dbsnapshots.go) in the site's host directory, outside
// the app volume: the site's code cannot read or delete it, and a bad import
// is always one restore away from undone.

const (
	mysqlContainer = "cic-mysql"
	// MaxImportSize bounds an uploaded dump (compressed if it is gzipped).
	MaxImportSize = 512 << 20
	// The decompressed stream is bounded too, or a small gzip could expand
	// into an unbounded load on the shared MySQL server (a "zip bomb").
	maxImportExpanded = 4 << 30
	dbTransferTimeout = 30 * time.Minute
)

// mysqlTool builds the docker exec for a MySQL client tool. A variable so the
// tests can see exactly what would run, without a MySQL server.
//
// As nobody, never root: cic-mysql runs with the host's network and holds
// every site's data, and the client reads what a customer uploaded.
var mysqlTool = func(ctx context.Context, password string, stdin bool, argv ...string) *exec.Cmd {
	args := []string{"exec", "-u", "65534:65534"}
	if stdin {
		args = append(args, "-i")
	}
	args = append(args, "-e", "MYSQL_PWD", mysqlContainer)
	cmd := exec.CommandContext(ctx, "docker", append(args, argv...)...)
	cmd.Env = append(os.Environ(), "MYSQL_PWD="+password)
	return cmd
}

func (m *Manager) dbPassword(id string) (string, error) {
	if err := ValidID(id); err != nil {
		return "", err
	}
	b, err := os.ReadFile(filepath.Join(m.dir(id), "db.secret"))
	if err != nil {
		return "", fmt.Errorf("site %q has no database", id)
	}
	return strings.TrimSpace(string(b)), nil
}

// ExportDB writes the site's database to w as gzipped SQL.
func (m *Manager) ExportDB(ctx context.Context, id string, w io.Writer) error {
	password, err := m.dbPassword(id)
	if err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(ctx, dbTransferTimeout)
	defer cancel()

	cmd := mysqlTool(ctx, password, false, "mysqldump",
		"-h", agentHost, "-u", DBUser(id),
		// A consistent snapshot without locking the site's tables.
		"--single-transaction", "--quick",
		// Needs the PROCESS privilege, which a site user rightly lacks.
		"--no-tablespaces",
		"--triggers", "--set-gtid-purged=OFF",
		DBName(id))
	zw := gzip.NewWriter(w)
	cmd.Stdout = zw
	stderr := &cappedBuffer{limit: 8 << 10}
	cmd.Stderr = stderr
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("export failed: %s", firstLine(stderr.buf.String(), err))
	}
	return zw.Close()
}

// ImportDB loads a .sql or .sql.gz dump into the site's database, after
// saving the current contents so the import can be undone.
//
// The upload is spooled to disk and checked IN FULL before the database is
// touched: streamed straight into mysql, an upload cut off by the size limit
// or a dropped connection would arrive as a truncated script and leave the
// database half-loaded.
func (m *Manager) ImportDB(ctx context.Context, id string, r io.Reader) error {
	password, err := m.dbPassword(id)
	if err != nil {
		return err
	}
	spool, err := os.CreateTemp(m.dir(id), ".import-*")
	if err != nil {
		return fmt.Errorf("no scratch space for the upload")
	}
	defer os.Remove(spool.Name())
	defer spool.Close()
	n, err := io.Copy(spool, io.LimitReader(r, MaxImportSize+1))
	if err != nil {
		return fmt.Errorf("the upload was interrupted; nothing was imported")
	}
	if n > MaxImportSize {
		return fmt.Errorf("the file is larger than %d MB; nothing was imported", MaxImportSize>>20)
	}
	if n == 0 {
		return fmt.Errorf("the file is empty; nothing was imported")
	}

	open := func() (io.Reader, func(), error) {
		if _, err := spool.Seek(0, io.SeekStart); err != nil {
			return nil, nil, err
		}
		br := bufio.NewReader(spool)
		if magic, _ := br.Peek(2); len(magic) == 2 && magic[0] == 0x1f && magic[1] == 0x8b {
			zr, err := gzip.NewReader(br)
			if err != nil {
				return nil, nil, fmt.Errorf("that is not a valid .gz file; nothing was imported")
			}
			return zr, func() { zr.Close() }, nil
		}
		return br, func() {}, nil
	}

	// A full read first: a corrupt or truncated .gz fails here, and a small
	// .gz that expands past the limit (a "zip bomb") is refused here - both
	// before anything reaches the database.
	src, done, err := open()
	if err != nil {
		return err
	}
	expanded, err := io.Copy(io.Discard, io.LimitReader(src, maxImportExpanded+1))
	done()
	if err != nil {
		return fmt.Errorf("the file is damaged (%v); nothing was imported", err)
	}
	if expanded > maxImportExpanded {
		return fmt.Errorf("the dump expands past %d GB; nothing was imported", maxImportExpanded>>30)
	}

	// One import at a time per site, and not while a command (a migration,
	// say) is running against the same database.
	lock, _ := commandLocks.LoadOrStore(id, &sync.Mutex{})
	if !lock.(*sync.Mutex).TryLock() {
		return ErrBusy
	}
	defer lock.(*sync.Mutex).Unlock()

	saved, err := m.SnapshotDB(ctx, id, "before-import")
	if err != nil {
		return fmt.Errorf("nothing was imported: the current database could not be saved first (%v)", err)
	}

	src, done, err = open()
	if err != nil {
		return err
	}
	defer done()
	ctx, cancel := context.WithTimeout(ctx, dbTransferTimeout)
	defer cancel()
	// The dump is the customer's file, read by the mysql CLIENT, whose own
	// commands run before any SQL reaches the server - grants cannot stop
	// them. Proved on a host (2026-09-25): "\\! cmd" and "system cmd" ran a
	// shell as root in cic-mysql, "tee" wrote a file there, "source" read one
	// and echoed it back in its error. --skip-system-command stops the shell
	// only; --commands=FALSE stops every client command but DELIMITER, which
	// dumps with triggers and procedures need.
	cmd := mysqlTool(ctx, password, true, "mysql",
		"--skip-system-command", "--commands=FALSE",
		"-h", agentHost, "-u", DBUser(id),
		"--default-character-set=utf8mb4",
		DBName(id))
	cmd.Stdin = src
	stderr := &cappedBuffer{limit: 8 << 10}
	cmd.Stdout, cmd.Stderr = io.Discard, stderr
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("import stopped: %s. The database as it was before is saved as snapshot %s; restore it (or import it back) to undo", firstLine(stderr.buf.String(), err), saved.Name)
	}
	return nil
}

// BeforeImport opens the copy saved by the last import, if there is one.
func (m *Manager) BeforeImport(id string) (*os.File, error) {
	list, err := m.DBSnapshots(id)
	if err != nil {
		return nil, err
	}
	for _, snap := range list {
		if snap.Reason == "before-import" {
			return m.openSnapshot(id, snap.Name)
		}
	}
	return nil, fmt.Errorf("no import has been made, so there is no earlier copy")
}

// firstLine is the useful part of a MySQL client's stderr: the first line
// that is not the password-on-the-command-line warning.
func firstLine(stderr string, err error) string {
	for _, l := range strings.Split(stderr, "\n") {
		l = strings.TrimSpace(l)
		if l != "" && !strings.Contains(l, "Using a password") {
			return l
		}
	}
	return err.Error()
}
