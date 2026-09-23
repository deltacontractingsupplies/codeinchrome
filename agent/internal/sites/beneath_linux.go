//go:build linux

package sites

import (
	"errors"
	"fmt"
	"math/rand/v2"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"time"

	"golang.org/x/sys/unix"
)

// Creating things inside a site, with the KERNEL enforcing that nothing
// passes through a symlink or leaves the site's directory.
//
// The agent runs as root and the site's own code can create symlinks. A
// check-then-write in user space leaves a window in which a folder can be
// swapped for a link to /etc; openat2 with RESOLVE_BENEATH|RESOLVE_NO_SYMLINKS
// closes it: the lookup itself refuses. Folders are made one component at a
// time from a handle obtained that way, and files are opened O_EXCL|O_NOFOLLOW
// relative to it.

const beneath = unix.RESOLVE_BENEATH | unix.RESOLVE_NO_SYMLINKS | unix.RESOLVE_NO_MAGICLINKS

func openDirBeneath(rootFD int, rel string) (int, error) {
	if rel == "" || rel == "." {
		return unix.Dup(rootFD)
	}
	return openat2Beneath(rootFD, rel, unix.O_PATH|unix.O_DIRECTORY|unix.O_CLOEXEC)
}

// noOpenat2 is set the first time the kernel - or a seccomp filter -
// answers ENOSYS. systemd's RestrictSUIDSGID= (on in the agent's unit) does
// exactly that: seccomp cannot inspect openat2's flags, so systemd refuses the
// whole call with ENOSYS to send programs back to openat. Every test passed
// outside systemd and site creation then failed on the hosts. A test may set
// it to exercise the walk.
var noOpenat2 atomic.Bool

// openat2Beneath opens rel under rootFD with no symlink and no escape on the
// way: openat2(RESOLVE_BENEATH|RESOLVE_NO_SYMLINKS) where it is allowed, and
// otherwise the same guarantee built from openat - one component at a time,
// each relative to the handle of the folder before it, each O_NOFOLLOW. A
// component that is a symlink fails (ELOOP, or ENOTDIR for a folder opened
// O_PATH|O_DIRECTORY|O_NOFOLLOW); ".." cannot occur, the path is Clean()ed
// from "/". Nothing is resolved by path from outside the site, so no swap
// between two steps can redirect the walk: each step starts from a handle.
func openat2Beneath(rootFD int, rel string, flags int) (int, error) {
	if !noOpenat2.Load() {
		fd, err := unix.Openat2(rootFD, rel, &unix.OpenHow{Flags: uint64(flags), Resolve: beneath})
		if !errors.Is(err, unix.ENOSYS) {
			return fd, err
		}
		noOpenat2.Store(true)
	}
	parts := splitRel(rel)
	if len(parts) == 0 {
		return unix.Openat(rootFD, ".", flags|unix.O_NOFOLLOW, 0)
	}
	dir, err := unix.Dup(rootFD)
	if err != nil {
		return -1, err
	}
	for _, part := range parts[:len(parts)-1] {
		next, err := unix.Openat(dir, part, unix.O_PATH|unix.O_DIRECTORY|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0)
		unix.Close(dir)
		if err != nil {
			if errors.Is(err, unix.ENOTDIR) {
				return -1, unix.ELOOP // a symlink (or a file) where a folder should be
			}
			return -1, err
		}
		dir = next
	}
	defer unix.Close(dir)
	return unix.Openat(dir, parts[len(parts)-1], flags|unix.O_NOFOLLOW, 0)
}

func splitRel(rel string) []string {
	var parts []string
	for _, p := range strings.Split(filepath.ToSlash(filepath.Clean("/"+rel)), "/") {
		if p != "" {
			parts = append(parts, p)
		}
	}
	return parts
}

