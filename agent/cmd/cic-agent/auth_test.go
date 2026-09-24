package main

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestEveryRouteButLivenessAndTLSAskNeedsTheToken(t *testing.T) {
	const token = "0123456789abcdef0123456789abcdef" // gitleaks:allow - a test token
	reached := false
	h := authenticated(token, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { reached = true }))

	for _, c := range []struct {
		path, header string
		want         bool
	}{
		{"/v1/sites", "", false},
		{"/v1/sites", "Bearer wrong", false},
		{"/v1/sites", "Bearer " + token + "x", false},
		{"/v1/sites", "bearer " + token, false},
		{"/v1/sites", token, false},
		{"/v1/sites", "Bearer " + token, true},
		{"/healthz", "", true},
		{"/tls-ask", "", true},
		// Only the exact paths are open: anything that merely starts or
		// ends like them is not.
		{"/healthz/../v1/sites", "", false},
		{"/v1/healthz", "", false},
		{"/tls-ask/x", "", false},
	} {
		reached = false
		req := httptest.NewRequest("GET", "http://agent"+c.path, nil)
		req.URL.Path = c.path // keep it unnormalised, as a raw request would be
		if c.header != "" {
			req.Header.Set("Authorization", c.header)
		}
		rec := httptest.NewRecorder()
		h.ServeHTTP(rec, req)
		if reached != c.want {
			t.Errorf("%s with %q: reached=%v, want %v (status %d)", c.path, c.header, reached, c.want, rec.Code)
		}
		if !c.want && rec.Code != http.StatusUnauthorized {
			t.Errorf("%s with %q: status %d, want 401", c.path, c.header, rec.Code)
		}
	}
}
