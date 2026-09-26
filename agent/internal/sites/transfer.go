package sites

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

// Moving a site to another host: its files, its version history and its
// database go from this agent to another, through the control plane, and the
// site comes up there as it was - same APP_KEY, same data, new database
// password. Used to drain a host, and to rebalance.
//
// The files are packed and unpacked by tar INSIDE the site's own container,
// as the site's own user. The tree is the tenant's, and the tenant can plant
// symlinks in it; run inside the container, the worst a planted link can reach
// is the container's own read-only filesystem - never the host's. The version
// history lives outside the container and belongs to the agent, which the
// tenant cannot write to; it is packed by the agent and every entry is checked
// on the way in regardless.

// transferExcludes are regenerated on the target: compiled views and caches.
var transferExcludes = []string{"./storage/framework/cache", "./storage/framework/views", "./storage/framework/sessions/*"}

// unpackScript unpacks the tar on stdin into root, then recreates the
// directories transferExcludes left out. Laravel does not create them itself:
// without storage/framework/views every page is a 500 ("Please provide a
// valid cache path") - what a whole-site restore did to a live store on
// 2026-09-26, and what a move would have done the same way.
func unpackScript(root string) []string {
	return []string{"sh", "-c", `tar -C "$1" -xzf - --no-same-owner --no-overwrite-dir || exit
if [ -d "$1/storage/framework" ]; then
  mkdir -p "$1/storage/framework/cache/data" "$1/storage/framework/views" "$1/storage/framework/sessions"
fi`, "unpack", root}
}

// transferCommand runs tar in a site's container; a variable so tests can
// stand in for Docker.
var transferCommand = func(ctx context.Context, container string, args ...string) *exec.Cmd {
	return exec.CommandContext(ctx, "docker", append([]string{"exec", "-i", "-u", "33:33", container}, args...)...)
}

// ExportFiles streams the site's application as a gzipped tar.
func (m *Manager) ExportFiles(ctx context.Context, id string, w io.Writer) error {
	site, err := m.transferable(id)
	if err != nil {
		return err
	}
	args := []string{"tar", "-C", "/var/www/html", "-czf", "-"}
	for _, x := range transferExcludes {
		args = append(args, "--exclude="+x)
	}
	cmd := transferCommand(ctx, site.Container, append(args, ".")...)
	cmd.Stdout = w
	var stderr cappedBuffer
	stderr.limit = 4 << 10
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("pack the site's files: %v: %s", err, strings.TrimSpace(stderr.buf.String()))
	}
	return nil
}

// ImportFiles replaces the site's application with an exported one, then
// gives it THIS host's database credentials: the export carries the source's.
func (m *Manager) ImportFiles(ctx context.Context, id string, r io.Reader) error {
	site, err := m.transferable(id)
	if err != nil {
		return err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	// Empty the tree first (the fresh skeleton the site was created with),
	// then unpack. Both inside the container, as its user.
	clear := transferCommand(ctx, site.Container, "sh", "-c", "find /var/www/html -mindepth 1 -maxdepth 1 -exec rm -rf {} +")
	if out, err := clear.CombinedOutput(); err != nil {
		return fmt.Errorf("clear the target: %v: %s", err, strings.TrimSpace(string(out)))
	}
	cmd := transferCommand(ctx, site.Container, unpackScript("/var/www/html")...)
	cmd.Stdin = r
	var stderr cappedBuffer
	stderr.limit = 4 << 10
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("unpack the site's files: %v: %s", err, strings.TrimSpace(stderr.buf.String()))
	}

	password, err := m.dbPassword(id)
	if err != nil {
		return err
	}
	if err := m.configureAppDatabase(ctx, site, password); err != nil {
		return err
	}
	// Caches compiled on the source name paths and settings of the source;
	// clear them and let the site's first requests rebuild what it needs.
	_, _ = run(ctx, 60*time.Second, "docker", "exec", "-u", "33:33", site.Container,
		"php", "/var/www/html/artisan", "optimize:clear", "--no-interaction")
	return nil
}

