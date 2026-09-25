package sites

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"net"
	"net/http"
	"os"
	"time"
)

// edgeStatus asks the host's own Caddy for a site's front page, the way a
// visitor reaches it (TLS, the site's name), and returns the status, or 0 if
// there was no answer. Used around a vhost rewrite: `caddy validate` accepts
// a config whose expressions then fail on every request - which answered two
// sites with 502 for a minute (2026-09-25).
var edgeStatus = func(ctx context.Context, domain string, roots *x509.CertPool) int {
	dialer := &net.Dialer{Timeout: 5 * time.Second}
	client := &http.Client{
		Timeout: 10 * time.Second,
		Transport: &http.Transport{
			// Verified: against this host's own origin certificate (which only
			// Cloudflare trusts publicly), or the system's roots without one.
			TLSClientConfig: &tls.Config{ServerName: domain, RootCAs: roots, MinVersion: tls.VersionTLS12},
			DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
				return dialer.DialContext(ctx, network, "127.0.0.1:443")
			},
		},
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, "https://"+domain+"/", nil)
	if err != nil {
		return 0
	}
	req.Header.Set("User-Agent", "codeinchrome-agent-edge-check")
	res, err := client.Do(req)
	if err != nil {
		return 0
	}
	res.Body.Close()
	return res.StatusCode
}

// brokenByReload lists the sites that answered below 500 before and answer
// 500 or more now. A site that did not answer before (0) or answers nothing
// now (0: slow, not proven broken) is not counted.
func brokenByReload(before, after map[string]int) []string {
	var broken []string
	for domain, was := range before {
		if was > 0 && was < 500 && after[domain] >= 500 {
			broken = append(broken, domain)
		}
	}
	return broken
}

// edgeRoots is what a probe of this host's own Caddy trusts: exactly the
// origin certificate it serves (Go accepts a certificate that is itself in
// the pool), or nil - the system's roots - on a host without one.
func (m *Manager) edgeRoots() *x509.CertPool {
	if m.cfg.OriginCert == "" {
		return nil
	}
	pem, err := os.ReadFile(m.cfg.OriginCert)
	if err != nil {
		return nil
	}
	pool := x509.NewCertPool()
	if !pool.AppendCertsFromPEM(pem) {
		return nil
	}
	return pool
}
