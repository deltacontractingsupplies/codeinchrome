//go:build !linux

package sites

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"syscall"
)

// The same rule as beneath_linux.go - never through a symlink, never out of
// the site - checked component by component. Used where openat2 does not
// exist (the developer's machine, for tests); the hosts run the Linux version.

func mkdirBeneath(root, rel string) error {
	cur := root
	for _, part := range strings.Split(filepath.ToSlash(filepath.Clean("/"+rel)), "/") {
		if part == "" {
			continue
		}
		cur = filepath.Join(cur, part)
		info, err := os.Lstat(cur)
		if os.IsNotExist(err) {
			if err := os.Mkdir(cur, 0o750); err != nil {
				return err
			}
			_ = chownAsWWW(cur)
			continue
		}
		if err != nil {
			return err
		}
		if info.Mode()&os.ModeSymlink != 0 || !info.IsDir() {
			return fmt.Errorf("%w: %s is not a plain folder", errOutside, strings.TrimPrefix(cur, root))
		}
	}
	return nil
}

func createBeneath(root, rel string, perm os.FileMode) (*os.File, error) {
	dir, base := filepath.Split(filepath.Clean("/" + rel))
	if base == "" {
		return nil, fmt.Errorf("invalid path")
	}
	if err := mkdirBeneath(root, dir); err != nil {
		return nil, err
	}
	f, err := os.OpenFile(filepath.Join(root, dir, base), os.O_CREATE|os.O_EXCL|os.O_WRONLY|syscall.O_NOFOLLOW, perm)
	if err != nil {
		if os.IsExist(err) {
			return nil, fmt.Errorf("%s already exists", strings.TrimPrefix(rel, "/"))
		}
		return nil, err
	}
	_ = chownAsWWW(f.Name())
	return f, nil
}

// plainParents refuses rel if any folder on the way to it is a symlink.
func plainParents(root, rel string) error {
	cur := root
	parts := strings.Split(filepath.ToSlash(filepath.Clean("/"+rel)), "/")
	for _, part := range parts[:len(parts)-1] {
		if part == "" {
			continue
		}
		cur = filepath.Join(cur, part)
		info, err := os.Lstat(cur)
		if err != nil {
			return err
		}
		if info.Mode()&os.ModeSymlink != 0 || !info.IsDir() {
			return fmt.Errorf("%w: %s is not a plain folder", errOutside, strings.TrimPrefix(cur, root))
		}
	}
	return nil
}

func openBeneath(root, rel string) (*os.File, error) {
	if err := plainParents(root, rel); err != nil {
		if os.IsNotExist(err) {
			return nil, os.ErrNotExist
		}
		return nil, err
	}
	f, err := os.OpenFile(filepath.Join(root, filepath.Clean("/"+rel)), os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		if os.IsNotExist(err) {
			return nil, os.ErrNotExist
		}
		return nil, fmt.Errorf("%w: %s", errOutside, rel)
	}
	return f, nil
}

func replaceBeneath(root, rel string, perm os.FileMode, fill func(*os.File) error) error {
	dir, base := filepath.Split(filepath.Clean("/" + rel))
	if base == "" {
		return fmt.Errorf("invalid path")
	}
	if err := mkdirBeneath(root, dir); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Join(root, dir), ".cic-write-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	_ = chownAsWWW(tmp.Name())
	_ = tmp.Chmod(perm)
	if err := fill(tmp); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), filepath.Join(root, dir, base))
}

func renameBeneath(root, from, to string) error {
	tdir, _ := filepath.Split(filepath.Clean("/" + to))
	if err := mkdirBeneath(root, tdir); err != nil {
		return err
	}
	if err := plainParents(root, from); err != nil {
		return err
	}
	dst := filepath.Join(root, filepath.Clean("/"+to))
	if _, err := os.Lstat(dst); err == nil {
		return errExists
	}
	return os.Rename(filepath.Join(root, filepath.Clean("/"+from)), dst)
}

func statAt(dir *os.File, name string) (os.FileInfo, error) {
	return os.Lstat(filepath.Join(dir.Name(), name))
}
