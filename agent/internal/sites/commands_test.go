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
		"artisan":  {"migrate", "route:list", "make:model", "cache:clear"},
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
