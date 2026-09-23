package sites

import (
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
