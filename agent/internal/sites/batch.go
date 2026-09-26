package sites

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// Fewer, bigger calls for agents. An agent building an app writes many files
// and makes small changes to existing ones; one browser round trip and one
// history commit per file made a five-file change slow for no reason. These
// keep every guarantee of a single write - the size limit, the kernel-checked
// path, the revision check - and record ONE version for the whole change.

const (
	maxBatchFiles = 200
	maxBatchBytes = 16 << 20
)

// FileWrite is one file of a batch; Expect as in WriteFileIf.
type FileWrite struct {
	Path    string `json:"path"`
	Content string `json:"content"`
	Expect  string `json:"expect"`
}

// WrittenFile reports one file of a batch.
type WrittenFile struct {
	Path     string `json:"path"`
	Bytes    int    `json:"bytes"`
	Revision string `json:"revision"`
	// Created: the file is new (the editor marks it A, not M).
	Created bool `json:"created"`
	// For PHP files: "ok", or the syntax error with its line, from php -l in
	// the site's own container. Written either way - the file is the caller's
	// to fix - but they learn now, not from a 500 later.
	Lint string `json:"lint,omitempty"`
}

// BatchError names the file a batch stopped at. Nothing was written.
type BatchError struct {
	Path string
	Err  error
}

func (e *BatchError) Error() string { return e.Path + ": " + e.Err.Error() }
func (e *BatchError) Unwrap() error { return e.Err }

// WriteMany checks every file first and writes only if all of them pass, so a
// conflict or a bad path in file 7 does not leave files 1-6 half-applied.
func (m *Manager) WriteMany(ctx context.Context, id string, files []FileWrite, message string) ([]WrittenFile, error) {
	if len(files) == 0 {
		return nil, fmt.Errorf("no files given")
	}
	if len(files) > maxBatchFiles {
		return nil, fmt.Errorf("%d files; at most %d in one call", len(files), maxBatchFiles)
	}
	total := 0
	seen := map[string]bool{}
	for _, f := range files {
		total += len(f.Content)
		key := filepath.Clean("/" + f.Path)
		if seen[key] {
			return nil, &BatchError{f.Path, fmt.Errorf("the same file twice in one batch")}
		}
		seen[key] = true
	}
	if total > maxBatchBytes {
		return nil, fmt.Errorf("%d bytes in one call; the limit is %d", total, maxBatchBytes)
	}

	written, err := func() ([]WrittenFile, error) {
		m.mu.Lock()
		defer m.mu.Unlock()
		type ready struct {
			root, rel string
			existed   bool
		}
		prepared := make([]ready, len(files))
		for i, f := range files {
			root, rel, existed, err := m.checkWrite(id, f.Path, f.Content, f.Expect)
			if err != nil {
				return nil, &BatchError{f.Path, err}
			}
			prepared[i] = ready{root, rel, existed}
		}
		out := make([]WrittenFile, 0, len(files))
		for i, f := range files {
			rev, err := writePrepared(prepared[i].root, prepared[i].rel, f.Content)
			if err != nil {
				// A disk error after the checks passed: what was written is
				// reported, and recorded below, rather than hidden.
				return out, &BatchError{f.Path, err}
			}
			out = append(out, WrittenFile{Path: f.Path, Bytes: len(f.Content), Revision: rev, Created: !prepared[i].existed})
		}
		return out, nil
	}()
	if len(written) > 0 {
		m.record(ctx, id, batchMessage(message, written))
		m.lintWritten(ctx, id, written)
	}
	return written, err
}

// lintWritten fills in Lint for the PHP files among written, in one php -l
// run for all of them (PHP 8.3 lints several files at once).
func (m *Manager) lintWritten(ctx context.Context, id string, written []WrittenFile) {
	var paths []string
	for _, w := range written {
		if isLintable(w.Path) {
			paths = append(paths, cleanRel(w.Path))
		}
	}
	if len(paths) == 0 {
		return
	}
	results := m.lintPHP(ctx, id, paths)
	for i := range written {
		if r, ok := results[cleanRel(written[i].Path)]; ok {
			written[i].Lint = r
		}
	}
}

