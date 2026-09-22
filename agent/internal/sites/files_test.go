package sites

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// newTestManager builds a manager over a real temporary directory with one
// site, plus a secret OUTSIDE the site that nothing may ever reach.
func newTestManager(t *testing.T) (*Manager, string, string) {
	t.Helper()

	base := t.TempDir()
	root := filepath.Join(base, "customers")
	caddy := filepath.Join(base, "caddy")

	for _, d := range []string{filepath.Join(root, "demo", "vol", "app", "public"), caddy} {
		if err := os.MkdirAll(d, 0o750); err != nil {
			t.Fatal(err)
		}
	}

	// The thing an attacker is trying to reach. Outside the site, inside the
	// same temp tree so every escape route has a real target to find.
	secret := filepath.Join(base, "secret.txt")
	if err := os.WriteFile(secret, []byte("THE-SECRET"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "demo", "vol", "app", "public", "index.php"), []byte("<?php echo 1;"), 0o640); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "demo", "vol", "app", ".env"), []byte("APP_KEY=base64:x"), 0o640); err != nil {
		t.Fatal(err)
	}

	m, err := New(Config{Root: root, CaddyDir: caddy, HostID: "test-host"})
	if err != nil {
		t.Fatal(err)
	}

	return m, base, secret
}

// Every one of these is a real technique. A single one getting through is a
// read of arbitrary host files through a browser panel.
func TestResolveRefusesEveryEscape(t *testing.T) {
	m, base, _ := newTestManager(t)

	escapes := map[string]string{
		"../../../etc/passwd":                    "plain traversal",
		"..":                                     "the parent itself",
		"../":                                    "parent with a slash",
		"/etc/passwd":                            "absolute path",
		"public/../../../../etc/passwd":          "traversal after a valid segment",
		"public/../..":                           "traversal landing above the root",
		"./../../secret.txt":                     "leading dot then traversal",
		"....//....//secret.txt":                 "doubled dots",
		"public/./../../secret.txt":              "dot segments mixed in",
		"\x00/etc/passwd":                        "null byte prefix",
		"public/\x00../../secret.txt":            "embedded null byte",
		"..\\..\\secret.txt":                     "windows separators",
		strings.Repeat("../", 40) + "etc/passwd": "deep traversal",
	}

	for path, why := range escapes {
		got, err := m.resolve("demo", path)
		if err != nil {
			continue // refused, which is correct
		}
		// Anything that resolved must still be inside the site.
		appRoot, _ := filepath.EvalSymlinks(m.appDir("demo"))
		if !strings.HasPrefix(got, appRoot) {
			t.Errorf("resolve(%q) ESCAPED to %q (%s)", path, got, why)
		}
		if strings.HasPrefix(got, base) && !strings.HasPrefix(got, appRoot) {
			t.Errorf("resolve(%q) reached %q outside the site (%s)", path, got, why)
		}
	}
}

// A symlink is the escape that no amount of string checking catches: the path
// contains no "..", is perfectly well formed, and points straight out.
func TestResolveRefusesASymlinkOutOfTheTree(t *testing.T) {
	m, _, secret := newTestManager(t)

	link := filepath.Join(m.appDir("demo"), "public", "escape.txt")
	if err := os.Symlink(secret, link); err != nil {
		t.Skipf("cannot create symlinks here: %v", err)
	}

	if _, err := m.resolve("demo", "public/escape.txt"); err == nil {
		t.Fatal("resolve followed a symlink out of the site")
	}

	if content, err := m.ReadFile(context.Background(), "demo", "public/escape.txt"); err == nil {
		t.Fatalf("ReadFile followed a symlink out of the site and returned %q", content)
	}
}

// A symlinked DIRECTORY in the middle of the path is the subtler version.
func TestResolveRefusesASymlinkedParentDirectory(t *testing.T) {
	m, base, _ := newTestManager(t)

	outside := filepath.Join(base, "outside")
	if err := os.MkdirAll(outside, 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(outside, "loot.txt"), []byte("LOOT"), 0o640); err != nil {
		t.Fatal(err)
	}

	link := filepath.Join(m.appDir("demo"), "out")
	if err := os.Symlink(outside, link); err != nil {
		t.Skipf("cannot create symlinks here: %v", err)
	}

	if _, err := m.ReadFile(context.Background(), "demo", "out/loot.txt"); err == nil {
		t.Fatal("read a file through a symlinked parent directory")
	}
	// And writing through it must not create a file outside the site either.
	if err := m.WriteFile(context.Background(), "demo", "out/planted.txt", "x"); err == nil {
		if _, serr := os.Stat(filepath.Join(outside, "planted.txt")); serr == nil {
			t.Fatal("wrote a file OUTSIDE the site through a symlinked parent")
		}
	}
}

