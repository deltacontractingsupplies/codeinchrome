package sites

import (
	"archive/zip"
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

// The rest of a hosting panel's file manager: folders, rename and move, copy,
// upload and download (binary too), folder delete into the bin, search, zip
// and unzip.
//
// The agent runs as root, so the rule every walk below keeps is: NEVER follow
// a symlink. A site can hold a link to /etc/shadow; reading through it would
// hand host files to the customer. Walks skip symlinks outright, and every
// single path goes through resolve(), which proves where it really points.

const (
	MaxUploadSize   = 32 << 20  // matches the image's upload_max_filesize
	maxTreeBytes    = 200 << 20 // copy, zip, unzip: total bytes
	maxTreeEntries  = 20000     // ... and entries (zip bombs, runaway copies)
	maxSearchFile   = 1 << 20   // larger files are not searched
	maxSearchResult = 500
)

var errOutside = errors.New("path is outside the site")

// Never searched, zipped or copied into history's reach: dependencies,
// caches, and the site's secrets.
func skippedDir(rel string) bool {
	switch strings.SplitN(rel, "/", 2)[0] {
	case "vendor", "node_modules", "storage", ".git":
		return true
	}
	return rel == "bootstrap/cache"
}

func isSecretName(name string) bool { return name == ".env" || strings.HasPrefix(name, ".env.") }

func (m *Manager) realRoot(id string) (string, error) {
	return filepath.EvalSymlinks(m.appDir(id))
}

// chownTree gives www-data everything from base down to abs, so the site's
// PHP can use what the panel creates.
func chownPath(root, abs string) {
	for p := abs; p != root && strings.HasPrefix(p, root); p = filepath.Dir(p) {
		_ = chownAsWWW(p)
	}
}

func (m *Manager) Mkdir(_ context.Context, id, rel string) error {
	abs, err := m.resolve(id, rel)
	if err != nil {
		return err
	}
	root, _ := m.realRoot(id)
	return mkdirBeneath(root, strings.TrimPrefix(abs, root))
}

// Rename moves a file or folder. The destination must not exist: a move
// never silently replaces something.
func (m *Manager) Rename(ctx context.Context, id, from, to string) error {
	src, err := m.resolveLink(id, from)
	if err != nil {
		return err
	}
	dst, err := m.resolve(id, to)
	if err != nil {
		return err
	}
	root, _ := m.realRoot(id)
	if src == root || dst == root {
		return fmt.Errorf("the site root cannot be moved")
	}
	if src == dst {
		return fmt.Errorf("source and destination are the same")
	}
	if strings.HasPrefix(dst, src+string(os.PathSeparator)) {
		return fmt.Errorf("a folder cannot be moved into itself")
	}
	if _, err := os.Lstat(dst); err == nil {
		return fmt.Errorf("something already exists at the destination")
	}
	if err := mkdirBeneath(root, strings.TrimPrefix(filepath.Dir(dst), root)); err != nil {
		return err
	}
	if err := os.Rename(src, dst); err != nil {
		return fmt.Errorf("cannot move that")
	}
	m.record(ctx, id, fmt.Sprintf("move %s to %s", m.relativeTo(id, src), m.relativeTo(id, dst)))
	return nil
}

// resolveLink is resolve() for operations on the entry ITSELF (move, delete):
// a symlink inside the site is moved as a link, never through to its target.
func (m *Manager) resolveLink(id, rel string) (string, error) {
	parent, err := m.resolve(id, filepath.Dir(filepath.Clean("/"+rel)))
	if err != nil {
		return "", err
	}
	abs := filepath.Join(parent, filepath.Base(filepath.Clean("/"+rel)))
	if _, err := os.Lstat(abs); err != nil {
		return "", fmt.Errorf("no such path")
	}
	return abs, nil
}

// Copy copies a file or folder to a destination that must not exist.
func (m *Manager) Copy(ctx context.Context, id, from, to string) error {
	src, err := m.resolve(id, from)
	if err != nil {
		return err
	}
	dst, err := m.resolve(id, to)
	if err != nil {
		return err
	}
	if _, err := os.Lstat(dst); err == nil {
		return fmt.Errorf("something already exists at the destination")
	}
	if strings.HasPrefix(dst, src+string(os.PathSeparator)) {
		return fmt.Errorf("a folder cannot be copied into itself")
	}
	root, _ := m.realRoot(id)
	dstRel := strings.TrimPrefix(dst, root)
	budget := treeBudget{}
	created := false
	err = filepath.WalkDir(src, func(p string, d fs.DirEntry, werr error) error {
		if werr != nil {
			return werr
		}
		if d.Type()&fs.ModeSymlink != 0 {
			return nil // never followed, never copied
		}
		rel := filepath.Join(dstRel, strings.TrimPrefix(p, src))
		if d.IsDir() {
			created = true
			return mkdirBeneath(root, rel)
		}
		info, err := d.Info()
		if err != nil {
			return err
		}
		if err := budget.add(info.Size()); err != nil {
			return err
		}
		created = true
		return copyFileBeneath(root, p, rel)
	})
	if err != nil {
		if created {
			m.removeBeneath(root, dst)
		}
		return fmt.Errorf("copy failed: %v", err)
	}
	m.record(ctx, id, fmt.Sprintf("copy %s to %s", m.relativeTo(id, src), m.relativeTo(id, dst)))
	return nil
}

type treeBudget struct {
	bytes   int64
	entries int
}

func (b *treeBudget) add(size int64) error {
	b.bytes += size
	b.entries++
	if b.bytes > maxTreeBytes || b.entries > maxTreeEntries {
		return fmt.Errorf("too large: at most %d MB and %d files", maxTreeBytes>>20, maxTreeEntries)
	}
	return nil
}

// copyFileBeneath copies src (a regular file, already inside the site) to a
// NEW file at rel under root, through the kernel-enforced helpers.
func copyFileBeneath(root, src, rel string) error {
	in, err := os.OpenFile(src, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := createBeneath(root, rel, 0o640)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		return err
	}
	return out.Close()
}

// removeBeneath undoes a partial copy or unzip - only if the path is still a
// real folder inside the site (never through a link planted since).
func (m *Manager) removeBeneath(root, abs string) {
	if info, err := os.Lstat(abs); err == nil && info.IsDir() && strings.HasPrefix(abs, root+string(os.PathSeparator)) {
		_ = os.RemoveAll(abs)
	}
}

// Upload writes any file - binary included - up to MaxUploadSize. Written to
// a temporary file and renamed, so a failed upload leaves nothing behind.
func (m *Manager) Upload(ctx context.Context, id, rel string, body io.Reader) error {
	abs, err := m.resolve(id, rel)
	if err != nil {
		return err
	}
	if info, err := os.Stat(abs); err == nil && info.IsDir() {
		return fmt.Errorf("that is a folder")
	}
	dir := filepath.Dir(abs)
	root, _ := m.realRoot(id)
	if err := mkdirBeneath(root, strings.TrimPrefix(dir, root)); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(dir, ".cic-upload-*")
	if err != nil {
		return fmt.Errorf("cannot write there")
	}
	defer os.Remove(tmp.Name())
	n, err := io.Copy(tmp, io.LimitReader(body, MaxUploadSize+1))
	tmp.Close()
	if err != nil {
		return fmt.Errorf("upload interrupted")
	}
	if n > MaxUploadSize {
		return fmt.Errorf("the file is larger than %d MB", MaxUploadSize>>20)
	}
	_ = chownAsWWW(tmp.Name())
	_ = os.Chmod(tmp.Name(), 0o640)
	if err := os.Rename(tmp.Name(), abs); err != nil {
		return fmt.Errorf("cannot write there")
	}
	m.record(ctx, id, "upload "+m.relativeTo(id, abs))
	return nil
}

// Download streams a file's bytes and returns its name.
func (m *Manager) Download(_ context.Context, id, rel string, w io.Writer) (string, error) {
	abs, err := m.resolve(id, rel)
	if err != nil {
		return "", err
	}
	f, err := os.Open(abs)
	if err != nil {
		return "", fmt.Errorf("no such file")
	}
	defer f.Close()
	if info, err := f.Stat(); err != nil || info.IsDir() {
		return "", fmt.Errorf("that is a folder; zip it first")
	}
	if _, err := io.Copy(w, f); err != nil {
		return "", err
	}
	return filepath.Base(abs), nil
}

// DeleteTree removes a folder and everything in it - only with confirm, never
// the site root. Its files go to the bin: the last version of each stays in
// history (dependencies and caches, which history excludes, do not).
func (m *Manager) DeleteTree(ctx context.Context, id, rel string, confirm bool) error {
	if !confirm {
		return ErrNeedsConfirm
	}
	abs, err := m.resolveLink(id, rel)
	if err != nil {
		return err
	}
	root, _ := m.realRoot(id)
	if abs == root {
		return fmt.Errorf("refusing to delete the site root")
	}
	if err := os.RemoveAll(abs); err != nil {
		return fmt.Errorf("could not delete everything: %v", err)
	}
	m.record(ctx, id, "delete folder "+m.relativeTo(id, abs))
	return nil
}

// Hit is one line matching a search.
type Hit struct {
	Path string `json:"path"`
	Line int    `json:"line"`
	Text string `json:"text"`
}

// Search finds text in the site's files, case-insensitively. Dependencies,
// caches, secrets, binary and very large files are not searched.
func (m *Manager) Search(ctx context.Context, id, query string, limit int) ([]Hit, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	query = strings.ToLower(strings.TrimSpace(query))
	if len(query) < 2 || len(query) > 200 {
		return nil, fmt.Errorf("search for 2 to 200 characters")
	}
	if limit <= 0 || limit > maxSearchResult {
		limit = 200
	}
	root, err := m.realRoot(id)
	if err != nil {
		return nil, fmt.Errorf("site %q has no app directory", id)
	}
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()

	hits := []Hit{}
	scanned := 0
	errDone := errors.New("done")
	err = filepath.WalkDir(root, func(p string, d fs.DirEntry, werr error) error {
		if werr != nil || ctx.Err() != nil {
			return errDone
		}
		rel := strings.TrimPrefix(strings.TrimPrefix(p, root), "/")
		if d.Type()&fs.ModeSymlink != 0 {
			return nil
		}
		if d.IsDir() {
			if rel != "" && skippedDir(rel) {
				return fs.SkipDir
			}
			return nil
		}
		if isSecretName(d.Name()) {
			return nil
		}
		if info, err := d.Info(); err != nil || info.Size() > maxSearchFile {
			return nil
		}
		if scanned++; scanned > maxTreeEntries {
			return errDone
		}
		b, err := os.ReadFile(p)
		if err != nil || IsBinary(b) {
			return nil
		}
		sc := bufio.NewScanner(strings.NewReader(string(b)))
		sc.Buffer(make([]byte, 0, 64<<10), maxSearchFile)
		for n := 1; sc.Scan(); n++ {
			if strings.Contains(strings.ToLower(sc.Text()), query) {
				text := strings.TrimSpace(sc.Text())
				if len(text) > 200 {
					text = text[:200]
				}
				hits = append(hits, Hit{Path: "/" + rel, Line: n, Text: text})
				if len(hits) >= limit {
					return errDone
				}
			}
		}
		return nil
	})
	if err != nil && !errors.Is(err, errDone) {
		return hits, err
	}
	return hits, nil
}

// Zip archives a file or folder into a .zip inside the site. Secrets and
// symlinks are left out.
func (m *Manager) Zip(ctx context.Context, id, from, to string) error {
	src, err := m.resolve(id, from)
	if err != nil {
		return err
	}
	dst, err := m.resolve(id, to)
	if err != nil {
		return err
	}
	if !strings.HasSuffix(dst, ".zip") {
		return fmt.Errorf("the archive name must end in .zip")
	}
	if _, err := os.Lstat(dst); err == nil {
		return fmt.Errorf("something already exists at %s", to)
	}
	tmp, err := os.CreateTemp(filepath.Dir(dst), ".cic-zip-*")
	if err != nil {
		return fmt.Errorf("cannot write there")
	}
	defer os.Remove(tmp.Name())
	zw := zip.NewWriter(tmp)
	base := filepath.Dir(src)
	budget := treeBudget{}
	werr := filepath.WalkDir(src, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.Type()&fs.ModeSymlink != 0 || d.IsDir() || isSecretName(d.Name()) || p == tmp.Name() {
			return nil
		}
		info, err := d.Info()
		if err != nil {
			return err
		}
		if err := budget.add(info.Size()); err != nil {
			return err
		}
		w, err := zw.Create(strings.TrimPrefix(strings.TrimPrefix(p, base), "/"))
		if err != nil {
			return err
		}
		f, err := os.Open(p)
		if err != nil {
			return err
		}
		defer f.Close()
		_, err = io.Copy(w, f)
		return err
	})
	if cerr := zw.Close(); werr == nil {
		werr = cerr
	}
	tmp.Close()
	if werr != nil {
		return fmt.Errorf("zip failed: %v", werr)
	}
	_ = chownAsWWW(tmp.Name())
	_ = os.Chmod(tmp.Name(), 0o640)
	if err := os.Rename(tmp.Name(), dst); err != nil {
		return fmt.Errorf("cannot write there")
	}
	return nil
}

