package sites

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
)

// SelfTest proves, at startup and inside the agent's own sandbox, that the
// kernel-checked file operations every site depends on actually work here:
// create a folder, write a file, read it back, rename it, list the folder,
// and refuse a symlink. A sandbox or kernel that breaks them - systemd's
// seccomp answering openat2 with ENOSYS did, and every site creation failed
// while every test outside systemd passed - must stop the agent from
// starting, where the deploy's own check sees it, not surface later as a
// customer's failed save.
func (m *Manager) SelfTest() (mode string, err error) {
	scratch, err := os.MkdirTemp(m.cfg.Root, ".cic-selftest-")
	if err != nil {
		return "", fmt.Errorf("scratch folder: %w", err)
	}
	defer os.RemoveAll(scratch)
	root, err := filepath.EvalSymlinks(scratch)
	if err != nil {
		return "", err
	}

	if err := replaceBeneath(root, "/a/b/file.txt", 0o640, func(f *os.File) error {
		_, err := f.WriteString("ok")
		return err
	}); err != nil {
		return "", fmt.Errorf("write: %w", err)
	}
	f, err := openBeneath(root, "/a/b/file.txt")
	if err != nil {
		return "", fmt.Errorf("read: %w", err)
	}
	b, _ := io.ReadAll(f)
	f.Close()
	if string(b) != "ok" {
		return "", fmt.Errorf("read back %q", b)
	}
	if err := renameBeneath(root, "/a/b/file.txt", "/a/moved.txt"); err != nil {
		return "", fmt.Errorf("rename: %w", err)
	}
	var seen int
	if err := walkBeneath(root, root, func(string, os.DirEntry, error) error { seen++; return nil }); err != nil || seen != 4 {
		return "", fmt.Errorf("walk: saw %d entries, %v", seen, err)
	}
	if err := os.Symlink("/etc", filepath.Join(root, "link")); err != nil {
		return "", err
	}
	if f, err := openBeneath(root, "/link/hostname"); err == nil {
		f.Close()
		return "", fmt.Errorf("a path through a symlink was NOT refused")
	}
	return beneathMode(), nil
}
