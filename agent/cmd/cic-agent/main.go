// cic-agent runs on every codeinchrome host.
//
// It is the only thing on the box that creates sites, and it is deliberately
// small: one static binary, no runtime to patch, no framework. Everything a
// customer can do passes through here, so the surface is kept narrow enough to
// read in one sitting.
//
// The rule this codebase is built around applies hardest here: a response must
// never claim more than the mechanism behind it can support. Where this agent
// cannot prove something, it says so in the payload rather than implying it.
package main

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"

	"github.com/codeinchrome/agent/internal/api"
	"github.com/codeinchrome/agent/internal/sites"
)

var version = "dev" // overwritten at build time from agent/VERSION; "dev" means an unstamped build

func main() {
	var (
		addr     = flag.String("addr", "127.0.0.1:9440", "listen address")
		root     = flag.String("root", "/srv/customers", "customer data root")
		caddyDir = flag.String("caddy", "/opt/codeinchrome/caddy/sites", "per-site Caddy config directory")
		hostFile = flag.String("host-id", "/opt/codeinchrome/etc/host.id", "file holding this host's id")
		platform = flag.String("platform-domain", "", "sites under this domain are served through Cloudflare with the origin certificate")
		origCert = flag.String("origin-cert", "", "Cloudflare Origin CA certificate for *.platform-domain (empty: on-demand ACME for every name)")
		origKey  = flag.String("origin-key", "", "private key for -origin-cert")
	)
	flag.Parse()

	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))
	slog.SetDefault(logger)

	token := os.Getenv("CIC_AGENT_TOKEN")
	if len(token) < 32 {
		fatal("CIC_AGENT_TOKEN must be set and at least 32 characters; refusing to start without authentication")
	}

	hostID, err := os.ReadFile(*hostFile)
	if err != nil {
		fatal("cannot read host id from %s: %v (run infra/bootstrap.sh first)", *hostFile, err)
	}

	mgr, err := sites.New(sites.Config{
		Root:     *root,
		CaddyDir: *caddyDir,
		HostID:   strings.TrimSpace(string(hostID)),
		// From /opt/codeinchrome/etc/mysql.env via the systemd unit. Read
		// from the environment rather than a flag so it never appears in
		// `ps` output.
		MySQLPassword:  os.Getenv("CIC_MYSQL_ROOT_PASSWORD"),
		PlatformDomain: *platform,
		OriginCert:     *origCert,
		OriginKey:      *origKey,
	})
	if err != nil {
		fatal("cannot start site manager: %v", err)
	}

	// Heal before serving. A host that rebooted has every container back on a
	// restart policy, and until this runs its vhosts may name ports those
	// containers no longer hold - which is a 502 on every site at once.
	if changed, err := mgr.Reconcile(context.Background()); err != nil {
		slog.Error("reconcile failed; some sites may be serving 502", "err", err, "changed", changed)
	} else if len(changed) > 0 {
		slog.Info("reconciled vhosts to match running containers", "changes", changed)
	} else {
		slog.Info("vhosts already match the running containers", "sites", len(changed))
	}

	srv := &http.Server{
		Addr:              *addr,
		Handler:           authenticated(token, api.Routes(mgr, version)),
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       2 * time.Minute,
		WriteTimeout:      5 * time.Minute, // container builds are slow
		IdleTimeout:       2 * time.Minute,
	}

	go func() {
		slog.Info("listening", "addr", *addr, "host", mgr.HostID(), "version", version)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			fatal("listen: %v", err)
		}
	}()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop

	slog.Info("shutting down")
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := srv.Shutdown(ctx); err != nil {
		slog.Error("shutdown", "err", err)
	}
}

// authenticated gates every route on a constant-time bearer comparison.
//
// The agent listens on loopback and the control plane reaches it over an SSH
// tunnel, so this is defence in depth rather than the only lock — but an agent
// that would serve an unauthenticated request is one misconfigured firewall
// away from serving every customer's source code to the internet.
func authenticated(token string, next http.Handler) http.Handler {
	want := []byte("Bearer " + token)
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Two unauthenticated routes, both deliberately so:
		//   /healthz  - liveness must not require a secret
		//   /tls-ask  - Caddy calls it before issuing a certificate and cannot
		//               send a bearer token. It answers only yes/no for one
		//               domain, and the agent binds 127.0.0.1, which customer
		//               containers cannot reach (verify-isolation.sh proves it).
		if r.URL.Path == "/healthz" || r.URL.Path == "/tls-ask" {
			next.ServeHTTP(w, r)
			return
		}
		got := []byte(r.Header.Get("Authorization"))
		if subtle.ConstantTimeCompare(got, want) != 1 {
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusUnauthorized)
			_ = json.NewEncoder(w).Encode(map[string]any{
				"ok":    false,
				"error": "unauthorized",
				"hint":  "send Authorization: Bearer <CIC_AGENT_TOKEN>",
			})
			return
		}
		next.ServeHTTP(w, r)
	})
}

func fatal(format string, a ...any) {
	fmt.Fprintf(os.Stderr, "cic-agent: "+format+"\n", a...)
	os.Exit(1)
}
