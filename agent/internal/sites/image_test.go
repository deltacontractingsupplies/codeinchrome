package sites

import (
	"context"
	"net"
	"net/http"
	"strconv"
	"strings"
	"testing"
	"time"
)

// A site that redirects / to its https login page is healthy: the redirect
// is its answer. Following it (to a URL the loopback check cannot reach) made
// a working site look DOWN and stopped an image roll.
func TestARedirectIsAHealthyAnswerAndA500IsNot(t *testing.T) {
	status := http.StatusFound
	ln, _ := net.Listen("tcp", "127.0.0.1:0")
	srv := &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if status == http.StatusFound {
			http.Redirect(w, r, "https://shop.codeinchrome.com/login", status)
			return
		}
		w.WriteHeader(status)
	})}
	go srv.Serve(ln)
	defer srv.Close()
	port, _ := strconv.Atoi(strings.Split(ln.Addr().String(), ":")[1])

	if err := waitForHTTP(context.Background(), port, 3*time.Second); err != nil {
		t.Fatalf("a redirect must count as healthy: %v", err)
	}
	status = http.StatusInternalServerError
	if err := waitForHTTP(context.Background(), port, 2*time.Second); err == nil {
		t.Fatal("a 500 must not count as healthy")
	}
}
