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
	{"function names spelled in hex escapes", regexp.MustCompile(`(\\x[0-9a-fA-F]{2}){6,}`)},
	{"a commercial PHP encoder (ionCube, SourceGuardian, Zend Guard)", regexp.MustCompile(`(?i)(ionCube Loader|extension_loaded\(\s*['"]ionCube|\bsg_load\s*\(|@Zend;|Zend Optimizer|<\?php //00[0-9a-f]{2})`)},
	{"a large encoded blob run as code", regexp.MustCompile(`(?s)\b(eval|assert)\s*\(.{0,200}['"][A-Za-z0-9+/=]{1000,}['"]`)},
}

// phpObfuscation names the first rule a PHP file breaks, or "".
func phpObfuscation(content string) string {
	for _, r := range obfuscationRules {
		if r.re.MatchString(content) {
			return r.name
		}
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
		found = append(found, Finding{Path: "/" + strings.TrimPrefix(path, "/"), Kind: "malware", Detail: sig})
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
	found, err := clamScan(ctx, root, root)
	if err != nil {
		return nil, err
	}
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
		b, err := readBeneath(root, p, MaxFileSize)
		if err != nil {
			return nil
		}
		if why := phpObfuscation(string(b)); why != "" {
			found = append(found, Finding{Path: "/" + rel, Kind: "obfuscated", Detail: why})
		}
		return nil
	})
	if err != nil {
		return found, err
	}
	return found, ctx.Err()
}

// scanContent refuses content the moment it is written: obfuscated PHP by
// our rules (no clamd needed, so an editor save is checked even if clamd is
// down), and known malware by ClamAV on the written file.
func scanContent(rel, content string) *ErrMalware {
	if checkedForObfuscation(rel) {
		if why := phpObfuscation(content); why != "" {
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
	if len(found) > 0 {
		return &ErrMalware{Findings: found}
	}
	return nil
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
