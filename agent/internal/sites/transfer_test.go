package sites

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func tarGz(t *testing.T, entries map[string]string, extra ...*tar.Header) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	for name, body := range entries {
		if strings.HasSuffix(name, "/") {
			tw.WriteHeader(&tar.Header{Name: name, Typeflag: tar.TypeDir, Mode: 0o700})
			continue
		}
		tw.WriteHeader(&tar.Header{Name: name, Typeflag: tar.TypeReg, Mode: 0o600, Size: int64(len(body))})
		tw.Write([]byte(body))
	}
	for _, h := range extra {
		tw.WriteHeader(h)
	}
	tw.Close()
	gz.Close()
	return buf.Bytes()
}

func TestHistoryMovesWholeAndNothingElseGetsIn(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "routes/web.php", "<?php // v1")
	m.WriteFile(ctx, id, "routes/web.php", "<?php // v2")

	var out bytes.Buffer
	if err := m.ExportHistory(ctx, id, &out); err != nil {
		t.Fatal(err)
	}

	// Onto another site (another host, in real life): the versions come across.
	other, _ := historyManager(t)
	if err := other.ImportHistory(ctx, id, bytes.NewReader(out.Bytes())); err != nil {
		t.Fatal(err)
	}
	os.MkdirAll(other.appDir(id), 0o755)
	versions, err := other.History(ctx, id, "routes/web.php", 10)
	if err != nil || len(versions) < 2 {
		t.Fatalf("history after the move: %v %+v", err, versions)
	}

	outside := filepath.Join(filepath.Dir(other.historyDir(id)), "escaped")
	for name, archive := range map[string][]byte{
		"a path outside history.git": tarGz(t, map[string]string{"history.git/": "", "escaped": "x"}),
		"a climb out":                tarGz(t, map[string]string{"history.git/../escaped": "x"}),
		"a symlink":                  tarGz(t, map[string]string{"history.git/": ""}, &tar.Header{Name: "history.git/link", Typeflag: tar.TypeSymlink, Linkname: "/etc"}),
	} {
		if err := other.ImportHistory(ctx, id, bytes.NewReader(archive)); err == nil {
			t.Errorf("%s was accepted", name)
		}
		if _, err := os.Lstat(outside); err == nil {
			t.Fatalf("%s wrote outside the history", name)
		}
	}
	// A refused import leaves the history that was there.
	if v, _ := other.History(ctx, id, "routes/web.php", 10); len(v) < 2 {
		t.Fatal("a refused import damaged the existing history")
	}
}

func TestAPausedSiteIsNotMoved(t *testing.T) {
	m, id := historyManager(t)
	m.save(Site{ID: id, Suspended: true})
	if err := m.ExportFiles(context.Background(), id, &bytes.Buffer{}); err == nil || !strings.Contains(err.Error(), "paused") {
		t.Fatalf("a paused site was exported: %v", err)
	}
}
