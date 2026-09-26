package sites

import (
	"fmt"
	"os"
	"path/filepath"
	"syscall"
)

// Per-site disk I/O ceilings, so one site cannot take the disk from every
// other site on the host (ROADMAP, security audit 2026-09-25).
//
// They are set on the host's physical disk, not on the site's own loop
// device: loop numbers are handed out at mount time and change across
// reboots, and the kernel (blkcg-aware loop, 5.12+) charges a site's I/O
// through its loop device to the site's cgroup on the disk underneath.
// Measured on a host on 2026-09-25: a 5 MB/s write limit on the disk held a
// write inside the site to 5.2 MB/s, exactly as the same limit on the loop
// device did.
//
// The values leave a normal app alone. Copying a site's vendor/ (78 MB, 9,289
// files, caches dropped - the worst case) took 4.97 s unlimited and 5.38 s
// with these (+8%); a tighter 200/100 MB/s, 4,000/2,000 IOPS cost +13%. The
// disk itself writes at ~850 MB/s, so one site gets under a quarter of it.
const (
	ioReadBps   = "400mb"
	ioWriteBps  = "200mb"
	ioReadIOPS  = "10000"
	ioWriteIOPS = "5000"
)

// runSpec versions the `docker run` settings that can only change by
// recreating a container. A container labelled with an older one is
// outdated, and fleet:roll-image moves it the same way it moves a site onto
// a rebuilt image. "-io" records that the disk limits were applied, so a host
// whose disk could not be found (none) is not mistaken for a limited one.
const runSpecVersion = "2"

func (m *Manager) runSpec() string {
	spec := runSpecVersion + "-io"
	if m.ioDisk == "" {
		spec = runSpecVersion + "-none"
	}
	// "-dns": resolving through the host's forwarder, which needs a new
	// container; fleet:roll-image moves sites onto it one at a time.
	if m.cfg.SiteDNS != "" {
		spec += "-dns"
	}
	return spec
}

// ioArgs are the docker run flags for the ceilings, or none when the disk is
// unknown: a site without limits beats a site that cannot be created.
func (m *Manager) ioArgs() []string {
	if m.ioDisk == "" {
		return nil
	}
	d := m.ioDisk
	return []string{
		"--device-read-bps", d + ":" + ioReadBps,
		"--device-write-bps", d + ":" + ioWriteBps,
		"--device-read-iops", d + ":" + ioReadIOPS,
		"--device-write-iops", d + ":" + ioWriteIOPS,
	}
}

// Variables so the tests can build a fake /sys and /dev.
var (
	sysDevBlock   = "/sys/dev/block"
	devDir        = "/dev"
	isBlockDevice = func(path string) bool {
		fi, err := os.Stat(path)
		return err == nil && fi.Mode()&os.ModeDevice != 0
	}
)

// diskUnder finds the whole disk that holds path (/dev/sda for a directory on
// /dev/sda1), or "" if it cannot be found - on a machine without /sys, say.
func diskUnder(path string) string {
	var st syscall.Stat_t
	if err := syscall.Stat(path, &st); err != nil {
		return ""
	}
	dev := uint64(st.Dev) // int32 on darwin, uint64 on linux
	// Linux's encoding (glibc gnu_dev_major/minor).
	major := (dev>>8)&0xfff | (dev>>32)&^uint64(0xfff)
	minor := dev&0xff | (dev>>12)&^uint64(0xff)
	return diskFor(fmt.Sprintf("%d:%d", major, minor))
}

// diskFor resolves a "major:minor" to its whole disk's device node.
func diskFor(majMin string) string {
	real, err := filepath.EvalSymlinks(filepath.Join(sysDevBlock, majMin))
	if err != nil {
		return ""
	}
	// A partition's directory sits inside its disk's (…/sda/sda1).
	if _, err := os.Stat(filepath.Join(real, "partition")); err == nil {
		real = filepath.Dir(real)
	}
	node := filepath.Join(devDir, filepath.Base(real))
	if !isBlockDevice(node) {
		return ""
	}
	return node
}
