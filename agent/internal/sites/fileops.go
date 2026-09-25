package sites

import (
	"archive/zip"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
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

var errExists = errors.New("something already exists at the destination")

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
	if err := refuseSecretIntoPublic(root, src, dst); err != nil {
		return err
	}
	if err := renameBeneath(root, strings.TrimPrefix(src, root), strings.TrimPrefix(dst, root)); err != nil {
		if errors.Is(err, errExists) || errors.Is(err, errOutside) {
			return err
		}
		return fmt.Errorf("cannot move that")
	}
	if bad := scanMoved(ctx, root, dst); bad != nil {
		_ = renameBeneath(root, strings.TrimPrefix(dst, root), strings.TrimPrefix(src, root))
		return bad
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
	if err := refuseSecretIntoPublic(root, src, dst); err != nil {
		return err
	}
	dstRel := strings.TrimPrefix(dst, root)
	budget := treeBudget{}
	created := false
	err = walkBeneath(root, src, func(p string, d fs.DirEntry, werr error) error {
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
			m.removeCopied(root, dst)
		}
		return fmt.Errorf("copy failed: %v", err)
	}
	if bad := scanMoved(ctx, root, dst); bad != nil {
		m.removeCopied(root, dst)
		return bad
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
	// The whole path re-resolved in the kernel, not just the last component:
	// O_NOFOLLOW alone would still follow a folder on the way that was
	// swapped for a symlink after the walk listed it.
	in, err := openBeneath(root, strings.TrimPrefix(src, root))
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

// removeCopied undoes a copy: a folder as removeBeneath does, a single file
// through the no-follow removal (a refused copy of one file was left behind).
func (m *Manager) removeCopied(root, abs string) {
	if info, err := os.Lstat(abs); err == nil && info.Mode().IsRegular() {
		_ = removeBeneath(root, strings.TrimPrefix(abs, root))
		return
	}
	m.removeBeneath(root, abs)
}

// Upload writes any file - binary included - up to MaxUploadSize. Written to
// a temporary file and renamed, so a failed upload leaves nothing behind.
func (m *Manager) Upload(ctx context.Context, id, rel string, body io.Reader) error {
	abs, err := m.resolve(id, rel)
	if err != nil {
		return err
	}
	root, err := m.realRoot(id)
	if err != nil {
		return err
	}
	rel = strings.TrimPrefix(abs, root)
	if f, err := openBeneath(root, rel); err == nil {
		info, serr := f.Stat()
		f.Close()
		if serr == nil && info.IsDir() {
			return fmt.Errorf("that is a folder")
		}
	}
	var n int64
	err = replaceBeneath(root, rel, 0o640, func(f *os.File) error {
		var cerr error
		n, cerr = io.Copy(f, io.LimitReader(body, MaxUploadSize+1))
		if cerr != nil {
			return fmt.Errorf("upload interrupted")
		}
		if n > MaxUploadSize {
			return fmt.Errorf("the file is larger than %d MB", MaxUploadSize>>20)
		}
		return nil
	})
	if err != nil {
		if n > MaxUploadSize || strings.HasPrefix(err.Error(), "upload interrupted") {
			return err
		}
		return fmt.Errorf("cannot write there")
	}
	// Scanned the moment it lands; refused and removed if it is malware or
	// obfuscated PHP - and if the scan cannot run, it is not kept unscanned.
	if err := scanFile(ctx, root, rel); err != nil {
		_ = removeBeneath(root, rel)
		if _, bad := IsMalware(err); bad {
			return err
		}
		return fmt.Errorf("the upload could not be checked for malware, so it was not kept: %v", err)
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
	root, err := m.realRoot(id)
	if err != nil {
		return "", err
	}
	f, err := openBeneath(root, strings.TrimPrefix(abs, root))
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

// readBeneath reads up to limit bytes of the file at abs, a path under root,
// through openBeneath: a file the walk saw can be swapped for a symlink before
// it is read, and the read must refuse rather than follow it.
func readBeneath(root, abs string, limit int64) ([]byte, error) {
	f, err := openBeneath(root, strings.TrimPrefix(abs, root))
	if err != nil {
		return nil, err
	}
	defer f.Close()
	return io.ReadAll(io.LimitReader(f, limit))
}

// Search finds text in the site's files, case-insensitively: the editor's
// search box. Dependencies, caches, secrets, binary and very large files are
// not searched.
func (m *Manager) Search(ctx context.Context, id, query string, limit int) ([]Hit, error) {
	query = strings.TrimSpace(query)
	if len(query) < 2 || len(query) > 200 {
		return nil, fmt.Errorf("search for 2 to 200 characters")
	}
	res, err := m.Grep(ctx, id, GrepOptions{Pattern: query, IgnoreCase: true, Limit: limit})
	for i := range res.Hits {
		res.Hits[i].Text = strings.TrimSpace(res.Hits[i].Text)
		if len(res.Hits[i].Text) > 200 {
			res.Hits[i].Text = res.Hits[i].Text[:200]
		}
	}
	return res.Hits, err
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
	root, err := m.realRoot(id)
	if err != nil {
		return err
	}
	base := filepath.Dir(src)
	budget := treeBudget{}
	var werr error
	err = replaceBeneath(root, strings.TrimPrefix(dst, root), 0o640, func(out *os.File) error {
		zw := zip.NewWriter(out)
		werr = walkBeneath(root, src, func(p string, d fs.DirEntry, err error) error {
			if err != nil {
				return err
			}
			// .cic-write-* is this archive itself, still being written.
			if d.Type()&fs.ModeSymlink != 0 || d.IsDir() || isSecretName(d.Name()) || strings.HasPrefix(d.Name(), ".cic-write-") {
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
			f, err := openBeneath(root, strings.TrimPrefix(p, root))
			if err != nil {
				return err
			}
			defer f.Close()
			_, err = io.Copy(w, io.LimitReader(f, info.Size()))
			return err
		})
		if cerr := zw.Close(); werr == nil {
			werr = cerr
		}
		return werr
	})
	if werr != nil {
		return fmt.Errorf("zip failed: %v", werr)
	}
	if err != nil {
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
	root, err := m.realRoot(id)
	if err != nil {
		return err
	}
	// Opened by a kernel-checked resolution, not zip.OpenReader(path): the
	// archive (or a folder on the way to it) could be swapped for a symlink
	// after resolve() approved it.
	af, err := openBeneath(root, strings.TrimPrefix(src, root))
	if err != nil {
		return fmt.Errorf("not a readable zip archive")
	}
	defer af.Close()
	ainfo, err := af.Stat()
	if err != nil || !ainfo.Mode().IsRegular() {
		return fmt.Errorf("not a readable zip archive")
	}
	zr, err := zip.NewReader(af, ainfo.Size())
	if err != nil {
		return fmt.Errorf("not a readable zip archive")
	}

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

	intoRel := strings.TrimPrefix(dstRoot, root)
	var written []string
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
		if err == nil {
			written = append(written, rel)
		}
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
	// Every extracted file scanned as one batch: if any is malware or
	// obfuscated PHP - or the scan cannot run - none of the archive is kept.
	var scanErr error
	var paths []string
	for _, rel := range written {
		if bad := scanWrittenPHP(root, rel); bad != nil {
			scanErr = bad
			break
		}
		paths = append(paths, filepath.Join(root, rel))
	}
	if scanErr == nil {
		if found, err := clamScan(ctx, root, paths...); err != nil {
			scanErr = fmt.Errorf("the archive could not be checked for malware, so it was not unpacked: %v", err)
		} else {
			scanErr = refusal(found)
		}
	}
	if scanErr != nil {
		for _, rel := range written {
			_ = removeBeneath(root, rel)
		}
		return scanErr
	}
	m.record(ctx, id, fmt.Sprintf("unzip %s into %s", m.relativeTo(id, src), m.relativeTo(id, dstRoot)))
	return nil
}

// refuseSecretIntoPublic stops a .env file - the site's keys and database
// password - from being moved or copied into public/, the only directory the
// web serves. The edge refuses any path that LOOKS like a secret (caddy.go's
// secretPath), but a move can give it any name at all: public/config.txt
// would be served. A folder being moved or copied in is refused if it holds one.
func refuseSecretIntoPublic(root, src, dst string) error {
	pub := filepath.Join(root, "public")
	if dst != pub && !strings.HasPrefix(dst, pub+string(os.PathSeparator)) {
		return nil
	}
	found := false
	_ = walkBeneath(root, src, func(p string, d fs.DirEntry, err error) error {
		if err == nil && !d.IsDir() && isEnvFile(d.Name()) {
			found = true
			return fs.SkipAll
		}
		return nil
	})
	if found {
		return fmt.Errorf("a .env file cannot go into public/: everything there is served to the world")
	}
	return nil
}

// isEnvFile: .env, .env.backup, .env.production, production.env, ...
func isEnvFile(name string) bool {
	n := strings.ToLower(name)
	return n == ".env" || strings.HasPrefix(n, ".env.") || strings.HasSuffix(n, ".env")
}

// quickOpenSkip are folders Quick Open does not list: dependencies, caches
// and generated files, which VS Code's files.exclude and search.exclude hide
// by default too.
var quickOpenSkip = map[string]bool{
	"vendor": true, "node_modules": true, ".git": true, "storage/framework": true, "bootstrap/cache": true, "public/build": true,
}

const maxQuickOpen = 20000

// Paths lists every file in the site for Quick Open (⌘P), site-relative and
// sorted, skipping dependencies and caches. Truncated at maxQuickOpen.
func (m *Manager) Paths(_ context.Context, id string) ([]string, bool, error) {
	root, err := m.realRoot(id)
	if err != nil {
		return nil, false, err
	}
	var out []string
	truncated := false
	err = walkBeneath(root, root, func(p string, d fs.DirEntry, werr error) error {
		if werr != nil || d == nil {
			return nil
		}
		rel := strings.TrimPrefix(strings.TrimPrefix(p, root), "/")
		if d.IsDir() {
			if quickOpenSkip[rel] {
				return fs.SkipDir
			}
			return nil
		}
		if d.Type()&fs.ModeSymlink != 0 {
			return nil
		}
		if len(out) >= maxQuickOpen {
			truncated = true
			return fs.SkipAll
		}
		out = append(out, "/"+rel)
		return nil
	})
	if errors.Is(err, fs.SkipAll) {
		err = nil
	}
	return out, truncated, err
}

// scanMoved checks what a move or copy put at dst. Saving code as notes.txt
// and renaming it to public/x.php skipped every check until the six-hourly
// scan (the second security audit, 2026-09-25). The PHP rules always run;
// ClamAV too when the tree is small enough to keep a move quick (the
// scheduled scan covers the rest). A ClamAV that cannot run does not block
// the move: the files were already on the site.
func scanMoved(ctx context.Context, root, dst string) error {
	var paths []string
	var bytes int64
	var found *ErrMalware
	_ = walkBeneath(root, dst, func(p string, d fs.DirEntry, werr error) error {
		if werr != nil || d.IsDir() || d.Type()&fs.ModeSymlink != 0 {
			return nil
		}
		rel := strings.TrimPrefix(p, root)
		if bad := scanWrittenPHP(root, rel); bad != nil {
			found = bad
			return fs.SkipAll
		}
		if info, err := d.Info(); err == nil {
			bytes += info.Size()
		}
		paths = append(paths, p)
		return nil
	})
	if found != nil {
		return found
	}
	if len(paths) == 0 || len(paths) > 200 || bytes > 64<<20 {
		return nil
	}
	hits, err := clamScan(ctx, root, paths...)
	if err != nil {
		return nil
	}
	return refusal(hits)
}
