package sites

import (
	"archive/zip"
	"bytes"
	"context"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestMkdirRenameCopyAndMoveStayInsideTheSite(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	app := m.appDir(id)

	if err := m.Mkdir(ctx, id, "/resources/views/shop"); err != nil {
		t.Fatal(err)
	}
	if info, err := os.Stat(filepath.Join(app, "resources/views/shop")); err != nil || !info.IsDir() {
		t.Fatal("folder not created")
	}
	m.WriteFile(ctx, id, "/a.txt", "hello")
	if err := m.Copy(ctx, id, "/a.txt", "/b.txt"); err != nil {
		t.Fatal(err)
	}
	if err := m.Rename(ctx, id, "/b.txt", "/resources/views/shop/c.txt"); err != nil {
		t.Fatal(err)
	}
	got, _ := os.ReadFile(filepath.Join(app, "resources/views/shop/c.txt"))
	if string(got) != "hello" {
		t.Fatalf("moved content %q", got)
	}
	if _, err := os.Stat(filepath.Join(app, "b.txt")); err == nil {
		t.Fatal("rename left the source behind")
	}

	for _, bad := range [][2]string{{"/a.txt", "/a.txt"}, {"/", "/x"}, {"/resources", "/resources/views/inside"}} {
		if err := m.Rename(ctx, id, bad[0], bad[1]); err == nil {
			t.Errorf("Rename(%q, %q) should be refused", bad[0], bad[1])
		}
	}
	// "../.." is clamped to the site root, as every path is: the file moves
	// to the root of the SITE, and nothing appears outside it.
	m.WriteFile(ctx, id, "/d.txt", "d")
	if err := m.Rename(ctx, id, "/d.txt", "../../escape.txt"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(app, "escape.txt")); err != nil {
		t.Fatal("the clamped move should land at the site root")
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(filepath.Dir(app)), "escape.txt")); err == nil {
		t.Fatal("a move escaped the site")
	}
	if err := m.Copy(ctx, id, "/a.txt", "/resources/views/shop/c.txt"); err == nil {
		t.Error("copy must never overwrite an existing file")
	}
}

func TestUploadAndDownloadBinaryFilesByteForByte(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	png := []byte{0x89, 'P', 'N', 'G', 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0xff, 0x00, 0x10}
	if err := m.Upload(ctx, id, "/public/images/logo.png", bytes.NewReader(png)); err != nil {
		t.Fatal(err)
	}
	var out bytes.Buffer
	name, err := m.Download(ctx, id, "/public/images/logo.png", &out)
	if err != nil || name != "logo.png" || !bytes.Equal(out.Bytes(), png) {
		t.Fatalf("download gave %q %v %v", name, out.Bytes(), err)
	}
	big := bytes.NewReader(make([]byte, MaxUploadSize+1))
	if err := m.Upload(ctx, id, "/big.bin", big); err == nil {
		t.Error("an upload over the limit must be refused")
	}
	if _, err := os.Stat(filepath.Join(m.appDir(id), "big.bin")); err == nil {
		t.Error("a refused upload must leave nothing behind")
	}
}

func TestAFolderDeleteNeedsConfirmAndGoesToTheBin(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "/app/Old/One.php", "<?php 1")
	m.WriteFile(ctx, id, "/app/Old/Two.php", "<?php 2")

	if err := m.DeleteTree(ctx, id, "/app/Old", false); err == nil {
		t.Fatal("a folder delete without confirm must be refused")
	}
	if err := m.DeleteTree(ctx, id, "/", true); err == nil {
		t.Fatal("the site root must never be deleted")
	}
	if err := m.DeleteTree(ctx, id, "/app/Old", true); err != nil {
		t.Fatal(err)
	}
	bin, _ := m.Deleted(ctx, id, 50)
	if len(bin) != 2 {
		t.Fatalf("both files should be in the bin, got %+v", bin)
	}
}

func TestSearchFindsTextAndSkipsSecretsAndVendor(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "/routes/web.php", "<?php\nRoute::get('/checkout', fn () => 1);")
	m.WriteFile(ctx, id, "/vendor/x/y.php", "checkout")
	os.WriteFile(filepath.Join(m.appDir(id), ".env"), []byte("CHECKOUT_SECRET=abc"), 0o640)

	hits, err := m.Search(ctx, id, "checkout", 50)
	if err != nil {
		t.Fatal(err)
	}
	if len(hits) != 1 || hits[0].Path != "/routes/web.php" || hits[0].Line != 2 {
		t.Fatalf("want one hit in routes/web.php line 2, got %+v", hits)
	}
}

