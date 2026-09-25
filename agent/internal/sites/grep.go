package sites

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io/fs"
	"path"
	"regexp"
	"strings"
	"time"
)

// GrepOptions is what `grep -r` needs beyond the pattern: the editor's
// cic.sh gives an agent in a browser the shell's own commands, and each flag
// here is one of grep's.
type GrepOptions struct {
	Pattern    string
	Regex      bool     // -E: an RE2 expression (linear time, so no pattern can hang the host)
	IgnoreCase bool     // -i
	Word       bool     // -w
	Under      string   // the file or folder searched; "/" is the whole site
	Include    []string // --include=GLOB, matched against the file's name
	Limit      int
}

// GrepResult is the matches, and whether the search stopped early.
type GrepResult struct {
	Hits      []Hit `json:"hits"`
	Truncated bool  `json:"truncated"`
}

// under resolves a folder or file inside the site for a walk, and says
// whether it asked for a folder the site-wide walk skips (vendor/...): an
// explicit `grep -r x vendor/laravel` searches it, a site-wide one does not.
func (m *Manager) under(id, root, rel string) (string, bool, error) {
	if rel == "" || rel == "/" {
		return root, false, nil
	}
	abs, err := m.resolve(id, rel)
	if err != nil {
		return "", false, err
	}
	inside := strings.TrimPrefix(strings.TrimPrefix(abs, root), "/")
	return abs, inside != "" && skippedDir(inside), nil
}

func grepMatcher(o GrepOptions) (*regexp.Regexp, error) {
	if l := len(o.Pattern); l < 1 || l > 200 {
		return nil, fmt.Errorf("search for 1 to 200 characters")
	}
	expr := o.Pattern
	if !o.Regex {
		expr = regexp.QuoteMeta(expr)
	}
	if o.Word {
		expr = `\b(?:` + expr + `)\b`
	}
	if o.IgnoreCase {
		expr = `(?i)` + expr
	}
	re, err := regexp.Compile(expr)
	if err != nil {
		return nil, fmt.Errorf("the pattern is not a valid regular expression: %v", err)
	}
	return re, nil
}

// Grep finds lines matching a pattern in the site's files. Secrets, binary
// and very large files are never searched; dependencies and caches only when
// they are what was asked for.
func (m *Manager) Grep(ctx context.Context, id string, o GrepOptions) (GrepResult, error) {
	res := GrepResult{Hits: []Hit{}}
	if err := ValidID(id); err != nil {
		return res, err
	}
	re, err := grepMatcher(o)
	if err != nil {
		return res, err
	}
	for _, g := range o.Include {
		if _, err := path.Match(g, ""); err != nil {
			return res, fmt.Errorf("--include %q is not a valid pattern", g)
		}
	}
	if o.Limit <= 0 || o.Limit > maxSearchResult {
		o.Limit = 200
	}
	root, err := m.realRoot(id)
	if err != nil {
		return res, fmt.Errorf("site %q has no app directory", id)
	}
	start, inSkipped, err := m.under(id, root, o.Under)
	if err != nil {
		return res, err
	}
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()

	scanned := 0
	errDone := errors.New("done")
	err = walkBeneath(root, start, func(p string, d fs.DirEntry, werr error) error {
		if werr != nil || d == nil {
			if p == start {
				return fmt.Errorf("no such file or folder")
			}
			return nil
		}
		if ctx.Err() != nil {
			res.Truncated = true
			return errDone
		}
		rel := strings.TrimPrefix(strings.TrimPrefix(p, root), "/")
		if d.Type()&fs.ModeSymlink != 0 {
			return nil
		}
		if d.IsDir() {
			if rel != "" && !inSkipped && skippedDir(rel) {
				return fs.SkipDir
			}
			return nil
		}
		// Every name a secrets file goes by (production.env too), never searched.
		if isSecretName(d.Name()) || isEnvFile(d.Name()) || !includes(o.Include, d.Name()) {
			return nil
		}
		if info, err := d.Info(); err != nil || info.Size() > maxSearchFile {
			return nil
		}
		if scanned++; scanned > maxTreeEntries {
			res.Truncated = true
			return errDone
		}
		b, err := readBeneath(root, p, maxSearchFile)
		if err != nil || IsBinary(b) {
			return nil
		}
		sc := bufio.NewScanner(strings.NewReader(string(b)))
		sc.Buffer(make([]byte, 0, 64<<10), maxSearchFile)
		for n := 1; sc.Scan(); n++ {
			if re.MatchString(sc.Text()) {
				// As grep prints it, indentation and all, up to a limit.
				text := strings.TrimRight(sc.Text(), "\r")
				if len(text) > 300 {
					text = text[:300]
				}
				res.Hits = append(res.Hits, Hit{Path: "/" + rel, Line: n, Text: text})
				if len(res.Hits) >= o.Limit {
					res.Truncated = true
					return errDone
				}
			}
		}
		return nil
	})
	if err != nil && !errors.Is(err, errDone) {
		return res, err
	}
	return res, nil
}

