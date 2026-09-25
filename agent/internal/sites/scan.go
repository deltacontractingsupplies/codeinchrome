package sites

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Malware and encrypted PHP on a customer's site (owner's decision,
// 2026-09-25): the site is free and public, so a file that is known malware,
// or PHP written to hide what it does, is never accepted, and a site found
// holding one is suspended and its account banned by the control plane.
//
// Two checks:
//   - ClamAV (clamd, installed by infra/bootstrap.sh) for known malware,
//     webshells and phishing kits, in every file
//   - our own rules for PHP that hides its purpose: code decoded and then
//     run, request input handed to a shell, and commercial encoders. Only
//     outside vendor/ and node_modules/: packages installed by Composer do
//     carry base64 and eval for good reasons, and a false positive here would
//     ban an innocent customer. ClamAV still scans those.

// Finding is one file the scan refused.
type Finding struct {
	Path   string `json:"path"`
	Kind   string `json:"kind"` // "malware" or "obfuscated"
	Detail string `json:"detail"`
}

// ErrMalware refuses a write or upload that a scan flagged. The control plane
// acts on it (App\Abuse\Enforcer).
type ErrMalware struct{ Findings []Finding }

func (e *ErrMalware) Error() string {
	if len(e.Findings) == 0 {
		return "malware"
	}
	f := e.Findings[0]
	if f.Kind == "obfuscated" {
		return fmt.Sprintf("refused: %s holds code written to hide what it does (%s). Encrypted or obfuscated PHP is not allowed on codeinchrome", f.Path, f.Detail)
	}
	return fmt.Sprintf("refused: %s is malware (%s)", f.Path, f.Detail)
}

// IsMalware reports whether err is a refusal for malware or obfuscated code.
func IsMalware(err error) (*ErrMalware, bool) {
	var m *ErrMalware
	return m, errors.As(err, &m)
}

// The rules for PHP that hides its purpose. Each is something a normal
// Laravel app never needs in its own code.
var obfuscationRules = []struct {
	name string
	re   *regexp.Regexp
}{
	{"code decoded and then run", regexp.MustCompile(`(?i)\b(eval|assert|create_function)\s*\(\s*(@\s*)?(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev|rawurldecode|hex2bin|convert_uudecode|pack)\s*\(`)},
	{"request input run as code", regexp.MustCompile(`(?i)\b(eval|assert|create_function)\s*\(\s*(@\s*)?\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b`)},
	{"request input handed to a shell", regexp.MustCompile(`(?i)\b(system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec)\s*\(\s*(@\s*)?\$_(GET|POST|REQUEST|COOKIE|SERVER)\b`)},
	{"preg_replace /e (runs its replacement as code)", regexp.MustCompile(`(?i)\bpreg_replace\s*\(\s*['"][/#~!@|%][^'"]*[/#~!@|%][a-z]*e[a-z]*['"]`)},
	{"a commercial PHP encoder (ionCube, SourceGuardian, Zend Guard)", regexp.MustCompile(`(?i)(ionCube Loader|extension_loaded\(\s*['"]ionCube|\bsg_load\s*\(|@Zend;|Zend Optimizer|<\?php //00[0-9a-f]{2})`)},
	{"a large encoded blob run as code", regexp.MustCompile(`(?s)\b(eval|assert)\s*\(.{0,200}['"][A-Za-z0-9+/=]{1000,}['"]`)},
	{"create_function (removed from PHP 8; used to hide code)", regexp.MustCompile(`(?i)\bcreate_function\s*\(`)},
	{"request input called as a function", regexp.MustCompile(`(?i)@?\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\s*\[[^\]]*\]\s*\(`)},
	{"request input included as code", regexp.MustCompile(`(?i)\b(include|require)(_once)?\b\s*\(?\s*@?\$_(GET|POST|REQUEST|COOKIE|SERVER)`)},
	{"request input handed to a callback runner", regexp.MustCompile(`(?i)\b(call_user_func(_array)?|array_map|array_filter|array_walk|usort|uasort|uksort|register_shutdown_function|register_tick_function|forward_static_call(_array)?|iterator_apply)\s*\(\s*@?\$_(GET|POST|REQUEST|COOKIE|SERVER)`)},
	{"a function name built from chr()", regexp.MustCompile(`(?i)(\bchr\s*\(\s*\d+\s*\)\s*\.\s*){3,}`)},
	{"a PHP file written from request input or decoded data", regexp.MustCompile(`(?is)\bfile_put_contents\s*\([^;]{0,200}\.ph(p[0-9]?|tml|ar)\b[^;]{0,300}(base64_decode|gzinflate|str_rot13|hex2bin|\$_(GET|POST|REQUEST|COOKIE|FILES))`)},
}