func TestZipAndUnzipRefuseToEscapeTheSite(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "/theme/a.css", "a{}")
	m.WriteFile(ctx, id, "/theme/b.css", "b{}")
	if err := m.Zip(ctx, id, "/theme", "/theme.zip"); err != nil {
		t.Fatal(err)
	}
	if err := m.Unzip(ctx, id, "/theme.zip", "/restored"); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(filepath.Join(m.appDir(id), "restored/theme/a.css")); string(got) != "a{}" {
		t.Fatalf("unzipped content %q", got)
	}

	// A "zip slip" archive: an entry named ../../evil.php.
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	w, _ := zw.Create("../../evil.php")
	w.Write([]byte("<?php evil"))
	zw.Close()
	m.Upload(ctx, id, "/evil.zip", bytes.NewReader(buf.Bytes()))
	if err := m.Unzip(ctx, id, "/evil.zip", "/x"); err == nil || !strings.Contains(err.Error(), "outside") {
		t.Fatalf("a zip-slip archive must be refused, got %v", err)
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(m.appDir(id)), "evil.php")); err == nil {
		t.Fatal("zip slip wrote outside the site")
	}
}

// The agent runs as root. A site can hold a symlink to a host file; no walk
// may read through it.
func TestNoWalkFollowsASymlinkOutOfTheSite(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	outside := filepath.Join(t.TempDir(), "shadow")
	os.WriteFile(outside, []byte("root:SECRET-HASH"), 0o600)
	app := m.appDir(id)
	os.MkdirAll(filepath.Join(app, "leak"), 0o755)
	os.Symlink(outside, filepath.Join(app, "leak/shadow"))
	os.Symlink(filepath.Dir(outside), filepath.Join(app, "leakdir"))
	m.WriteFile(ctx, id, "/leak/ok.txt", "fine")

	if hits, _ := m.Search(ctx, id, "SECRET-HASH", 50); len(hits) != 0 {
		t.Fatalf("search read through a symlink: %+v", hits)
	}
	if err := m.Zip(ctx, id, "/leak", "/leak.zip"); err != nil {
		t.Fatal(err)
	}
	zr, _ := zip.OpenReader(filepath.Join(app, "leak.zip"))
	for _, f := range zr.File {
		if strings.Contains(f.Name, "shadow") {
			t.Fatalf("zip included the symlinked file %q", f.Name)
		}
	}
	zr.Close()
	if err := m.Copy(ctx, id, "/leak", "/leak2"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Lstat(filepath.Join(app, "leak2/shadow")); err == nil {
		t.Fatal("copy carried the symlink along")
	}
	var out bytes.Buffer
	if _, err := m.Download(ctx, id, "/leak/shadow", &out); err == nil {
		t.Fatalf("download read a host file through a symlink: %q", out.String())
	}
}

// The site's own code can create symlinks. One planted inside an unzip or
// copy destination, pointing at a host directory, must not let the agent
// (root) write there.
func TestUnzipAndCopyNeverWriteThroughAPlantedSymlink(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	app := m.appDir(id)
	hostDir := t.TempDir() // stands in for /etc on the host

	// An archive with theme/owned.txt, and a destination whose "theme" is a
	// symlink the site planted, pointing out.
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	w, _ := zw.Create("theme/owned.txt")
	w.Write([]byte("written by the agent"))
	zw.Close()
	m.Upload(ctx, id, "/a.zip", bytes.NewReader(buf.Bytes()))
	os.MkdirAll(filepath.Join(app, "dest"), 0o755)
	os.Symlink(hostDir, filepath.Join(app, "dest/theme"))

	err := m.Unzip(ctx, id, "/a.zip", "/dest")
	if _, statErr := os.Stat(filepath.Join(hostDir, "owned.txt")); statErr == nil {
		t.Fatal("unzip wrote OUTSIDE the site through a planted symlink")
	}
	if err == nil {
		t.Fatal("unzip through a symlinked folder should be refused")
	}

	// Copy: a destination parent that is a planted symlink.
	m.WriteFile(ctx, id, "/src/x.txt", "x")
	os.Symlink(hostDir, filepath.Join(app, "cdest"))
	if err := m.Copy(ctx, id, "/src", "/cdest/src"); err == nil {
		if _, statErr := os.Stat(filepath.Join(hostDir, "src/x.txt")); statErr == nil {
			t.Fatal("copy wrote OUTSIDE the site through a planted symlink")
		}
	}
	if _, statErr := os.Stat(filepath.Join(hostDir, "src")); statErr == nil {
		t.Fatal("copy created something outside the site")
	}
}

// The window these close: resolve() approves a path, then the site's own code
// swaps a folder on it for a symlink to the host before the agent - running as
// root - opens it. The helpers are called here directly on the path AFTER the
// swap, which is exactly what the agent would do if it lost that race.
func TestWritesReadsAndMovesRefuseAFolderSwappedForASymlink(t *testing.T) {
	m, id := historyManager(t)
	root, err := m.realRoot(id)
	if err != nil {
		t.Fatal(err)
	}
	hostDir := t.TempDir() // stands in for /etc
	os.WriteFile(filepath.Join(hostDir, "shadow"), []byte("root:secret"), 0o600)
	os.Symlink(hostDir, filepath.Join(root, "swapped"))

	err = replaceBeneath(root, "/swapped/owned", 0o640, func(f *os.File) error {
		_, err := f.WriteString("written by the agent")
		return err
	})
	if _, statErr := os.Stat(filepath.Join(hostDir, "owned")); statErr == nil {
		t.Fatal("a write went OUTSIDE the site through a swapped folder")
	}
	if err == nil {
		t.Fatal("a write through a symlinked folder should be refused")
	}

	if f, err := openBeneath(root, "/swapped/shadow"); err == nil {
		f.Close()
		t.Fatal("a read followed a swapped folder out of the site")
	}
	os.Symlink(filepath.Join(hostDir, "shadow"), filepath.Join(root, "leaf-link"))
	if f, err := openBeneath(root, "/leaf-link"); err == nil {
		f.Close()
		t.Fatal("a read followed a symlinked file out of the site")
	}
	if _, err := readBeneath(root, filepath.Join(root, "swapped/shadow"), 1<<20); err == nil {
		t.Fatal("search read through a swapped folder")
	}

	os.WriteFile(filepath.Join(root, "mine.txt"), []byte("x"), 0o640)
	if err := renameBeneath(root, "/mine.txt", "/swapped/mine.txt"); err == nil {
		t.Fatal("a move went through a symlinked folder")
	}
	if _, statErr := os.Stat(filepath.Join(hostDir, "mine.txt")); statErr == nil {
		t.Fatal("a move placed a file OUTSIDE the site")
	}
	if err := renameBeneath(root, "/swapped/shadow", "/stolen"); err == nil {
		t.Fatal("a move pulled a host file into the site")
	}
}

func TestAMoveNeverReplacesWhatIsAtTheDestination(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "/a.txt", "a")
	m.WriteFile(ctx, id, "/b.txt", "b")
	if err := m.Rename(ctx, id, "/a.txt", "/b.txt"); err == nil {
		t.Fatal("a move replaced an existing file")
	}
	if got, _ := m.ReadFile(ctx, id, "/b.txt"); got != "b" {
		t.Fatalf("destination changed to %q", got)
	}
}

