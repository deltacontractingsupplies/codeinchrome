package sites

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// ReplaceWithClone makes a public GitHub repository the site itself: an
// open-source Laravel app, cloned and run as the site. Destructive, so:
//   - it needs confirm;
//   - the repository is fetched, unpacked and scanned in a staging folder
//     outside the app (and out of the container's reach) BEFORE anything
//     else, in the request itself - a bad repository fails at once, and
//     malware is reported the same way as for any clone;
//   - then, in the background, a backup of the site (files and database);
//     no backup, no replace;
//   - the site's .env (keys, database) and storage/ (uploads, logs) are kept,
//     everything else is the repository's; then composer install.
//     Migrations are the owner's call: the answer says to run them.
// BackupStatus reports it, as it does a restore.

var ErrNotLaravel = errors.New("not a Laravel application: the repository has no artisan and composer.json at its top")

// keptOnReplace: what the site keeps of itself.
var keptOnReplace = map[string]bool{".env": true, "storage": true}

func (m *Manager) stagingDir(id string) string { return filepath.Join(m.volume(id), "clone-staging") }

// StartReplaceWithClone checks the repository now and replaces the site in
// the background; confirm must be true.
func (m *Manager) StartReplaceWithClone(ctx context.Context, id, repository, ref string, confirm bool) (*BackupOp, error) {
	if !confirm {
		return nil, ErrNeedsConfirm
	}
	if err := ValidID(id); err != nil {
		return nil, err
	}
	owner, repo, err := ParseRepository(repository)
	if err != nil {
		return nil, err
	}
	if ref == "" {
		ref = "HEAD"
	}
	if !gitRef.MatchString(ref) || strings.Contains(ref, "..") {
		return nil, fmt.Errorf("%q is not a branch, tag or commit name", ref)
	}
	if op := m.BackupStatus(id); op != nil && op.State == "running" {
		return nil, ErrBackupRunning
	}
	select {
	case cloneSlots <- struct{}{}:
	case <-time.After(20 * time.Second):
		return nil, fmt.Errorf("this host is busy with other clones; try again in a minute")
	}
	release := func() { <-cloneSlots }

	// Fetched, unpacked and scanned now, where the site cannot reach.
	staging := m.stagingDir(id)
	cleanup := func() { _ = os.RemoveAll(staging); release() }
	_ = os.RemoveAll(staging)
	if err := os.MkdirAll(staging, 0o750); err != nil {
		release()
		return nil, fmt.Errorf("no staging space: %v", err)
	}
	arc, err := fetchArchive(ctx, owner, repo, ref)
	if err != nil {
		cleanup()
		return nil, err
	}
	written, err := extractZip(ctx, staging, arc.zr, staging, arc.prefix)
	arc.Close()
	if err != nil {
		cleanup()
		return nil, err
	}
	for _, need := range []string{"artisan", "composer.json"} {
		if _, err := os.Lstat(filepath.Join(staging, need)); err != nil {
			cleanup()
			return nil, ErrNotLaravel
		}
	}

	op := &BackupOp{Kind: "clone-replace", State: "running", Started: time.Now().UTC(),
		Message: fmt.Sprintf("backing up, then replacing the site with %s/%s@%s", owner, repo, ref)}
	if err := m.begin(id, op); err != nil {
		cleanup()
		return nil, err
	}
	go func() {
		bg, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
		defer cancel()
		msg, savedAs, err := m.swapInClone(bg, id, owner, repo, ref, written)
		// Cleaned up BEFORE the status says finished: whoever sees "done" may
		// start the next clone at once, and must find the slot free and no
		// staging folder left.
		cleanup()
		m.update(id, func(op *BackupOp) {
			now := time.Now().UTC()
			op.Finished = &now
			op.SavedAs = savedAs
			if err != nil {
				op.State, op.Message = "failed", err.Error()
				return
			}
			op.State, op.Message = "done", msg
		})
	}()
	c := *op
	return &c, nil
}

func (m *Manager) swapInClone(ctx context.Context, id, owner, repo, ref string, written []string) (string, string, error) {
	// A backup first, or nothing happens.
	savedAs, err := m.runBackup(ctx, id)
	if err != nil {
		return "", "", fmt.Errorf("nothing was changed: the backup before replacing failed (%v)", err)
	}
	restore := fmt.Sprintf("restore backup %s to undo", savedAs)
	staging := m.stagingDir(id)

	// The swap: the site's own files out (except what it keeps), the
	// repository's in. The app folder is the container's mount, so it stays
	// and its contents change.
	app, err := m.realRoot(id)
	if err != nil {
		return "", savedAs, err
	}
	current, err := os.ReadDir(app)
	if err != nil {
		return "", savedAs, err
	}
	for _, e := range current {
		if keptOnReplace[e.Name()] {
			continue
		}
		if err := removeAllBeneath(app, "/"+e.Name()); err != nil {
			return "", savedAs, fmt.Errorf("the replace stopped half way (%v); %s", err, restore)
		}
	}
	incoming, err := os.ReadDir(staging)
	if err != nil {
		return "", savedAs, fmt.Errorf("the replace stopped half way (%v); %s", err, restore)
	}
	for _, e := range incoming {
		if keptOnReplace[e.Name()] {
			if _, err := os.Lstat(filepath.Join(app, e.Name())); err == nil {
				continue // the site's own .env and storage stay
			}
		}
		// rename(2) never follows a link at the destination: a name the site's
		// code planted in the meantime is replaced, or the rename fails.
		if err := os.Rename(filepath.Join(staging, e.Name()), filepath.Join(app, e.Name())); err != nil {
			return "", savedAs, fmt.Errorf("the replace stopped half way (%v); %s", err, restore)
		}
	}
	var mine []string
	for _, rel := range written {
		if !keptOnReplace[strings.SplitN(strings.TrimPrefix(rel, "/"), "/", 2)[0]] {
			mine = append(mine, rel)
		}
	}
	m.rememberCloned(id, app, mine)
	m.record(ctx, id, fmt.Sprintf("replace the site with %s/%s@%s (backed up first as %s)", owner, repo, ref, savedAs))

	// Its dependencies.
	res, err := m.RunCommand(ctx, id, "composer", []string{"install", "--no-dev", "--optimize-autoloader"}, false)
	if err != nil || res.ExitCode != 0 {
		detail := res.Output
		if err != nil {
			detail = err.Error()
		}
		return "", savedAs, fmt.Errorf("the site now holds %s/%s, but composer install failed (%s); fix composer.json and run composer install, or %s", owner, repo, lastLine(detail), restore)
	}
	return fmt.Sprintf("the site is now %s/%s@%s (%d files, dependencies installed). Its .env and storage/ were kept. Backed up first as %s. Next: php artisan migrate, and compare its .env with the app's .env.example.",
		owner, repo, ref, len(mine), savedAs), savedAs, nil
}
