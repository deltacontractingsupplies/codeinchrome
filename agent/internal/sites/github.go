package sites

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"
)

// A site linked to its owner's GitHub repository (owner, 2026-09-26: every
// change is a version already; linked, it is also in the owner's own GitHub,
// so a deleted free site's code is not gone with it).
//
// Every version the site's history records is pushed there, with a deploy key
// made for this site alone. The owner adds its PUBLIC half to the repository
// once (Settings > Deploy keys, "Allow write access"); the private half never
// leaves the host - github/ in the site's host directory, which the site's
// own code cannot reach (only vol/app is in its container). Nothing to
// register, no token held, one repository per key, revoked on GitHub by
// deleting the key.
//
// What is pushed is the history, and the history never holds .env or other
// secrets files (history.go): the same exclusions apply to GitHub.
//
// Pushes are fast-forward only. If GitHub has commits the site does not (the
// owner pushed there), nothing of theirs is overwritten: the site's history
// goes to the branch codeinchrome/sync instead, and the link says so.
//
// GitHub's SSH host keys are pinned (published at api.github.com/meta; the
// ed25519 key checked against its SHA256 fingerprint
// +DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU on 2026-09-26).

const githubKnownHosts = `github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl
github.com ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEmKSENjQEezOmxkZMy7opKgwFB9nkt5YRrYMjNuG5N87uRgg6CLrbo5wAdT/y6v0mKV0U2w0WZ2YB/++Tpockg=
github.com ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQCj7ndNxQowgcQnjshcLrqPEiiphnt+VTTvDP6mHBL9j1aNUkY4Ue1gvwnGLVlOhGeYrnZaMgRK6+PKCUXaDbC7qtbW8gIkhL7aGCsOr/C56SJMy/BCZfxd1nWzAOxSDPgVsmerOBYfNqltV9/hWCqBywINIR+5dIg6JTJ72pcEpEjcYgXkE2YEFXV1JHnsKgbLWNlhScqb2UmyRkQyytRLtL+38TGxkxCflmO+5Z8CSSNY7GidjMIZ7Q4zMjA2n1nGrlTDkzwDCsw+wqFPGQA179cnfGWOWRVruj16z6XyvxvjJwbz0wQZ75XK5tKSb7FNyeIEs4TT4jk+S4dhPeAUC5y+bDYirYgM4GC7uEnztnZyaVWQ7B381AK4Qdrwt51ZqExKbQpTUNn+EjqoTwvqNj4kqx5QUCI0ThS/YkOxJCXmPUWZbhjpCg56i+2aB6CmK2JGhn57K5mj0MNdBXA4/WnwH6XoPWJzK5Nyu2zB3nAZp+S5hpQs+p1vN1/wsjk=
`

const (
	githubSyncBranch = "codeinchrome/sync"
	githubPushLimit  = 5 * time.Minute // a first push carries the whole history
)

// githubPushDelay: a burst of saves is one push. A variable for the tests.
var githubPushDelay = 10 * time.Second

var (
	githubRepoRe   = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9._-]{1,100}$`)
	githubBranchRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$`)

	// githubRemote is where a repository is pushed; a variable so the tests
	// push to a local repository instead of GitHub.
	githubRemote = func(repo string) string { return "git@github.com:" + repo + ".git" }

	githubLocks    sync.Map // site id -> *sync.Mutex
	githubTimers   sync.Map // site id -> *time.Timer
	githubInflight sync.WaitGroup
)

// GitHubLink is a site's link, as the owner is shown it.
type GitHubLink struct {
	Repo       string     `json:"repo"`
	Branch     string     `json:"branch"`
	PublicKey  string     `json:"publicKey"`
	State      string     `json:"state"` // waiting_for_key | linked | diverged | error
	Hint       string     `json:"hint,omitempty"`
	LastPushAt *time.Time `json:"lastPushAt,omitempty"`
	LastCommit string     `json:"lastCommit,omitempty"`
}

// ErrNotLinked means the site has no GitHub repository linked.
var ErrNotLinked = errors.New("not linked to GitHub")

func (m *Manager) githubDir(id string) string { return filepath.Join(m.dir(id), "github") }

func validGitHubTarget(repo, branch string) error {
	if !githubRepoRe.MatchString(repo) || strings.Contains(repo, "..") {
		return fmt.Errorf("the repository must be owner/name, as in its GitHub address")
	}
	if !githubBranchRe.MatchString(branch) || strings.Contains(branch, "..") || strings.Contains(branch, "//") ||
		strings.HasSuffix(branch, "/") || strings.HasSuffix(branch, ".lock") || branch == githubSyncBranch {
		return fmt.Errorf("%q is not a branch name that can be used", branch)
	}
	return nil
}

