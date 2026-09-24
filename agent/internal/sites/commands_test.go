package sites

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestCommandAllowList(t *testing.T) {
	allowed := map[string][]string{
		"artisan":  {"migrate", "route:list", "make:model", "cache:clear", "app:check", "app:import-products", "test"},
		"composer": {"require", "install", "dump-autoload"},
	}
	for tool, cmds := range allowed {
		for _, c := range cmds {
			if _, _, err := validateCommand(tool, []string{c}); err != nil {
				t.Errorf("%s %s refused: %v", tool, c, err)
			}
		}
	}

	refused := [][]string{
		{"artisan", "tinker"},                   // an arbitrary-code prompt
		{"artisan", "serve"},                    // opens a listener
		{"artisan", "down"},                     // not offered
		{"artisan", "migrate", "--env=testing"}, // switches configuration
		{"artisan", "migrate", "--env", "x"},
		{"artisan", "migrate", "--path=../../../etc"},
		{"artisan", "migrate;rm", "-rf"},
		{"artisan", "route:list", "$(id)"},
		{"artisan", "route:list", "`id`"},
		{"composer", "exec", "bash"},
		{"composer", "run-script", "x"},
		{"composer", "global", "require", "x"},
		{"bash", "-c", "id"},
		{"artisan"},
		{"artisan", "app:"},      // no name
		{"artisan", "App:check"}, // not the app: namespace
		{"artisan", "app:check;id"},
		{"artisan", "app:../../x"},
		{"artisan", "application:check"},
	}
	for _, r := range refused {
		tool, args := r[0], r[1:]
		if _, _, err := validateCommand(tool, args); err == nil {
			t.Errorf("%s %s was ALLOWED", tool, strings.Join(args, " "))
		}
	}
}

func TestProductionCommandsGetForceAndNoPrompt(t *testing.T) {
	argv, _, err := validateCommand("artisan", []string{"migrate"})
	if err != nil {
		t.Fatal(err)
	}
	joined := strings.Join(argv, " ")
	if !strings.Contains(joined, "--force") || !strings.Contains(joined, "--no-interaction") {
		t.Errorf("migrate would wait for a prompt in production: %s", joined)
	}
}

// PHPUnit refuses options it does not know, and artisan test passes them on:
// a test run must not carry --no-interaction (every run failed with it).
func TestATestRunCarriesNoOptionPHPUnitRefuses(t *testing.T) {
	argv, _, err := validateCommand("artisan", []string{"test", "--filter=Checkout"})
	if err != nil {
		t.Fatal(err)
	}
	if contains(argv, "--no-interaction") {
		t.Fatalf("artisan test must not pass --no-interaction to PHPUnit: %v", argv)
	}
	if other, _, _ := validateCommand("artisan", []string{"migrate"}); !contains(other, "--no-interaction") {
		t.Fatalf("every other command still never waits for a prompt: %v", other)
	}
}

func TestAccessLogIsCompacted(t *testing.T) {
	raw := `{"ts":1790000000.5,"status":200,"duration":0.0123,"size":512,"request":{"remote_ip":"203.0.113.7","method":"GET","host":"shop.codeinchrome.com","uri":"/cart"}}
not json
{"ts":1790000001,"level":"info","msg":"no request here"}`
	got := compactAccessLog(raw)
	want := "2026-09-21 14:13:20 200 GET shop.codeinchrome.com/cart 512B 12ms 203.0.113.7"
	if got != want {
		t.Fatalf("got %q\nwant %q", got, want)
	}
}

func TestComposerCannotBePointedAtAnotherDirectory(t *testing.T) {
	for _, args := range [][]string{
		{"install", "--working-dir=/tmp"},
		{"require", "vendor/pkg", "--working-dir", "/tmp"},
		{"install", "-d", "/tmp"},
		{"install", "-d/tmp"},
	} {
		if _, _, err := validateCommand("composer", args); err == nil {
			t.Errorf("composer %v was accepted", args)
		}
	}
	if _, _, err := validateCommand("composer", []string{"require", "-W", "laravel/reverb", "--dev"}); err != nil {
		t.Errorf("an ordinary require was refused: %v", err)
	}
}

