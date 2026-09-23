package sites

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"unicode/utf8"
)

// The file API the browser panel drives.
//
// Every path in here is attacker-controlled, so the whole design is built
// around one guarantee: a resolved path that is not inside the site's own
// app directory is refused, and the check is made on the path AFTER symlinks
// are resolved rather than on the string the caller sent.
//
// String checks alone are not enough. `strings.Contains(p, "..")` rejects
// harmless names, misses URL-encoded and unicode variants, and says nothing at
// all about a symlink the customer created pointing at /etc/shadow. Resolving
// first and comparing the result is the only check that covers all three.

const (
	// A single file the panel can open. Large enough for any source file,
	// small enough that a request cannot exhaust the host's memory.
	MaxFileSize = 2 << 20 // 2 MiB

	// Listing a directory is cheap, but a directory with a million entries is
	// not. This is a limit on the answer, and the answer says when it is hit.
	maxEntries = 2000
)

// chownAsWWW hands a path to the container's www-data, which is required for
// the site's own PHP to read what the panel wrote.
//
// Only root can chown to another uid. The agent runs as root in production, so
// a failure there is real and must not be swallowed. Under a non-root process
// - a test, or a developer running the agent directly - the file already
// belongs to the only user involved, so there is nothing to do and nothing to
// report. Ignoring the error unconditionally would have hidden a genuine
// production failure; skipping it unconditionally would have broken the tests.
func chownAsWWW(path string) error {
	if os.Geteuid() != 0 {
		return nil
	}
	return os.Chown(path, wwwUID, wwwGID)
}

type FileEntry struct {
	Name  string `json:"name"`
	Path  string `json:"path"`
	Dir   bool   `json:"dir"`
	Size  int64  `json:"size"`
	Mode  string `json:"mode"`
	MTime int64  `json:"mtime"`
}

type Listing struct {
	Path      string      `json:"path"`
	Entries   []FileEntry `json:"entries"`
	Truncated bool        `json:"truncated"`
}

// resolve turns a caller-supplied relative path into an absolute one that is
// PROVEN to be inside the site's app directory.
//
// The returned path is safe to open. A returned error means the caller asked
// for something outside the site, and the reason is deliberately vague to the
// caller - telling someone whether /etc/shadow exists is itself information.
func (m *Manager) resolve(id, rel string) (string, error) {
	if err := ValidID(id); err != nil {
		return "", err
	}

	root := m.appDir(id)

	// EvalSymlinks on the root as well: on macOS and in some container setups
	// the root itself may be reached through a symlink, and comparing a
	// resolved child against an unresolved parent never matches.
	realRoot, err := filepath.EvalSymlinks(root)
	if err != nil {
		return "", fmt.Errorf("site %q has no app directory", id)
	}

	if strings.ContainsRune(rel, 0) {
		return "", fmt.Errorf("invalid path")
	}

	// Clean collapses ".." segments; joining to the root keeps it relative.
	candidate := filepath.Join(realRoot, filepath.Clean("/"+rel))

	// Resolve as much of the path as EXISTS, then re-append the rest.
	//
	// A file being created has no leaf yet, and `app/Models/Thing.php` in a
	// site with no Models directory has no parent either - so resolving only
	// the immediate parent refuses every write into a new nested directory.
	// Walking up to the nearest existing ancestor handles any depth, and still
	// catches the case that matters: an existing ancestor that is a symlink
	// pointing out of the site. The unresolved remainder is already
	// Clean()ed, so it cannot reintroduce a "..".
	resolved, err := filepath.EvalSymlinks(candidate)
	if err != nil {
		existing := candidate
		var remainder []string
		for {
			parent := filepath.Dir(existing)
			if parent == existing {
				return "", fmt.Errorf("no such path")
			}
			remainder = append([]string{filepath.Base(existing)}, remainder...)
			existing = parent

			if realExisting, rerr := filepath.EvalSymlinks(existing); rerr == nil {
				resolved = filepath.Join(append([]string{realExisting}, remainder...)...)
				break
			}
		}
	}

	if resolved != realRoot && !strings.HasPrefix(resolved, realRoot+string(os.PathSeparator)) {
		// The one message for every kind of escape: traversal, absolute path,
		// symlink out of the tree. Distinguishing them tells a prober which
		// technique got closest.
		return "", fmt.Errorf("path is outside the site")
	}

	return resolved, nil
}

// relativeTo renders a resolved path back into the site-relative form the
// panel uses, so nothing ever hands the host's real layout to the browser.
func (m *Manager) relativeTo(id, abs string) string {
	root := m.appDir(id)
	if realRoot, err := filepath.EvalSymlinks(root); err == nil {
		root = realRoot
	}
	rel, err := filepath.Rel(root, abs)
	if err != nil {
		return "/"
	}
	if rel == "." {
		return "/"
	}
	return "/" + filepath.ToSlash(rel)
}

