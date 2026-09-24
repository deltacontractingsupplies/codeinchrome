package sites

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// A deleted site's access log is kept aside as evidence, never destroyed.
func TestADeletedSitesAccessLogIsKeptAsideNotDestroyed(t *testing.T) {
	dir := t.TempDir()
	orig := deletedLogDir
	deletedLogDir = filepath.Join(dir, "deleted")
	t.Cleanup(func() { deletedLogDir = orig })

	log := filepath.Join(dir, "phish.log")
	os.WriteFile(log, []byte(`{"request":{"remote_ip":"198.51.100.4","uri":"/login"}}`+"\n"), 0o640)
	if err := keepDeletedLog(log, "phish"); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(log); !os.IsNotExist(err) {
		t.Error("the live log must be gone from its usual place")
	}
	kept, _ := filepath.Glob(filepath.Join(deletedLogDir, "phish-*Z.log"))
	if len(kept) != 1 {
		t.Fatalf("kept: %v", kept)
	}
	if b, _ := os.ReadFile(kept[0]); !strings.Contains(string(b), "/login") {
		t.Error("the kept log lost its contents")
	}
	if err := keepDeletedLog(filepath.Join(dir, "never.log"), "never"); err != nil {
		t.Errorf("a site with no log is not an error: %v", err)
	}
}
