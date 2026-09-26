package sites

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"
)

// A one-time sign-in link (owner, 2026-09-26: an agent in the browser cannot
// type a password, yet must test the pages behind the app's own login).
//
// https://<site>/__codeinchrome/sign-in?u=&g=&p=&e=&n=&s= opens the site in
// the browser signed in as the app's user u - a real session of the app's
// own, made the way cic.request({ as }) makes one (LoginCookie) - and lands
// on path p. Caddy hands that path to the agent before anything reaches the
// site's code, so the site cannot fake or see it.
//
// The link is minted by the control plane for the site's owner only, and is
// signed (HMAC-SHA256) with a key both derive from this host's agent secret,
// bound to the site, the user, the guard, the path and the time: valid for
// ten minutes and ONCE. p must be a path on the site (no scheme, no host, no
// "//"), so the link can never send the browser anywhere else.

const signInPath = "/__codeinchrome/sign-in"

// SignInMaxAge is how long a link is valid.
const SignInMaxAge = 10 * time.Minute

var (
	// signInSession makes the app's own session (a variable so the tests
	// need no container).
	signInSession = func(m *Manager, ctx context.Context, id string, user int64, guard string) (string, string, error) {
		return m.LoginCookie(ctx, id, user, guard)
	}

	usedSignIns   sync.Map // nonce -> expiry
	signInSweepMu sync.Mutex
	signInSwept   time.Time
)

// SignInKey is the signing key derived from the agent secret: never the
// secret itself, so a leaked signature says nothing about it.
func SignInKey(agentSecret string) []byte {
	sum := sha256.Sum256([]byte("codeinchrome sign-in link v1\x00" + agentSecret))
	return sum[:]
}

// SignInMessage is exactly what is signed.
func SignInMessage(site string, user int64, guard, path string, expires int64, nonce string) string {
	return strings.Join([]string{"v1", site, strconv.FormatInt(user, 10), guard, path, strconv.FormatInt(expires, 10), nonce}, "\n")
}

// SignInOpts is a link's query, as received.
type SignInOpts struct {
	User    string
	Guard   string
	Path    string
	Expires string
	Nonce   string
	Sig     string
}

// safeLocalPath: a path on this site, and nothing that a browser would read
// as another site ("//evil", "/\evil", a scheme).
func safeLocalPath(p string) bool {
	if p == "" || p[0] != '/' || strings.HasPrefix(p, "//") || strings.HasPrefix(p, "/\\") || len(p) > 2000 {
		return false
	}
	u, err := url.Parse(p)
	return err == nil && u.Scheme == "" && u.Host == "" && !strings.ContainsAny(p, "\r\n\t")
}

// SignInKey is the key sign-in links are checked with (empty: none are valid).
func (m *Manager) SignInKey() []byte { return m.cfg.SignInKey }

// SignIn checks a link and makes the session. It answers the cookie to set
// and the path to go to.
func (m *Manager) SignIn(ctx context.Context, id string, key []byte, o SignInOpts, now time.Time) (cookie, value, path string, err error) {
	if err := ValidID(id); err != nil {
		return "", "", "", err
	}
	user, err := strconv.ParseInt(o.User, 10, 64)
	if err != nil || user <= 0 {
		return "", "", "", errors.New("this sign-in link is not valid")
	}
	expires, err := strconv.ParseInt(o.Expires, 10, 64)
	if err != nil {
		return "", "", "", errors.New("this sign-in link is not valid")
	}
	guard := o.Guard
	if guard == "" {
		guard = "web"
	}
	if !guardRe.MatchString(guard) || !safeLocalPath(o.Path) || len(o.Nonce) < 16 || len(o.Nonce) > 64 {
		return "", "", "", errors.New("this sign-in link is not valid")
	}
	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(SignInMessage(id, user, guard, o.Path, expires, o.Nonce)))
	want := mac.Sum(nil)
	got, err := hex.DecodeString(o.Sig)
	if err != nil || !hmac.Equal(got, want) {
		return "", "", "", errors.New("this sign-in link is not valid")
	}
	exp := time.Unix(expires, 0)
	if !now.Before(exp) || exp.Sub(now) > SignInMaxAge+time.Minute {
		return "", "", "", errors.New("this sign-in link has expired; ask for a new one")
	}
	// Once: a link seen in a history, a log or over a shoulder opens nothing.
	sweepSignIns(now)
	if _, used := usedSignIns.LoadOrStore(o.Nonce, exp); used {
		return "", "", "", errors.New("this sign-in link was already used; ask for a new one")
	}
	name, val, err := signInSession(m, ctx, id, user, guard)
	if err != nil {
		return "", "", "", err
	}
	return name, val, o.Path, nil
}

// sweepSignIns forgets used links once they have expired anyway.
func sweepSignIns(now time.Time) {
	signInSweepMu.Lock()
	defer signInSweepMu.Unlock()
	if now.Sub(signInSwept) < time.Minute {
		return
	}
	signInSwept = now
	usedSignIns.Range(func(k, v any) bool {
		if now.After(v.(time.Time)) {
			usedSignIns.Delete(k)
		}
		return true
	})
}

// signInRoute is the vhost's part: the reserved path goes to the agent, with
// the site's id in the URL the agent sees - a link minted for one site is
// refused by every other (the signature binds the id).
func signInRoute(id string) string {
	return fmt.Sprintf("\t@cic_signin path %s\n\thandle @cic_signin {\n\t\trewrite * /v1/signin/%s?{query}\n\t\treverse_proxy 127.0.0.1:9440\n\t}\n", signInPath, id)
}
