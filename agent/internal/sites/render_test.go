package sites

import (
	"context"
	"os/exec"
	"strings"
	"sync"
	"testing"
)

func TestOnlyThePlatformsOwnSitesCanBeRendered(t *testing.T) {
	m := &Manager{cfg: Config{PlatformDomain: "codeinchrome.com"}}
	for _, ok := range []string{"https://shop.codeinchrome.com/", "https://shop.codeinchrome.com/login?x=1", "https://SHOP.codeinchrome.com:443/a#frag"} {
		if _, err := m.renderable(ok); err != nil {
			t.Errorf("%s refused: %v", ok, err)
		}
	}
	for _, bad := range []string{
		"http://shop.codeinchrome.com/", "https://evil.example/", "https://codeinchrome.com/",
		"https://a.b.codeinchrome.com/", "https://user:pw@shop.codeinchrome.com/", "https://shop.codeinchrome.com:8443/",
		"file:///etc/passwd", "https://169.254.169.254/", "https://shop.codeinchrome.com.evil.example/", "javascript:alert(1)",
		"https://localhost/", "data:text/html,<p>x</p>",
	} {
		if _, err := m.renderable(bad); err == nil {
			t.Errorf("%s was accepted", bad)
		}
	}
}

// The container is started with every privilege taken away, and removed.
func TestTheRendererRunsLockedDownAndIsAlwaysRemoved(t *testing.T) {
	m := &Manager{cfg: Config{PlatformDomain: "codeinchrome.com"}}
	var mu sync.Mutex
	var calls [][]string
	orig := renderDocker
	t.Cleanup(func() { renderDocker = orig })
	renderDocker = func(ctx context.Context, args ...string) *exec.Cmd {
		mu.Lock()
		calls = append(calls, args)
		mu.Unlock()
		if args[0] == "run" {
			return exec.CommandContext(ctx, "printf", "%s", "<html><body><a href=\"https://evil.example/\">x</a></body></html>")
		}
		return exec.CommandContext(ctx, "true")
	}
	res, err := m.RenderURL(context.Background(), "https://shop.codeinchrome.com/")
	if err != nil || !strings.Contains(res.DOM, "evil.example") {
		t.Fatalf("render: %+v %v", res, err)
	}
	var run, rm []string
	for _, c := range calls {
		switch c[0] {
		case "run":
			run = c
		case "rm":
			rm = c
		}
	}
	joined := strings.Join(run, " ")
	for _, want := range []string{"--rm", "--network cic-render", "--memory 768m", "--pids-limit 256", "--read-only",
		"--cap-drop ALL", "--security-opt no-new-privileges", "--user 10001:10001", renderImage,
		"--user-agent=" + renderUA, "--dump-dom https://shop.codeinchrome.com/"} {
		if !strings.Contains(joined, want) {
			t.Errorf("run is missing %q:\n%s", want, joined)
		}
	}
	if strings.Contains(renderUA, "Headless") {
		t.Error("the renderer announces itself as headless")
	}
	if len(rm) < 3 || rm[1] != "-f" || !strings.HasPrefix(rm[2], "cic-render-") {
		t.Errorf("the container was not removed afterwards: %v", rm)
	}
}