func (m *Manager) ListFiles(_ context.Context, id, rel string) (Listing, error) {
	abs, err := m.resolve(id, rel)
	if err != nil {
		return Listing{}, err
	}

	root, err := m.realRoot(id)
	if err != nil {
		return Listing{}, err
	}
	// Listed through a kernel-checked handle: a folder swapped for a symlink
	// after resolve() cannot turn this into a listing of someone else's tree.
	dir, err := openBeneath(root, strings.TrimPrefix(abs, root))
	if err != nil {
		return Listing{}, fmt.Errorf("no such path")
	}
	defer dir.Close()
	info, err := dir.Stat()
	if err != nil {
		return Listing{}, fmt.Errorf("no such path")
	}
	if !info.IsDir() {
		return Listing{}, fmt.Errorf("not a directory")
	}

	dirEntries, err := dir.ReadDir(-1)
	if err != nil {
		return Listing{}, fmt.Errorf("cannot read that directory")
	}

	out := Listing{Path: m.relativeTo(id, abs), Entries: []FileEntry{}}
	for _, e := range dirEntries {
		if len(out.Entries) >= maxEntries {
			out.Truncated = true
			break
		}
		// Relative to the open directory, not by path, for the same reason.
		fi, err := statAt(dir, e.Name())
		if err != nil {
			continue
		}
		out.Entries = append(out.Entries, FileEntry{
			Name:  e.Name(),
			Path:  m.relativeTo(id, filepath.Join(abs, e.Name())),
			Dir:   e.IsDir(),
			Size:  fi.Size(),
			Mode:  fi.Mode().Perm().String(),
			MTime: fi.ModTime().Unix(),
		})
	}

	// Directories first, then names. A stable order means the panel does not
	// reshuffle itself between reads.
	sort.Slice(out.Entries, func(i, j int) bool {
		if out.Entries[i].Dir != out.Entries[j].Dir {
			return out.Entries[i].Dir
		}
		return out.Entries[i].Name < out.Entries[j].Name
	})

	return out, nil
}

// ErrConflict means the file changed since the caller last read it.
var ErrConflict = errors.New("conflict")

// Revision identifies exact file contents. Content-addressed rather than a
// modification time: mtimes have coarse resolution on some filesystems and are
// preserved by `cp -a`, so two different versions can share one.
func Revision(content []byte) string {
	sum := sha256.Sum256(content)
	return hex.EncodeToString(sum[:])
}

// ReadFile returns the contents of one file, refusing anything too large to
// edit sensibly rather than streaming it into the caller's memory.
func (m *Manager) ReadFile(ctx context.Context, id, rel string) (string, error) {
	content, _, err := m.ReadFileRevision(ctx, id, rel)
	return content, err
}

// ReadFileRevision is ReadFile plus the revision the caller must present to
// write the file back without overwriting someone else's change.
func (m *Manager) ReadFileRevision(_ context.Context, id, rel string) (string, string, error) {
	abs, err := m.resolve(id, rel)
	if err != nil {
		return "", "", err
	}

	root, err := m.realRoot(id)
	if err != nil {
		return "", "", err
	}
	// Opened in the kernel with no symlink on the way, so the path resolve()
	// approved cannot be swapped for a link to a host file in between.
	f, err := openBeneath(root, strings.TrimPrefix(abs, root))
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return "", "", fmt.Errorf("no such file")
		}
		return "", "", fmt.Errorf("cannot read that file")
	}
	defer f.Close()

	info, err := f.Stat()
	if err != nil {
		return "", "", fmt.Errorf("no such file")
	}
	if info.IsDir() {
		return "", "", fmt.Errorf("that is a directory")
	}
	if info.Size() > MaxFileSize {
		return "", "", fmt.Errorf("file is %d bytes; the editor limit is %d", info.Size(), MaxFileSize)
	}

	// LimitReader as well as the stat check: the file could grow between the
	// two, and a stat is not a lock.
	b, err := io.ReadAll(io.LimitReader(f, MaxFileSize+1))
	if err != nil {
		return "", "", fmt.Errorf("cannot read that file")
	}
	if len(b) > MaxFileSize {
		return "", "", fmt.Errorf("file grew past the editor limit while being read")
	}

	// Refuse binary files rather than return them as text.
	//
	// The content travels as a JSON string, and encoding/json replaces every
	// invalid UTF-8 sequence with U+FFFD. An image or a font would therefore
	// arrive already mangled, and the first save from the editor would write
	// the mangled version back over the original - silent, permanent
	// corruption of a file nobody meant to change.
	if IsBinary(b) {
		return "", "", fmt.Errorf("binary file: not editable as text")
	}

	return string(b), Revision(b), nil
}

