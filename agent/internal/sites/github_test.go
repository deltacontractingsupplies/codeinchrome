package sites

import (
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// bareRemote stands in for GitHub: a local bare repository the link pushes
// to (githubRemote), and a way to read what arrived.
func bareRemote(t *testing.T) (dir string, show func(ref, path string) string) {
	t.Helper()
	dir = filepath.Join(t.TempDir(), "remote.git")
	if out, err := exec.Command("git", "init", "-q", "--bare", dir).CombinedOutput(); err != nil {
		t.Fatalf("%v %s", err, out)
	}
	old := githubRemote
	githubRemote = func(string) string { return dir }
	// No push outlives its test: one firing later would read the NEXT
	// test's remote (the race detector caught exactly that).
	t.Cleanup(func() { StopGitHubPushes(); githubRemote = old })
	show = func(ref, path string) string {
		out, err := exec.Command("git", "--git-dir="+dir, "show", ref+":"+path).CombinedOutput()
		if err != nil {
			return "<none: " + strings.TrimSpace(string(out)) + ">"
		}
		return string(out)
	}
	return dir, show
}

func TestALinkedSitesVersionsArriveOnGitHubWithoutItsSecrets(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	_, show := bareRemote(t)
	os.WriteFile(filepath.Join(m.appDir(id), ".env"), []byte("APP_KEY=base64:do-not-push\nDB_PASSWORD=hunter2\n"), 0o600)
	if err := m.WriteFile(ctx, id, "routes/web.php", "<?php // one"); err != nil {
		t.Fatal(err)
	}

	l, err := m.LinkGitHub(ctx, id, "acme/shop", "")
	if err != nil {
		t.Fatal(err)
	}
	if l.State != "linked" || l.Branch != "main" || !strings.HasPrefix(l.PublicKey, "ssh-ed25519 ") || l.LastCommit == "" || l.LastPushAt == nil {
		t.Fatalf("link %+v", l)
	}
	if got := show("main", "routes/web.php"); got != "<?php // one" {
		t.Fatalf("GitHub has %q", got)
	}
	if got := show("main", ".env"); !strings.HasPrefix(got, "<none") {
		t.Fatal(".env reached GitHub")
	}
	// The private half stays on the host, readable by nothing else.
	if info, err := os.Stat(filepath.Join(m.githubDir(id), "id_ed25519")); err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("private key %v %v", info, err)
	}

	// The next version goes too.
	m.WriteFile(ctx, id, "routes/web.php", "<?php // two")
	if l, err := m.PushGitHub(ctx, id); err != nil || l.State != "linked" {
		t.Fatalf("%+v %v", l, err)
	}
	if got := show("main", "routes/web.php"); got != "<?php // two" {
		t.Fatalf("GitHub has %q", got)
	}
}

func TestSomethingPushedOnGitHubIsNeverOverwritten(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	remote, show := bareRemote(t)
	m.WriteFile(ctx, id, "routes/web.php", "<?php // site")
	if l, _ := m.LinkGitHub(ctx, id, "acme/shop", "main"); l.State != "linked" {
		t.Fatalf("%+v", l)
	}

	// The owner pushes to GitHub directly.
	work := t.TempDir()
	for _, args := range [][]string{
		// -b main: whatever the machine's default branch (CI's git says master).
		{"clone", "-q", "-b", "main", remote, work},
		{"-C", work, "-c", "user.name=owner", "-c", "user.email=o@example.com", "commit", "-q", "--allow-empty", "-m", "owner's own commit"},
		{"-C", work, "push", "-q", "origin", "HEAD:main"},
	} {
		if out, err := exec.Command("git", args...).CombinedOutput(); err != nil {
			t.Fatalf("git %v: %v %s", args, err, out)
		}
	}
	ownerHead, _ := exec.Command("git", "--git-dir="+remote, "rev-parse", "main").Output()

	m.WriteFile(ctx, id, "routes/web.php", "<?php // site, later")
	l, err := m.PushGitHub(ctx, id)
	if err != nil || l.State != "diverged" || !strings.Contains(l.Hint, githubSyncBranch) {
		t.Fatalf("%+v %v", l, err)
	}
	if now, _ := exec.Command("git", "--git-dir="+remote, "rev-parse", "main").Output(); string(now) != string(ownerHead) {
		t.Fatal("the owner's main was overwritten")
	}
	if got := show(githubSyncBranch, "routes/web.php"); got != "<?php // site, later" {
		t.Fatalf("the site's history is not on %s: %q", githubSyncBranch, got)
	}
}

