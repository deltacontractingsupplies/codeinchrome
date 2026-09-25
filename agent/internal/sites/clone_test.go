package sites

import (
	"archive/zip"
	"bytes"
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// A stand-in for GitHub: /owner/repo/archive/<ref>.zip answers with the zip
// the test gives it, under the "repo-sha/" folder GitHub uses.
func fakeGitHub(t *testing.T, files map[string]string) *[]string {
	t.Helper()
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	zw.Create("demo-abc123/")
	for name, content := range files {
		w, _ := zw.Create("demo-abc123/" + name)
		w.Write([]byte(content))
	}
	zw.Close()
	var asked []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		asked = append(asked, r.URL.Path)
		switch r.URL.Path {
		case "/acme/demo/archive/HEAD.zip", "/acme/demo/archive/v1.2.zip":
			w.Write(buf.Bytes())
		case "/acme/redirect/archive/HEAD.zip":
			http.Redirect(w, r, "https://evil.example.com/x.zip", http.StatusFound)
		default:
			http.NotFound(w, r)
		}
	}))
	t.Cleanup(srv.Close)
	orig := archiveBase
	archiveBase = srv.URL
	t.Cleanup(func() { archiveBase = orig })
	return &asked
}

func TestCloneBringsAPublicRepositoryIntoANewFolder(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	asked := fakeGitHub(t, map[string]string{
		"composer.json":        `{"name":"acme/demo"}`,
		"app/Models/Thing.php": "<?php\n\nclass Thing {}\n",
		"routes/web.php":       "<?php\n",
	})

	r, err := m.Clone(ctx, id, "https://github.com/acme/demo.git", "", "")
	if err != nil {
		t.Fatal(err)
	}
	if r.Into != "/demo" || r.Files != 3 || r.Repository != "acme/demo" || r.Ref != "HEAD" {
		t.Fatalf("result %+v", r)
	}
	got, _ := os.ReadFile(filepath.Join(m.appDir(id), "demo/app/Models/Thing.php"))
	if string(got) != "<?php\n\nclass Thing {}\n" {
		t.Fatalf("the file arrived as %q (GitHub's top folder must be taken off)", got)
	}
	// owner/repo shorthand, a tag, and a folder of one's choosing.
	if _, err := m.Clone(ctx, id, "acme/demo", "v1.2", "/vendor-src/demo"); err != nil {
		t.Fatal(err)
	}
	if (*asked)[len(*asked)-1] != "/acme/demo/archive/v1.2.zip" {
		t.Fatalf("asked GitHub for %v", *asked)
	}

	// Not over an existing folder, the site, or public/.
	for _, into := range []string{"/demo", "/", "/public/demo", "/public"} {
		if _, err := m.Clone(ctx, id, "acme/demo", "", into); err == nil {
			t.Fatalf("clone into %s was accepted", into)
		}
	}
}

func TestCloneRefusesAnythingButAPublicGitHubRepository(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	fakeGitHub(t, map[string]string{"a.txt": "a"})
	for _, repo := range []string{
		"https://gitlab.com/acme/demo", "http://github.com/acme/demo", "https://github.com/acme",
		"https://github.com/acme/demo/../../x", "file:///etc/passwd", "acme/..", "-oProxyCommand=x/y",
		"https://github.com@evil.example.com/acme/demo",
	} {
		if _, err := m.Clone(ctx, id, repo, "", "/x"); err == nil {
			t.Fatalf("%q was accepted", repo)
		}
	}
	for _, ref := range []string{"../x", "a b", "main;rm", strings.Repeat("a", 101)} {
		if _, err := m.Clone(ctx, id, "acme/demo", ref, "/x"); err == nil {
			t.Fatalf("ref %q was accepted", ref)
		}
	}
	// A repository that is not there, and a redirect anywhere but GitHub.
	if _, err := m.Clone(ctx, id, "acme/missing", "", "/x"); err == nil || !strings.Contains(err.Error(), "not found") {
		t.Fatalf("a missing repository: %v", err)
	}
	if _, err := m.Clone(ctx, id, "acme/redirect", "", "/x"); err == nil {
		t.Fatal("a redirect off GitHub was followed")
	}
	if _, err := os.Stat(filepath.Join(m.appDir(id), "x")); err == nil {
		t.Fatal("a refused clone left its folder behind")
	}
}

// The same scan as any upload: one bad file and none of it is kept.
func TestCloneOfMalwareKeepsNothing(t *testing.T) {
	m, id := historyManager(t)
	fakeGitHub(t, map[string]string{
		"ok.php":    "<?php echo 'fine';\n",
		"shell.php": "<?php eval(base64_decode($_POST['x']));\n",
	})
	_, err := m.Clone(context.Background(), id, "acme/demo", "", "/kit")
	var bad *ErrMalware
	if !errors.As(err, &bad) {
		t.Fatalf("a repository with a webshell: want a malware refusal, got %v", err)
	}
	if _, err := os.Stat(filepath.Join(m.appDir(id), "kit")); err == nil {
		t.Fatal("the refused clone left files behind")
	}
}

// A file flagged by a later scan counts as the repository's only while it is
// byte for byte what was cloned.
func TestOnlyAnUnchangedClonedFileIsTheRepositorys(t *testing.T) {
	m, id := historyManager(t)
	ctx := context.Background()
	fakeGitHub(t, map[string]string{"lib/a.php": "<?php echo 'a';\n", "lib/b.php": "<?php echo 'b';\n"})
	if _, err := m.Clone(ctx, id, "acme/demo", "", "/demo"); err != nil {
		t.Fatal(err)
	}
	if !m.FromCloneUnchanged(id, "/demo/lib/a.php") {
		t.Fatal("a cloned file is not recognised")
	}
	// Changed, added, or somewhere else: the customer's own.
	m.WriteFile(ctx, id, "/demo/lib/b.php", "<?php echo 'changed';\n")
	m.WriteFile(ctx, id, "/demo/lib/new.php", "<?php echo 'new';\n")
	m.WriteFile(ctx, id, "/app/a.php", "<?php echo 'a';\n") // same bytes, other path
	for _, p := range []string{"/demo/lib/b.php", "/demo/lib/new.php", "/app/a.php", "/demo/../demo/lib/missing.php"} {
		if m.FromCloneUnchanged(id, p) {
			t.Fatalf("%s counted as the repository's", p)
		}
	}
	// The record is out of the site's reach.
	if _, err := os.Stat(filepath.Join(m.appDir(id), "cloned.json")); err == nil {
		t.Fatal("the record is inside the app directory")
	}
}
