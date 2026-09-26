package sites

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"log/slog"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

// History: every change to a site's files is a git commit, so nothing a
// person or an agent does in the editor is ever lost, and any earlier version
// of any file - including a deleted one (the "bin") - can be put back.
//
// Where it lives, and why that is the security boundary:
//
//	vol/app          the application; the ONLY directory the container mounts
//	vol/history.git  the repository: on the site's own disk (so it counts
//	                 against the plan's quota and is backed up with the site),
//	                 root-owned 0700, and NOT mounted into the container
//
// The agent runs git as root. If the repository were inside the app, the
// customer's code could plant .git/hooks or a .git/config (core.fsmonitor,
// a filter or diff driver) and have the agent run it ON THE HOST. Here the
// repository and its config are out of the container's reach, and every call
// also disables hooks, fsmonitor, system and global config explicitly.
//
// Secrets never enter history: .env and friends are excluded on every add by
// pathspec, which a customer's .gitignore cannot override (it can un-ignore,
// but not un-exclude a pathspec).

const (
	maxHistoryFile = 20 << 20 // larger files are left out of history
	historyTimeout = 60 * time.Second
)

// Never committed, whatever the site's own .gitignore says.
var historyExcludes = []string{
	":(exclude,glob).env", ":(exclude,glob).env.*", ":(exclude,glob)**/.env", ":(exclude,glob)**/.env.*",
	":(exclude)storage", ":(exclude)vendor", ":(exclude)node_modules", ":(exclude)bootstrap/cache",
	":(exclude)public/storage", ":(exclude)public/hot", ":(exclude).phpunit.cache", ":(exclude).phpunit.result.cache",
}

var (
	revisionRe   = regexp.MustCompile(`^[0-9a-f]{40}$`)
	historyLocks sync.Map // site id -> *sync.Mutex
)

func (m *Manager) historyDir(id string) string { return filepath.Join(m.volume(id), "history.git") }

func historyLock(id string) *sync.Mutex {
	l, _ := historyLocks.LoadOrStore(id, &sync.Mutex{})
	return l.(*sync.Mutex)
}

// git runs one git command against a site's history, with nothing from the
// environment, the system or the repository able to run code.
func (m *Manager) git(ctx context.Context, id string, args ...string) (string, error) {
	return m.gitIn(ctx, id, nil, args...)
}

// gitIn is git with stdin.
func (m *Manager) gitIn(ctx context.Context, id string, stdin io.Reader, args ...string) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, historyTimeout)
	defer cancel()
	base := []string{
		"-c", "core.hooksPath=/dev/null",
		"-c", "core.fsmonitor=false",
		"-c", "safe.directory=*",
		"-c", "core.quotePath=false",
		"-c", "commit.gpgSign=false",
		"-c", "core.autocrlf=false",
		"-c", "gc.auto=0",
		"--git-dir=" + m.historyDir(id),
		"--work-tree=" + m.appDir(id),
	}
	cmd := exec.CommandContext(ctx, "git", append(base, args...)...)
	cmd.Stdin = stdin
	cmd.Env = []string{
		"PATH=/usr/bin:/bin", "HOME=/nonexistent", "LC_ALL=C",
		"GIT_CONFIG_NOSYSTEM=1", "GIT_CONFIG_GLOBAL=/dev/null", "GIT_TERMINAL_PROMPT=0",
		"GIT_AUTHOR_NAME=codeinchrome", "GIT_AUTHOR_EMAIL=history@codeinchrome.com",
		"GIT_COMMITTER_NAME=codeinchrome", "GIT_COMMITTER_EMAIL=history@codeinchrome.com",
	}
	var out, errb bytes.Buffer
	cmd.Stdout, cmd.Stderr = &out, &errb
	if err := cmd.Run(); err != nil {
		return out.String(), fmt.Errorf("git %s: %v: %s", args[0], err, strings.TrimSpace(errb.String()))
	}
	return out.String(), nil
}

