package sites

import (
	"bytes"
	"compress/gzip"
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// fakeMySQL replaces the docker exec with a shell that records what it was
// asked to run and, for mysql, what it was fed.
func fakeMySQL(t *testing.T, dumpOutput string, failImport bool) (calls *[]string, fed *bytes.Buffer) {
	t.Helper()
	calls = &[]string{}
	fed = &bytes.Buffer{}
	fedFile := filepath.Join(t.TempDir(), "fed.sql")
	orig := mysqlTool
	t.Cleanup(func() { mysqlTool = orig })
	mysqlTool = func(ctx context.Context, password string, stdin bool, argv ...string) *exec.Cmd {
		built := orig(ctx, password, stdin, argv...)
		*calls = append(*calls, strings.Join(built.Args, " "))
		for _, e := range built.Env {
			if e == "MYSQL_PWD="+password {
				*calls = append(*calls, "env:has-password")
			}
		}
		if argv[0] == "mysqldump" {
			return exec.CommandContext(ctx, "printf", "%s", dumpOutput)
		}
		script := "cat > " + fedFile
		if failImport {
			script = "cat >/dev/null; echo 'ERROR 1064 (42000) at line 1: You have an error' >&2; exit 1"
		}
		cmd := exec.CommandContext(ctx, "sh", "-c", script)
		return cmd
	}
	fedReader = func() string { b, _ := os.ReadFile(fedFile); return string(b) }
	return calls, fed
}

var fedReader func() string

func withDB(t *testing.T) (*Manager, string) {
	m, id := historyManager(t)
	os.MkdirAll(m.dir(id), 0o700)
	os.WriteFile(filepath.Join(m.dir(id), "db.secret"), []byte("s3cret-pw\n"), 0o600)
	return m, id
}

func TestExportRunsAsTheSitesOwnUserWithThePasswordNeverInArgv(t *testing.T) {
	m, id := withDB(t)
	calls, _ := fakeMySQL(t, "CREATE TABLE t (id int);\n", false)
	var out bytes.Buffer
	if err := m.ExportDB(context.Background(), id, &out); err != nil {
		t.Fatal(err)
	}
	zr, err := gzip.NewReader(&out)
	if err != nil {
		t.Fatal("export is not gzip:", err)
	}
	var sql bytes.Buffer
	sql.ReadFrom(zr)
	if sql.String() != "CREATE TABLE t (id int);\n" {
		t.Fatalf("export content %q", sql.String())
	}
	cmd := (*calls)[0]
	for _, want := range []string{"docker exec -u 65534:65534 -e MYSQL_PWD cic-mysql mysqldump", "-u " + DBUser(id), "--single-transaction", DBName(id)} {
		if !strings.Contains(cmd, want) {
			t.Fatalf("export command %q lacks %q", cmd, want)
		}
	}
	if strings.Contains(strings.Join(*calls, " "), "s3cret-pw") {
		t.Fatal("the database password appears in a command line")
	}
	if (*calls)[1] != "env:has-password" {
		t.Fatal("the password is not passed through the environment")
	}
}

func TestImportSavesTheCurrentDatabaseFirstAndAcceptsGzip(t *testing.T) {
	m, id := withDB(t)
	fakeMySQL(t, "-- the old data\n", false)

	var gz bytes.Buffer
	zw := gzip.NewWriter(&gz)
	zw.Write([]byte("INSERT INTO t VALUES (1);\n"))
	zw.Close()
	if err := m.ImportDB(context.Background(), id, &gz); err != nil {
		t.Fatal(err)
	}
	if got := fedReader(); got != "INSERT INTO t VALUES (1);\n" {
		t.Fatalf("mysql was fed %q", got)
	}
	f, err := m.BeforeImport(id)
	if err != nil {
		t.Fatal("no copy of the database from before the import:", err)
	}
	defer f.Close()
	zr, _ := gzip.NewReader(f)
	var old bytes.Buffer
	old.ReadFrom(zr)
	if old.String() != "-- the old data\n" {
		t.Fatalf("saved copy holds %q", old.String())
	}
	if info, _ := os.Stat(filepath.Join(m.dir(id), beforeImportFile)); info.Mode().Perm() != 0o600 {
		t.Fatalf("saved copy is %v, want 0600", info.Mode().Perm())
	}
	// The saved copy is outside the app: the site's code cannot reach it.
	if strings.HasPrefix(filepath.Join(m.dir(id), beforeImportFile), m.appDir(id)+string(os.PathSeparator)) {
		t.Fatal("the saved copy is inside the site's app directory")
	}
}

func TestADamagedOrEmptyDumpNeverReachesTheDatabase(t *testing.T) {
	m, id := withDB(t)
	calls, _ := fakeMySQL(t, "", false)

	var gz bytes.Buffer
	zw := gzip.NewWriter(&gz)
	zw.Write(bytes.Repeat([]byte("INSERT INTO t VALUES (1);\n"), 1000))
	zw.Close()
	truncated := gz.Bytes()[:gz.Len()/2]

	for name, body := range map[string][]byte{"truncated gzip": truncated, "empty": nil} {
		if err := m.ImportDB(context.Background(), id, bytes.NewReader(body)); err == nil || !strings.Contains(err.Error(), "nothing was imported") {
			t.Fatalf("%s: got %v, want a refusal", name, err)
		}
	}
	if len(*calls) != 0 {
		t.Fatalf("MySQL was called for a bad file: %v", *calls)
	}
}

func TestAFailedImportSaysTheOldDatabaseIsKept(t *testing.T) {
	m, id := withDB(t)
	fakeMySQL(t, "-- old\n", true)
	err := m.ImportDB(context.Background(), id, strings.NewReader("NOT SQL"))
	if err == nil || !strings.Contains(err.Error(), "ERROR 1064") || !strings.Contains(err.Error(), "import it back to undo") {
		t.Fatalf("got %v", err)
	}
	if _, err := m.BeforeImport(id); err != nil {
		t.Fatal("the pre-import copy is missing after a failed import")
	}
}

// A dump is the customer's file, read by the mysql client: its own commands
// (\! and system: a shell; tee: write a file; source: read one) must be off,
// and the client must not run as root in the shared MySQL container.
func TestImportTurnsOffTheClientsCommandsAndRunsAsNobody(t *testing.T) {
	m, id := withDB(t)
	calls, _ := fakeMySQL(t, "", false)
	dump := "\\! touch /tmp/pwned\nsystem id\ntee /tmp/x\nsource /etc/passwd\nCREATE TABLE t (id int);\n"
	_ = m.ImportDB(context.Background(), id, strings.NewReader(dump))
	var importCall string
	for _, c := range *calls {
		if strings.Contains(c, " mysql ") && !strings.Contains(c, "mysqldump") {
			importCall = c
		}
	}
	if importCall == "" {
		t.Fatalf("no mysql import call in %v", *calls)
	}
	for _, want := range []string{"--skip-system-command", "--commands=FALSE", "exec -u 65534:65534"} {
		if !strings.Contains(importCall, want) {
			t.Errorf("import call lacks %q: %s", want, importCall)
		}
	}
	if i, j := strings.Index(importCall, "--commands=FALSE"), strings.Index(importCall, DBName(id)); i < 0 || j < i {
		t.Errorf("the options must come before the database name: %s", importCall)
	}
	for _, c := range *calls {
		if strings.HasPrefix(c, "docker exec") && !strings.Contains(c, "-u 65534:65534") {
			t.Errorf("a MySQL tool runs as root: %s", c)
		}
	}
}