// ExportHistory streams the site's version history (a bare git repository
// the agent owns) as a gzipped tar of history.git/.
func (m *Manager) ExportHistory(ctx context.Context, id string, w io.Writer) error {
	if err := ValidID(id); err != nil {
		return err
	}
	dir := m.historyDir(id)
	if _, err := os.Stat(dir); err != nil {
		return errNoHistory
	}
	lock := historyLock(id)
	lock.Lock()
	defer lock.Unlock()
	cmd := exec.CommandContext(ctx, "tar", "-C", filepath.Dir(dir), "-czf", "-", filepath.Base(dir))
	// macOS tar would add AppleDouble "._" entries (only the tests run there).
	cmd.Env = append(os.Environ(), "COPYFILE_DISABLE=1")
	cmd.Stdout = w
	var stderr cappedBuffer
	stderr.limit = 4 << 10
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("pack history: %v: %s", err, strings.TrimSpace(stderr.buf.String()))
	}
	return nil
}

var errNoHistory = errors.New("this site has no history yet")

// ImportHistory replaces the site's history with an exported one. Every entry
// must be a plain file or directory under history.git/ - nothing else is
// written, so an archive cannot place a link or a file anywhere else.
func (m *Manager) ImportHistory(ctx context.Context, id string, r io.Reader) error {
	if err := ValidID(id); err != nil {
		return err
	}
	lock := historyLock(id)
	lock.Lock()
	defer lock.Unlock()

	dest := m.historyDir(id)
	staging := dest + ".incoming"
	_ = os.RemoveAll(staging)
	if err := os.MkdirAll(staging, 0o700); err != nil {
		return err
	}
	defer os.RemoveAll(staging)

	gz, err := gzip.NewReader(r)
	if err != nil {
		return fmt.Errorf("history archive: %w", err)
	}
	tr := tar.NewReader(gz)
	var total int64
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return fmt.Errorf("history archive: %w", err)
		}
		name := filepath.Clean(h.Name)
		if name != "history.git" && !strings.HasPrefix(name, "history.git/") || strings.Contains(name, "..") || filepath.IsAbs(name) {
			return fmt.Errorf("history archive: unexpected entry %q", h.Name)
		}
		target := filepath.Join(staging, strings.TrimPrefix(strings.TrimPrefix(name, "history.git"), "/"))
		switch h.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0o700); err != nil {
				return err
			}
		case tar.TypeReg:
			total += h.Size
			if total > 8<<30 {
				return fmt.Errorf("history archive is larger than 8 GB")
			}
			if err := os.MkdirAll(filepath.Dir(target), 0o700); err != nil {
				return err
			}
			f, err := os.OpenFile(target, os.O_CREATE|os.O_EXCL|os.O_WRONLY|syscall.O_NOFOLLOW, 0o600)
			if err != nil {
				return err
			}
			_, cerr := io.Copy(f, io.LimitReader(tr, h.Size))
			f.Close()
			if cerr != nil {
				return cerr
			}
		default:
			return fmt.Errorf("history archive: %q is not a plain file or directory", h.Name)
		}
	}
	if err := os.RemoveAll(dest); err != nil {
		return err
	}
	if err := os.Rename(staging, dest); err != nil {
		return err
	}
	return os.Chmod(dest, 0o700)
}

// SetMaintenance puts the site into Laravel's maintenance mode (503 for
// visitors) or takes it out, for the minutes a move takes.
func (m *Manager) SetMaintenance(ctx context.Context, id string, down bool) error {
	site, err := m.transferable(id)
	if err != nil {
		return err
	}
	verb := "up"
	if down {
		verb = "down"
	}
	if out, err := run(ctx, 60*time.Second, "docker", "exec", "-u", "33:33", site.Container,
		"php", "/var/www/html/artisan", verb, "--no-interaction"); err != nil {
		return fmt.Errorf("artisan %s: %v: %s", verb, err, strings.TrimSpace(out))
	}
	return nil
}

// transferable is a site whose container is running: a paused site is not
// moved (it is resumed, or deleted, where it is).
func (m *Manager) transferable(id string) (Site, error) {
	if err := ValidID(id); err != nil {
		return Site{}, err
	}
	site, err := m.load(id)
	if err != nil {
		return Site{}, fmt.Errorf("no such site %q", id)
	}
	if site.Suspended {
		return Site{}, fmt.Errorf("%s is paused; resume it before moving it", id)
	}
	if site.Container == "" {
		site.Container = m.container(id)
	}
	return site, nil
}
