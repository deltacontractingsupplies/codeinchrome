package sites

import (
	"context"
	"crypto/tls"
	"net"
	"net/http"
	"time"
)

// edgeStatus asks the host's own Caddy for a site's front page, the way a
// visitor reaches it (TLS, the site's name), and returns the status, or 0 if
// there was no answer. Used around a vhost rewrite: `caddy validate` accepts
// a config whose expressions then fail on every request - which answered two
// sites with 502 for a minute (2026-09-25).
var edgeStatus = func(ctx context.Context, domain string) int {
	dialer := &net.Dialer{Timeout: 5 * time.Second}
	client := &http.Client{
		Timeout: 10 * time.Second,
		Transport: &http.Transport{
			// The origin certificate is Cloudflare's, trusted only by Cloudflare;
			// this is the host talking to itself about a status code.
			TLSClientConfig: &tls.Config{ServerName: domain, InsecureSkipVerify: true}, //nolint:gosec
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
