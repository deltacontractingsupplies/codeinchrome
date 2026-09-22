package sites

import (
	"os"
	"path/filepath"
	"testing"
)

// A site id becomes a directory name, a container name and a DNS label. Anything
// that gets past this is a path traversal, a container-name collision or a broken
// certificate, so the cases below are deliberately hostile.
func TestValidID(t *testing.T) {
	valid := []string{
		"daleel-store", "abc", "a1b", "my-shop-2026",
		"a234567890123456789012345678901234567890", // 40, the ceiling
	}
	for _, id := range valid {
		if err := ValidID(id); err != nil {
			t.Errorf("ValidID(%q) rejected a legitimate id: %v", id, err)
		}
	}

	invalid := map[string]string{
		"":   "empty",
		"ab": "too short",
		"a2345678901234567890123456789012345678901": "41 chars, over the ceiling",
		"-lead":         "leading hyphen breaks DNS labels",
		"trail-":        "trailing hyphen breaks DNS labels",
		"Upper":         "uppercase is not valid in a DNS label",
		"under_score":   "underscore is not valid in a DNS label",
		"dot.ted":       "a dot would create a subdomain level",
		"../etc/passwd": "path traversal",
		"a/b":           "path separator",
		`a\b`:           "windows path separator",
		"a b":           "space",
		"a--b":          "consecutive hyphens collide with punycode",
		"site;rm -rf /": "shell metacharacters",
		"site$(whoami)": "command substitution",
		"site\nnewline": "newline could split a config file",
		"site\x00null":  "null byte",
		"--flag":        "could be read as a docker flag",
	}
	for id, why := range invalid {
		if err := ValidID(id); err == nil {
			t.Errorf("ValidID(%q) ACCEPTED an invalid id (%s)", id, why)
		}
	}
}

// The domain is written verbatim into a Caddy config. A value containing a brace
// or a newline could close the site block and open another one, which would let a
// customer serve on a domain that is not theirs.
func TestValidDomain(t *testing.T) {
	for _, d := range []string{"daleel.store", "shop.example.com", "a.b.c.d.example.org"} {
		if err := validDomain(d); err != nil {
			t.Errorf("validDomain(%q) rejected a legitimate domain: %v", d, err)
		}
	}

	invalid := map[string]string{
		"":                         "empty",
		"localhost":                "no dot, not a real public domain",
		"evil.com {\n}\nother.com": "braces and newlines could close the Caddy block",
		"a.com\nb.com":             "newline could inject a second site block",
		`a.com"`:                   "quote could break out of a quoted value",
		"a.com\\":                  "backslash escape",
		"has space.com":            "space",
	}
	for d, why := range invalid {
		if err := validDomain(d); err == nil {
			t.Errorf("validDomain(%q) ACCEPTED an invalid domain (%s)", d, why)
		}
	}

	long := make([]byte, 254)
	for i := range long {
		long[i] = 'a'
	}
	if err := validDomain(string(long)); err == nil {
		t.Error("validDomain accepted a 254-character domain; the limit is 253")
	}
}

func TestHostsAnswersOnlyForDomainsThisHostServes(t *testing.T) {
	root := t.TempDir()
	m, err := New(Config{Root: root, CaddyDir: t.TempDir(), HostID: "h"})
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(root, "shop"), 0o750); err != nil {
		t.Fatal(err)
	}
	if err := m.save(Site{ID: "shop", Domain: "shop.codeinchrome.com"}); err != nil {
		t.Fatal(err)
	}

	for _, d := range []string{"shop.codeinchrome.com", "SHOP.codeinchrome.com", "shop.codeinchrome.com."} {
		if !m.Hosts(d) {
			t.Errorf("Hosts(%q) = false for a domain this host serves", d)
		}
	}
	for _, d := range []string{"", "evil.com", "other.codeinchrome.com", "codeinchrome.com", "xshop.codeinchrome.com"} {
		if m.Hosts(d) {
			t.Errorf("Hosts(%q) = true: would let anyone make us request a certificate for it", d)
		}
	}
}

func TestSetEnvReplacesCommentedKeysAndKeepsEverythingElse(t *testing.T) {
	in := "APP_NAME=Laravel\nDB_CONNECTION=sqlite\n# DB_HOST=127.0.0.1\n# DB_PORT=3306\n\n# keep me\nAPP_DEBUG=false\n"
	out := SetEnv(in, "DB_CONNECTION", "mysql")
	out = SetEnv(out, "DB_HOST", "cic-db")
	out = SetEnv(out, "DB_PASSWORD", "s3cret")

	want := "APP_NAME=Laravel\nDB_CONNECTION=mysql\nDB_HOST=cic-db\n# DB_PORT=3306\n\n# keep me\nAPP_DEBUG=false\nDB_PASSWORD=s3cret\n"
	if out != want {
		t.Fatalf("SetEnv produced\n%q\nwant\n%q", out, want)
	}
	// A key that merely STARTS with another must not be touched.
	if got := SetEnv("DB_HOST_EXTRA=x\n", "DB_HOST", "y"); got != "DB_HOST_EXTRA=x\nDB_HOST=y\n" {
		t.Fatalf("prefix key was clobbered: %q", got)
	}
}

func TestDatabaseIdentifiersAreSafeForEveryValidID(t *testing.T) {
	for _, id := range []string{"abc", "my-shop", "a234567890123456789012345678901234567890"} {
		if err := ValidID(id); err != nil {
			t.Fatal(err)
		}
		if _, err := quoteIdent(DBName(id)); err != nil {
			t.Errorf("DBName(%q) = %q is not a safe identifier", id, DBName(id))
		}
		if u := DBUser(id); len(u) > 32 || !safeIdent.MatchString(u) {
			t.Errorf("DBUser(%q) = %q: MySQL user names are limited to 32 safe characters", id, u)
		}
	}
	if DBUser("a-b") == DBUser("a-c") {
		t.Error("two sites share a database user")
	}
	for _, bad := range []string{"x`; DROP DATABASE mysql; --", "a b", "", "A"} {
		if _, err := quoteIdent(bad); err == nil {
			t.Errorf("quoteIdent accepted %q", bad)
		}
	}
}

func TestHostsApprovesVerifiedAliasesOnly(t *testing.T) {
	root := t.TempDir()
	m, err := New(Config{Root: root, CaddyDir: t.TempDir(), HostID: "h"})
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(root, "shop"), 0o750); err != nil {
		t.Fatal(err)
	}
	if err := m.save(Site{ID: "shop", Domain: "shop.codeinchrome.com", Aliases: []string{"shop.example.com"}}); err != nil {
		t.Fatal(err)
	}
	if !m.Hosts("SHOP.example.com") {
		t.Error("a verified alias is not approved for a certificate")
	}
	if m.Hosts("example.com") || m.Hosts("other.example.com") {
		t.Error("an unverified name under the alias was approved")
	}
}