// Unzip extracts an archive into a folder of the site. Every entry is checked
// to land inside that folder ("zip slip"), symlink entries are refused, and
// size and count are capped (zip bombs). Nothing is overwritten.
func (m *Manager) Unzip(ctx context.Context, id, archive, into string) error {
	src, err := m.resolve(id, archive)
	if err != nil {
		return err
	}
	dstRoot, err := m.resolve(id, into)
	if err != nil {
		return err
	}
	zr, err := zip.OpenReader(src)
	if err != nil {
		return fmt.Errorf("not a readable zip archive")
	}
	defer zr.Close()

	// Check the whole archive before writing anything.
	var total uint64
	if len(zr.File) > maxTreeEntries {
		return fmt.Errorf("too many files in the archive")
	}
	for _, f := range zr.File {
		name := filepath.Clean("/" + f.Name)
		target := filepath.Join(dstRoot, name)
		if strings.Contains(f.Name, "..") || filepath.IsAbs(f.Name) || !strings.HasPrefix(target, dstRoot+string(os.PathSeparator)) {
			return fmt.Errorf("%w: the archive entry %q would land outside the destination", errOutside, f.Name)
		}
		if f.Mode()&fs.ModeSymlink != 0 {
			return fmt.Errorf("the archive contains a symlink (%q); refused", f.Name)
		}
		if total += f.UncompressedSize64; total > maxTreeBytes {
			return fmt.Errorf("the archive expands to more than %d MB", maxTreeBytes>>20)
		}
		if _, err := os.Lstat(target); err == nil && !f.FileInfo().IsDir() {
			return fmt.Errorf("%s already exists; unzip into an empty folder", strings.TrimPrefix(name, "/"))
		}
	}

	root, _ := m.realRoot(id)
	intoRel := strings.TrimPrefix(dstRoot, root)
	for _, f := range zr.File {
		rel := filepath.Join(intoRel, filepath.Clean("/"+f.Name))
		if f.FileInfo().IsDir() {
			if err := mkdirBeneath(root, rel); err != nil {
				return err
			}
			continue
		}
		rc, err := f.Open()
		if err != nil {
			return err
		}
		// Kernel-enforced: no symlink anywhere on the way, nothing replaced.
		out, err := createBeneath(root, rel, 0o640)
		if err != nil {
			rc.Close()
			return err
		}
		// Bounded by what the header claimed, so a lying header cannot write more.
		_, err = io.Copy(out, io.LimitReader(rc, int64(f.UncompressedSize64)+1))
		rc.Close()
		out.Close()
		if err != nil {
			return err
		}
	}
	m.record(ctx, id, fmt.Sprintf("unzip %s into %s", m.relativeTo(id, src), m.relativeTo(id, dstRoot)))
	return nil
}