// Names a webshell hides behind an escape sequence, a string concatenation
// or a variable. None is ever spelled that way in ordinary code.
var dangerousCallable = regexp.MustCompile(`(?i)^(system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec|assert|eval|create_function|base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|hex2bin|convert_uudecode|call_user_func|call_user_func_array|file_put_contents|move_uploaded_file|curl_exec|fsockopen|preg_replace)$`)

var (
	dqString     = regexp.MustCompile(`"((?:[^"\\]|\\.)*)"`)
	phpEscape    = regexp.MustCompile(`\\(x[0-9a-fA-F]{1,2}|[0-7]{1,3})`)
	concatQuotes = regexp.MustCompile(`(['"])\s*\.\s*(['"])`)
	taintedVar   = regexp.MustCompile(`(?i)\$(\w+)\s*=\s*\(?\s*@?\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b`)
	namedVar     = regexp.MustCompile(`\$(\w+)\s*=\s*['"]([A-Za-z_][A-Za-z0-9_]*)['"]\s*;`)
	backtickVar  = regexp.MustCompile("`[^`\n]*\\$[^`\n]*`")
	evalCall     = regexp.MustCompile(`(?i)\beval\s*(/\*.*?\*/\s*)?\(`)
	notEvalCall  = regexp.MustCompile(`(?i)(->|::|\$|\bfunction\s+)\s*$`)
	// Comments cannot run: they are dropped before any rule looks (a "//"
	// right after ":" is a URL, and "#[" an attribute, so those stay).
	blockComment = regexp.MustCompile(`(?s)/\*.*?\*/`)
	lineComment  = regexp.MustCompile(`(?m)(^|[^:\\])//[^\n]*`)
	hashComment  = regexp.MustCompile(`(?m)^[ \t]*#([^\[\n][^\n]*)?$`)
	sqString     = regexp.MustCompile(`'(?:[^'\\]|\\.)*'`)
)

// escapedCallable: a double-quoted string that spells a dangerous function
// only once its escapes are decoded ("\x73ystem", "\163\171\163..."). Any
// number of escapes, mixed with plain letters: the old rule wanted six in a
// row, and so banned a PNG signature check while "sy\x73tem" passed (the
// second security audit, 2026-09-25).
func escapedCallable(content string) bool {
	for _, m := range dqString.FindAllStringSubmatch(content, -1) {
		lit := m[1]
		if !phpEscape.MatchString(lit) {
			continue
		}
		decoded := phpEscape.ReplaceAllStringFunc(lit, func(e string) string {
			var n uint64
			var err error
			if e[1] == 'x' {
				n, err = strconv.ParseUint(e[2:], 16, 8)
			} else {
				n, err = strconv.ParseUint(e[1:], 8, 8)
			}
			if err != nil {
				return e
			}
			return string(rune(n))
		})
		if dangerousCallable.MatchString(strings.TrimSpace(decoded)) {
			return true
		}
	}
	return false
}

// phpObfuscation names the first rule a PHP file breaks, or "". isBlade:
// a Blade view, whose backticks are JavaScript template strings.
func phpObfuscation(content string, isBlade ...bool) string {
	content = hashComment.ReplaceAllString(lineComment.ReplaceAllString(blockComment.ReplaceAllString(content, ""), "$1"), "")
	for _, r := range obfuscationRules {
		if r.re.MatchString(content) {
			return r.name
		}
	}
	// eval( anywhere, except a method or a function definition named eval.
	for _, loc := range evalCall.FindAllStringIndex(content, -1) {
		if !notEvalCall.MatchString(content[max(0, loc[0]-40):loc[0]]) {
			return "eval (no Laravel app runs strings as code)"
		}
	}
	if escapedCallable(content) {
		return "function names spelled in escape sequences"
	}
	// 'ba'.'se64_decode' is 'base64_decode': join literal concatenations first.
	joined := concatQuotes.ReplaceAllString(content, "")
	for _, m := range namedVar.FindAllStringSubmatch(joined, -1) {
		if dangerousCallable.MatchString(m[2]) {
			return "a dangerous function's name kept in a variable"
		}
	}
	for _, m := range taintedVar.FindAllStringSubmatch(content, -1) {
		v := regexp.QuoteMeta(m[1])
		if regexp.MustCompile(`(^|[^\w>:])@?\$` + v + `\s*\(`).MatchString(content) {
			return "request input called as a function"
		}
		if regexp.MustCompile(`(?i)\b(system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec|eval|assert|include|require)(_once)?\b\s*\(?\s*@?\$` + v + `\b`).MatchString(content) {
			return "request input handed to a shell or run as code"
		}
	}
	// Backticks outside strings are PHP's shell operator; inside a string
	// ("check `systemctl status {$unit}`") they are just text.
	if !(len(isBlade) > 0 && isBlade[0]) && backtickVar.MatchString(sqString.ReplaceAllString(dqString.ReplaceAllString(content, `""`), "''")) {
		return "a shell command in backticks"
	}
	return ""
}