// githubKey is the site's deploy key's public half, made the first time.
func (m *Manager) githubKey(ctx context.Context, id string) (string, error) {
	dir := m.githubDir(id)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	key := filepath.Join(dir, "id_ed25519")
	if _, err := os.Stat(key); os.IsNotExist(err) {
		if _, err := run(ctx, 30*time.Second, "ssh-keygen", "-q", "-t", "ed25519", "-N", "", "-C", "codeinchrome site "+id, "-f", key); err != nil {
			return "", fmt.Errorf("could not make the site's deploy key: %v", err)
		}
	}
	pub, err := os.ReadFile(key + ".pub")
	return strings.TrimSpace(string(pub)), err
}

func (m *Manager) loadGitHubLink(id string) (GitHubLink, error) {
	b, err := os.ReadFile(filepath.Join(m.githubDir(id), "link.json"))
	if os.IsNotExist(err) {
		return GitHubLink{}, ErrNotLinked
	}
	if err != nil {
		return GitHubLink{}, err
	}
	var l GitHubLink
	return l, json.Unmarshal(b, &l)
}

func (m *Manager) saveGitHubLink(id string, l GitHubLink) error {
	b, err := json.Marshal(l)
	if err != nil {
		return err
	}
	tmp := filepath.Join(m.githubDir(id), ".link.json.tmp")
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, filepath.Join(m.githubDir(id), "link.json"))
}

// LinkGitHub links the site to owner/repo (branch: "main" if empty) and
// pushes at once: "waiting_for_key" until the owner has added the key.
func (m *Manager) LinkGitHub(ctx context.Context, id, repo, branch string) (GitHubLink, error) {
	if err := ValidID(id); err != nil {
		return GitHubLink{}, err
	}
	if branch == "" {
		branch = "main"
	}
	if err := validGitHubTarget(repo, branch); err != nil {
		return GitHubLink{}, err
	}
	pub, err := m.githubKey(ctx, id)
	if err != nil {
		return GitHubLink{}, err
	}
	if err := m.saveGitHubLink(id, GitHubLink{Repo: repo, Branch: branch, PublicKey: pub, State: "waiting_for_key"}); err != nil {
		return GitHubLink{}, err
	}
	return m.PushGitHub(ctx, id)
}

// GitHubStatus is the site's link.
func (m *Manager) GitHubStatus(id string) (GitHubLink, error) {
	if err := ValidID(id); err != nil {
		return GitHubLink{}, err
	}
	return m.loadGitHubLink(id)
}

// UnlinkGitHub stops pushing and destroys the site's deploy key (the owner
// deletes its public half on GitHub too; without the private half it opens
// nothing).
func (m *Manager) UnlinkGitHub(id string) error {
	if err := ValidID(id); err != nil {
		return err
	}
	if t, ok := githubTimers.LoadAndDelete(id); ok && t.(*time.Timer).Stop() {
		githubInflight.Done()
	}
	return os.RemoveAll(m.githubDir(id))
}

// PushGitHub pushes the site's history to its linked repository, now.
func (m *Manager) PushGitHub(ctx context.Context, id string) (GitHubLink, error) {
	if err := ValidID(id); err != nil {
		return GitHubLink{}, err
	}
	lk, _ := githubLocks.LoadOrStore(id, &sync.Mutex{})
	lk.(*sync.Mutex).Lock()
	defer lk.(*sync.Mutex).Unlock()

	l, err := m.loadGitHubLink(id)
	if err != nil {
		return GitHubLink{}, err
	}
	// A site with no version yet gets its first one: there is always
	// something to push.
	if !m.hasHistory(id) {
		if err := m.commit(ctx, id, "the site as it was when GitHub was linked"); err != nil {
			return l, err
		}
	}
	known := filepath.Join(m.githubDir(id), "known_hosts")
	if err := os.WriteFile(known, []byte(githubKnownHosts), 0o600); err != nil {
		return l, err
	}
	ssh := fmt.Sprintf("ssh -i '%s' -o IdentitiesOnly=yes -o UserKnownHostsFile='%s' -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=15",
		filepath.Join(m.githubDir(id), "id_ed25519"), known)
	push := func(ref string, force bool) error {
		args := []string{"push", "--porcelain"}
		if force {
			args = append(args, "--force") // only ever our own branch, codeinchrome/sync
		}
		out, err := m.gitWith(ctx, id, nil, []string{"GIT_SSH_COMMAND=" + ssh}, githubPushLimit,
			append(args, githubRemote(l.Repo), "HEAD:refs/heads/"+ref)...)
		if err != nil {
			// --porcelain prints why a ref was refused ("[rejected]") on stdout.
			return fmt.Errorf("%w %s", err, strings.TrimSpace(out))
		}
		return nil
	}

	err = push(l.Branch, false)
	l.State, l.Hint = githubOutcome(l, err)
	if l.State == "diverged" {
		if err := push(githubSyncBranch, true); err != nil {
			l.State, l.Hint = githubOutcome(l, err)
		}
	}
	if l.State == "linked" || l.State == "diverged" {
		now := time.Now().UTC().Truncate(time.Second)
		l.LastPushAt = &now
		if head, err := m.git(ctx, id, "rev-parse", "HEAD"); err == nil {
			l.LastCommit = strings.TrimSpace(head)
		}
	}
	if err := m.saveGitHubLink(id, l); err != nil {
		return l, err
	}
	return l, nil
}