// mkdirBeneath creates rel (and any missing parents) under root, refusing if
// any component is, or becomes, a symlink or not a folder.
func mkdirBeneath(root, rel string) error {
	rootFD, err := unix.Open(root, unix.O_PATH|unix.O_DIRECTORY|unix.O_CLOEXEC, 0)
	if err != nil {
		return err
	}
	defer unix.Close(rootFD)
	done := ""
	for _, part := range splitRel(rel) {
		parent, err := openDirBeneath(rootFD, done)
		if err != nil {
			return fmt.Errorf("%w: %s", errOutside, done)
		}
		err = unix.Mkdirat(parent, part, 0o750)
		unix.Close(parent)
		if err != nil && !errors.Is(err, unix.EEXIST) {
			return err
		}
		done = filepath.Join(done, part)
		// Whatever is there now must be a real folder reachable without links.
		check, err := openDirBeneath(rootFD, done)
		if err != nil {
			return fmt.Errorf("%w: %s is not a plain folder", errOutside, done)
		}
		if os.Geteuid() == 0 {
			_ = unix.Fchownat(check, "", wwwUID, wwwGID, unix.AT_EMPTY_PATH)
		}
		unix.Close(check)
	}
	return nil
}

// createBeneath creates a NEW file at rel under root (never an existing one,
// never through a link) and returns it open for writing.
func createBeneath(root, rel string, perm os.FileMode) (*os.File, error) {
	dir, base := filepath.Split(filepath.Clean("/" + rel))
	if base == "" {
		return nil, fmt.Errorf("invalid path")
	}
	if err := mkdirBeneath(root, dir); err != nil {
		return nil, err
	}
	rootFD, err := unix.Open(root, unix.O_PATH|unix.O_DIRECTORY|unix.O_CLOEXEC, 0)
	if err != nil {
		return nil, err
	}
	defer unix.Close(rootFD)
	parent, err := openDirBeneath(rootFD, strings.TrimPrefix(dir, "/"))
	if err != nil {
		return nil, fmt.Errorf("%w: %s", errOutside, dir)
	}
	defer unix.Close(parent)
	fd, err := unix.Openat(parent, base, unix.O_CREAT|unix.O_EXCL|unix.O_WRONLY|unix.O_NOFOLLOW|unix.O_CLOEXEC, uint32(perm))
	if err != nil {
		if errors.Is(err, unix.EEXIST) {
			return nil, fmt.Errorf("%s already exists", strings.TrimPrefix(rel, "/"))
		}
		return nil, err
	}
	if os.Geteuid() == 0 {
		_ = unix.Fchown(fd, wwwUID, wwwGID)
	}
	return os.NewFile(uintptr(fd), filepath.Join(root, rel)), nil
}

func openRootFD(root string) (int, error) {
	return unix.Open(root, unix.O_PATH|unix.O_DIRECTORY|unix.O_CLOEXEC, 0)
}

// openBeneath opens an EXISTING file or folder at rel under root for reading.
// No component - the file itself included - may be a symlink, so a path that
// resolve() approved cannot be swapped for a link to a host file before the
// open happens: the kernel refuses the lookup.
func openBeneath(root, rel string) (*os.File, error) {
	rootFD, err := openRootFD(root)
	if err != nil {
		return nil, err
	}
	defer unix.Close(rootFD)
	clean := strings.TrimPrefix(filepath.Clean("/"+rel), "/")
	if clean == "" {
		clean = "."
	}
	fd, err := openat2Beneath(rootFD, clean, unix.O_RDONLY|unix.O_CLOEXEC|unix.O_NOFOLLOW)
	if err != nil {
		if errors.Is(err, unix.ENOENT) {
			return nil, os.ErrNotExist
		}
		// The errno is kept: "outside the site" for an EAGAIN or an EACCES
		// sends whoever reads it looking for an attack that is not there.
		return nil, fmt.Errorf("%w: %s (%v)", errOutside, clean, err)
	}
	return os.NewFile(uintptr(fd), filepath.Join(root, clean)), nil
}

