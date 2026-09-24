package sites

import (
	"os"
	"path/filepath"
	"testing"
)

func TestTheCPUCounterAndLimitAreReadFromTheCgroup(t *testing.T) {
	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, "cpu.stat"), []byte("usage_usec 320845456\nuser_usec 175386165\nsystem_usec 145459291\n"), 0o644)
	os.WriteFile(filepath.Join(dir, "cpu.max"), []byte("50000 100000\n"), 0o644)
	if n, ok := readCPUUsage(filepath.Join(dir, "cpu.stat")); !ok || n != 320845456 {
		t.Errorf("usage: %d %v", n, ok)
	}
	if q := readCPUQuota(filepath.Join(dir, "cpu.max")); q != 0.5 {
		t.Errorf("quota: %v", q)
	}
	os.WriteFile(filepath.Join(dir, "cpu.max"), []byte("max 100000\n"), 0o644)
	if q := readCPUQuota(filepath.Join(dir, "cpu.max")); q != 0 {
		t.Errorf("no limit reads as 0: %v", q)
	}
	if _, ok := readCPUUsage(filepath.Join(dir, "missing")); ok {
		t.Error("an unreadable counter must not read as zero use")
	}
}