func (m *Manager) ensureHistory(ctx context.Context, id string) error {
	dir := m.historyDir(id)
	if _, err := os.Stat(filepath.Join(dir, "HEAD")); err == nil {
		return nil
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	if _, err := m.git(ctx, id, "init", "-q"); err != nil {
		return err
	}
	return os.Chmod(dir, 0o700)
}

// record commits whatever changed in the site. Called after every change the
// agent makes; a failure is logged, never returned - history must not be the
// reason a customer's save fails.
func (m *Manager) record(ctx context.Context, id, message string) {
	// Detached from the caller's cancellation: a browser that disconnects
	// after its save succeeded must not cost that save its version.
	if err := m.commit(context.WithoutCancel(ctx), id, message); err != nil {
		slog.Warn("history: commit failed", "site", id, "err", err)
	}
}

func (m *Manager) commit(ctx context.Context, id, message string) error {
	lock := historyLock(id)
	lock.Lock()
	defer lock.Unlock()

	if err := m.ensureHistory(ctx, id); err != nil {
		return err
	}
	// Not `git add -A`: on a real Laravel app it exits 1 whenever one of our
	// exclusions names a path the app's own .gitignore already ignores (.env,
	// vendor) - every commit failed, silently, on every live site, while the
	// unit tests (no .gitignore) passed. ls-files lists exactly what changed,
	// honouring both the app's .gitignore and our exclusions, and never
	// complains; update-index stages exactly that list. An excluded file is
	// never even hashed into the history's object store.
	changed, err := m.git(ctx, id, append([]string{"ls-files", "-z", "--others", "--modified", "--deleted", "--exclude-standard", "--", "."}, historyExcludes...)...)
	if err != nil {
		return err
	}
	if changed != "" {
		if _, err := m.gitIn(ctx, id, strings.NewReader(changed), "update-index", "--add", "--remove", "-z", "--stdin"); err != nil {
			return err
		}
	}
	// Leave very large files out: history is for code, not for uploads.
	staged, err := m.git(ctx, id, "diff", "--cached", "--name-only", "-z", "--diff-filter=AM")
	if err != nil {
		return err
	}
	for _, p := range strings.Split(strings.TrimRight(staged, "\x00"), "\x00") {
		if p == "" {
			continue
		}
		if info, err := os.Lstat(filepath.Join(m.appDir(id), p)); err == nil && info.Size() > maxHistoryFile {
			if _, err := m.git(ctx, id, "rm", "--cached", "-q", "--", p); err != nil {
				return err
			}
		}
	}
	if _, err := m.git(ctx, id, "diff", "--cached", "--quiet"); err == nil {
		return nil // nothing changed
	}
	_, err = m.git(ctx, id, "commit", "-q", "--no-verify", "-m", message)
	return err
}

// Version is one commit touching a file (or the site).
type Version struct {
	Commit  string    `json:"commit"`
	At      time.Time `json:"at"`
	Message string    `json:"message"`
}

func (m *Manager) hasHistory(id string) bool {
	_, err := os.Stat(filepath.Join(m.historyDir(id), "HEAD"))
	return err == nil
}

// History lists the versions of a file, newest first; an empty path lists
// every change to the site.
func (m *Manager) History(ctx context.Context, id, rel string, limit int) ([]Version, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	if !m.hasHistory(id) {
		return []Version{}, nil
	}
	if limit <= 0 || limit > 500 {
		limit = 100
	}
	args := []string{"log", "--format=%H%x1f%at%x1f%s", "-n", strconv.Itoa(limit)}
	if rel != "" {
		clean, err := historyPath(rel)
		if err != nil {
			return nil, err
		}
		args = append(args, "--", clean)
	}
	out, err := m.git(ctx, id, args...)
	if err != nil {
		if strings.Contains(err.Error(), "does not have any commits") {
			return []Version{}, nil
		}
		return nil, err
	}
	return parseVersions(out), nil
}

func parseVersions(out string) []Version {
	vs := []Version{}
	for _, line := range strings.Split(strings.TrimSpace(out), "\n") {
		f := strings.SplitN(line, "\x1f", 3)
		if len(f) != 3 {
			continue
		}
		sec, _ := strconv.ParseInt(f[1], 10, 64)
		vs = append(vs, Version{Commit: f[0], At: time.Unix(sec, 0).UTC(), Message: f[2]})
	}
	return vs
}

// historyPath validates a path inside the app: relative, no "..", and never
// a secret - the same rule that keeps .env out of history keeps it out of here.
func historyPath(rel string) (string, error) {
	// A leading "/" is the app root, as in every other file call - so
	// "/etc/passwd" means app/etc/passwd, never the host's.
	clean := filepath.ToSlash(filepath.Clean(strings.TrimLeft(rel, "/")))
	if clean == "." || clean == "" || strings.HasPrefix(clean, "../") || clean == ".." {
		return "", fmt.Errorf("invalid path")
	}
	base := filepath.Base(clean)
	if base == ".env" || strings.HasPrefix(base, ".env.") {
		return "", fmt.Errorf("secrets are not kept in history")
	}
	return clean, nil
}

// FileAt returns a file's contents at one commit.
func (m *Manager) FileAt(ctx context.Context, id, rev, rel string) (string, error) {
	if err := ValidID(id); err != nil {
		return "", err
	}
	if !revisionRe.MatchString(rev) {
		return "", fmt.Errorf("invalid revision")
	}
	clean, err := historyPath(rel)
	if err != nil {
		return "", err
	}
	out, err := m.git(ctx, id, "show", rev+":"+clean)
	if err != nil {
		return "", fmt.Errorf("that file is not in that version")
	}
	return out, nil
}

// Binned is a deleted file and the version to restore it from.
type Binned struct {
	Path      string    `json:"path"`
	DeletedAt time.Time `json:"deletedAt"`
	From      string    `json:"from"` // the last commit that had it
}

// Deleted is the bin: files deleted and not since re-created, newest first.
func (m *Manager) Deleted(ctx context.Context, id string, limit int) ([]Binned, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	bin := []Binned{}
	if !m.hasHistory(id) {
		return bin, nil
	}
	if limit <= 0 || limit > 500 {
		limit = 100
	}
	out, err := m.git(ctx, id, "log", "--diff-filter=D", "--name-only", "--format=@@%H%x1f%P%x1f%at", "-n", "1000")
	if err != nil {
		return bin, nil
	}
	seen := map[string]bool{}
	var cur Binned
	for _, line := range strings.Split(out, "\n") {
		if strings.HasPrefix(line, "@@") {
			f := strings.SplitN(strings.TrimPrefix(line, "@@"), "\x1f", 3)
			if len(f) != 3 {
				continue
			}
			sec, _ := strconv.ParseInt(f[2], 10, 64)
			parent := strings.Fields(f[1])
			cur = Binned{DeletedAt: time.Unix(sec, 0).UTC()}
			if len(parent) > 0 {
				cur.From = parent[0]
			}
			continue
		}
		p := strings.TrimSpace(line)
		if p == "" || seen[p] || cur.From == "" {
			continue
		}
		seen[p] = true
		if _, err := os.Lstat(filepath.Join(m.appDir(id), p)); err == nil {
			continue // re-created since
		}
		bin = append(bin, Binned{Path: p, DeletedAt: cur.DeletedAt, From: cur.From})
		if len(bin) >= limit {
			break
		}
	}
	return bin, nil
}

// Restore puts a file back as it was at rev. The restore is a new version of
// its own: history is only ever added to, never rewritten.
func (m *Manager) Restore(ctx context.Context, id, rev, rel string) error {
	content, err := m.FileAt(ctx, id, rev, rel)
	if err != nil {
		return err
	}
	clean, _ := historyPath(rel)
	if _, _, err := m.writeFileIf(ctx, id, clean, content, "", ""); err != nil {
		return err
	}
	m.record(ctx, id, fmt.Sprintf("restore %s from %s", clean, rev[:7]))
	return nil
}