func includes(globs []string, name string) bool {
	if len(globs) == 0 {
		return true
	}
	for _, g := range globs {
		if ok, _ := path.Match(g, name); ok {
			return true
		}
	}
	return false
}

// FindOptions is `find`: where, how deep, and which entries.
type FindOptions struct {
	Under      string
	Name       string // -name GLOB (-iname with IgnoreCase)
	IgnoreCase bool
	Type       string    // "f", "d" or "" for both
	NewerThan  time.Time // -newer FILE / -mmin -N
	MaxDepth   int       // 0: no limit
	All        bool      // walk into dependencies and caches too (du of the whole site)
	Limit      int
}

// Entry is one file or folder found.
type Entry struct {
	Path     string `json:"path"`
	Dir      bool   `json:"dir"`
	Size     int64  `json:"size"`
	Modified int64  `json:"mtime"`
}

// FindResult is what matched, what every walked file adds up to (for du and
// wc), and whether the walk stopped early.
type FindResult struct {
	Entries   []Entry `json:"entries"`
	Files     int     `json:"files"`
	Bytes     int64   `json:"bytes"`
	Truncated bool    `json:"truncated"`
	// Skipped: folders listed but not entered, so a caller can say so.
	Skipped []string `json:"skipped"`
}

const maxFindEntries = 5000

// Find walks a folder the way `find` does. Folders a site-wide walk skips
// (vendor, node_modules, caches) are listed but not entered, unless asked for
// by name or with All; symlinks are never followed.
func (m *Manager) Find(ctx context.Context, id string, o FindOptions) (FindResult, error) {
	res := FindResult{Entries: []Entry{}, Skipped: []string{}}
	if err := ValidID(id); err != nil {
		return res, err
	}
	if o.Type != "" && o.Type != "f" && o.Type != "d" {
		return res, fmt.Errorf("-type is f or d")
	}
	name := o.Name
	if o.IgnoreCase {
		name = strings.ToLower(name)
	}
	if name != "" {
		if _, err := path.Match(name, ""); err != nil {
			return res, fmt.Errorf("-name %q is not a valid pattern", o.Name)
		}
	}
	if o.Limit <= 0 || o.Limit > maxFindEntries {
		o.Limit = maxFindEntries
	}
	root, err := m.realRoot(id)
	if err != nil {
		return res, fmt.Errorf("site %q has no app directory", id)
	}
	start, inSkipped, err := m.under(id, root, o.Under)
	if err != nil {
		return res, err
	}
	ctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	base := strings.Count(strings.TrimPrefix(start, root), "/")
	if start == root {
		base = 0
	}

	walked := 0
	errDone := errors.New("done")
	err = walkBeneath(root, start, func(p string, d fs.DirEntry, werr error) error {
		if werr != nil || d == nil {
			if p == start {
				return fmt.Errorf("no such file or folder")
			}
			return nil
		}
		if ctx.Err() != nil || walked >= maxTreeEntries*5 {
			res.Truncated = true
			return errDone
		}
		walked++
		rel := strings.TrimPrefix(strings.TrimPrefix(p, root), "/")
		if d.Type()&fs.ModeSymlink != 0 {
			return nil
		}
		depth := strings.Count("/"+rel, "/") - base
		info, err := d.Info()
		if err != nil {
			return nil
		}
		if !d.IsDir() {
			res.Files++
			res.Bytes += info.Size()
		}
		// Past the limit the walk goes on counting, so du's total is whole.
		if p != start && o.matches(d, info, name) {
			if len(res.Entries) < o.Limit {
				res.Entries = append(res.Entries, Entry{Path: "/" + rel, Dir: d.IsDir(), Size: info.Size(), Modified: info.ModTime().Unix()})
			} else {
				res.Truncated = true
			}
		}
		if d.IsDir() && p != start {
			if o.MaxDepth > 0 && depth >= o.MaxDepth {
				return fs.SkipDir
			}
			if !o.All && !inSkipped && quickOpenSkip[rel] {
				res.Skipped = append(res.Skipped, "/"+rel)
				return fs.SkipDir
			}
		}
		return nil
	})
	if err != nil && !errors.Is(err, errDone) {
		return res, err
	}
	return res, nil
}

func (o FindOptions) matches(d fs.DirEntry, info fs.FileInfo, name string) bool {
	if o.Type == "f" && d.IsDir() || o.Type == "d" && !d.IsDir() {
		return false
	}
	if name != "" {
		n := d.Name()
		if o.IgnoreCase {
			n = strings.ToLower(n)
		}
		if ok, _ := path.Match(name, n); !ok {
			return false
		}
	}
	return o.NewerThan.IsZero() || info.ModTime().After(o.NewerThan)
}
