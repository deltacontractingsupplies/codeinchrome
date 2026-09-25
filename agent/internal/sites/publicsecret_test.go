package sites

import (
	"archive/zip"
	"bytes"
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// A made-up .env: every value here is fake.
const fakeEnv = `APP_NAME=Shop
APP_KEY=base64:ZmFrZS1rZXktZm9yLXRlc3RzLW9ubHktMTIzNDU2Nzg=
DB_PASSWORD="fake-db-password-42"
STRIPE_KEY=pk_test_fakepublishable123456
STRIPE_SECRET=fake-stripe-secret-value-123456
VITE_APP_NAME=ShopFrontEndName
MAPS_PUBLIC_KEY=public-maps-key-123456
SHORT_TOKEN=abc
MAPBOX_ACCESS_TOKEN=pk.eyJ1IjoiZmFrZSJ9.fakefakefake
SENTRY_LARAVEL_DSN=https://fakekey@o1.ingest.sentry.io/1
GOOGLE_OAUTH_CLIENT_ID=123-fake.apps.googleusercontent.com
AUTH0_DOMAIN=fake-tenant.eu.auth0.com
ALGOLIA_SEARCH_KEY=fakesearchonlykey123
BASIC_AUTH_PASSWORD=fake-basic-password
`

func secretSite(t *testing.T) (*Manager, string, string) {
	t.Helper()
	m, id := historyManager(t)
	app := m.appDir(id)
	os.WriteFile(filepath.Join(app, ".env"), []byte(fakeEnv), 0o640)
	os.MkdirAll(filepath.Join(app, "public"), 0o755)
	return m, id, app
}

func isPublishRefusal(err error) bool {
	var e *ErrPublishesSecret
	return errors.As(err, &e)
}

func TestTheSitesSecretsCannotBeWrittenIntoPublic(t *testing.T) {
	m, id, _ := secretSite(t)
	ctx := context.Background()
	for _, leak := range []string{
		"ZmFrZS1rZXktZm9yLXRlc3RzLW9ubHktMTIzNDU2Nzg=",                // APP_KEY without its label
		"APP_KEY=base64:ZmFrZS1rZXktZm9yLXRlc3RzLW9ubHktMTIzNDU2Nzg=", // the whole line
		"<script>const p = 'fake-db-password-42';</script>",           // a password in a page
		"fake-stripe-secret-value-123456",
		fakeEnv, // cat .env > public/x.txt
	} {
		for _, path := range []string{"/public/leak.txt", "/public/js/app.js", "/public/index.html"} {
			if err := m.WriteFile(ctx, id, path, leak); !isPublishRefusal(err) {
				t.Fatalf("%s with %.30q: want a refusal, got %v", path, leak, err)
			}
		}
		if _, err := m.WriteMany(ctx, id, []FileWrite{{Path: "/public/ok.css", Content: "a{}"}, {Path: "/public/leak.txt", Content: leak}}, ""); err == nil {
			t.Fatalf("a batch carried %.30q into public/", leak)
		}
	}
	if _, err := os.Stat(filepath.Join(m.appDir(id), "public/ok.css")); err == nil {
		t.Fatal("a refused batch wrote some of its files")
	}
	// Outside public/, and public-by-design values, are fine.
	for path, content := range map[string]string{
		"/config/copy.txt":     fakeEnv,
		"/public/js/stripe.js": "Stripe('pk_test_fakepublishable123456')",
		"/public/js/name.js":   "'ShopFrontEndName'",
		"/public/js/maps.js":   "'public-maps-key-123456'",
		"/public/short.txt":    "abc",
	} {
		if err := m.WriteFile(ctx, id, path, content); err != nil {
			t.Fatalf("%s was refused: %v", path, err)
		}
	}
}

func TestSecretsCannotBeCopiedMovedUploadedOrUnzippedIntoPublic(t *testing.T) {
	m, id, app := secretSite(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "/notes/keys.txt", "db: fake-db-password-42\n")
	m.WriteFile(ctx, id, "/notes/dir/k.txt", "fake-stripe-secret-value-123456")

	if err := m.Copy(ctx, id, "/notes/keys.txt", "/public/keys.txt"); !isPublishRefusal(err) {
		t.Fatalf("copy: %v", err)
	}
	if err := m.Copy(ctx, id, "/notes/dir", "/public/dir"); !isPublishRefusal(err) {
		t.Fatalf("copy of a folder: %v", err)
	}
	if err := m.Rename(ctx, id, "/notes/keys.txt", "/public/keys.txt"); !isPublishRefusal(err) {
		t.Fatalf("move: %v", err)
	}
	if _, err := os.Stat(filepath.Join(app, "notes/keys.txt")); err != nil {
		t.Fatal("a refused move lost the file")
	}
	if err := m.Upload(ctx, id, "/public/up.txt", strings.NewReader("x fake-db-password-42 y")); !isPublishRefusal(err) {
		t.Fatalf("upload: %v", err)
	}
	if _, err := os.Stat(filepath.Join(app, "public/up.txt")); err == nil {
		t.Fatal("a refused upload was kept")
	}
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	w, _ := zw.Create("leak.txt")
	w.Write([]byte("fake-db-password-42"))
	zw.Close()
	m.Upload(ctx, id, "/a.zip", bytes.NewReader(buf.Bytes()))
	if err := m.Unzip(ctx, id, "/a.zip", "/public/z"); !isPublishRefusal(err) {
		t.Fatalf("unzip: %v", err)
	}
	if _, err := os.Stat(filepath.Join(app, "public/z/leak.txt")); err == nil {
		t.Fatal("a refused unzip left the file")
	}
	// Anywhere else they may go.
	if err := m.Copy(ctx, id, "/notes/keys.txt", "/storage/keys.txt"); err != nil {
		t.Fatal(err)
	}
}

func TestSiteSecretsReadsOnlyWhatMatters(t *testing.T) {
	_, _, app := secretSite(t)
	var names []string
	for _, s := range siteSecrets(app) {
		names = append(names, s.name)
	}
	if strings.Join(names, ",") != "APP_KEY,DB_PASSWORD,STRIPE_SECRET,BASIC_AUTH_PASSWORD" {
		t.Fatalf("secrets: %v", names)
	}
}

// Keys a browser needs are not refused in public/.
func TestPublicByDesignKeysAreAllowedInPublic(t *testing.T) {
	m, id, _ := secretSite(t)
	for _, v := range []string{"pk.eyJ1IjoiZmFrZSJ9.fakefakefake", "https://fakekey@o1.ingest.sentry.io/1",
		"123-fake.apps.googleusercontent.com", "fake-tenant.eu.auth0.com", "fakesearchonlykey123"} {
		if err := m.WriteFile(context.Background(), id, "/public/js/app.js", "const k = '"+v+"';"); err != nil {
			t.Fatalf("%s was refused: %v", v, err)
		}
	}
}

func TestNoArchiveInPublicAndARefusedUploadKeepsTheOldFile(t *testing.T) {
	m, id, app := secretSite(t)
	ctx := context.Background()
	m.WriteFile(ctx, id, "/app/a.txt", "a")
	if err := m.Zip(ctx, id, "/app", "/public/site.zip"); err == nil {
		t.Fatal("an archive was made in public/")
	}
	if err := m.Zip(ctx, id, "/app", "/backup.zip"); err != nil {
		t.Fatal(err)
	}
	m.WriteFile(ctx, id, "/public/robots.txt", "User-agent: *")
	if err := m.Upload(ctx, id, "/public/robots.txt", strings.NewReader("fake-db-password-42")); !isPublishRefusal(err) {
		t.Fatalf("upload: %v", err)
	}
	if got, _ := os.ReadFile(filepath.Join(app, "public/robots.txt")); string(got) != "User-agent: *" {
		t.Fatalf("a refused upload replaced the file: %q", got)
	}
}

// eval, artisan and composer run the site's own code: what it put in public/
// with a secret in it is taken away afterwards.
func TestUnpublishSecretsSinceRemovesWhatCodeWrote(t *testing.T) {
	m, id, app := secretSite(t)
	started := time.Now().Add(-time.Second)
	os.MkdirAll(filepath.Join(app, "public/x"), 0o755)
	os.WriteFile(filepath.Join(app, "public/x/leak.txt"), []byte(fakeEnv), 0o644)
	os.WriteFile(filepath.Join(app, "public/fine.txt"), []byte("hello"), 0o644)
	err := m.UnpublishSecretsSince(id, started)
	if !isPublishRefusal(err) {
		t.Fatalf("want a refusal, got %v", err)
	}
	if _, err := os.Stat(filepath.Join(app, "public/x/leak.txt")); err == nil {
		t.Fatal("the leaked file is still served")
	}
	if got, _ := os.ReadFile(filepath.Join(app, "storage/app/quarantine/public/x/leak.txt")); string(got) != fakeEnv {
		t.Fatal("the file was not kept in quarantine")
	}
	if _, err := os.Stat(filepath.Join(app, "public/fine.txt")); err != nil {
		t.Fatal("an innocent file was removed")
	}
	// Files older than the run are not its doing.
	old := time.Now().Add(-time.Hour)
	os.WriteFile(filepath.Join(app, "public/old.txt"), []byte("fake-db-password-42"), 0o644)
	os.Chtimes(filepath.Join(app, "public/old.txt"), old, old)
	if err := m.UnpublishSecretsSince(id, time.Now().Add(-time.Minute)); err != nil {
		t.Fatalf("an older file was blamed on this run: %v", err)
	}
}

// The scheduled scan's sweep: any age, reported as a finding for review.
func TestTheScheduledSweepQuarantinesOldLeaksToo(t *testing.T) {
	m, id, app := secretSite(t)
	old := time.Now().Add(-48 * time.Hour)
	os.WriteFile(filepath.Join(app, "public/debug.txt"), []byte("pw fake-db-password-42"), 0o644)
	os.Chtimes(filepath.Join(app, "public/debug.txt"), old, old)
	os.WriteFile(filepath.Join(app, "public/ok.txt"), []byte("fine"), 0o644)
	found := m.SweepPublishedSecrets(id, time.Time{})
	if len(found) != 1 || found[0].Kind != "published_secret" || found[0].Path != "/public/debug.txt" || !strings.Contains(found[0].Detail, "DB_PASSWORD") {
		t.Fatalf("findings %+v", found)
	}
	if _, err := os.Stat(filepath.Join(app, "public/ok.txt")); err != nil {
		t.Fatal("an innocent file was moved")
	}
	if len(m.SweepPublishedSecrets(id, time.Time{})) != 0 {
		t.Fatal("a second sweep found it again")
	}
}
