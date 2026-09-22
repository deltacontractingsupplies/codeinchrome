// Package api is the agent's HTTP surface.
//
// Every response carries ok, and ok is the VERDICT rather than the transport:
// a request that parsed fine but could not do what it asked returns ok:false.
// Two conventions in one API is the condition under which a caller writes
// `if (r.ok)` and is wrong half the time.
package api

import (
	"encoding/json"
	"net/http"
	"strings"
	"time"

	"github.com/codeinchrome/agent/internal/sites"
)

type resp map[string]any

func writeJSON(w http.ResponseWriter, status int, body resp) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

func ok(body resp) resp {
	body["ok"] = true
	return body
}

// fail always carries a hint. An error code without a next action just moves
// the problem to whoever is reading the log at 3am.
func fail(code, hint string) resp {
	return resp{"ok": false, "error": code, "hint": hint}
}

func Routes(mgr *sites.Manager, version string) http.Handler {
	mux := http.NewServeMux()

	// Liveness only. It deliberately reports nothing about sites, so it can
	// stay unauthenticated without leaking which customers are on this host.
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, ok(resp{"version": version, "time": time.Now().UTC()}))
	})

	mux.HandleFunc("GET /v1/host", func(w http.ResponseWriter, r *http.Request) {
		list, err := mgr.List(r.Context())
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, fail("host_unreadable", err.Error()))
			return
		}
		running := 0
		for _, s := range list {
			if s.State == "running" {
				running++
			}
		}
		writeJSON(w, http.StatusOK, ok(resp{
			"host": mgr.HostID(), "version": version,
			"sites": len(list), "running": running,
			"basis": "docker ps and the customer root, read at call time",
		}))
	})

	// Exposed as well as run at start-up: an operator who has just restarted
	// something should be able to heal the proxy without restarting the agent.
	mux.HandleFunc("POST /v1/reconcile", func(w http.ResponseWriter, r *http.Request) {
		changed, err := mgr.Reconcile(r.Context())
		if changed == nil {
			changed = []string{}
		}
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, resp{
				"ok": false, "error": "reconcile_failed", "hint": err.Error(), "changed": changed,
			})
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{
			"changed": changed,
			"basis":   "each running site's vhost compared against the port docker reports for it",
		}))
	})

	mux.HandleFunc("GET /v1/sites", func(w http.ResponseWriter, r *http.Request) {
		list, err := mgr.List(r.Context())
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, fail("list_failed", err.Error()))
			return
		}
		if list == nil {
			list = []sites.Site{}
		}
		writeJSON(w, http.StatusOK, ok(resp{"sites": list, "host": mgr.HostID()}))
	})

	mux.HandleFunc("POST /v1/sites", func(w http.ResponseWriter, r *http.Request) {
		var o sites.CreateOpts
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20)).Decode(&o); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {id, domain, cpuLimit?, memLimit?}"))
			return
		}
		site, err := mgr.Create(r.Context(), o)
		if err != nil {
			writeJSON(w, http.StatusUnprocessableEntity, fail("create_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusCreated, ok(resp{
			"site": site,
			"note": "The container is running and the vhost is written. TLS is issued by Caddy on first request, " +
				"so a certificate is NOT yet proven at this point — call GET /v1/sites/{id} after a request to confirm.",
		}))
	})

	mux.HandleFunc("GET /v1/sites/{id}", func(w http.ResponseWriter, r *http.Request) {
		site, err := mgr.Get(r.Context(), r.PathValue("id"))
		if err != nil {
			writeJSON(w, http.StatusNotFound, fail("no_such_site", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"site": site}))
	})

	mux.HandleFunc("DELETE /v1/sites/{id}", func(w http.ResponseWriter, r *http.Request) {
		done, err := mgr.Delete(r.Context(), r.PathValue("id"))
		if err != nil {
			writeJSON(w, http.StatusUnprocessableEntity, fail("delete_failed", err.Error()))
			return
		}

		// Each part is "removed", "absent" or "failed". ok means NOTHING was
		// left behind - which is not the same as "we removed everything",
		// because a site that was never here leaves nothing behind either.
		var failed []string
		present := 0
		for part, state := range done {
			switch state {
			case "failed":
				failed = append(failed, part)
			case "removed":
				present++
			}
		}

		body := resp{"parts": done, "basis": "each part observed before and after removal"}

		if len(failed) > 0 {
			body["ok"] = false
			body["error"] = "partially_removed"
			body["hint"] = "Still present: " + strings.Join(failed, ", ") + ". Do not treat this site as gone."
			writeJSON(w, http.StatusInternalServerError, body)
			return
		}

		if present == 0 {
			// Honest about doing nothing. The caller may well have aimed at
			// the wrong host, and reporting a removal would hide that.
			body["note"] = "Nothing was here to remove; every part was already absent."
		}

		writeJSON(w, http.StatusOK, ok(body))
	})

	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusNotFound, fail("no_such_route",
			"GET /healthz, GET /v1/host, GET|POST /v1/sites, GET|DELETE /v1/sites/{id}, POST /v1/reconcile"))
	})

	return mux
}