// Links that stay inside the site keep working: Laravel's public/storage is
// one. resolve() follows them, and the kernel-checked open then runs on the
// real path.
func TestAnInSiteSymlinkStillReadsAndWrites(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	app := m.appDir(id)
	os.MkdirAll(filepath.Join(app, "storage/app/public"), 0o755)
	os.MkdirAll(filepath.Join(app, "public"), 0o755)
	os.Symlink("../storage/app/public", filepath.Join(app, "public/storage"))

	if err := m.WriteFile(ctx, id, "/public/storage/logo.txt", "logo"); err != nil {
		t.Fatal(err)
	}
	if b, err := os.ReadFile(filepath.Join(app, "storage/app/public/logo.txt")); err != nil || string(b) != "logo" {
		t.Fatalf("write through an in-site link landed wrong: %q %v", b, err)
	}
	if got, err := m.ReadFile(ctx, id, "/public/storage/logo.txt"); err != nil || got != "logo" {
		t.Fatalf("read through an in-site link: %q %v", got, err)
	}
	var buf bytes.Buffer
	if _, err := m.Download(ctx, id, "/public/storage/logo.txt", &buf); err != nil || buf.String() != "logo" {
		t.Fatalf("download through an in-site link: %q %v", buf.String(), err)
	}
}

func TestZipStillProducesAnArchiveOfTheFolder(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "/theme/a.css", "body{}")
	m.WriteFile(ctx, id, "/theme/.env", "SECRET=1")
	if err := m.Zip(ctx, id, "/theme", "/theme/theme.zip"); err != nil {
		t.Fatal(err)
	}
	r, err := zip.OpenReader(filepath.Join(m.appDir(id), "theme/theme.zip"))
	if err != nil {
		t.Fatal(err)
	}
	defer r.Close()
	var names []string
	for _, f := range r.File {
		names = append(names, f.Name)
	}
	if strings.Join(names, ",") != "theme/a.css" {
		t.Fatalf("archive holds %v; want only theme/a.css (no .env, not itself)", names)
	}
}

