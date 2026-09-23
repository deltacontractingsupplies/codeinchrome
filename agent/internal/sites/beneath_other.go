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
