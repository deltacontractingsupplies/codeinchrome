package sites

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"sync"
)

// vendor/ and node_modules/ are other people's code, and plenty of it trips
// the PHP rules honestly: Laravel's own ConfigCacheCommand and Onceable,
// Carbon's Mixin and phpseclib use eval (measured 2026-09-26: about 30 of
// 8,878 PHP files in a stock Laravel vendor/). So the rules cannot simply
// apply there - but a blanket exemption let anyone hide a webshell in
// vendor/ and require it from a route (audit item A11). The line is drawn
// where the code came from:
//   - after every successful composer run the host records the SHA-256 of
//     each PHP file under vendor/, beside the site's history and out of the
//     container's reach; a file byte for byte as composer left it is
//     exempt, anything else under vendor/ or node_modules/ gets the rules
//     in the scheduled scan (a finding goes to a person: an app uploaded
//     with its own vendor/ is honest);
//   - a PHP file saved into vendor/ from the editor that trips the rules is
//     refused outright - packages come from composer, not from hand edits.

func isDependency(rel string) bool {
	rel = strings.TrimPrefix(filepath.ToSlash(rel), "/")
	return strings.HasPrefix(rel, "vendor/") || strings.HasPrefix(rel, "node_modules/") || strings.Contains(rel, "/node_modules/")
}

func isPHPFile(rel string) bool {
	switch strings.ToLower(filepath.Ext(rel)) {
	case ".php", ".phtml", ".php5", ".php7", ".phar", ".inc":
		return true
	}
	return false
}

// ErrDependencyEdit refuses a hand edit under vendor/: not malware, no ban.
type ErrDependencyEdit struct{ Path, Why string }

func (e *ErrDependencyEdit) Error() string {
	return fmt.Sprintf("refused: %s is in vendor/ and holds code the checks only allow as composer installed it (%s). Install or update packages with composer; keep your own code in app/", e.Path, e.Why)
}

// refuseDependencyEdit: the rules for PHP saved by hand into vendor/.
func refuseDependencyEdit(rel, content string) error {
	if !isDependency(rel) || !isPHPFile(rel) {
		return nil
	}
	if why := phpObfuscation(content, false); why != "" {
		return &ErrDependencyEdit{Path: strings.TrimPrefix(rel, "/"), Why: why}
	}
	return nil
}

const maxVendorFiles = 200000

var vendorLock sync.Mutex

func (m *Manager) vendorRecordFile(id string) string {
	return filepath.Join(m.volume(id), "vendor.json")
}

// rememberVendor records every PHP file under vendor/ as composer left it.
func (m *Manager) rememberVendor(id string) {
	root, err := m.realRoot(id)
	if err != nil {
		return
	}
	known := map[string]string{}
	_ = walkBeneath(root, filepath.Join(root, "vendor"), func(p string, d fs.DirEntry, err error) error {
		if err != nil || d == nil || d.IsDir() || d.Type()&fs.ModeSymlink != 0 || !isPHPFile(p) {
			return nil
		}
		if len(known) >= maxVendorFiles {
			return fs.SkipAll
		}
		b, rerr := readBeneath(root, p, MaxUploadSize)
		if rerr != nil {
			return nil
		}
		s := sha256.Sum256(b)
		known[strings.TrimPrefix(p, root)] = hex.EncodeToString(s[:])
		return nil
	})
	b, err := json.Marshal(known)
	if err != nil {
		return
	}
	vendorLock.Lock()
	defer vendorLock.Unlock()
	tmp := m.vendorRecordFile(id) + ".tmp"
	if os.WriteFile(tmp, b, 0o600) == nil {
		_ = os.Rename(tmp, m.vendorRecordFile(id))
	}
}

// installedByComposer: the recorded hashes, for a scan to consult.
func (m *Manager) installedByComposer(id string) map[string]string {
	vendorLock.Lock()
	defer vendorLock.Unlock()
	out := map[string]string{}
	if b, err := os.ReadFile(m.vendorRecordFile(id)); err == nil {
		_ = json.Unmarshal(b, &out)
	}
	return out
}

func sumOf(b []byte) string {
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:])
}
