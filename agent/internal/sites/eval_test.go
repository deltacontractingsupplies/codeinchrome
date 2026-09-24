package sites

import (
	"context"
	"os/exec"
	"strings"
	"testing"
)

func TestEvalRunsTheCodeInsideTheBootedAppAndNotThroughAShell(t *testing.T) {
	m, id := historyManager(t)
	m.save(Site{ID: id})
	stdinFile := t.TempDir() + "/stdin"
	orig := evalCommand
	t.Cleanup(func() { evalCommand = orig })
	evalCommand = func(ctx context.Context, container string) *exec.Cmd {
		cmd := exec.CommandContext(ctx, "sh", "-c", `cat > "$0"; echo 42`, stdinFile)
		return cmd
	}
	res, err := m.Eval(context.Background(), id, "<?php return App\\Models\\User::count(); // $(rm -rf /)")
	if err != nil || strings.TrimSpace(res.Output) != "42" {
		t.Fatalf("%v %+v", err, res)
	}
	sent, _ := exec.Command("cat", stdinFile).Output()
	s := string(sent)
	if !strings.HasPrefix(s, "<?php\nchdir('/var/www/html');") || !strings.Contains(s, "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();") {
		t.Fatalf("the app is not booted first:\n%s", s)
	}
	if !strings.Contains(s, " return App\\Models\\User::count(); // $(rm -rf /);") || strings.Contains(s, "<?php return") {
		t.Fatalf("the code must arrive verbatim, once, inside the closure:\n%s", s)
	}

	m.save(Site{ID: id, Suspended: true})
	if _, err := m.Eval(context.Background(), id, "return 1;"); err == nil {
		t.Fatal("code ran on a paused site")
	}
	if _, err := m.Eval(context.Background(), "../x", "return 1;"); err == nil {
		t.Fatal("a bad id was accepted")
	}
}

func TestLoginCookieComesFromTheAppsOwnSessionAndKey(t *testing.T) {
	m, id := historyManager(t)
	m.save(Site{ID: id})
	orig := evalCommand
	t.Cleanup(func() { evalCommand = orig })
	evalCommand = func(ctx context.Context, container string) *exec.Cmd {
		return exec.CommandContext(ctx, "sh", "-c", `cat >/dev/null; printf 'warning noise\n__CIC_COOKIE__laravel_session\neyJpdiI6'`)
	}
	name, value, err := m.LoginCookie(context.Background(), id, 7, "")
	if err != nil || name != "laravel_session" || value != "eyJpdiI6" {
		t.Fatalf("%q %q %v", name, value, err)
	}
	for _, g := range []string{"web'); system('x", "Admin", ""} {
		if g == "" {
			continue
		}
		if _, _, err := m.LoginCookie(context.Background(), id, 7, g); err == nil {
			t.Errorf("guard %q was accepted", g)
		}
	}
	if _, _, err := m.LoginCookie(context.Background(), id, 0, "web"); err == nil {
		t.Error("user 0 was accepted")
	}
}