func isLintable(p string) bool {
	return strings.HasSuffix(p, ".php") && !strings.HasSuffix(p, ".blade.php")
}

func cleanRel(p string) string { return filepath.Clean("/" + p) }

var lintError = regexp.MustCompile(`(?m)PHP (?:Parse|Fatal) error:\s+(.*) in /var/www/html(/\S+) on line (\d+)`)

// lintCommand runs php -l in the site's container; a variable so tests can
// stand in for Docker.
var lintCommand = func(ctx context.Context, container string, files []string) *exec.Cmd {
	args := append([]string{"exec", "-u", "33:33", container, "php", "-d", "error_log=", "-d", "log_errors=1", "-l"}, files...)
	return exec.CommandContext(ctx, "docker", args...)
}

// lintPHP answers "ok" or "line N: message" for each site-relative path. A
// container that cannot be asked (stopped, busy) answers nothing - no answer
// is not a verdict.
func (m *Manager) lintPHP(ctx context.Context, id string, rels []string) map[string]string {
	if len(rels) > 50 {
		rels = rels[:50]
	}
	files := make([]string, len(rels))
	for i, r := range rels {
		files[i] = "/var/www/html" + r
	}
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	out, _ := lintCommand(ctx, m.container(id), files).CombinedOutput()
	text := string(out)
	results := map[string]string{}
	for _, r := range rels {
		if strings.Contains(text, "No syntax errors detected in /var/www/html"+r) {
			results[r] = "ok"
		}
	}
	for _, mm := range lintError.FindAllStringSubmatch(text, -1) {
		results[mm[2]] = "line " + mm[3] + ": " + mm[1]
	}
	return results
}

func batchMessage(message string, files []WrittenFile) string {
	if message = strings.TrimSpace(message); message != "" {
		if len(message) > 200 {
			message = message[:200]
		}
		return message
	}
	names := make([]string, 0, 4)
	for i, f := range files {
		if i == 3 {
			names = append(names, fmt.Sprintf("and %d more", len(files)-3))
			break
		}
		names = append(names, strings.TrimPrefix(filepath.Clean("/"+f.Path), "/"))
	}
	return "save " + strings.Join(names, ", ")
}

// Edit is one find-and-replace. Find must occur exactly once, unless All.
type Edit struct {
	Find    string `json:"find"`
	Replace string `json:"replace"`
	All     bool   `json:"all"`
}

// EditFile applies edits to a file in order and saves it as one version. It
// fails - writing nothing - if any Find is missing or, without All, appears
// more than once: a replacement in the wrong place is worse than none.
func (m *Manager) EditFile(ctx context.Context, id, rel string, edits []Edit, expect string) (WrittenFile, error) {
	if len(edits) == 0 {
		return WrittenFile{}, fmt.Errorf("no edits given")
	}
	current, rev, err := m.ReadFileRevision(ctx, id, rel)
	if err != nil {
		return WrittenFile{}, err
	}
	if expect != "" && expect != rev {
		return WrittenFile{}, ErrConflict
	}
	text := current
	for i, e := range edits {
		if e.Find == "" {
			return WrittenFile{}, fmt.Errorf("edit %d: find is empty", i+1)
		}
		n := strings.Count(text, e.Find)
		switch {
		case n == 0:
			return WrittenFile{}, fmt.Errorf("edit %d: the text to find is not in the file (after the edits before it)", i+1)
		case n > 1 && !e.All:
			return WrittenFile{}, fmt.Errorf("edit %d: the text to find appears %d times; add surrounding lines to make it unique, or pass all: true", i+1, n)
		}
		text = strings.ReplaceAll(text, e.Find, e.Replace)
	}
	// Written only if the file is still what was edited: a save in between
	// is a conflict, not something to overwrite.
	newRev, _, err := m.writeFileIf(ctx, id, rel, text, rev, "edit "+strings.TrimPrefix(filepath.Clean("/"+rel), "/"))
	if err != nil {
		return WrittenFile{}, err
	}
	out := []WrittenFile{{Path: rel, Bytes: len(text), Revision: newRev}}
	m.lintWritten(ctx, id, out)
	return out[0], nil
}