func TestReadAndWriteStayInsideTheSite(t *testing.T) {
	ctx := context.Background()
	m, _, _ := newTestManager(t)

	if got, err := m.ReadFile(ctx, "demo", "public/index.php"); err != nil || !strings.Contains(got, "echo 1") {
		t.Fatalf("could not read a legitimate file: %q %v", got, err)
	}

	if err := m.WriteFile(ctx, "demo", "app/Models/Thing.php", "<?php class Thing {}"); err != nil {
		t.Fatalf("could not write a legitimate file: %v", err)
	}
	got, err := m.ReadFile(ctx, "demo", "app/Models/Thing.php")
	if err != nil || got != "<?php class Thing {}" {
		t.Fatalf("wrote and read back %q, %v", got, err)
	}

	// The write must not have landed world-readable on the host.
	info, err := os.Stat(filepath.Join(m.appDir("demo"), "app", "Models", "Thing.php"))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm()&0o007 != 0 {
		t.Errorf("written file is world-accessible: %v", info.Mode().Perm())
	}
}

func TestWriteRefusesOversizedContent(t *testing.T) {
	m, _, _ := newTestManager(t)

	err := m.WriteFile(context.Background(), "demo", "big.txt", strings.Repeat("a", MaxFileSize+1))
	if err == nil {
		t.Fatal("accepted content over the size limit")
	}
	if _, serr := os.Stat(filepath.Join(m.appDir("demo"), "big.txt")); serr == nil {
		t.Fatal("the rejected write still created a file")
	}
}

func TestReadRefusesAnOversizedFile(t *testing.T) {
	m, _, _ := newTestManager(t)

	big := filepath.Join(m.appDir("demo"), "big.bin")
	if err := os.WriteFile(big, make([]byte, MaxFileSize+10), 0o640); err != nil {
		t.Fatal(err)
	}

	if _, err := m.ReadFile(context.Background(), "demo", "big.bin"); err == nil {
		t.Fatal("read a file over the size limit")
	}
}

func TestListFilesIsRootedAndOrdered(t *testing.T) {
	m, _, _ := newTestManager(t)

	listing, err := m.ListFiles(context.Background(), "demo", "/")
	if err != nil {
		t.Fatal(err)
	}
	if listing.Path != "/" {
		t.Errorf("listing path is %q, want /", listing.Path)
	}
	for _, e := range listing.Entries {
		if !strings.HasPrefix(e.Path, "/") {
			t.Errorf("entry path %q is not site-relative", e.Path)
		}
		// The host's real layout must never reach the browser.
		if strings.Contains(e.Path, "/srv/") || strings.Contains(e.Path, m.dir("demo")) {
			t.Errorf("entry path %q leaks the host filesystem layout", e.Path)
		}
	}
	if len(listing.Entries) > 0 && !listing.Entries[0].Dir {
		for _, e := range listing.Entries {
			if e.Dir {
				t.Error("directories are not sorted before files")
				break
			}
		}
	}
}

func TestDeleteIsNotRecursiveAndProtectsTheRoot(t *testing.T) {
	ctx := context.Background()
	m, _, _ := newTestManager(t)

	if err := m.DeleteFile(ctx, "demo", "/"); err == nil {
		t.Fatal("deleted the site root")
	}
	if _, err := os.Stat(m.appDir("demo")); err != nil {
		t.Fatal("the site root was removed")
	}

	// A non-empty directory must be refused rather than silently emptied.
	if err := m.DeleteFile(ctx, "demo", "public"); err == nil {
		t.Fatal("deleted a non-empty directory")
	}
	if _, err := os.Stat(filepath.Join(m.appDir("demo"), "public", "index.php")); err != nil {
		t.Fatal("a file inside the directory was removed")
	}

	if err := m.DeleteFile(ctx, "demo", "public/index.php"); err != nil {
		t.Fatalf("could not delete a legitimate file: %v", err)
	}
}

