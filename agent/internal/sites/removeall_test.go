package sites

import (
	"os"
	"path/filepath"
	"testing"
)

// Deletes inside a site never reach outside it, whatever links the site's
// own code has planted.
func TestRemoveAllBeneathNeverLeavesTheSite(t *testing.T) {
	root := t.TempDir()
	outside := t.TempDir()
	os.WriteFile(filepath.Join(outside, "keep.txt"), []byte("another site's file"), 0o644)
	os.MkdirAll(filepath.Join(outside, "victim"), 0o755)
	os.WriteFile(filepath.Join(outside, "victim", "data.txt"), []byte("x"), 0o644)
	kept := func() {
		t.Helper()
		for _, p := range []string{"keep.txt", "victim/data.txt"} {
			if _, err := os.Stat(filepath.Join(outside, p)); err != nil {
				t.Fatalf("a delete inside the site removed %s outside it", p)
			}
		}
	}

	// A whole tree, with a link inside it pointing out: the tree goes, the
	// link is unlinked, what it pointed at stays.
	os.MkdirAll(filepath.Join(root, "a/b/c"), 0o755)
	os.WriteFile(filepath.Join(root, "a/b/c/f.txt"), []byte("f"), 0o644)
	os.Symlink(outside, filepath.Join(root, "a/b/out"))
	if err := removeAllBeneath(root, "/a"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Lstat(filepath.Join(root, "a")); err == nil {
		t.Fatal("the folder is still there")
	}
	kept()

	// The target itself a link: only the link goes.
	os.Symlink(filepath.Join(outside, "victim"), filepath.Join(root, "link"))
	if err := removeAllBeneath(root, "/link"); err != nil {
		t.Fatal(err)
	}
	kept()

	// A PARENT that is a link (what a swap mid-operation produces): refused.
	os.Symlink(outside, filepath.Join(root, "parent"))
	if err := removeAllBeneath(root, "/parent/victim"); err == nil {
		t.Fatal("a delete through a linked parent was allowed")
	}
	kept()

	if err := removeAllBeneath(root, "/"); err == nil {
		t.Fatal("the site root was deleted")
	}
	if err := removeAllBeneath(root, "/missing"); err != nil {
		t.Fatalf("a missing path: %v", err)
	}
}

func TestRemoveEmptyDirOnlyRemovesAnEmptyFolder(t *testing.T) {
	root := t.TempDir()
	os.MkdirAll(filepath.Join(root, "full"), 0o755)
	os.WriteFile(filepath.Join(root, "full/f.txt"), []byte("f"), 0o644)
	os.MkdirAll(filepath.Join(root, "empty"), 0o755)
	outside := t.TempDir()
	os.MkdirAll(filepath.Join(outside, "gone"), 0o755)
	os.Symlink(outside, filepath.Join(root, "link"))

	if err := removeEmptyDirBeneath(root, "/full"); err == nil {
		t.Fatal("a folder with a file in it was removed")
	}
	if err := removeEmptyDirBeneath(root, "/empty"); err != nil {
		t.Fatal(err)
	}
	if err := removeEmptyDirBeneath(root, "/link/gone"); err == nil {
		t.Fatal("rmdir went through a link")
	}
	if _, err := os.Stat(filepath.Join(outside, "gone")); err != nil {
		t.Fatal("a folder outside the site was removed")
	}
}
