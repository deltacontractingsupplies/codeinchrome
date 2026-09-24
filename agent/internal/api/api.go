// Package api is the agent's HTTP surface.
//
// Every response carries ok, and ok is the VERDICT rather than the transport:
// a request that parsed fine but could not do what it asked returns ok:false.
// Two conventions in one API is the condition under which a caller writes
// `if (r.ok)` and is wrong half the time.
package api

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
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

// refusedMalware answers a write the malware scan refused (sites/scan.go):
// 422 and "malware", with the findings, so the control plane can act on it
// (App\Abuse\Enforcer) and the customer is told what was refused.
func refusedMalware(w http.ResponseWriter, err error) bool {
	bad, is := sites.IsMalware(err)
	if !is {
		return false
	}
	writeJSON(w, http.StatusUnprocessableEntity, resp{"ok": false, "error": "malware", "findings": bad.Findings, "hint": bad.Error()})
	return true
}

func Routes(mgr *sites.Manager, version string) http.Handler {
	mux := http.NewServeMux()

	// Liveness only. It deliberately reports nothing about sites, so it can
	// stay unauthenticated without leaking which customers are on this host.
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, ok(resp{"version": version, "time": time.Now().UTC()}))
	})

	// Caddy's on-demand TLS gate. Caddy requests a certificate on the first
	// TLS handshake for a name, but only after this answers 200 for it.
	//
	// Why on-demand at all: with certificates requested at config load, Caddy
	// asked Let's Encrypt within seconds of the DNS record being created. The
	// validators sometimes looked the name up before it had propagated, got
	// NXDOMAIN, and that negative answer is cached for the zone's SOA minimum -
	// 30 minutes on this Cloudflare plan, which does not allow lowering it. A
	// newly created site was then unreachable over HTTPS for up to half an
	// hour. Requesting on first connection means the name demonstrably
	// resolves for at least one client before anyone asks the CA to check it.
	//
	// Why gated: without this, anyone pointing any name at this IP could make
	// us request certificates for it, burning the CA rate limit for everyone.
	mux.HandleFunc("GET /tls-ask", func(w http.ResponseWriter, r *http.Request) {
		if mgr.Hosts(r.URL.Query().Get("domain")) {
			w.WriteHeader(http.StatusOK)
			return
		}
		w.WriteHeader(http.StatusNotFound)
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

	mux.HandleFunc("GET /v1/images", func(w http.ResponseWriter, r *http.Request) {
		st, err := mgr.ImageStatuses(r.Context())
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, fail("images_unreadable", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"sites": st, "basis": "each container's image id compared with the base image's, read at call time"}))
	})

	mux.HandleFunc("POST /v1/sites/{id}/recreate", func(w http.ResponseWriter, r *http.Request) {
		outcome, err := mgr.Recreate(r.Context(), r.PathValue("id"))
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, fail("recreate_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"outcome": outcome}))
	})

	mux.HandleFunc("GET /v1/host/stats", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, ok(resp{
			"stats": mgr.Stats(r.Context()),
			"basis": "statfs, /proc/meminfo, /proc/loadavg, a MySQL ping and systemd, read at call time",
		}))
	})

	mux.HandleFunc("GET /v1/usage", func(w http.ResponseWriter, r *http.Request) {
		usage, err := mgr.Usage(r.Context())
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, fail("usage_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{
			"usage": usage,
			"basis": "statfs on each site's mounted disk; information_schema sizes for each database (InnoDB estimates)",
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

	// ── Files ────────────────────────────────────────────────────────────
	//
	// The panel drives these, so every path here is attacker-controlled. The
	// containment guarantee lives in sites.resolve, which compares the path
	// AFTER symlinks are resolved against the site's own app directory - a
	// string check alone catches neither an encoded traversal nor a symlink.
	// Errors are deliberately vague: telling a caller whether /etc/shadow
	// exists is itself information.

	mux.HandleFunc("GET /v1/sites/{id}/files", func(w http.ResponseWriter, r *http.Request) {
		path := r.URL.Query().Get("path")
		if path == "" {
			path = "/"
		}

		if r.URL.Query().Get("read") == "1" {
			content, rev, err := mgr.ReadFileRevision(r.Context(), r.PathValue("id"), path)
			if err != nil {
				writeJSON(w, http.StatusBadRequest, fail("cannot_read", err.Error()))
				return
			}
			// revision is what a caller presents as `expect` to save without
			// overwriting a change made since this read.
			writeJSON(w, http.StatusOK, ok(resp{"path": path, "content": content, "revision": rev}))
			return
		}

		listing, err := mgr.ListFiles(r.Context(), r.PathValue("id"), path)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_list", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"listing": listing}))
	})

	mux.HandleFunc("PUT /v1/sites/{id}/files", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Path    string `json:"path"`
			Content string `json:"content"`
			Expect  string `json:"expect"` // "", "absent", or a revision from a read
		}
		// Bounded at twice the file limit so the envelope and JSON escaping
		// have room, and no further: an unbounded body is a memory exhaustion
		// primitive on a host shared by every other customer.
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 2*sites.MaxFileSize)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {path, content}"))
			return
		}
		rev, err := mgr.WriteFileIf(r.Context(), r.PathValue("id"), body.Path, body.Content, body.Expect)
		if refusedMalware(w, err) {
			return
		}
		if errors.Is(err, sites.ErrConflict) {
			// Nothing was written. The caller's copy is stale; it must re-read,
			// reconcile, and try again with the new revision.
			writeJSON(w, http.StatusConflict, fail("conflict",
				"The file changed since you read it, or already exists. Nothing was written: re-read it and save again."))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_write", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{
			"path":     body.Path,
			"bytes":    len(body.Content),
			"revision": rev,
			"basis":    "written to a temporary file in the same directory and renamed",
		}))
	})

	// Many files in one call and one version (sites/batch.go).
	mux.HandleFunc("PUT /v1/sites/{id}/files/batch", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Files   []sites.FileWrite `json:"files"`
			Message string            `json:"message"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 2*16<<20)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {files: [{path, content, expect?}], message?}"))
			return
		}
		written, err := mgr.WriteMany(r.Context(), r.PathValue("id"), body.Files, body.Message)
		if len(written) == 0 && refusedMalware(w, err) {
			return
		}
		var be *sites.BatchError
		switch {
		case errors.Is(err, sites.ErrConflict):
			writeJSON(w, http.StatusConflict, resp{"ok": false, "error": "conflict", "path": be0(err),
				"hint": "That file changed since you read it, or already exists. NOTHING in this batch was written: re-read it and send the batch again."})
		case errors.As(err, &be) && len(written) == 0:
			writeJSON(w, http.StatusBadRequest, resp{"ok": false, "error": "cannot_write", "path": be.Path,
				"hint": be.Err.Error() + ". NOTHING in this batch was written."})
		case err != nil && len(written) == 0:
			writeJSON(w, http.StatusBadRequest, fail("cannot_write", err.Error()))
		case err != nil:
			writeJSON(w, http.StatusInternalServerError, resp{"ok": false, "error": "partially_written", "written": written, "hint": err.Error()})
		default:
			writeJSON(w, http.StatusOK, ok(resp{"written": written}))
		}
	})

	mux.HandleFunc("POST /v1/sites/{id}/files/edit", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Path   string       `json:"path"`
			Edits  []sites.Edit `json:"edits"`
			Expect string       `json:"expect"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 2*sites.MaxFileSize)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {path, edits: [{find, replace, all?}], expect?}"))
			return
		}
		out, err := mgr.EditFile(r.Context(), r.PathValue("id"), body.Path, body.Edits, body.Expect)
		if refusedMalware(w, err) {
			return
		}
		if errors.Is(err, sites.ErrConflict) {
			writeJSON(w, http.StatusConflict, fail("conflict", "The file changed since you read it. Nothing was written: re-read it and edit again."))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_edit", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"path": out.Path, "bytes": out.Bytes, "revision": out.Revision, "lint": out.Lint}))
	})

	// PHP in the site's own booted application (sites/eval.go).
	mux.HandleFunc("POST /v1/sites/{id}/eval", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Code string `json:"code"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 512<<10)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {code}"))
			return
		}
		res, err := mgr.Eval(r.Context(), r.PathValue("id"), body.Code)
		if errors.Is(err, sites.ErrBusy) {
			writeJSON(w, http.StatusConflict, fail("busy", "Another command is running on this site; try again in a moment."))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("eval_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"result": res}))
	})
	mux.HandleFunc("POST /v1/sites/{id}/login-cookie", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			UserID int64  `json:"userId"`
			Guard  string `json:"guard"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1024)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {userId, guard?}"))
			return
		}
		name, value, err := mgr.LoginCookie(r.Context(), r.PathValue("id"), body.UserID, body.Guard)
		if errors.Is(err, sites.ErrBusy) {
			writeJSON(w, http.StatusConflict, fail("busy", "Another command is running on this site; try again in a moment."))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("login_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"name": name, "value": value}))
	})

	// A request to the site itself, from its host (sites/batch.go).
	mux.HandleFunc("POST /v1/sites/{id}/request", func(w http.ResponseWriter, r *http.Request) {
		var body sites.SiteRequest
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 2*sites.MaxFileSize)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {method, path, headers?, body?}"))
			return
		}
		out, err := mgr.Request(r.Context(), r.PathValue("id"), body)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("request_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"response": out}))
	})

	mux.HandleFunc("DELETE /v1/sites/{id}/files", func(w http.ResponseWriter, r *http.Request) {
		path := r.URL.Query().Get("path")
		if path == "" {
			writeJSON(w, http.StatusBadRequest, fail("no_path", "pass ?path="))
			return
		}
		if err := mgr.DeleteFile(r.Context(), r.PathValue("id"), path); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_delete", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"path": path, "deleted": true}))
	})

	// The rest of the file manager: folders, move, copy, zip, unzip.
	pair := func(w http.ResponseWriter, r *http.Request) (a, b string, valid bool) {
		var body struct{ From, To, Path, Archive, Into string }
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 8192)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "invalid body"))
			return "", "", false
		}
		switch {
		case body.Archive != "":
			return body.Archive, body.Into, true
		case body.From != "":
			return body.From, body.To, true
		}
		return body.Path, "", true
	}
	fileOp := func(name string, op func(r *http.Request, a, b string) error) http.HandlerFunc {
		return func(w http.ResponseWriter, r *http.Request) {
			a, b, valid := pair(w, r)
			if !valid {
				return
			}
			if err := op(r, a, b); err != nil {
				if refusedMalware(w, err) {
					return
				}
				writeJSON(w, http.StatusBadRequest, fail("cannot_"+name, err.Error()))
				return
			}
			writeJSON(w, http.StatusOK, ok(resp{"done": name}))
		}
	}
	mux.HandleFunc("POST /v1/sites/{id}/files/mkdir", fileOp("mkdir", func(r *http.Request, a, _ string) error {
		return mgr.Mkdir(r.Context(), r.PathValue("id"), a)
	}))
	mux.HandleFunc("POST /v1/sites/{id}/files/move", fileOp("move", func(r *http.Request, a, b string) error {
		return mgr.Rename(r.Context(), r.PathValue("id"), a, b)
	}))
	mux.HandleFunc("POST /v1/sites/{id}/files/copy", fileOp("copy", func(r *http.Request, a, b string) error {
		return mgr.Copy(r.Context(), r.PathValue("id"), a, b)
	}))
	mux.HandleFunc("POST /v1/sites/{id}/files/zip", fileOp("zip", func(r *http.Request, a, b string) error {
		return mgr.Zip(r.Context(), r.PathValue("id"), a, b)
	}))
	mux.HandleFunc("POST /v1/sites/{id}/files/unzip", fileOp("unzip", func(r *http.Request, a, b string) error {
		return mgr.Unzip(r.Context(), r.PathValue("id"), a, b)
	}))
	mux.HandleFunc("DELETE /v1/sites/{id}/tree", func(w http.ResponseWriter, r *http.Request) {
		err := mgr.DeleteTree(r.Context(), r.PathValue("id"), r.URL.Query().Get("path"), r.URL.Query().Get("confirm") == "1")
		if errors.Is(err, sites.ErrNeedsConfirm) {
			writeJSON(w, http.StatusConflict, fail("needs_confirm", "Deleting a folder deletes everything in it. Pass confirm=1."))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_delete", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"deleted": true}))
	})
	mux.HandleFunc("GET /v1/sites/{id}/paths", func(w http.ResponseWriter, r *http.Request) {
		paths, truncated, err := mgr.Paths(r.Context(), r.PathValue("id"))
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_list", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"paths": paths, "truncated": truncated}))
	})

	// Every site's CPU counter (sites/cpu.go), for spotting a miner.
	mux.HandleFunc("GET /v1/cpu", func(w http.ResponseWriter, r *http.Request) {
		cpu, err := mgr.CPU(r.Context())
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, fail("cpu_unreadable", err.Error()))
			return
		}
		if cpu == nil {
			cpu = []sites.SiteCPU{}
		}
		writeJSON(w, http.StatusOK, ok(resp{"sites": cpu, "at": time.Now().UTC()}))
	})
	// A whole-site malware scan (sites/scan.go), run by the control plane on a
	// schedule. A scan that cannot run is an error, never "clean".
	mux.HandleFunc("POST /v1/sites/{id}/scan", func(w http.ResponseWriter, r *http.Request) {
		found, err := mgr.ScanSite(r.Context(), r.PathValue("id"))
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, fail("scan_failed", err.Error()))
			return
		}
		if found == nil {
			found = []sites.Finding{}
		}
		writeJSON(w, http.StatusOK, ok(resp{"findings": found, "clean": len(found) == 0}))
	})
	mux.HandleFunc("GET /v1/sites/{id}/search", func(w http.ResponseWriter, r *http.Request) {
		limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
		hits, err := mgr.Search(r.Context(), r.PathValue("id"), r.URL.Query().Get("q"), limit)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_search", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"hits": hits}))
	})
	// Raw bytes in and out: uploads and downloads carry binary files (images,
	// fonts, archives) that JSON strings cannot.
	mux.HandleFunc("PUT /v1/sites/{id}/upload", func(w http.ResponseWriter, r *http.Request) {
		body := http.MaxBytesReader(w, r.Body, sites.MaxUploadSize+1)
		if err := mgr.Upload(r.Context(), r.PathValue("id"), r.URL.Query().Get("path"), body); err != nil {
			if refusedMalware(w, err) {
				return
			}
			writeJSON(w, http.StatusBadRequest, fail("cannot_upload", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"path": r.URL.Query().Get("path")}))
	})
	mux.HandleFunc("GET /v1/sites/{id}/download", func(w http.ResponseWriter, r *http.Request) {
		// Buffered through a temp file so an error after the first byte can
		// still be reported as an error, not a truncated file.
		tmp, err := os.CreateTemp("", "cic-dl-*")
		if err != nil {
			writeJSON(w, http.StatusInternalServerError, fail("cannot_download", "no scratch space"))
			return
		}
		defer os.Remove(tmp.Name())
		defer tmp.Close()
		name, err := mgr.Download(r.Context(), r.PathValue("id"), r.URL.Query().Get("path"), tmp)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_download", err.Error()))
			return
		}
		tmp.Seek(0, 0)
		w.Header().Set("Content-Type", "application/octet-stream")
		w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=%q", name))
		w.Header().Set("X-Content-Type-Options", "nosniff")
		io.Copy(w, tmp)
	})

	// History: every version of every file, the bin of deleted files, and
	// restore (itself a new version - history is never rewritten).
	mux.HandleFunc("GET /v1/sites/{id}/history", func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		limit, _ := strconv.Atoi(q.Get("limit"))
		if rev := q.Get("rev"); rev != "" {
			content, err := mgr.FileAt(r.Context(), r.PathValue("id"), rev, q.Get("path"))
			if err != nil {
				writeJSON(w, http.StatusBadRequest, fail("cannot_read", err.Error()))
				return
			}
			writeJSON(w, http.StatusOK, ok(resp{"path": q.Get("path"), "rev": rev, "content": content}))
			return
		}
		versions, err := mgr.History(r.Context(), r.PathValue("id"), q.Get("path"), limit)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_list", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"path": q.Get("path"), "versions": versions}))
	})

	mux.HandleFunc("GET /v1/sites/{id}/bin", func(w http.ResponseWriter, r *http.Request) {
		limit, _ := strconv.Atoi(r.URL.Query().Get("limit"))
		bin, err := mgr.Deleted(r.Context(), r.PathValue("id"), limit)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_list", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"bin": bin}))
	})

	mux.HandleFunc("POST /v1/sites/{id}/history/restore", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Rev  string `json:"rev"`
			Path string `json:"path"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4096)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {rev, path}"))
			return
		}
		if err := mgr.Restore(r.Context(), r.PathValue("id"), body.Rev, body.Path); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_restore", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"path": body.Path, "restoredFrom": body.Rev}))
	})

	// Background processes beside Apache: queue worker, scheduler, Reverb.
	mux.HandleFunc("PUT /v1/sites/{id}/background", func(w http.ResponseWriter, r *http.Request) {
		var b sites.Background
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1024)).Decode(&b); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {queue, scheduler, reverb}"))
			return
		}
		applied, err := mgr.SetBackground(r.Context(), r.PathValue("id"), b)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_apply", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"applied": applied}))
	})

	mux.HandleFunc("PUT /v1/sites/{id}/aliases", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Aliases []string `json:"aliases"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 16<<10)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {aliases: [...]}"))
			return
		}
		site, err := mgr.SetAliases(r.Context(), r.PathValue("id"), body.Aliases)
		if err != nil {
			writeJSON(w, http.StatusUnprocessableEntity, fail("aliases_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"site": site, "basis": "vhost rewritten and proxy reloaded"}))
	})

	mux.HandleFunc("PUT /v1/sites/{id}/limits", func(w http.ResponseWriter, r *http.Request) {
		var o sites.LimitsOpts
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4096)).Decode(&o); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {cpuLimit?, memLimit?, diskGb?}"))
			return
		}
		applied, err := mgr.SetLimits(r.Context(), r.PathValue("id"), o)
		if err != nil {
			writeJSON(w, http.StatusUnprocessableEntity, fail("limits_failed", err.Error()))
			return
		}
		for _, v := range applied {
			if strings.HasPrefix(v, "failed") {
				body := resp{"ok": false, "error": "partially_applied", "applied": applied,
					"hint": "Some limits were not applied; see applied{}."}
				writeJSON(w, http.StatusInternalServerError, body)
				return
			}
		}
		writeJSON(w, http.StatusOK, ok(resp{"applied": applied}))
	})

	// ── Commands and logs ────────────────────────────────────────────────

	mux.HandleFunc("POST /v1/sites/{id}/command", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Tool    string   `json:"tool"`
			Args    []string `json:"args"`
			Confirm bool     `json:"confirm"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 16<<10)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {tool: artisan|composer, args: [...], confirm?}"))
			return
		}
		res, err := mgr.RunCommand(r.Context(), r.PathValue("id"), body.Tool, body.Args, body.Confirm)
		switch {
		case errors.Is(err, sites.ErrNeedsConfirm):
			writeJSON(w, http.StatusConflict, fail("needs_confirm",
				"This command destroys data. Nothing was run. Send it again with confirm: true if that is intended."))
		case errors.Is(err, sites.ErrBusy):
			writeJSON(w, http.StatusConflict, fail("busy", "Another command is still running for this site."))
		case err != nil:
			writeJSON(w, http.StatusUnprocessableEntity, fail("command_refused", err.Error()))
		default:
			// ok is the verdict: a command that exited non-zero did not succeed.
			body := resp{"result": res}
			if res.ExitCode == 0 {
				writeJSON(w, http.StatusOK, ok(body))
			} else {
				body["ok"], body["error"] = false, "command_failed"
				body["hint"] = fmt.Sprintf("exited %d; see result.output", res.ExitCode)
				if res.TimedOut {
					body["error"], body["hint"] = "timed_out", "The command was stopped at its time limit."
				}
				writeJSON(w, http.StatusOK, body)
			}
		}
	})

	mux.HandleFunc("GET /v1/sites/{id}/logs", func(w http.ResponseWriter, r *http.Request) {
		q := r.URL.Query()
		n, _ := strconv.Atoi(q.Get("lines"))
		var res sites.LogResult
		var err error
		if since, perr := strconv.ParseInt(q.Get("since"), 10, 64); perr == nil && q.Get("source") == "app" {
			res, err = mgr.LogsSince(r.PathValue("id"), q.Get("file"), since)
		} else {
			res, err = mgr.Logs(r.Context(), r.PathValue("id"), q.Get("source"), n)
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("logs_unavailable", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"log": res}))
	})

	// ── Database browser ─────────────────────────────────────────────────
	// Runs as the site's own MySQL user; see sites/dbquery.go.

	mux.HandleFunc("GET /v1/sites/{id}/db", func(w http.ResponseWriter, r *http.Request) {
		tables, err := mgr.Tables(r.Context(), r.PathValue("id"))
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("db_unavailable", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{
			"database": sites.DBName(r.PathValue("id")),
			"tables":   tables,
			"basis":    "information_schema, queried as the site's own user; row counts are InnoDB estimates",
		}))
	})

	mux.HandleFunc("POST /v1/sites/{id}/db/query", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			SQL   string `json:"sql"`
			Write bool   `json:"write"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 256<<10)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {sql, write?}"))
			return
		}
		result, err := mgr.Query(r.Context(), r.PathValue("id"), body.SQL, body.Write)
		if errors.Is(err, sites.ErrNeedsWrite) {
			writeJSON(w, http.StatusUnprocessableEntity, fail("needs_write",
				"This statement may change data. Nothing was run. Send it again with write: true if that is intended."))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("query_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"result": result}))
	})

	// Database export: the whole database as .sql.gz, streamed - a dump can be
	// gigabytes and is never buffered. A failure after the first byte cannot
	// change the status code; what protects the customer is gzip itself - a
	// dump cut off part-way has no trailer and fails its checksum on
	// decompression, so it cannot be mistaken for a complete one. The outcome
	// is also sent as an HTTP trailer (X-Export-Status) for any client that
	// reads trailers.
	// ?saved=before-import returns the copy the last import saved instead.
	mux.HandleFunc("GET /v1/sites/{id}/db/export", func(w http.ResponseWriter, r *http.Request) {
		// The server's 5-minute write timeout is for API calls, not a dump.
		_ = http.NewResponseController(w).SetWriteDeadline(time.Now().Add(35 * time.Minute))
		id := r.PathValue("id")
		name := sites.DBName(id) + ".sql.gz"
		if r.URL.Query().Get("saved") == "before-import" {
			f, err := mgr.BeforeImport(id)
			if err != nil {
				writeJSON(w, http.StatusNotFound, fail("no_saved_copy", err.Error()))
				return
			}
			defer f.Close()
			w.Header().Set("Trailer", "X-Export-Status")
			w.Header().Set("Content-Type", "application/gzip")
			w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=%q", sites.DBName(id)+"-before-import.sql.gz"))
			if _, err := io.Copy(w, f); err == nil {
				w.Header().Set("X-Export-Status", "ok")
			}
			return
		}
		w.Header().Set("Trailer", "X-Export-Status")
		w.Header().Set("Content-Type", "application/gzip")
		w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=%q", name))
		if err := mgr.ExportDB(r.Context(), id, w); err != nil {
			w.Header().Set("X-Export-Status", "failed: "+err.Error())
			return
		}
		w.Header().Set("X-Export-Status", "ok")
	})

	mux.HandleFunc("PUT /v1/sites/{id}/db/import", func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("confirm") != "1" {
			writeJSON(w, http.StatusConflict, fail("needs_confirm",
				"An import replaces data in the site's database. The current database is saved first. Send confirm=1 to go ahead."))
			return
		}
		rc := http.NewResponseController(w)
		_ = rc.SetReadDeadline(time.Now().Add(20 * time.Minute))
		_ = rc.SetWriteDeadline(time.Now().Add(35 * time.Minute))
		body := http.MaxBytesReader(w, r.Body, sites.MaxImportSize+1)
		err := mgr.ImportDB(r.Context(), r.PathValue("id"), body)
		if errors.Is(err, sites.ErrBusy) {
			writeJSON(w, http.StatusConflict, fail("busy", "Another import or command is running on this site. Try again when it finishes."))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("import_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"imported": true, "saved": "before-import"}))
	})

	// Backups: the nightly snapshots, a backup now, and a restore - which
	// backs the site up first, so it can be undone. Both run in the
	// background; GET .../backups reports progress as "operation".
	mux.HandleFunc("GET /v1/sites/{id}/backups", func(w http.ResponseWriter, r *http.Request) {
		id := r.PathValue("id")
		list, err := mgr.Backups(r.Context(), id)
		if err != nil {
			writeJSON(w, http.StatusBadGateway, fail("backups_unavailable", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"backups": list, "operation": mgr.BackupStatus(id)}))
	})
	mux.HandleFunc("POST /v1/sites/{id}/backups", func(w http.ResponseWriter, r *http.Request) {
		op, err := mgr.StartBackup(r.PathValue("id"))
		if errors.Is(err, sites.ErrBackupRunning) {
			writeJSON(w, http.StatusConflict, fail("busy", err.Error()))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_backup", err.Error()))
			return
		}
		writeJSON(w, http.StatusAccepted, ok(resp{"operation": op}))
	})
	mux.HandleFunc("POST /v1/sites/{id}/backups/restore", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Snapshot string `json:"snapshot"`
			Confirm  bool   `json:"confirm"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4<<10)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {snapshot, confirm}"))
			return
		}
		if !body.Confirm {
			writeJSON(w, http.StatusConflict, fail("needs_confirm",
				"A restore replaces the site's files and database and takes it offline for a few minutes. The site is backed up first. Send confirm: true to go ahead."))
			return
		}
		op, err := mgr.StartRestore(r.Context(), r.PathValue("id"), body.Snapshot)
		if errors.Is(err, sites.ErrBackupRunning) {
			writeJSON(w, http.StatusConflict, fail("busy", err.Error()))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_restore", err.Error()))
			return
		}
		writeJSON(w, http.StatusAccepted, ok(resp{"operation": op}))
	})

	// The editor's language server (Phpactor, inside the site's container):
	// a batch of JSON-RPC messages in, the responses and anything queued out.
	mux.HandleFunc("POST /v1/sites/{id}/lsp/{session}", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Messages []json.RawMessage `json:"messages"`
			WaitMs   int               `json:"waitMs"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 16<<20)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {messages: [...], waitMs}"))
			return
		}
		out, err := mgr.LSPExchange(r.Context(), r.PathValue("id"), r.PathValue("session"), body.Messages, time.Duration(body.WaitMs)*time.Millisecond)
		if errors.Is(err, sites.ErrLSPSessions) {
			writeJSON(w, http.StatusConflict, fail("too_many_sessions", err.Error()))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadGateway, fail("lsp_failed", err.Error()))
			return
		}
		if out == nil {
			out = []json.RawMessage{}
		}
		writeJSON(w, http.StatusOK, ok(resp{"messages": out}))
	})
	mux.HandleFunc("DELETE /v1/sites/{id}/lsp/{session}", func(w http.ResponseWriter, r *http.Request) {
		mgr.LSPClose(r.PathValue("id"), r.PathValue("session"))
		writeJSON(w, http.StatusOK, ok(resp{"closed": true}))
	})

	// Laravel Boost's MCP tools, answered by the site's own application.
	mux.HandleFunc("POST /v1/sites/{id}/mcp", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Method string          `json:"method"`
			Params json.RawMessage `json:"params"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1<<20)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {method, params}"))
			return
		}
		res, err := mgr.MCP(r.Context(), r.PathValue("id"), body.Method, body.Params)
		if errors.Is(err, sites.ErrMCPNotInstalled) {
			writeJSON(w, http.StatusConflict, fail("boost_not_installed", err.Error()))
			return
		}
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("mcp_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"result": res}))
	})

	mux.HandleFunc("PUT /v1/sites/{id}/suspended", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Suspended *bool `json:"suspended"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1024)).Decode(&body); err != nil || body.Suspended == nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {suspended: true|false}"))
			return
		}
		applied, err := mgr.SetSuspended(r.Context(), r.PathValue("id"), *body.Suspended)
		if err != nil {
			writeJSON(w, http.StatusUnprocessableEntity, fail("cannot_apply", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"applied": applied}))
	})

	mux.HandleFunc("PUT /v1/sites/{id}/php", func(w http.ResponseWriter, r *http.Request) {
		var body sites.PHPSettings
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4<<10)).Decode(&body); err != nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {memoryMB, maxExecutionSeconds, uploadMB}"))
			return
		}
		applied, err := mgr.SetPHP(r.Context(), r.PathValue("id"), body)
		if err != nil {
			writeJSON(w, http.StatusBadRequest, fail("cannot_apply", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"applied": applied}))
	})

	// Moving a site between hosts (sites/transfer.go). Streams, never buffered.
	stream := func(w http.ResponseWriter, r *http.Request, name string, export func(io.Writer) error) {
		_ = http.NewResponseController(w).SetWriteDeadline(time.Now().Add(2 * time.Hour))
		w.Header().Set("Trailer", "X-Export-Status")
		w.Header().Set("Content-Type", "application/gzip")
		w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=%q", name))
		if err := export(w); err != nil {
			w.Header().Set("X-Export-Status", "failed: "+err.Error())
			return
		}
		w.Header().Set("X-Export-Status", "ok")
	}
	receive := func(w http.ResponseWriter, r *http.Request, what string, imp func(io.Reader) error) {
		_ = http.NewResponseController(w).SetReadDeadline(time.Now().Add(2 * time.Hour))
		if err := imp(http.MaxBytesReader(w, r.Body, 16<<30)); err != nil {
			writeJSON(w, http.StatusUnprocessableEntity, fail("import_failed", what+": "+err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"imported": what}))
	}
	mux.HandleFunc("GET /v1/sites/{id}/transfer/files", func(w http.ResponseWriter, r *http.Request) {
		id := r.PathValue("id")
		stream(w, r, id+"-files.tar.gz", func(out io.Writer) error { return mgr.ExportFiles(r.Context(), id, out) })
	})
	mux.HandleFunc("PUT /v1/sites/{id}/transfer/files", func(w http.ResponseWriter, r *http.Request) {
		id := r.PathValue("id")
		receive(w, r, "files", func(in io.Reader) error { return mgr.ImportFiles(r.Context(), id, in) })
	})
	mux.HandleFunc("GET /v1/sites/{id}/transfer/history", func(w http.ResponseWriter, r *http.Request) {
		id := r.PathValue("id")
		stream(w, r, id+"-history.tar.gz", func(out io.Writer) error { return mgr.ExportHistory(r.Context(), id, out) })
	})
	mux.HandleFunc("PUT /v1/sites/{id}/transfer/history", func(w http.ResponseWriter, r *http.Request) {
		id := r.PathValue("id")
		receive(w, r, "history", func(in io.Reader) error { return mgr.ImportHistory(r.Context(), id, in) })
	})
	mux.HandleFunc("PUT /v1/sites/{id}/maintenance", func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			Down *bool `json:"down"`
		}
		if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 1024)).Decode(&body); err != nil || body.Down == nil {
			writeJSON(w, http.StatusBadRequest, fail("bad_json", "body must be {down: true|false}"))
			return
		}
		if err := mgr.SetMaintenance(r.Context(), r.PathValue("id"), *body.Down); err != nil {
			writeJSON(w, http.StatusUnprocessableEntity, fail("maintenance_failed", err.Error()))
			return
		}
		writeJSON(w, http.StatusOK, ok(resp{"down": *body.Down}))
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
			"GET /healthz, GET /v1/host, GET|POST /v1/sites, GET|DELETE /v1/sites/{id}, "+
				"GET|PUT|DELETE /v1/sites/{id}/files, GET /v1/sites/{id}/db, POST /v1/sites/{id}/db/query, POST /v1/reconcile"))
	})

	return mux
}

// be0 names the file a batch error is about, or "".
func be0(err error) string {
	var be *sites.BatchError
	if errors.As(err, &be) {
		return be.Path
	}
	return ""
}
