package sites

import (
	"context"
	"os"
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

// A page at every screen size: one locked-down browser run per size, at that
// window size, its picture read from a directory removed afterwards.
func TestAPageIsShotAtEveryScreenSizeLockedDown(t *testing.T) {
	m := &Manager{cfg: Config{PlatformDomain: "codeinchrome.com"}}
	png := "\x89PNG\r\n\x1a\n-fake-image-bytes"
	var runs []string
	var outDir string
	orig := renderDocker
	t.Cleanup(func() { renderDocker = orig })
	renderDocker = func(ctx context.Context, args ...string) *exec.Cmd {
		if args[0] != "run" {
			return exec.CommandContext(ctx, "true")
		}
		joined := strings.Join(args, " ")
		runs = append(runs, joined)
		// Where the browser would write: the host side of -v, and --screenshot's name.
		var host, file string
		for i, a := range args {
			if a == "-v" {
				host = strings.SplitN(args[i+1], ":", 2)[0]
			}
			if strings.HasPrefix(a, "--screenshot=/out/") {
				file = strings.TrimPrefix(a, "--screenshot=/out/")
			}
		}
		outDir = host
		return exec.CommandContext(ctx, "sh", "-c", `printf '%s' "$1" > "$2"`, "sh", png, host+"/"+file)
	}
	shots, err := m.RenderShots(context.Background(), "https://shop.codeinchrome.com/cart")
	if err != nil || len(shots) != 3 {
		t.Fatalf("%v %v", len(shots), err)
	}
	for i, want := range []string{"--window-size=390,844", "--window-size=820,1180", "--window-size=1440,900"} {
		if !strings.Contains(runs[i], want) || !strings.Contains(runs[i], "--read-only") || !strings.Contains(runs[i], "--cap-drop ALL") ||
			!strings.Contains(runs[i], "--user 10001:10001") || !strings.HasSuffix(runs[i], "https://shop.codeinchrome.com/cart") {
			t.Errorf("run %d: %s", i, runs[i])
		}
	}
	if shots[0].Name != "phone" || shots[2].Width != 1440 || string(shots[1].PNG) != png {
		t.Fatalf("shots %+v", shots[0].ScreenSize)
	}
	if _, err := os.Stat(outDir); !os.IsNotExist(err) {
		t.Fatal("the pictures' directory was left behind")
	}

	// Anything that is not a picture is refused, not passed on.
	renderDocker = func(ctx context.Context, args ...string) *exec.Cmd {
		if args[0] != "run" {
			return exec.CommandContext(ctx, "true")
		}
		for i, a := range args {
			if a == "-v" {
				return exec.CommandContext(ctx, "sh", "-c", `echo '<script>' > "$1/phone.png"`, "sh", strings.SplitN(args[i+1], ":", 2)[0])
			}
		}
		return exec.CommandContext(ctx, "true")
	}
	if _, err := m.RenderShots(context.Background(), "https://shop.codeinchrome.com/"); err == nil {
		t.Fatal("a non-PNG came back as a shot")
	}
	if _, err := m.RenderShots(context.Background(), "https://evil.example/"); err == nil {
		t.Fatal("another site was shot")
	}
}
