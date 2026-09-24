package sites

import (
	"regexp"
	"strings"
	"testing"
)

func TestPlatformNamesUseTheOriginCertAndCustomDomainsStayOnDemand(t *testing.T) {
	cfg := Config{PlatformDomain: "codeinchrome.com", OriginCert: "/etc/caddy/origin/cert.pem", OriginKey: "/etc/caddy/origin/key.pem"}
	out := caddyConfig(cfg, Site{ID: "shop", Domain: "shop.codeinchrome.com", Aliases: []string{"shop.example.org"}}, "20001")

	blocks := strings.Split(out, "\n}\n")
	if len(blocks) != 3 { // two blocks and the trailing remainder
		t.Fatalf("want two site blocks, got:\n%s", out)
	}
	if !strings.Contains(blocks[0], "shop.codeinchrome.com {") || !strings.Contains(blocks[0], "tls /etc/caddy/origin/cert.pem /etc/caddy/origin/key.pem") {
		t.Errorf("platform name must be served with the origin certificate:\n%s", blocks[0])
	}
	if strings.Contains(blocks[0], "shop.example.org") || strings.Contains(blocks[0], "on_demand") {
		t.Errorf("the origin block must not carry the custom domain or on-demand:\n%s", blocks[0])
	}
	if !strings.Contains(blocks[1], "shop.example.org {") || !strings.Contains(blocks[1], "on_demand") {
		t.Errorf("custom domain must keep its own on-demand certificate:\n%s", blocks[1])
	}
	for _, b := range blocks[:2] {
		if !strings.Contains(b, "reverse_proxy 127.0.0.1:20001") || !strings.Contains(b, "/var/log/caddy/shop.log") {
			t.Errorf("both blocks must reach the same container and log:\n%s", b)
		}
	}
}

func TestOnlyDirectSubdomainsCountAsPlatform(t *testing.T) {
	cfg := Config{PlatformDomain: "codeinchrome.com", OriginCert: "c", OriginKey: "k"}
	for name, want := range map[string]bool{
		"shop.codeinchrome.com":          true,
		"a.b.codeinchrome.com":           false, // the wildcard covers one label only
		"codeinchrome.com":               false,
		"shopcodeinchrome.com":           false,
		"shop.codeinchrome.com.evil.org": false,
		"shop.example.org":               false,
	} {
		if got := onPlatform(cfg, name); got != want {
			t.Errorf("onPlatform(%q) = %v, want %v", name, got, want)
		}
	}
}

func TestWithoutAnOriginCertEverythingStaysOnDemand(t *testing.T) {
	out := caddyConfig(Config{PlatformDomain: "codeinchrome.com"}, Site{ID: "shop", Domain: "shop.codeinchrome.com"}, "20001")
	if strings.Contains(out, "tls /") || !strings.Contains(out, "on_demand") {
		t.Errorf("no origin cert configured must mean on-demand, as before:\n%s", out)
	}
}

func TestReverbGetsTheWebSocketRouteAndItsAPIStaysPrivate(t *testing.T) {
	cfg := Config{PlatformDomain: "codeinchrome.com", OriginCert: "c", OriginKey: "k"}
	out := caddyConfig(cfg, Site{ID: "chat", Domain: "chat.codeinchrome.com", Reverb: true, WSPort: 21001}, "20001")
	if !strings.Contains(out, "handle /app/* {\n\t\t\treverse_proxy 127.0.0.1:21001\n\t\t}") {
		t.Errorf("the WebSocket endpoint must go to Reverb's port:\n%s", out)
	}
	if !strings.Contains(out, "handle {\n\t\t\treverse_proxy 127.0.0.1:20001 {") {
		t.Errorf("everything else must still go to the site:\n%s", out)
	}
	if strings.Contains(out, "/apps/") {
		t.Errorf("Reverb's publishing API must never be routed publicly:\n%s", out)
	}

	plain := caddyConfig(cfg, Site{ID: "shop", Domain: "shop.codeinchrome.com"}, "20001")
	if strings.Contains(plain, "handle /app/") || strings.Contains(plain, "21001") {
		t.Errorf("a site without Reverb gets no WebSocket route:\n%s", plain)
	}
}