func TestOnlyARealRepositoryAndBranchAndUnlinkDestroysTheKey(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	bareRemote(t)
	for _, c := range [][2]string{
		{"acme", "main"}, {"acme/shop/extra", "main"}, {"../shop", "main"}, {"-x/shop", "main"},
		{"acme/shop", ".."}, {"acme/shop", "a..b"}, {"acme/shop", "x.lock"}, {"acme/shop", "-f"}, {"acme/shop", githubSyncBranch},
		{"acme/sh op", "main"}, {"acme/shop", "main;rm"},
	} {
		if _, err := m.LinkGitHub(ctx, id, c[0], c[1]); err == nil {
			t.Errorf("%q %q accepted", c[0], c[1])
		}
	}
	if _, err := m.GitHubStatus(id); !errors.Is(err, ErrNotLinked) {
		t.Fatalf("a refused link was saved: %v", err)
	}
	m.WriteFile(ctx, id, "a.php", "<?php")
	m.LinkGitHub(ctx, id, "acme/shop", "main")
	if err := m.UnlinkGitHub(id); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(m.githubDir(id)); !os.IsNotExist(err) {
		t.Fatal("the key survived unlinking")
	}
	if _, err := m.PushGitHub(ctx, id); !errors.Is(err, ErrNotLinked) {
		t.Fatalf("pushed after unlinking: %v", err)
	}
}

func TestWhatGitHubSaysBecomesWhatToDo(t *testing.T) {
	l := GitHubLink{Repo: "acme/shop", Branch: "main"}
	for msg, want := range map[string]string{
		"git push: exit status 128: ERROR: Permission to acme/shop.git denied to deploy key. The key you are authenticating with has been marked as read only.": "waiting_for_key",
		"git push: exit status 128: git@github.com: Permission denied (publickey). fatal: Could not read from remote repository.":                               "waiting_for_key",
		"git push: exit status 1: ! [rejected] HEAD -> main (fetch first)":                                                                                      "diverged",
		"git push: exit status 1: remote: error: GH006: Protected branch update failed for refs/heads/main.":                                                    "error",
	} {
		if got, hint := githubOutcome(l, errors.New(msg)); got != want || hint == "" {
			t.Errorf("%q -> %s %q", msg, got, hint)
		}
	}
	if got, hint := githubOutcome(l, errors.New("read only")); !strings.Contains(hint, "Allow write access") || got != "waiting_for_key" {
		t.Errorf("read-only key: %s %q", got, hint)
	}
}

// Linked, a save reaches GitHub by itself - a burst of them as one push.
func TestASaveReachesGitHubByItself(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	remote, show := bareRemote(t)
	old := githubPushDelay
	githubPushDelay = 50 * time.Millisecond
	t.Cleanup(func() { StopGitHubPushes(); githubPushDelay = old })
	m.WriteFile(ctx, id, "a.php", "<?php // 1")
	m.LinkGitHub(ctx, id, "acme/shop", "main")

	for i := 2; i <= 4; i++ {
		m.WriteFile(ctx, id, "a.php", fmt.Sprintf("<?php // %d", i))
	}
	deadline := time.Now().Add(5 * time.Second)
	for show("main", "a.php") != "<?php // 4" {
		if time.Now().After(deadline) {
			t.Fatalf("GitHub still has %q", show("main", "a.php"))
		}
		time.Sleep(20 * time.Millisecond)
	}
	// Three saves, one push: GitHub's main moved from the link's commit to
	// the last save's, and the site's history has all three between them.
	count, _ := exec.Command("git", "--git-dir="+remote, "rev-list", "--count", "main").Output()
	if strings.TrimSpace(string(count)) != "4" {
		t.Fatalf("GitHub has %s commits, want 4 (every version kept)", count)
	}
}
