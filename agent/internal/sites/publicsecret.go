package sites

import (
	"bufio"
	"bytes"
	"fmt"
	"io"
	"io/fs"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// Everything under public/ is served to the world. A secret's NAME there is
// already refused (a .env moved or copied in; the edge's secretPath), but its
// VALUE could be written anywhere: `cat .env > public/x.txt`, the database
// password pasted into a page's script, a key copied into robots.txt. An
// agent reading the site's own content can be talked into exactly that (a
// prompt injection in a file or a page), so the host refuses it: no write
// that lands in public/ may contain one of the site's own secret values.

// secretName: the .env keys whose values must never be public.
var secretName = regexp.MustCompile(`(?i)(^APP_KEY$|KEY|SECRET|PASSWORD|PASSWD|PASS$|TOKEN|PRIVATE|CREDENTIAL|SALT|(^|_)AUTH(_|$))`)

// publicByDesign: keys that exist to be shown in a browser.
// (A browser needs these: map and search keys, a DSN for error reporting,
// OAuth client ids, client-side tokens.)
var publicByDesign = regexp.MustCompile(`(?i)(^VITE_|^MIX_|PUBLIC|PUBLISHABLE|SITE_?KEY|^PUSHER_APP_KEY$|^REVERB_APP_KEY$|CLIENT_?ID|CLIENT_SIDE|DOMAIN|_URL$|SEARCH|DSN|MAPS?_|MAPBOX)`)

type secretValue struct{ name, value string }

// siteSecrets reads the site's .env for values worth protecting: named like
// a secret, not public by design, long enough not to match by chance.
func siteSecrets(root string) []secretValue {
	f, err := openBeneath(root, ".env")
	if err != nil {
		return nil
	}
	defer f.Close()
	var out []secretValue
	sc := bufio.NewScanner(io.LimitReader(f, 1<<20))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		line = strings.TrimPrefix(line, "export ")
		name, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		name = strings.TrimSpace(name)
		value = strings.TrimSpace(value)
		if len(value) >= 2 && (value[0] == '"' || value[0] == '\'') && value[len(value)-1] == value[0] {
			value = value[1 : len(value)-1]
		}
		if !secretName.MatchString(name) || publicByDesign.MatchString(name) || strings.HasPrefix(value, "pk_") || strings.HasPrefix(value, "pk.") {
			continue
		}
		// APP_KEY's "base64:" is a label; the key is what follows.
		value = strings.TrimPrefix(value, "base64:")
		if len(value) < 12 {
			continue
		}
		out = append(out, secretValue{name, value})
	}
	return out
}

func underPublic(rel string) bool {
	rel = strings.TrimPrefix(filepath.Clean("/"+rel), "/")
	return rel == "public" || strings.HasPrefix(rel, "public/")
}

// ErrPublishesSecret is a refusal, not malware: nobody is banned for it.
type ErrPublishesSecret struct{ Name string }

func (e *ErrPublishesSecret) Error() string {
	return fmt.Sprintf("refused: this would publish the site's %s - everything in public/ is served to the world. "+
		"Keep secrets in .env and use them from PHP (config() or env() in config/); if this value is meant to be public, "+
		"give it a name with PUBLIC in it", e.Name)
}

// publishesSecret checks content bound for rel (site-relative).
func publishesSecret(root, rel string, content []byte) error {
	if !underPublic(rel) {
		return nil
	}
	for _, s := range siteSecrets(root) {
		if bytes.Contains(content, []byte(s.value)) {
			return &ErrPublishesSecret{s.name}
		}
	}
	return nil
}

