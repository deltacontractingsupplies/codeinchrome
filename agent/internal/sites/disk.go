package sites

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// Per-site disks. Each site's files live on their own sparse ext4 image,
// loop-mounted at <site>/vol, with the application in <site>/vol/app. The
// kernel enforces the size: a full disk is ENOSPC for that site alone, not a
// host whose root filesystem fills up under every other customer.
//
// The mounting itself is done by /opt/codeinchrome/bin/cic-mount, run through
// systemd-run so it happens in the HOST'S mount namespace. The agent runs in
// its own (a side effect of ProtectSystem), and a mount made there would be
// invisible to Docker. See infra/cic-mount for the full reasoning.

const (
	mountHelper = "/opt/codeinchrome/bin/cic-mount"
	defaultDisk = 1 // GB
	maxDisk     = 2048
)

func (m *Manager) diskImage(id string) string { return filepath.Join(m.dir(id), "disk.img") }
func (m *Manager) volume(id string) string    { return filepath.Join(m.dir(id), "vol") }

// appDir is the customer's application: the only directory a container mounts.
func (m *Manager) appDir(id string) string { return filepath.Join(m.volume(id), "app") }

func (m *Manager) mountOp(ctx context.Context, args ...string) error {
	full := append([]string{"--wait", "--quiet", "--collect", "--pipe", mountHelper}, args...)
	if _, err := run(ctx, 2*time.Minute, "systemd-run", full...); err != nil {
		return fmt.Errorf("cic-mount %s: %w", strings.Join(args, " "), err)
	}
	return nil
}

// isMounted reads the kernel's own table rather than trusting that a command
// succeeded: the whole disk design fails open if a mount silently did not
// happen, because the container would then write to the host's disk.
func isMounted(path string) bool {
	b, err := os.ReadFile("/proc/self/mountinfo")
	if err != nil {
		return false
	}
	for _, line := range strings.Split(string(b), "\n") {
		f := strings.Fields(line)
		if len(f) > 4 && f[4] == path {
			return true
		}
	}
	return false
}

func (m *Manager) createDisk(ctx context.Context, id string, gb int) error {
	if gb < 1 || gb > maxDisk {
		return fmt.Errorf("disk size %d GB is out of range 1-%d", gb, maxDisk)
	}
	img := m.diskImage(id)
	f, err := os.OpenFile(img, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
	if err != nil {
		return fmt.Errorf("create disk image: %w", err)
	}
	// Sparse: the size is a ceiling, not an allocation.
	if err := f.Truncate(int64(gb) << 30); err != nil {
		f.Close()
		return fmt.Errorf("size disk image: %w", err)
	}
	f.Close()

	// -m 0: no blocks reserved for root. There is no root process on this
	// filesystem, so the usual 5% would just be space the customer paid for
	// and cannot use.
	// -i 8192: an inode per 8 KB rather than ext4's 16 KB. A Laravel app is
	// tens of thousands of small files (vendor/ alone ~11,000), and a 1 GB
	// disk ran out of inodes with most of its bytes free - after which the
	// site could not save a single file. The inode tables cost ~3% of the
	// disk, and on a sparse image nothing until they are used.
	if _, err := run(ctx, 2*time.Minute, "mkfs.ext4", "-q", "-F", "-m", "0", "-i", "8192", img); err != nil {
		return fmt.Errorf("format disk image: %w", err)
	}
	if err := m.mountOp(ctx, "mount", id); err != nil {
		return err
	}
	if !isMounted(m.volume(id)) {
		return fmt.Errorf("the disk for %s reported mounted but is not in the mount table", id)
	}
	return nil
}

// releaseDisk unmounts the site's disk. Returns an error if it is still
// mounted afterwards, so a caller never RemoveAll()s through a live mount.
func (m *Manager) releaseDisk(ctx context.Context, id string) error {
	if !isMounted(m.volume(id)) {
		return nil
	}
	if err := m.mountOp(ctx, "umount", id); err != nil {
		return err
	}
	if isMounted(m.volume(id)) {
		return fmt.Errorf("%s is still mounted", m.volume(id))
	}
	return nil
}

// InodeUsage is read from the mounted filesystem at call time.
func (m *Manager) InodeUsage(id string) (used, total int64) {
	if !isMounted(m.volume(id)) {
		return 0, 0
	}
	var st syscall.Statfs_t
	if err := syscall.Statfs(m.volume(id), &st); err != nil {
		return 0, 0
	}
	return int64(st.Files) - int64(st.Ffree), int64(st.Files)
}

// DiskUsage is read from the mounted filesystem at call time.
func (m *Manager) DiskUsage(id string) (used, size int64, ok bool) {
	if !isMounted(m.volume(id)) {
		return 0, 0, false
	}
	var st syscall.Statfs_t
	if err := syscall.Statfs(m.volume(id), &st); err != nil {
		return 0, 0, false
	}
	size = int64(st.Blocks) * int64(st.Bsize)
	used = size - int64(st.Bfree)*int64(st.Bsize)
	return used, size, true
}

func (m *Manager) growDisk(ctx context.Context, id string, gb int) error {
	return m.mountOp(ctx, "grow", id, strconv.Itoa(gb))
}

// SiteUsage is one site's footprint, measured at call time.
type SiteUsage struct {
	ID            string `json:"id"`
	DiskUsedBytes int64  `json:"diskUsedBytes"`
	DiskSizeBytes int64  `json:"diskSizeBytes"`
	DiskMounted   bool   `json:"diskMounted"`
	// Inodes: a disk can be "full" of small files long before its bytes are.
	InodesUsed  int64 `json:"inodesUsed"`
	InodesTotal int64 `json:"inodesTotal"`
	// The database lives on the host's MySQL, OUTSIDE the site's disk, so the
	// disk ceiling does not bound it. It is measured and reported here so the
	// control plane can count it against the plan.
	DatabaseBytes int64 `json:"databaseBytes"`
}

// Usage measures every site on this host in one pass: one statfs per disk and
// one query for every database, rather than a request per site.
func (m *Manager) Usage(ctx context.Context) ([]SiteUsage, error) {
	list, err := m.List(ctx)
	if err != nil {
		return nil, err
	}

	dbBytes := map[string]int64{}
	if db, err := m.rootDB(); err == nil {
		defer db.Close()
		rows, err := db.QueryContext(ctx, `SELECT table_schema, COALESCE(SUM(data_length + index_length), 0)
			FROM information_schema.tables WHERE table_schema LIKE 'site\\_%' GROUP BY table_schema`)
		if err == nil {
			defer rows.Close()
			for rows.Next() {
				var schema string
				var n int64
				if rows.Scan(&schema, &n) == nil {
					dbBytes[schema] = n
				}
			}
		}
	}

	out := make([]SiteUsage, 0, len(list))
	for _, s := range list {
		used, size, mounted := m.DiskUsage(s.ID)
		iUsed, iTotal := m.InodeUsage(s.ID)
		out = append(out, SiteUsage{
			ID: s.ID, DiskUsedBytes: used, DiskSizeBytes: size, DiskMounted: mounted,
			DatabaseBytes: dbBytes[DBName(s.ID)], InodesUsed: iUsed, InodesTotal: iTotal,
		})
	}
	return out, nil
}
