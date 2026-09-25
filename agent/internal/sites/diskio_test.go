package sites

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// A fake /sys/dev/block: 8:0 is the disk sda, 8:1 its partition sda1, 7:3 a
// loop device (a whole disk of its own, no partition file).
func fakeSys(t *testing.T) {
	t.Helper()
	root := t.TempDir()
	for _, d := range []string{"devices/pci0/host0/block/sda/sda1", "devices/virtual/block/loop3"} {
		if err := os.MkdirAll(filepath.Join(root, d), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.WriteFile(filepath.Join(root, "devices/pci0/host0/block/sda/sda1/partition"), []byte("1\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	blk := filepath.Join(root, "dev-block")
	if err := os.MkdirAll(blk, 0o755); err != nil {
		t.Fatal(err)
	}
	for link, target := range map[string]string{
		"8:0": "../devices/pci0/host0/block/sda",
		"8:1": "../devices/pci0/host0/block/sda/sda1",
		"7:3": "../devices/virtual/block/loop3",
	} {
		if err := os.Symlink(target, filepath.Join(blk, link)); err != nil {
			t.Fatal(err)
		}
	}
	oldSys, oldDev, oldIs := sysDevBlock, devDir, isBlockDevice
	t.Cleanup(func() { sysDevBlock, devDir, isBlockDevice = oldSys, oldDev, oldIs })
	sysDevBlock, devDir = blk, "/dev"
	isBlockDevice = func(p string) bool { return p == "/dev/sda" || p == "/dev/loop3" }
}

func TestThePartitionUnderTheSitesResolvesToItsWholeDisk(t *testing.T) {
	fakeSys(t)
	for in, want := range map[string]string{"8:1": "/dev/sda", "8:0": "/dev/sda", "7:3": "/dev/loop3", "9:9": ""} {
		if got := diskFor(in); got != want {
			t.Errorf("diskFor(%s) = %q, want %q", in, got, want)
		}
	}
	isBlockDevice = func(string) bool { return false }
	if got := diskFor("8:1"); got != "" {
		t.Errorf("a name that is not a block device must not be used: %q", got)
	}
}

func TestEverySiteGetsTheDiskCeilingsAndTheLabelSaysSo(t *testing.T) {
	s := Site{ID: "shop", Container: "cic-shop", CPULimit: "1", MemLimit: "1024m", Port: 20000}
	limited := strings.Join((&Manager{cfg: Config{HostID: "h9"}, ioDisk: "/dev/sda"}).runArgs(s), " ")
	for _, want := range []string{
		"--device-read-bps /dev/sda:400mb", "--device-write-bps /dev/sda:200mb",
		"--device-read-iops /dev/sda:10000", "--device-write-iops /dev/sda:5000",
		"--label codeinchrome.runspec=2-io",
	} {
		if !strings.Contains(limited, want) {
			t.Errorf("missing %q in %s", want, limited)
		}
	}
	if !strings.HasSuffix(limited, " "+laravelImage) {
		t.Error("the image must stay the last argument")
	}

	// Disk unknown: the site is still created, and its label does not claim limits.
	plain := strings.Join((&Manager{cfg: Config{HostID: "h9"}}).runArgs(s), " ")
	if strings.Contains(plain, "--device-") || !strings.Contains(plain, "--label codeinchrome.runspec=2-none") {
		t.Errorf("without a disk: %s", plain)
	}
}