// SiteRequest is a request to the site itself, made from its own host.
type SiteRequest struct {
	Method  string            `json:"method"`
	Path    string            `json:"path"`
	Headers map[string]string `json:"headers"`
	Body    string            `json:"body"`
}

// SiteResponse is what the site answered. Body is capped; Truncated says so.
type SiteResponse struct {
	Status    int               `json:"status"`
	Headers   map[string]string `json:"headers"`
	Body      string            `json:"body"`
	Truncated bool              `json:"truncated"`
	Ms        int64             `json:"ms"`
}

const maxSiteResponse = 1 << 20

var siteClient = &http.Client{
	Timeout: 30 * time.Second,
	// A redirect is an answer to report, not to follow: the agent is testing
	// what its app does, and "302 to /login" is exactly that.
	CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
}

// allowedRequestHeaders is what an agent may set. Nothing that changes where
// the request goes or who it claims to come from.
var allowedRequestHeaders = map[string]bool{
	"accept": true, "accept-language": true, "content-type": true, "cookie": true,
	"authorization": true, "x-requested-with": true, "x-csrf-token": true, "x-xsrf-token": true,
	"if-none-match": true, "if-modified-since": true, "user-agent": true,
}

// Request asks the site a question the way a visitor would - its own Apache,
// on its own loopback port, with its real name - so an agent can test what it
// built without a browser tab per page and without cross-origin refusals. It
// can reach nothing but this one site.
func (m *Manager) Request(ctx context.Context, id string, req SiteRequest) (SiteResponse, error) {
	if err := ValidID(id); err != nil {
		return SiteResponse{}, err
	}
	site, err := m.load(id)
	if err != nil {
		return SiteResponse{}, fmt.Errorf("no such site %q", id)
	}
	if site.Suspended {
		return SiteResponse{}, fmt.Errorf("the site is paused")
	}
	method := strings.ToUpper(strings.TrimSpace(req.Method))
	if method == "" {
		method = http.MethodGet
	}
	switch method {
	case http.MethodGet, http.MethodHead, http.MethodPost, http.MethodPut, http.MethodPatch, http.MethodDelete, http.MethodOptions:
	default:
		return SiteResponse{}, fmt.Errorf("method %s is not allowed", method)
	}
	// A path on this site, and nothing else: no scheme, no host, no userinfo.
	if !strings.HasPrefix(req.Path, "/") || strings.HasPrefix(req.Path, "//") || strings.ContainsAny(req.Path, " \r\n\\") {
		return SiteResponse{}, fmt.Errorf("path must be a path on the site, starting with a single /")
	}
	if len(req.Body) > MaxFileSize {
		return SiteResponse{}, fmt.Errorf("body is %d bytes; the limit is %d", len(req.Body), MaxFileSize)
	}

	r, err := http.NewRequestWithContext(ctx, method, fmt.Sprintf("http://127.0.0.1:%d%s", site.Port, req.Path), strings.NewReader(req.Body))
	if err != nil {
		return SiteResponse{}, fmt.Errorf("cannot build that request: %v", err)
	}
	r.Host = site.Domain
	for k, v := range req.Headers {
		if !allowedRequestHeaders[strings.ToLower(k)] {
			return SiteResponse{}, fmt.Errorf("header %q cannot be set", k)
		}
		r.Header.Set(k, v)
	}
	if r.Header.Get("User-Agent") == "" {
		r.Header.Set("User-Agent", "codeinchrome-editor")
	}
	start := time.Now()
	resp, err := siteClient.Do(r)
	if err != nil {
		return SiteResponse{}, fmt.Errorf("the site did not answer: %v", err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, maxSiteResponse+1))
	out := SiteResponse{Status: resp.StatusCode, Headers: map[string]string{}, Ms: time.Since(start).Milliseconds()}
	if len(body) > maxSiteResponse {
		body, out.Truncated = body[:maxSiteResponse], true
	}
	out.Body = string(body)
	for k, v := range resp.Header {
		out.Headers[strings.ToLower(k)] = strings.Join(v, ", ")
	}
	// Several cookies do not survive a comma join; keep them apart.
	if c := resp.Header.Values("Set-Cookie"); len(c) > 1 {
		out.Headers["set-cookie"] = strings.Join(c, "\n")
	}
	return out, nil
}