func TestFileApiRejectsABadSiteId(t *testing.T) {
	ctx := context.Background()
	m, _, _ := newTestManager(t)

	for _, id := range []string{"../demo", "demo/../..", "Upper", "a", ""} {
		if _, err := m.ListFiles(ctx, id, "/"); err == nil {
			t.Errorf("ListFiles accepted the invalid site id %q", id)
		}
		if _, err := m.ReadFile(ctx, id, "x"); err == nil {
			t.Errorf("ReadFile accepted the invalid site id %q", id)
		}
	}
}

// A binary file must be refused, not returned as text: JSON encoding would
// replace its invalid bytes with U+FFFD, and saving it back would corrupt it.
func TestReadRefusesBinaryFiles(t *testing.T) {
	m, _, _ := newTestManager(t)
	app := filepath.Join(m.appDir("demo"), "public")

	png := []byte{0x89, 'P', 'N', 'G', 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00, 0x00, 0x0d, 0xff, 0xfe}
	if err := os.WriteFile(filepath.Join(app, "logo.png"), png, 0o640); err != nil {
		t.Fatal(err)
	}
	if _, err := m.ReadFile(context.Background(), "demo", "public/logo.png"); err == nil {
		t.Fatal("returned a binary file as text; saving it back would corrupt it")
	}

	latin1 := []byte("caf\xe9") // valid Latin-1, invalid UTF-8
	if err := os.WriteFile(filepath.Join(app, "old.txt"), latin1, 0o640); err != nil {
		t.Fatal(err)
	}
	if _, err := m.ReadFile(context.Background(), "demo", "public/old.txt"); err == nil {
		t.Fatal("returned invalid UTF-8 as text")
	}

	// And ordinary multibyte text must still be readable.
	if err := os.WriteFile(filepath.Join(app, "ar.txt"), []byte("مرحبا — café ✓"), 0o640); err != nil {
		t.Fatal(err)
	}
	if got, err := m.ReadFile(context.Background(), "demo", "public/ar.txt"); err != nil || got != "مرحبا — café ✓" {
		t.Fatalf("valid UTF-8 did not round-trip: %q %v", got, err)
	}
}

// Two editors - a customer and their AI agent - with the same file open. The
// second save must not silently erase the first.
func TestWriteIfRefusesAStaleRevision(t *testing.T) {
	ctx := context.Background()
	m, _, _ := newTestManager(t)

	_, rev, err := m.ReadFileRevision(ctx, "demo", "public/index.php")
	if err != nil {
		t.Fatal(err)
	}

	// Editor A saves first, against the revision both of them read.
	revA, err := m.WriteFileIf(ctx, "demo", "public/index.php", "<?php echo 'A';", rev)
	if err != nil {
		t.Fatalf("first save refused: %v", err)
	}

	// Editor B saves against the SAME old revision: must be refused.
	if _, err := m.WriteFileIf(ctx, "demo", "public/index.php", "<?php echo 'B';", rev); !errors.Is(err, ErrConflict) {
		t.Fatalf("stale save was not refused as a conflict: %v", err)
	}
	if got, _ := m.ReadFile(ctx, "demo", "public/index.php"); got != "<?php echo 'A';" {
		t.Fatalf("A's work was overwritten: %q", got)
	}

	// B re-reads, gets A's revision, and can now save.
	if _, err := m.WriteFileIf(ctx, "demo", "public/index.php", "<?php echo 'B';", revA); err != nil {
		t.Fatalf("save with the current revision refused: %v", err)
	}
}

func TestWriteIfAbsentDoesNotClobberANewFile(t *testing.T) {
	ctx := context.Background()
	m, _, _ := newTestManager(t)

	if _, err := m.WriteFileIf(ctx, "demo", "app/New.php", "first", "absent"); err != nil {
		t.Fatalf("creating a new file refused: %v", err)
	}
	if _, err := m.WriteFileIf(ctx, "demo", "app/New.php", "second", "absent"); !errors.Is(err, ErrConflict) {
		t.Fatalf("\"new file\" replaced an existing one: %v", err)
	}
	if got, _ := m.ReadFile(ctx, "demo", "app/New.php"); got != "first" {
		t.Fatalf("existing file was clobbered: %q", got)
	}
	// A revision for a file that does not exist is also a conflict: it was deleted.
	if _, err := m.WriteFileIf(ctx, "demo", "app/Gone.php", "x", Revision([]byte("old"))); !errors.Is(err, ErrConflict) {
		t.Fatalf("saving over a deleted file was not a conflict: %v", err)
	}
}
