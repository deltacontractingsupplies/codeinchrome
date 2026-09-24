package sites

import (
	"os"
	"os/exec"
	"path/filepath"
	"testing"
)

// The prelude runs before every language server: an index built under other
// settings (or none recorded - every index from before 0.26.1) is dropped,
// and one built under these settings is kept, so it is not rebuilt each time.
func TestTheLanguageServerPreludeDropsAnIndexBuiltUnderOtherSettings(t *testing.T) {
	root := t.TempDir()
	cache, config := filepath.Join(root, "cache"), filepath.Join(root, "config")
	index := filepath.Join(cache, "phpactor", "index", "html-1", "file_0")
	run := func() {
		t.Helper()
		cmd := exec.Command("sh", "-c", lspPrelude)
		cmd.Env = append(os.Environ(), "XDG_CACHE_HOME="+cache, "XDG_CONFIG_HOME="+config,
			"TMPDIR="+filepath.Join(cache, "tmp"), "CIC_LSP_PID="+filepath.Join(root, "pid"))
		if out, err := cmd.CombinedOutput(); err != nil {
			t.Fatalf("prelude failed: %v\n%s", err, out)
		}
	}
	seed := func() {
		os.MkdirAll(filepath.Dir(index), 0o755)
		os.WriteFile(index, []byte("vendor record"), 0o644)
	}
	exists := func(p string) bool { _, err := os.Stat(p); return err == nil }

	seed() // an index from before settings were recorded
	run()
	if exists(index) {
		t.Fatal("an index with no recorded settings must be dropped")
	}
	if b, _ := os.ReadFile(filepath.Join(config, "phpactor", "phpactor.json")); string(b) != lspConfig {
		t.Fatalf("settings not written: %q", b)
	}
	if !exists(filepath.Join(root, "pid")) {
		t.Fatal("pid file not written")
	}

	seed() // built under these settings: kept
	run()
	if !exists(index) {
		t.Fatal("an index built under the same settings must be kept")
	}

	os.WriteFile(filepath.Join(cache, "cic-index-settings"), []byte(`{"older":"settings"}`), 0o644)
	run()
	if exists(index) {
		t.Fatal("an index built under other settings must be dropped")
	}
}