func TestOnlyAWebSocketSiteGetsTheHighOpenFileLimit(t *testing.T) {
	m := &Manager{cfg: Config{HostID: "h9"}}
	has := func(args []string, want string) bool {
		for i := range args {
			if args[i] == "--ulimit" && i+1 < len(args) && args[i+1] == want {
				return true
			}
		}
		return false
	}
	web := m.runArgs(Site{ID: "shop", Container: "cic-shop", CPULimit: "1", MemLimit: "1024m", Port: 20000})
	if has(web, "nofile=65536:65536") {
		t.Fatal("a web-only site must keep the host's default open-file limit")
	}
	ws := m.runArgs(Site{ID: "shop", Container: "cic-shop", CPULimit: "1", MemLimit: "1024m", Port: 20000, Reverb: true, WSPort: 30000})
	if !has(ws, "nofile=65536:65536") {
		t.Fatalf("a Reverb site needs room for its connections: %v", ws)
	}
	if ws[len(ws)-1] != laravelImage {
		t.Fatal("the image must be the last argument")
	}
}

func TestASuspendedSiteServesThePausedPageAndNeverReachesTheContainer(t *testing.T) {
	cfg := Config{PlatformDomain: "codeinchrome.com", OriginCert: "c", OriginKey: "k"}
	out := caddyConfig(cfg, Site{ID: "shop", Domain: "shop.codeinchrome.com", Aliases: []string{"shop.example.org"},
		Reverb: true, WSPort: 20002, Suspended: true}, "")

	if strings.Contains(out, "reverse_proxy") {
		t.Errorf("a suspended site must not proxy anything, WebSockets included:\n%s", out)
	}
	if strings.Count(out, "This site is paused") != 2 || strings.Count(out, `" 503`) != 2 {
		t.Errorf("both the platform name and the custom domain must answer 503 with the paused page:\n%s", out)
	}
	if strings.Contains(out, "\n\"") || strings.Count(out, "{") != strings.Count(out, "}") {
		t.Errorf("the page must stay one quoted token inside balanced blocks:\n%s", out)
	}
}

func TestSecretsAreRefusedAtTheEdgeBeforeAnyRoute(t *testing.T) {
	cfg := Config{PlatformDomain: "codeinchrome.com", OriginCert: "c", OriginKey: "k"}
	for _, s := range []Site{
		{ID: "shop", Domain: "shop.codeinchrome.com"},
		{ID: "shop", Domain: "shop.codeinchrome.com", Reverb: true, WSPort: 20002},
		{ID: "shop", Domain: "shop.codeinchrome.com", Suspended: true},
	} {
		out := caddyConfig(cfg, s, "20001")
		guard := strings.Index(out, "respond @cic_secret_name 404")
		first := len(out)
		for _, d := range []string{"reverse_proxy", "handle", `" 503`} {
			if i := strings.Index(out, d); i >= 0 && i < first {
				first = i
			}
		}
		if guard < 0 || guard > first || !strings.Contains(out, "\troute {") {
			t.Errorf("the secret guard must come first, inside an ordered route block:\n%s", out)
		}
	}

	// Composed exactly as Caddy evaluates the two matchers: a name is always
	// refused; a dotfile is refused outside /.well-known/.
	nameRe, dotRe := regexp.MustCompile(secretPath), regexp.MustCompile(secretDot)
	refused := func(p string) bool {
		return nameRe.MatchString(p) || (dotRe.MatchString(p) && !strings.HasPrefix(p, "/.well-known/"))
	}
	re := struct{ MatchString func(string) bool }{refused}
	for path, want := range map[string]bool{
		"/.well-known/dump.sql": true, "/.well-known/id_rsa.pem": true, "/.well-known/debug.log": true,
		"/.well-known/.env": true, "/.well-known/auth.json": true, "/.well-known/composer.json": true,
		"/.well-known/acme-challenge/abc123": false, "/.well-known/security.txt": false,
		"/.well-known/apple-app-site-association": false,
		"/.env": true, "/.ENV": true, "/.env.backup": true, "/sub/.env": true, "/.git/config": true,
		"/.htaccess": true, "/backup.sql": true, "/db.sqlite": true, "/laravel.log": true, "/config.php.bak": true,
		"/prod.env": true, "/composer.json": true, "/composer.lock": true, "/artisan": true, "/auth.json": true,
		"/server.key": true, "/x/index.php.swp": true,
		"/": false, "/index.php": false, "/build/assets/app-4f2a.js": false, "/storage/photos/cat.jpg": false,
		"/products/environment": false, "/blog/sql-tips": false, "/robots.txt": false, "/css/app.css": false,
		"/api/logs": false, "/keyboard": false,
	} {
		if re.MatchString(path) != want {
			t.Errorf("%s: refused=%v, want %v", path, !want, want)
		}
	}
}

