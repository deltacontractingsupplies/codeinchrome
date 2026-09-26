package sites

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"strconv"
	"strings"
	"testing"
	"time"
)

func signedLink(key []byte, site string, user int64, guard, path string, exp time.Time, nonce string) SignInOpts {
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(SignInMessage(site, user, guard, path, exp.Unix(), nonce)))
	return SignInOpts{User: strconv.FormatInt(user, 10), Guard: guard, Path: path, Expires: strconv.FormatInt(exp.Unix(), 10), Nonce: nonce, Sig: hex.EncodeToString(mac.Sum(nil))}
}

func TestASignInLinkOpensOneSiteOnceForTenMinutesAndNowhereElse(t *testing.T) {
	m := &Manager{}
	key := SignInKey("agent-secret-of-this-host-000000000000")
	old := signInSession
	var made []string
	signInSession = func(_ *Manager, _ context.Context, id string, user int64, guard string) (string, string, error) {
		made = append(made, id+"/"+strconv.FormatInt(user, 10)+"/"+guard)
		return "shop_session", "eyJ-encrypted", nil
	}
	defer func() { signInSession = old }()
	now := time.Now()
	good := signedLink(key, "shop", 1, "web", "/admin/orders?page=2", now.Add(5*time.Minute), "n0nce-aaaaaaaaaaaa")

	name, value, path, err := m.SignIn(context.Background(), "shop", key, good, now)
	if err != nil || name != "shop_session" || value != "eyJ-encrypted" || path != "/admin/orders?page=2" || strings.Join(made, ",") != "shop/1/web" {
		t.Fatalf("%s %s %s %v %v", name, value, path, err, made)
	}
	// Once.
	if _, _, _, err := m.SignIn(context.Background(), "shop", key, good, now); err == nil || !strings.Contains(err.Error(), "already used") {
		t.Fatalf("used twice: %v", err)
	}

	for name, c := range map[string]struct {
		site string
		o    SignInOpts
		at   time.Time
	}{
		"another site": {"other", signedLink(key, "shop", 1, "web", "/", now.Add(5*time.Minute), "n0nce-bbbbbbbbbbbb"), now},
		"another user": {"shop", func() SignInOpts {
			o := signedLink(key, "shop", 1, "web", "/", now.Add(5*time.Minute), "n0nce-cccccccccccc")
			o.User = "2"
			return o
		}(), now},
		"another path": {"shop", func() SignInOpts {
			o := signedLink(key, "shop", 1, "web", "/", now.Add(5*time.Minute), "n0nce-dddddddddddd")
			o.Path = "/admin"
			return o
		}(), now},
		"another key":      {"shop", signedLink(SignInKey("another host"), "shop", 1, "web", "/", now.Add(5*time.Minute), "n0nce-eeeeeeeeeeee"), now},
		"expired":          {"shop", signedLink(key, "shop", 1, "web", "/", now.Add(-time.Second), "n0nce-ffffffffffff"), now},
		"too far ahead":    {"shop", signedLink(key, "shop", 1, "web", "/", now.Add(2*time.Hour), "n0nce-gggggggggggg"), now},
		"elsewhere //":     {"shop", signedLink(key, "shop", 1, "web", "//evil.example/x", now.Add(5*time.Minute), "n0nce-hhhhhhhhhhhh"), now},
		"elsewhere scheme": {"shop", signedLink(key, "shop", 1, "web", "https://evil.example/", now.Add(5*time.Minute), "n0nce-iiiiiiiiiiii"), now},
		"elsewhere /\\":    {"shop", signedLink(key, "shop", 1, "web", "/\\evil.example", now.Add(5*time.Minute), "n0nce-jjjjjjjjjjjj"), now},
		"short nonce":      {"shop", signedLink(key, "shop", 1, "web", "/", now.Add(5*time.Minute), "abc"), now},
	} {
		if _, _, _, err := m.SignIn(context.Background(), c.site, key, c.o, c.at); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	if len(made) != 1 {
		t.Fatalf("a session was made for a refused link: %v", made)
	}
}

func TestTheSignInPathIsTheAgentsBeforeAnythingReachesTheSite(t *testing.T) {
	conf := caddyConfig(Config{}, Site{ID: "shop", Domain: "shop.example.test"}, "20001")
	signin := strings.Index(conf, "@cic_signin path /__codeinchrome/sign-in")
	proxy := strings.Index(conf, "reverse_proxy 127.0.0.1:20001")
	if signin < 0 || proxy < 0 || signin > proxy {
		t.Fatalf("the sign-in route must come before the site:\n%s", conf)
	}
	if !strings.Contains(conf, "rewrite * /v1/signin/shop?{query}") || !strings.Contains(conf, "reverse_proxy 127.0.0.1:9440") {
		t.Fatalf("not handed to this site's agent route:\n%s", conf)
	}
	// After the edge's own refusals (secrets, programs, service workers)...
	if guard := strings.Index(conf, "@cic_secret_name"); guard < 0 || guard > signin {
		t.Fatalf("the secret guard must still come first:\n%s", conf)
	}
	// ...and not at all for a paused site, which proxies nothing.
	if paused := caddyConfig(Config{}, Site{ID: "shop", Domain: "shop.example.test", Suspended: true}, "20001"); strings.Contains(paused, "cic_signin") {
		t.Fatalf("a paused site still hands out sign-ins:\n%s", paused)
	}
}

// The control plane signs links in PHP (App\Fleet\SignInLink); this vector
// is asserted there too, so the two can never drift apart.
func TestTheSignatureIsTheOneTheControlPlaneMakes(t *testing.T) {
	key := SignInKey("0123456789abcdef0123456789abcdef")
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(SignInMessage("shop", 7, "web", "/admin?x=1", 1790000000, "abcdefghijklmnop")))
	if got := hex.EncodeToString(key); got != "ec437d1ab5072e8782b583b0231bc23db07ee2dbdffecceccd7dda1c9b21e7ef" {
		t.Fatalf("key %s", got)
	}
	if got := hex.EncodeToString(mac.Sum(nil)); got != "3939f0cf14cff11f05c2ea8e0949c0349965d200e0f957b5adfd68af6871a0c6" {
		t.Fatalf("signature %s", got)
	}
}