// IsBinary reports whether content cannot round-trip through a JSON string
// unchanged: it contains a NUL byte, or it is not valid UTF-8.
func IsBinary(b []byte) bool {
	for _, c := range b {
		if c == 0 {
			return true
		}
	}
	return !utf8.Valid(b)
}

// WriteFile replaces a file's contents unconditionally.
func (m *Manager) WriteFile(ctx context.Context, id, rel, content string) error {
	_, err := m.WriteFileIf(ctx, id, rel, content, "")
	return err
}

// WriteFileIf replaces a file's contents only if it is still what the caller
// last saw, and returns the new revision.
//
// expect:
//
//	""        write unconditionally
//	"absent"  the file must not exist yet (creating a "new" file must not
//	          quietly replace one that someone else just made)
//	<sha256>  the file must currently have exactly this revision
//
// A customer and their AI agent can have the same file open at once. Without
// this, whichever saved second silently erased the other's work.
//
// Written to a temporary file in the same directory and renamed, so a failure
// part-way leaves the previous version intact rather than a truncated one.
func (m *Manager) WriteFileIf(ctx context.Context, id, rel, content, expect string) (string, error) {
	return m.writeFileIf(ctx, id, rel, content, expect, "save "+rel)
}

// writeFileIf is WriteFileIf with the history message to record ("" records
// nothing: the caller records its own, as a restore does).
func (m *Manager) writeFileIf(ctx context.Context, id, rel, content, expect, note string) (string, error) {
	rev, err := m.writeLocked(id, rel, content, expect)
	if err == nil && note != "" {
		m.record(ctx, id, note)
	}
	return rev, err
}

func (m *Manager) writeLocked(id, rel, content, expect string) (string, error) {
	// Held across check-and-rename, or two writers could both pass the check.
	m.mu.Lock()
	defer m.mu.Unlock()

	if len(content) > MaxFileSize {
		return "", fmt.Errorf("content is %d bytes; the limit is %d", len(content), MaxFileSize)
	}

	abs, err := m.resolve(id, rel)
	if err != nil {
		return "", err
	}
	root, err := m.realRoot(id)
	if err != nil {
		return "", err
	}
	// Everything below goes through the *Beneath helpers, which re-check in
	// the kernel what resolve() checked in user space: the site's own code
	// can replace a folder with a symlink between the two, and the agent is
	// root.
	relAbs := strings.TrimPrefix(abs, root)

	var current []byte
	exists := false
	if f, oerr := openBeneath(root, relAbs); oerr == nil {
		info, serr := f.Stat()
		if serr == nil && info.IsDir() {
			f.Close()
			return "", fmt.Errorf("that is a directory")
		}
		if expect != "" {
			current, oerr = io.ReadAll(io.LimitReader(f, MaxFileSize+1))
			exists = oerr == nil
		}
		f.Close()
	} else if !errors.Is(oerr, os.ErrNotExist) {
		return "", fmt.Errorf("cannot write there")
	}

	if expect != "" {
		switch {
		case expect == "absent" && exists:
			return "", ErrConflict
		case expect != "absent" && !exists:
			return "", ErrConflict
		case expect != "absent" && Revision(current) != expect:
			return "", ErrConflict
		}
	}

	// Owned by www-data, or the site's own PHP cannot read what the panel just
	// wrote - and 0640 so it is not world-readable on the host.
	if err := replaceBeneath(root, relAbs, 0o640, func(f *os.File) error {
		_, werr := f.WriteString(content)
		return werr
	}); err != nil {
		return "", fmt.Errorf("cannot write there")
	}
	return Revision([]byte(content)), nil
}

// DeleteFile removes a file or an empty directory.
//
// Deliberately NOT recursive. A recursive delete behind a browser API is one
// mistaken path away from destroying a customer's whole application, and the
// panel has no use for it that a sequence of explicit deletes cannot cover.
func (m *Manager) DeleteFile(ctx context.Context, id, rel string) error {
	abs, err := m.resolve(id, rel)
	if err != nil {
		return err
	}

	root := m.appDir(id)
	if realRoot, err := filepath.EvalSymlinks(root); err == nil {
		root = realRoot
	}
	if abs == root {
		return fmt.Errorf("refusing to delete the site root")
	}

	if err := os.Remove(abs); err != nil {
		if info, serr := os.Stat(abs); serr == nil && info.IsDir() {
			return fmt.Errorf("directory is not empty")
		}
		return fmt.Errorf("no such file")
	}

	// Into the bin: the last version stays in history, restorable.
	m.record(ctx, id, "delete "+m.relativeTo(id, abs))
	return nil
}
