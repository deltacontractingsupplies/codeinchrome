package sites

import (
	"errors"
	"io/fs"
	"path/filepath"
	"sort"
	"strings"
)

// walkBeneath is filepath.WalkDir for trees the agent (root) walks inside a
// site, with every directory opened through openBeneath.
//
// WalkDir descends by path: between listing a folder and opening one of its
// subfolders, the site's own code can swap that subfolder for a symlink, and
// the walk would then list - and hand its caller - another tenant's tree or
// the host's. Opening each directory by a fresh, kernel-checked resolution
// from the site root (no symlink anywhere on the way) makes that swap an
// error instead of a detour. Callers still open FILES with openBeneath.
//
// start is an absolute path under root. fn is called as by WalkDir, in
// lexical order; fs.SkipDir from a directory skips it.
func walkBeneath(root, start string, fn fs.WalkDirFunc) error {
	f, err := openBeneath(root, strings.TrimPrefix(start, root))
	if err != nil {
		return fn(start, nil, err)
	}
	info, err := f.Stat()
	f.Close()
	if err != nil {
		return fn(start, nil, err)
	}
	d := fs.FileInfoToDirEntry(info)
	if err := fn(start, d, nil); err != nil || !d.IsDir() {
		if errors.Is(err, fs.SkipDir) {
			return nil
		}
		return err
	}
	err = walkDirBeneath(root, start, d, fn)
	if errors.Is(err, fs.SkipDir) {
		return nil
	}
	return err
}

func walkDirBeneath(root, dir string, d fs.DirEntry, fn fs.WalkDirFunc) error {
	f, err := openBeneath(root, strings.TrimPrefix(dir, root))
	if err != nil {
		return fn(dir, d, err)
	}
	entries, err := f.ReadDir(-1)
	f.Close()
	if err != nil {
		return fn(dir, d, err)
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].Name() < entries[j].Name() })
	for _, e := range entries {
		p := filepath.Join(dir, e.Name())
		err := fn(p, e, nil)
		if err != nil {
			if errors.Is(err, fs.SkipDir) && e.IsDir() {
				continue
			}
			return err
		}
		if e.IsDir() {
			if err := walkDirBeneath(root, p, e, fn); err != nil {
				return err
			}
		}
	}
	return nil
}
