package sites

import (
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// A tool, not a check: CORPUS=<a Laravel app> runs the obfuscation rules
// over its code and names every file they would flag - run over our own
// control plane and every live site before a rule change ships (2026-09-25:
// 257 + 394 files, no false positive).
func TestTheRulesOverACodebase(t *testing.T) {
	root := os.Getenv("CORPUS")
	if root == "" {
		t.Skip()
	}
	n := 0
	_ = filepath.WalkDir(root, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		rel := strings.TrimPrefix(p, root+"/")
		if d.IsDir() {
			if rel == "vendor" || rel == "node_modules" || rel == "storage" {
				return fs.SkipDir
			}
			return nil
		}
		if !checkedForObfuscation(rel) {
			return nil
		}
		b, _ := os.ReadFile(p)
		n++
		if why := phpObfuscation(string(b), strings.HasSuffix(rel, ".blade.php")); why != "" {
			t.Logf("FLAGGED %s: %s", rel, why)
		}
		return nil
	})
	t.Logf("scanned %d PHP files", n)
}
