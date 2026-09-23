//go:build linux

package sites

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"

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
	return unix.Openat2(rootFD, rel, &unix.OpenHow{
		Flags:   unix.O_PATH | unix.O_DIRECTORY | unix.O_CLOEXEC,
		Resolve: beneath,
	})
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
