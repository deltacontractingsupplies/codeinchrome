package sites

import (
	"archive/zip"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// `git clone` for an agent in a browser: a public GitHub repository brought
// into a new folder of the site, as GitHub's own archive of it. There is no
// git on a site (and no network from the editor), so the host fetches it -
// only from GitHub, only over HTTPS, only up to the size an unzip may be -
// and it goes through the same checks as any archive: nothing outside the
// folder, no symlinks, every file scanned, all of it refused if one is bad.

const maxCloneDownload = 100 << 20

var (
	githubRepo = regexp.MustCompile(`^(?:https://github\.com/)?([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9._-]{1,100}?)(?:\.git)?/?$`)
	gitRef     = regexp.MustCompile(`^[A-Za-z0-9._/-]{1,100}$`)
	// Where GitHub sends an archive request; anything else is refused.
	archiveHosts = map[string]bool{"github.com": true, "codeload.github.com": true}
)

// CloneResult says what arrived.
type CloneResult struct {
	Repository string `json:"repository"`
	Ref        string `json:"ref"`
	Into       string `json:"into"`
	Files      int    `json:"files"`
	Bytes      int64  `json:"bytes"`
	Millis     int64  `json:"ms"`
}

// ParseRepository accepts https://github.com/owner/repo(.git) or owner/repo.
func ParseRepository(s string) (owner, repo string, err error) {
	m := githubRepo.FindStringSubmatch(strings.TrimSpace(s))
	if m == nil || m[2] == "." || m[2] == ".." {
		return "", "", fmt.Errorf("only public GitHub repositories can be cloned: https://github.com/owner/repo")
	}
	return m[1], m[2], nil
}

// archiveClient follows GitHub's redirect to codeload and nowhere else.
var archiveClient = &http.Client{
	Timeout: 2 * time.Minute,
	CheckRedirect: func(req *http.Request, via []*http.Request) error {
		if len(via) > 3 {
			return errors.New("too many redirects")
		}
		if req.URL.Scheme != "https" || !archiveHosts[req.URL.Hostname()] {
			return fmt.Errorf("refused a redirect to %s", req.URL.Host)
		}
		return nil
	},
}

// archiveBase is where archives are fetched; a test points it at itself.
var archiveBase = "https://github.com"

// cloneSlots: at most two clones at once on a host - each downloads up to
// 100 MB on a line every site on it shares.
var cloneSlots = make(chan struct{}, 2)

// Clone fetches owner/repo at ref (the default branch when empty) into the
// site folder into, which must not exist yet.
func (m *Manager) Clone(ctx context.Context, id, repository, ref, into string) (CloneResult, error) {
	select {
	case cloneSlots <- struct{}{}:
		defer func() { <-cloneSlots }()
	case <-time.After(20 * time.Second):
		return CloneResult{}, fmt.Errorf("this host is busy with other clones; try again in a minute")
	case <-ctx.Done():
		return CloneResult{}, ctx.Err()
	}
	start := time.Now()
	owner, repo, err := ParseRepository(repository)
	if err != nil {
		return CloneResult{}, err
	}
	if ref == "" {
		ref = "HEAD"
	}
	if !gitRef.MatchString(ref) || strings.Contains(ref, "..") {
		return CloneResult{}, fmt.Errorf("%q is not a branch, tag or commit name", ref)
	}
	if into == "" {
		into = "/" + repo
	}
	root, err := m.realRoot(id)
	if err != nil {
		return CloneResult{}, fmt.Errorf("site %q has no app directory", id)
	}
	dst, err := m.resolve(id, into)
	if err != nil {
		return CloneResult{}, err
	}
	if dst == root {
		return CloneResult{}, fmt.Errorf("clone into a new folder, not over the site itself")
	}
	pub := filepath.Join(root, "public")
	if dst == pub || strings.HasPrefix(dst, pub+string(os.PathSeparator)) {
		// A repository's .env and sources would be served from there.
		return CloneResult{}, fmt.Errorf("a repository cannot be cloned into public/: everything there is served to the world")
	}
	if _, err := os.Lstat(dst); err == nil {
		return CloneResult{}, fmt.Errorf("destination path '%s' already exists", strings.TrimPrefix(dst, root))
	}

	// Downloaded to a scratch file first: a zip is read from its end.
	tmp, err := os.CreateTemp("", "cic-clone-*.zip")
	if err != nil {
		return CloneResult{}, fmt.Errorf("no scratch space for the download")
	}
	defer os.Remove(tmp.Name())
	defer tmp.Close()
	u := fmt.Sprintf("%s/%s/%s/archive/%s.zip", archiveBase, url.PathEscape(owner), url.PathEscape(repo), strings.Join(escapeEach(strings.Split(ref, "/")), "/"))
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return CloneResult{}, err
	}
	req.Header.Set("User-Agent", "codeinchrome-agent (git clone for a site)")
	res, err := archiveClient.Do(req)
	if err != nil {
		return CloneResult{}, fmt.Errorf("could not reach GitHub: %v", err)
	}
	defer res.Body.Close()
	if res.StatusCode == http.StatusNotFound {
		return CloneResult{}, fmt.Errorf("repository %s/%s (at %s) not found, or not public", owner, repo, ref)
	}
	if res.StatusCode != http.StatusOK {
		return CloneResult{}, fmt.Errorf("GitHub answered %s", res.Status)
	}
	n, err := io.Copy(tmp, io.LimitReader(res.Body, maxCloneDownload+1))
	if err != nil {
		return CloneResult{}, fmt.Errorf("the download failed: %v", err)
	}
	if n > maxCloneDownload {
		return CloneResult{}, fmt.Errorf("the repository's archive is larger than %d MB", maxCloneDownload>>20)
	}
	zr, err := zip.NewReader(tmp, n)
	if err != nil {
		return CloneResult{}, fmt.Errorf("GitHub's archive could not be read")
	}
	// GitHub puts everything under one folder, "repo-<ref>/".
	prefix := ""
	if len(zr.File) > 0 {
		if i := strings.Index(zr.File[0].Name, "/"); i > 0 {
			prefix = zr.File[0].Name[:i+1]
		}
	}
	if err := mkdirBeneath(root, strings.TrimPrefix(dst, root)); err != nil {
		return CloneResult{}, err
	}
	files, err := extractZip(ctx, root, zr, dst, prefix)
	if err != nil {
		// Deleted through directory handles, never by path: the site's code
		// could have swapped a parent folder for a link while this ran.
		_ = removeAllBeneath(root, strings.TrimPrefix(dst, root))
		return CloneResult{}, err
	}
	var bytes int64
	for _, f := range zr.File {
		bytes += int64(f.UncompressedSize64)
	}
	m.record(ctx, id, fmt.Sprintf("clone %s/%s@%s into %s", owner, repo, ref, m.relativeTo(id, dst)))
	return CloneResult{Repository: owner + "/" + repo, Ref: ref, Into: strings.TrimPrefix(dst, root), Files: files, Bytes: bytes, Millis: time.Since(start).Milliseconds()}, nil
}

func escapeEach(parts []string) []string {
	out := make([]string, len(parts))
	for i, p := range parts {
		out[i] = url.PathEscape(p)
	}
	return out
}