// githubOutcome turns a push's answer into the link's state and what the
// owner should do about it.
func githubOutcome(l GitHubLink, err error) (string, string) {
	if err == nil {
		return "linked", ""
	}
	msg := err.Error()
	settings := "https://github.com/" + l.Repo + "/settings/keys/new"
	switch {
	case strings.Contains(msg, "read only") || strings.Contains(msg, "read-only"):
		return "waiting_for_key", "The key was added without write access. On " + settings + " add it again with \"Allow write access\" ticked."
	case strings.Contains(msg, "Permission denied") || strings.Contains(msg, "Repository not found") || strings.Contains(msg, "Could not read from remote"):
		return "waiting_for_key", "Add this site's key to " + settings + " with \"Allow write access\" ticked (and check the repository " + l.Repo + " exists). Pushing starts as soon as it is there."
	case strings.Contains(msg, "[rejected]") || strings.Contains(msg, "non-fast-forward") || strings.Contains(msg, "fetch first") ||
		strings.Contains(msg, "Updates were rejected"):
		return "diverged", "GitHub's " + l.Branch + " has commits this site does not. Nothing of them was overwritten: the site's history is on the branch " + githubSyncBranch + " - merge it on GitHub."
	case strings.Contains(msg, "protected branch") || strings.Contains(msg, "GH006") || strings.Contains(msg, "GH013"):
		return "error", "The branch " + l.Branch + " is protected on GitHub. Link another branch, or allow this key to push to it."
	default:
		lines := strings.Split(strings.TrimSpace(msg), "\n")
		return "error", "The push failed: " + lines[len(lines)-1]
	}
}

// scheduleGitHubPush pushes a linked site a little after its latest version:
// a burst of saves is one push.
func (m *Manager) scheduleGitHubPush(id string) {
	if _, err := os.Stat(filepath.Join(m.githubDir(id), "link.json")); err != nil {
		return
	}
	githubInflight.Add(1)
	t := time.AfterFunc(githubPushDelay, func() {
		defer githubInflight.Done()
		githubTimers.Delete(id)
		if l, err := m.PushGitHub(context.Background(), id); err != nil {
			slog.Warn("github: push failed", "site", id, "err", err)
		} else if l.State != "linked" {
			slog.Info("github: not pushed", "site", id, "state", l.State)
		}
	})
	if old, loaded := githubTimers.Swap(id, t); loaded && old.(*time.Timer).Stop() {
		githubInflight.Done() // replaced before it ran: it never will
	}
}

// StopGitHubPushes cancels the pushes waiting to run and waits for the ones
// running (the agent stopping; each test ending).
func StopGitHubPushes() {
	githubTimers.Range(func(id, t any) bool {
		if t.(*time.Timer).Stop() {
			githubInflight.Done()
		}
		githubTimers.Delete(id)
		return true
	})
	githubInflight.Wait()
}

// CatchUpGitHub pushes every linked site once (the agent starting): a version
// recorded just before a restart, or during a network outage, still arrives.
func (m *Manager) CatchUpGitHub(ctx context.Context) (pushed int) {
	entries, err := os.ReadDir(m.cfg.Root)
	if err != nil {
		return 0
	}
	for _, e := range entries {
		if !e.IsDir() || ValidID(e.Name()) != nil {
			continue
		}
		l, err := m.loadGitHubLink(e.Name())
		if err != nil || (l.State != "linked" && l.State != "diverged") {
			continue
		}
		if l, err := m.PushGitHub(ctx, e.Name()); err == nil && l.State == "linked" {
			pushed++
		}
	}
	return pushed
}