func TestTheAppLogIsTheNewestDailyFileAndNeverFollowsALink(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	logs := filepath.Join(m.appDir(id), "storage/logs")
	os.MkdirAll(logs, 0o755)
	os.WriteFile(filepath.Join(logs, "laravel-2026-09-22.log"), []byte("yesterday\n"), 0o644)
	os.WriteFile(filepath.Join(logs, "laravel-2026-09-23.log"), []byte("line one\nERROR today\n"), 0o644)
	old := time.Now().Add(-24 * time.Hour)
	os.Chtimes(filepath.Join(logs, "laravel-2026-09-22.log"), old, old)
	os.WriteFile(filepath.Join(logs, "other.log"), []byte("not laravel\n"), 0o644)

	res, err := m.Logs(ctx, id, "app", 10)
	if err != nil || !strings.Contains(res.Lines, "ERROR today") || strings.Contains(res.Lines, "yesterday") {
		t.Fatalf("want today's daily log, got %q %v", res.Lines, err)
	}

	// storage/logs swapped for a link to the host: nothing is read.
	host := t.TempDir()
	os.WriteFile(filepath.Join(host, "laravel.log"), []byte("HOST SECRET\n"), 0o644)
	os.RemoveAll(logs)
	os.Symlink(host, logs)
	res, _ = m.Logs(ctx, id, "app", 10)
	if strings.Contains(res.Lines, "HOST SECRET") {
		t.Fatal("the log reader followed a symlink out of the site")
	}
}

// A check marks the log, works, then reads what was written since: exactly
// that, even when a new entry reads word for word like an old one.
func TestLogsSinceReturnsOnlyWhatWasWrittenAfterTheMark(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	logs := filepath.Join(m.appDir(id), "storage/logs")
	os.MkdirAll(logs, 0o755)
	today := filepath.Join(logs, "laravel-2026-09-24.log")
	entry := "[2026-09-24 09:00:00] production.ERROR: boom\n#0 {main}\n"
	os.WriteFile(today, []byte(entry), 0o644)

	mark, err := m.Logs(ctx, id, "app", 1)
	if err != nil || mark.File != "laravel-2026-09-24.log" || mark.Size != int64(len(entry)) {
		t.Fatalf("mark = %+v %v", mark, err)
	}
	if res, _ := m.LogsSince(id, mark.File, mark.Size); res.Lines != "" {
		t.Fatalf("nothing written yet, got %q", res.Lines)
	}

	f, _ := os.OpenFile(today, os.O_APPEND|os.O_WRONLY, 0)
	f.WriteString(entry) // the same error again: it is new, and must be reported
	f.Close()
	res, _ := m.LogsSince(id, mark.File, mark.Size)
	if res.Lines != strings.TrimRight(entry, "\n") {
		t.Fatalf("want exactly the repeated entry, got %q", res.Lines)
	}

	// The day turns: the new file is new from its start.
	tomorrow := filepath.Join(logs, "laravel-2026-09-25.log")
	os.WriteFile(tomorrow, []byte("[2026-09-25 00:00:01] production.ERROR: after midnight\n"), 0o644)
	later := time.Now().Add(time.Minute)
	os.Chtimes(tomorrow, later, later)
	res, _ = m.LogsSince(id, mark.File, mark.Size)
	if !strings.Contains(res.Lines, "after midnight") || strings.Contains(res.Lines, "boom") {
		t.Fatalf("want the new day's file whole, got %q", res.Lines)
	}

	// A log cleared to shorter than the mark is read from its start.
	os.WriteFile(tomorrow, []byte("x\n"), 0o644)
	os.Chtimes(tomorrow, later, later)
	if res, _ = m.LogsSince(id, "laravel-2026-09-25.log", 10_000); res.Lines != "x" {
		t.Fatalf("want the cleared log from its start, got %q", res.Lines)
	}
}

// A test run must never reach the site's live database: whatever .env says,
// the run's environment points it at an in-memory SQLite and a MySQL host
// that does not exist.
func TestATestRunNeverReachesTheLiveDatabase(t *testing.T) {
	env := commandEnv("artisan", []string{"test"})
	want := map[string]bool{"DB_CONNECTION=sqlite": true, "DB_DATABASE=:memory:": true, "APP_ENV=testing": true, "DB_HOST=db.invalid": true,
		"DB_URL=": true} // a URL in .env would override all of the above
	got := map[string]bool{}
	for _, e := range env {
		got[e] = true
	}
	for w := range want {
		if !got[w] {
			t.Errorf("artisan test runs without %s: %v", w, env)
		}
	}
	if len(commandEnv("artisan", []string{"migrate"})) != 0 {
		t.Error("only the test run gets the test environment")
	}
}