// The Copy/Zip/Search walk, at the moment a listed subfolder has just been
// swapped for a symlink to another tenant: the walk must refuse to descend,
// and never hand its caller a single name from the other side.
func TestTheTreeWalkNeverDescendsThroughASwappedFolder(t *testing.T) {
	m, id := historyManager(t)
	root, _ := m.realRoot(id)
	other := t.TempDir() // another tenant's app
	os.WriteFile(filepath.Join(other, "their-secret.env"), []byte("x"), 0o600)
	os.Mkdir(filepath.Join(other, "their-folder"), 0o755)
	os.Symlink(other, filepath.Join(root, "evil"))

	info, _ := os.Stat(other) // the walk believed "evil" was a folder when it listed it
	var seen []string
	err := walkDirBeneath(root, filepath.Join(root, "evil"), fs.FileInfoToDirEntry(info), func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		seen = append(seen, p)
		return nil
	})
	if err == nil || len(seen) > 0 {
		t.Fatalf("walked into another tree: err=%v saw=%v", err, seen)
	}

	// And the file copy re-resolves the whole path, not just its last part.
	if err := copyFileBeneath(root, filepath.Join(root, "evil/their-secret.env"), "/stolen.env"); err == nil {
		t.Fatal("copied a file through a swapped folder")
	}
	if _, err := os.Lstat(filepath.Join(root, "stolen.env")); err == nil {
		t.Fatal("a file from another tenant landed in the site")
	}
}

func TestCopyAndListingStillWorkOnAnOrdinaryTreeAndThroughInSiteLinks(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	app := m.appDir(id)
	m.WriteFile(ctx, id, "/theme/css/a.css", "a")
	m.WriteFile(ctx, id, "/theme/b.css", "b")
	if err := m.Copy(ctx, id, "/theme", "/theme2"); err != nil {
		t.Fatal(err)
	}
	for _, f := range []string{"theme2/css/a.css", "theme2/b.css"} {
		if _, err := os.Stat(filepath.Join(app, f)); err != nil {
			t.Fatalf("%s not copied", f)
		}
	}
	os.Symlink("theme", filepath.Join(app, "skin"))
	l, err := m.ListFiles(ctx, id, "/skin")
	if err != nil || len(l.Entries) != 2 || l.Entries[0].Name != "css" || !l.Entries[0].Dir || l.Entries[1].Size != 1 {
		t.Fatalf("listing through an in-site link: %+v %v", l, err)
	}
}

func TestTheStartupSelfTestPasses(t *testing.T) {
	m, err := New(Config{Root: t.TempDir(), CaddyDir: t.TempDir(), HostID: "h9"})
	if err != nil {
		t.Fatal(err)
	}
	if mode, err := m.SelfTest(); err != nil || mode == "" {
		t.Fatalf("self-test: %q %v", mode, err)
	}
}

// A payload saved under a harmless name and then renamed or copied into
// place is checked where it lands, and the operation undone (audit, 2026-09-25).
func TestRenamingOrCopyingCodeIntoPlaceIsScanned(t *testing.T) {
	m, id := historyManager(t)
	root := m.appDir(id)
	os.MkdirAll(filepath.Join(root, "public"), 0o755)
	os.WriteFile(filepath.Join(root, "notes.txt"), []byte(`<?php passthru($_GET['c']);`), 0o640)

	err := m.Rename(context.Background(), id, "/notes.txt", "/public/x.php")
	if _, ok := IsMalware(err); !ok {
		t.Fatalf("a rename into a .php must be scanned: %v", err)
	}
	if _, err := os.Stat(filepath.Join(root, "public/x.php")); !os.IsNotExist(err) {
		t.Fatal("the refused rename must be undone")
	}
	if _, err := os.Stat(filepath.Join(root, "notes.txt")); err != nil {
		t.Fatal("...and the file put back where it was")
	}

	err = m.Copy(context.Background(), id, "/notes.txt", "/public/y.php")
	if _, ok := IsMalware(err); !ok {
		t.Fatalf("a copy into a .php must be scanned: %v", err)
	}
	if _, err := os.Stat(filepath.Join(root, "public/y.php")); !os.IsNotExist(err) {
		t.Fatal("the refused copy must be removed")
	}

	// Ordinary moves still work.
	os.WriteFile(filepath.Join(root, "ok.php"), []byte(`<?php return view('home');`), 0o640)
	if err := m.Rename(context.Background(), id, "/ok.php", "/public/ok.php"); err != nil {
		t.Fatalf("an ordinary move: %v", err)
	}
}