// checkedForObfuscation: PHP the customer wrote. Packages Composer installed
// (vendor/) and front-end dependencies are left to ClamAV.
func checkedForObfuscation(rel string) bool {
	rel = strings.TrimPrefix(filepath.ToSlash(rel), "/")
	if strings.HasPrefix(rel, "vendor/") || strings.HasPrefix(rel, "node_modules/") || strings.Contains(rel, "/node_modules/") {
		return false
	}
	ext := strings.ToLower(filepath.Ext(rel))
	return ext == ".php" || ext == ".phtml" || ext == ".php5" || ext == ".php7" || ext == ".phar" || ext == ".inc"
}

// clamdscan runs the scanner; a variable so tests need no clamd.
var clamdscan = func(ctx context.Context, paths ...string) (string, int, error) {
	// --stream: the bytes are sent to clamd. --fdpass failed on every file
	// ("Not a regular file") from inside the agent's private mount namespace
	// (systemd ProtectSystem/PrivateTmp), found on the first real scan.
	args := append([]string{"--stream", "--no-summary", "--infected", "--"}, paths...)
	cmd := exec.CommandContext(ctx, "clamdscan", args...)
	out, err := cmd.CombinedOutput()
	code := 0
	if err != nil {
		var ee *exec.ExitError
		if !errors.As(err, &ee) {
			return string(out), -1, err
		}
		code = ee.ExitCode()
	}
	return string(out), code, nil
}

// unscannableSig: ClamAV could not look inside (a password-protected archive
// or document, or past its size limits - clamd.conf AlertEncrypted*/
// AlertExceedsMax). Not evidence of malware, so never a ban: the file is
// refused, or reviewed, because nothing vouches for what it holds (the
// second security audit, 2026-09-25: an encrypted zip with EICAR inside was
// reported clean).
func unscannableSig(sig string) bool {
	return strings.HasPrefix(sig, "Heuristics.Encrypted") || strings.HasPrefix(sig, "Heuristics.Limits.Exceeded")
}

// refusal turns what a scan of written files found into the error the write
// fails with: malware (a ban), or a plain refusal for what could not be read.
func refusal(found []Finding) error {
	var bad, unreadable []Finding
	for _, f := range found {
		if f.Kind == "unscannable" {
			unreadable = append(unreadable, f)
		} else {
			bad = append(bad, f)
		}
	}
	switch {
	case len(bad) > 0:
		return &ErrMalware{Findings: bad}
	case len(unreadable) > 0:
		return fmt.Errorf("refused: %s could not be checked for malware (a password-protected or oversize archive); upload it unencrypted, or its files one by one", unreadable[0].Path)
	}
	return nil
}

// clamScan scans paths (files or folders) and returns what it found. Exit 0:
// clean; 1: found; anything else: the scan itself failed, which is an error -
// never "clean".
func clamScan(ctx context.Context, root string, paths ...string) ([]Finding, error) {
	if len(paths) == 0 {
		return nil, nil
	}
	ctx, cancel := context.WithTimeout(ctx, 30*time.Minute)
	defer cancel()
	out, code, err := clamdscan(ctx, paths...)
	if err != nil {
		return nil, fmt.Errorf("malware scan could not run: %v", err)
	}
	if code != 0 && code != 1 {
		return nil, fmt.Errorf("malware scan failed (clamdscan exit %d): %s", code, firstLine(out, nil))
	}
	var found []Finding
	sc := bufio.NewScanner(strings.NewReader(out))
	for sc.Scan() {
		line := sc.Text()
		if !strings.HasSuffix(line, " FOUND") {
			continue
		}
		i := strings.LastIndex(line, ": ")
		if i < 0 {
			continue
		}
		path := strings.TrimPrefix(line[:i], root)
		sig := strings.TrimSuffix(line[i+2:], " FOUND")
		kind := "malware"
		if unscannableSig(sig) {
			kind = "unscannable"
		}
		found = append(found, Finding{Path: "/" + strings.TrimPrefix(path, "/"), Kind: kind, Detail: sig})
	}
	if code == 1 && len(found) == 0 {
		return nil, fmt.Errorf("malware scan reported a finding it did not name: %s", firstLine(out, nil))
	}
	return found, nil
}