// publishedSecretIn reads what is at rel (a file or a folder under root)
// and checks it: for an upload, a copy or a move that is already on disk.
func publishedSecretIn(root, rel string, secrets []secretValue) error {
	if len(secrets) == 0 {
		return nil
	}
	var found error
	_ = walkBeneath(root, filepath.Join(root, filepath.Clean("/"+rel)), func(p string, d fs.DirEntry, err error) error {
		if err != nil || d == nil || d.IsDir() || d.Type()&fs.ModeSymlink != 0 {
			return nil
		}
		b, rerr := readBeneath(root, p, MaxUploadSize)
		if rerr != nil {
			return nil
		}
		for _, s := range secrets {
			if bytes.Contains(b, []byte(s.value)) {
				found = &ErrPublishesSecret{s.name}
				return fs.SkipAll
			}
		}
		return nil
	})
	return found
}

// refuseSecretValuesIntoPublic: publishedSecretIn for a destination that is
// in public/, and nothing to do for one that is not.
func refuseSecretValuesIntoPublic(root, dst string) error {
	rel := strings.TrimPrefix(dst, root)
	if !underPublic(rel) {
		return nil
	}
	return publishedSecretIn(root, rel, siteSecrets(root))
}

// quarantineDir is where a file taken out of public/ goes: kept (the site
// may have written it at runtime, with no saved version), never served.
const quarantineDir = "storage/app/quarantine"

// SweepPublishedSecrets moves every file in public/ changed since `since`
// (the zero time: all of them) that carries one of the site's secret values
// into storage/app/quarantine/, and says what it moved. Run after code the
// host did not write itself (eval, artisan, composer) and by the scheduled
// scan, which catches what the site's own code wrote while serving a request.
func (m *Manager) SweepPublishedSecrets(id string, since time.Time) []Finding {
	if ValidID(id) != nil {
		return nil
	}
	root, err := m.realRoot(id)
	if err != nil {
		return nil
	}
	secrets := siteSecrets(root)
	if len(secrets) == 0 {
		return nil
	}
	var found []Finding
	_ = walkBeneath(root, filepath.Join(root, "public"), func(p string, d fs.DirEntry, werr error) error {
		if werr != nil || d == nil || d.IsDir() || d.Type()&fs.ModeSymlink != 0 {
			return nil
		}
		info, err := d.Info()
		if err != nil || (!since.IsZero() && info.ModTime().Before(since)) || info.Size() > MaxUploadSize {
			return nil
		}
		rel := strings.TrimPrefix(p, root)
		b, err := readBeneath(root, p, MaxUploadSize)
		if err != nil {
			return nil
		}
		for _, s := range secrets {
			if !bytes.Contains(b, []byte(s.value)) {
				continue
			}
			to := filepath.Join("/", quarantineDir, rel)
			detail := fmt.Sprintf("carried the value of %s; moved out of public/ to %s", s.name, strings.TrimPrefix(to, "/"))
			if err := quarantine(root, rel, to, b); err != nil {
				_ = removeBeneath(root, rel)
				detail = fmt.Sprintf("carried the value of %s; removed from public/ (%v)", s.name, err)
			}
			found = append(found, Finding{Path: rel, Kind: "published_secret", Detail: detail})
			break
		}
		return nil
	})
	return found
}

// quarantine writes b at `to` (a new file, never through a link) and then
// removes `from`.
func quarantine(root, from, to string, b []byte) error {
	if err := mkdirBeneath(root, filepath.Dir(to)); err != nil {
		return err
	}
	_ = removeBeneath(root, to) // an earlier copy of the same path
	f, err := createBeneath(root, to, 0o640)
	if err != nil {
		return err
	}
	if _, err := f.Write(b); err != nil {
		f.Close()
		return err
	}
	if err := f.Close(); err != nil {
		return err
	}
	return removeBeneath(root, from)
}

// UnpublishSecretsSince is the sweep as a refusal, for eval and commands.
func (m *Manager) UnpublishSecretsSince(id string, t time.Time) error {
	found := m.SweepPublishedSecrets(id, t)
	if len(found) == 0 {
		return nil
	}
	name := strings.TrimPrefix(strings.SplitN(found[0].Detail, ";", 2)[0], "carried the value of ")
	return fmt.Errorf("%w - %s", &ErrPublishesSecret{name}, found[0].Detail)
}