func TestEverySiteRunsWithDebugPagesForcedOff(t *testing.T) {
	m := &Manager{cfg: Config{Root: t.TempDir(), HostID: "h"}}
	for _, s := range []Site{
		{ID: "shop", Container: "cic-shop", CPULimit: "1", MemLimit: "640m", Port: 20000},
		{ID: "shop", Container: "cic-shop", CPULimit: "1", MemLimit: "640m", Port: 20000, Reverb: true, WSPort: 30000, PHP: PHPSettings{MemoryMB: 128}},
	} {
		if !strings.Contains(strings.Join(m.runArgs(s), " "), "--env APP_DEBUG=false") {
			t.Errorf("APP_DEBUG must be forced off in the container's environment: %v", m.runArgs(s))
		}
	}
}

// The owner's rule while every site is free (2026-09-25): no program or
// archive downloads, no redirect to another site but payment and sign-in.
func TestEveryAppResponseIsCheckedForDownloadsAndOffsiteRedirects(t *testing.T) {
	cfg := Config{PlatformDomain: "codeinchrome.com", OriginCert: "c", OriginKey: "k"}
	for _, s := range []Site{
		{ID: "shop", Domain: "shop.codeinchrome.com"},
		{ID: "chat", Domain: "chat.codeinchrome.com", Reverb: true, WSPort: 21001},
	} {
		out := caddyConfig(cfg, s, "20001")
		for _, want := range []string{"@cic_redirect_ok expression", "handle_response @cic_download_type", "handle_response @cic_download_name",
			"header Content-Type application/x-msdownload*", "header Content-Disposition *.exe*", "handle_response @cic_redirect", "copy_response"} {
			if !strings.Contains(out, want) {
				t.Errorf("%s: missing %q:\n%s", s.ID, want, out)
			}
		}
		// One value per line: Caddy's response header matcher silently ignored
		// the rest of a line with several (found testing on a host).
		for _, line := range strings.Split(out, "\n") {
			if f := strings.Fields(line); len(f) > 3 && f[0] == "header" && (f[1] == "Content-Type" || f[1] == "Content-Disposition") {
				t.Errorf("several values on one matcher line: %q", line)
			}
		}
	}
	if out := caddyConfig(cfg, Site{ID: "shop", Domain: "shop.codeinchrome.com", Suspended: true}, "20001"); strings.Contains(out, "reverse_proxy") {
		t.Errorf("a suspended site reaches no app:\n%s", out)
	}
}

func TestOnlyRedirectsToThisSiteOrAPaymentOrSignInProviderPass(t *testing.T) {
	cfg := Config{PlatformDomain: "codeinchrome.com"}
	abs, scheme, double := redirectRules(cfg, Site{ID: "shop", Domain: "shop.codeinchrome.com", Aliases: []string{"myshop.example"}})
	a, sc, d := regexp.MustCompile(abs), regexp.MustCompile(scheme), regexp.MustCompile(double)
	allowed := func(loc string) bool { return a.MatchString(loc) || (!sc.MatchString(loc) && !d.MatchString(loc)) }
	for loc, want := range map[string]bool{
		"/login": true, "login": true, "?page=2": true, "#top": true, "../up": true, "/login?next=https://evil.example": true,
		"https://shop.codeinchrome.com/cart": true, "https://SHOP.codeinchrome.com": true, "https://www.myshop.example/": true,
		"https://myshop.example:8443/x": true, "https://checkout.stripe.com/c/pay/cs_1": true, "https://www.paypal.com/checkoutnow": true,
		"https://accounts.google.com/o/oauth2/auth": true, "https://appleid.apple.com/auth/authorize": true,
		"https://app.codeinchrome.com/login": true, "https://store.lemonsqueezy.com/checkout": true,
		"https://virusdownloadauto.example/get": false, "http://evil.example": false, "//evil.example/x": false,
		"/\\evil.example": false, "\\\\evil.example": false, " //evil.example": false, "\t//evil.example": false,
		"javascript:alert(1)": false, "JaVaScRiPt:alert(1)": false, "data:text/html,x": false,
		"https://checkout.stripe.com.evil.example/": false, "https://checkout.stripe.com@evil.example/": false,
		"https://evil.example/https://checkout.stripe.com": false, "https://other.codeinchrome.com/": false,
		"https://shop.codeinchrome.com.evil.example": false, "ftp://shop.codeinchrome.com/": false,
	} {
		if got := allowed(loc); got != want {
			t.Errorf("%q: allowed=%v, want %v", loc, got, want)
		}
	}
}