// ScanSite scans a whole site: ClamAV over every file, and the obfuscation
// rules over the customer's own PHP.
func (m *Manager) ScanSite(ctx context.Context, id string) ([]Finding, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	root, err := m.realRoot(id)
	if err != nil {
		return nil, fmt.Errorf("site %q has no app directory", id)
	}
	// The rules below need no clamd: a ClamAV failure must not skip them (the
	// second security audit, 2026-09-25). Both results come back together.
	found, clamErr := clamScan(ctx, root, root)
	err = walkBeneath(root, root, func(p string, d fs.DirEntry, werr error) error {
		if werr != nil || ctx.Err() != nil {
			return nil
		}
		rel := strings.TrimPrefix(strings.TrimPrefix(p, root), "/")
		if d.IsDir() {
			if rel == "vendor" || rel == "node_modules" || strings.HasSuffix(rel, "/node_modules") || rel == "storage/framework" {
				return fs.SkipDir
			}
			return nil
		}
		if d.Type()&fs.ModeSymlink != 0 || !checkedForObfuscation(rel) {
			return nil
		}
		// Up to the upload limit, as a save or unzip is checked - not only the
		// first 2 MiB (the second security audit, 2026-09-25). Bigger PHP is
		// not something a site's own code needs: a person looks.
		if info, ierr := d.Info(); ierr == nil && info.Size() > MaxUploadSize {
			found = append(found, Finding{Path: "/" + rel, Kind: "unscannable", Detail: "a PHP file larger than the checks read"})
			return nil
		}
		b, err := readBeneath(root, p, MaxUploadSize)
		if err != nil {
			return nil
		}
		if why := phpObfuscation(string(b), strings.HasSuffix(rel, ".blade.php")); why != "" {
			found = append(found, Finding{Path: "/" + rel, Kind: "obfuscated", Detail: why})
		}
		return nil
	})
	if err != nil {
		return found, err
	}
	if clamErr != nil {
		return found, clamErr
	}
	return found, ctx.Err()
}

// scanContent refuses content the moment it is written: obfuscated PHP by
// our rules (no clamd needed, so an editor save is checked even if clamd is
// down), and known malware by ClamAV on the written file.
func scanContent(rel, content string) *ErrMalware {
	if checkedForObfuscation(rel) {
		if why := phpObfuscation(content, strings.HasSuffix(rel, ".blade.php")); why != "" {
			return &ErrMalware{Findings: []Finding{{Path: "/" + strings.TrimPrefix(rel, "/"), Kind: "obfuscated", Detail: why}}}
		}
	}
	return nil
}

// scanFile runs ClamAV (and the PHP rules) on one file already written
// beneath root. A scan that cannot run is reported as an error, never as clean.
func scanFile(ctx context.Context, root, rel string) error {
	rel = "/" + strings.TrimPrefix(rel, "/")
	if checkedForObfuscation(rel) {
		if b, err := readBeneath(root, filepath.Join(root, rel), MaxUploadSize); err == nil {
			if bad := scanContent(rel, string(b)); bad != nil {
				return bad
			}
		}
	}
	found, err := clamScan(ctx, root, filepath.Join(root, rel))
	if err != nil {
		return err
	}
	return refusal(found)
}

// scanWrittenPHP applies the obfuscation rules to a file already written.
func scanWrittenPHP(root, rel string) *ErrMalware {
	if !checkedForObfuscation(rel) {
		return nil
	}
	b, err := readBeneath(root, filepath.Join(root, rel), MaxUploadSize)
	if err != nil {
		return nil
	}
	return scanContent(rel, string(b))
}

// ScanChangedSince holds the PHP rules against every customer PHP file
// changed since t: what an artisan or composer command, or code run with
// eval, wrote - which no save-time check saw (the second security audit,
// 2026-09-25). Composer's vendor/ and the framework's caches are left out,
// as in every other scan.
func (m *Manager) ScanChangedSince(id string, t time.Time) *ErrMalware {
	if ValidID(id) != nil {
		return nil
	}
	root, err := m.realRoot(id)
	if err != nil {
		return nil
	}
	var found *ErrMalware
	_ = walkBeneath(root, root, func(p string, d fs.DirEntry, werr error) error {
		if werr != nil {
			return nil
		}
		rel := strings.TrimPrefix(strings.TrimPrefix(p, root), "/")
		if d.IsDir() {
			if rel == "vendor" || rel == "node_modules" || strings.HasSuffix(rel, "/node_modules") || rel == "storage/framework" || rel == "bootstrap/cache" {
				return fs.SkipDir
			}
			return nil
		}
		if d.Type()&fs.ModeSymlink != 0 || !checkedForObfuscation(rel) {
			return nil
		}
		if info, err := d.Info(); err != nil || info.ModTime().Before(t) {
			return nil
		}
		if bad := scanWrittenPHP(root, "/"+rel); bad != nil {
			found = bad
			return fs.SkipAll
		}
		return nil
	})
	return found
}
