package sites

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"sync"
)

// A cloned repository is someone else's code, scanned when it arrived. If a
// later signature update flags one of its files, that is news for a person
// to review - not grounds to ban the customer. But only for the file exactly
// as it was cloned: a record of each file's SHA-256 is kept beside the
// site's history, outside anything the site's container can reach, so
// neither a marker file nor a back-dated mtime can claim the exemption for
// code the customer wrote.

const maxClonedFiles = 200000

var clonedLock sync.Mutex

func (m *Manager) clonedFile(id string) string { return filepath.Join(m.volume(id), "cloned.json") }

func (m *Manager) loadCloned(id string) map[string]string {
	out := map[string]string{}
	b, err := os.ReadFile(m.clonedFile(id))
	if err == nil {
		_ = json.Unmarshal(b, &out)
	}
	return out
}

// rememberCloned records the hash of each written file (site-relative).
func (m *Manager) rememberCloned(id, root string, rels []string) {
	clonedLock.Lock()
	defer clonedLock.Unlock()
	known := m.loadCloned(id)
	for _, rel := range rels {
		if len(known) >= maxClonedFiles {
			break
		}
		if sum, ok := fileSum(root, rel); ok {
			known[filepath.Clean("/"+rel)] = sum
		}
	}
	b, err := json.Marshal(known)
	if err != nil {
		return
	}
	tmp := m.clonedFile(id) + ".tmp"
	if os.WriteFile(tmp, b, 0o600) == nil {
		_ = os.Rename(tmp, m.clonedFile(id))
	}
}

// FromCloneUnchanged: is the file at rel exactly as a clone brought it?
func (m *Manager) FromCloneUnchanged(id, rel string) bool {
	if ValidID(id) != nil {
		return false
	}
	clonedLock.Lock()
	want, ok := m.loadCloned(id)[filepath.Clean("/"+rel)]
	clonedLock.Unlock()
	if !ok {
		return false
	}
	root, err := m.realRoot(id)
	if err != nil {
		return false
	}
	got, ok := fileSum(root, rel)
	return ok && got == want
}

func fileSum(root, rel string) (string, bool) {
	b, err := readBeneath(root, filepath.Join(root, filepath.Clean("/"+rel)), MaxUploadSize)
	if err != nil {
		return "", false
	}
	s := sha256.Sum256(b)
	return hex.EncodeToString(s[:]), true
}