// replaceBeneath writes a file at rel under root atomically: a temporary file
// is created beside it, filled, and renamed over it - every step relative to a
// handle on the parent folder that was opened with no symlink on the way. A
// folder swapped for a link after the check makes the open fail rather than
// the write land outside the site.
func replaceBeneath(root, rel string, perm os.FileMode, fill func(*os.File) error) error {
	dir, base := filepath.Split(filepath.Clean("/" + rel))
	if base == "" {
		return fmt.Errorf("invalid path")
	}
	if err := mkdirBeneath(root, dir); err != nil {
		return err
	}
	rootFD, err := openRootFD(root)
	if err != nil {
		return err
	}
	defer unix.Close(rootFD)
	parent, err := openDirBeneath(rootFD, strings.TrimPrefix(dir, "/"))
	if err != nil {
		return fmt.Errorf("%w: %s", errOutside, dir)
	}
	defer unix.Close(parent)

	var tmp string
	var fd int
	for i := 0; ; i++ {
		tmp = fmt.Sprintf(".cic-write-%d-%d", os.Getpid(), rand.Uint64())
		fd, err = unix.Openat(parent, tmp, unix.O_CREAT|unix.O_EXCL|unix.O_WRONLY|unix.O_NOFOLLOW|unix.O_CLOEXEC, uint32(perm))
		if err == nil {
			break
		}
		if !errors.Is(err, unix.EEXIST) || i > 10 {
			return err
		}
	}
	f := os.NewFile(uintptr(fd), tmp)
	done := false
	defer func() {
		if !done {
			_ = unix.Unlinkat(parent, tmp, 0)
		}
	}()
	if os.Geteuid() == 0 {
		if err := unix.Fchown(fd, wwwUID, wwwGID); err != nil {
			f.Close()
			return err
		}
	}
	_ = unix.Fchmod(fd, uint32(perm))
	if err := fill(f); err != nil {
		f.Close()
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	if err := unix.Renameat(parent, tmp, parent, base); err != nil {
		return err
	}
	done = true
	return nil
}

// renameBeneath moves from to to, both under root, never replacing anything
// at the destination (RENAME_NOREPLACE: the kernel checks, so there is no
// window between "nothing is there" and the move). The entry itself is moved
// as it is - a symlink moves as a link.
func renameBeneath(root, from, to string) error {
	fdir, fbase := filepath.Split(filepath.Clean("/" + from))
	tdir, tbase := filepath.Split(filepath.Clean("/" + to))
	if fbase == "" || tbase == "" {
		return fmt.Errorf("invalid path")
	}
	if err := mkdirBeneath(root, tdir); err != nil {
		return err
	}
	rootFD, err := openRootFD(root)
	if err != nil {
		return err
	}
	defer unix.Close(rootFD)
	fp, err := openDirBeneath(rootFD, strings.TrimPrefix(fdir, "/"))
	if err != nil {
		return fmt.Errorf("%w: %s", errOutside, fdir)
	}
	defer unix.Close(fp)
	tp, err := openDirBeneath(rootFD, strings.TrimPrefix(tdir, "/"))
	if err != nil {
		return fmt.Errorf("%w: %s", errOutside, tdir)
	}
	defer unix.Close(tp)
	if err := unix.Renameat2(fp, fbase, tp, tbase, unix.RENAME_NOREPLACE); err != nil {
		if errors.Is(err, unix.EEXIST) {
			return errExists
		}
		return err
	}
	return nil
}

// statAt lstats name inside the open directory dir, by handle rather than by
// path, so no swap on the way can make it describe some other file.
func statAt(dir *os.File, name string) (os.FileInfo, error) {
	var st unix.Stat_t
	if err := unix.Fstatat(int(dir.Fd()), name, &st, unix.AT_SYMLINK_NOFOLLOW); err != nil {
		return nil, err
	}
	return statInfo{name: name, st: st}, nil
}

type statInfo struct {
	name string
	st   unix.Stat_t
}

func (s statInfo) Name() string { return s.name }
func (s statInfo) Size() int64  { return s.st.Size }
func (s statInfo) Mode() os.FileMode {
	m := os.FileMode(s.st.Mode & 0o777)
	switch s.st.Mode & unix.S_IFMT {
	case unix.S_IFDIR:
		m |= os.ModeDir
	case unix.S_IFLNK:
		m |= os.ModeSymlink
	case unix.S_IFREG:
	default:
		m |= os.ModeIrregular
	}
	return m
}
func (s statInfo) ModTime() time.Time { return time.Unix(s.st.Mtim.Sec, s.st.Mtim.Nsec) }
func (s statInfo) IsDir() bool        { return s.Mode().IsDir() }
func (s statInfo) Sys() any           { return &s.st }
