package sites

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// PHP hidden in an image, and an include that runs a non-PHP file (A8/A10).
func TestAnIncludeOfANonPHPFileIsCaught(t *testing.T) {
	for _, code := range []string{
		`<?php include storage_path('app/avatars/me.jpg');`,
		`<?php require_once base_path("public/img/logo.png");`,
		`<?php include(__DIR__ . '/../storage/logs/laravel.log');`,
		`<?php require '/tmp/x.txt';`,
	} {
		if why := phpObfuscation(code); why != "a non-PHP file included as code" {
			t.Errorf("%s: %q", code, why)
		}
	}
	for _, code := range []string{
		`<?php require __DIR__.'/../vendor/autoload.php';`,
		`<?php require base_path('routes/console.php');`,
		`<?php include $path;`,
		`@include('partials.nav')`,
		`<?php $css = 'app.css'; echo view('x');`,
		`<?php return ['logo' => 'img/logo.png'];`,
		`<?php include public_path('icons/cart.svg'); ?>`,                // inlining an SVG is honest
		`<style><?php include resource_path('css/mail.css'); ?></style>`, // and CSS
	} {
		if why := phpObfuscation(code, strings.HasPrefix(code, "@")); why != "" {
			t.Errorf("false positive %s: %q", code, why)
		}
	}
}

func TestPHPHiddenInAnImageIsRefusedOnUploadAndFoundByTheScan(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	png := append([]byte("\x89PNG\r\n\x1a\n\x00\x00"), []byte("<?PHP system($_GET['c']); ?>")...)
	err := m.Upload(ctx, id, "/storage/app/avatars/me.png", bytes.NewReader(png))
	if bad, ok := IsMalware(err); !ok || bad.Findings[0].Detail != "PHP code hidden in an image" {
		t.Fatalf("upload: %v", err)
	}
	if _, err := os.Stat(filepath.Join(m.appDir(id), "storage/app/avatars/me.png")); err == nil {
		t.Fatal("the image was kept")
	}
	// An honest image, including one with "<?=" by chance, is fine.
	if err := m.Upload(ctx, id, "/public/ok.png", bytes.NewReader([]byte("\x89PNG\r\n<?=\x01\x02"))); err != nil {
		t.Fatalf("an honest image: %v", err)
	}
	// Written some other way (the site's own code), the scan finds it.
	os.WriteFile(filepath.Join(m.appDir(id), "public/pic.jpg"), png, 0o644)
	found, _ := m.ScanSite(ctx, id)
	hit := false
	for _, f := range found {
		hit = hit || (f.Path == "/public/pic.jpg" && f.Kind == "obfuscated")
	}
	if !hit {
		t.Fatalf("the scan missed PHP in an image: %v", found)
	}
}
