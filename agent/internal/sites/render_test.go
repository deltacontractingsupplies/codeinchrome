package sites

import (
	"context"
	"encoding/json"
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

// fakeShots stands in for the renderer: docker run prints what shots.py
// would, for the sizes and addresses it was given; the spec it got is kept.
func fakeShots(t *testing.T, lines func(spec []map[string]any) string) *[]string {
	t.Helper()
	var runs []string
	orig := renderDocker
	t.Cleanup(func() { renderDocker = orig })
	renderDocker = func(ctx context.Context, args ...string) *exec.Cmd {
		if args[0] != "run" {
			return exec.CommandContext(ctx, "true")
		}
		runs = append(runs, strings.Join(args, " "))
		var spec []map[string]any
		json.Unmarshal([]byte(args[len(args)-1]), &spec)
		return exec.CommandContext(ctx, "printf", "%s", lines(spec))
	}
	return &runs
}

func shotLine(name string, content int, png string) string {
	b, _ := json.Marshal(map[string]any{"name": name, "contentWidth": content, "png": []byte(png)})
	return string(b) + "\n"
}

const fakePNG = "\x89PNG\r\n\x1a\n-fake"

// Every screen size, as devices show it, in ONE locked-down browser run, and
// how wide the content is at each: wider than the screen is sideways scroll.
func TestAPageIsShotAtEverySizeInOneLockedDownRunAndMeasured(t *testing.T) {
	m := &Manager{cfg: Config{PlatformDomain: "codeinchrome.com"}}
	runs := fakeShots(t, func(spec []map[string]any) string {
		out := ""
		for _, s := range spec {
			w := int(s["width"].(float64))
			if s["name"] == "phone" {
				w = 608 // a 600 px element on a 390 px phone
			}
			out += shotLine(s["name"].(string), w, fakePNG)
		}
		return out
	})
	shots, err := m.RenderShots(context.Background(), "https://shop.codeinchrome.com/cart")
	if err != nil || len(shots) != 3 {
		t.Fatalf("%d %v", len(shots), err)
	}
	if len(*runs) != 1 {
		t.Fatalf("%d browser runs, want one for every size", len(*runs))
	}
	run := (*runs)[0]
	for _, want := range []string{"--read-only", "--cap-drop ALL", "--user 10001:10001", "--network cic-render", "--entrypoint python3", renderImage + " " + shotsDriver,
		`"width":390`, `"width":820`, `"width":1440`, `"url":"https://shop.codeinchrome.com/cart"`} {
		if !strings.Contains(run, want) {
			t.Errorf("run is missing %q:\n%s", want, run)
		}
	}
	if strings.Contains(run, " -v ") {
		t.Error("the renderer was given a host directory")
	}
	if !shots[0].Overflow || shots[0].ContentWidth != 608 || shots[1].Overflow || shots[2].Overflow || string(shots[1].PNG) != fakePNG {
		t.Fatalf("measurements %+v %+v %+v", shots[0].ContentWidth, shots[1].Overflow, shots[2].Overflow)
	}
}

func TestOnlyPicturesOfOurSizesComeBack(t *testing.T) {
	m := &Manager{cfg: Config{PlatformDomain: "codeinchrome.com"}}
	fakeShots(t, func([]map[string]any) string {
		return shotLine("phone", 390, fakePNG) + shotLine("tablet", 820, "<script>") + shotLine("huge", 9, fakePNG) + "not json\n"
	})
	if _, err := m.RenderShots(context.Background(), "https://shop.codeinchrome.com/"); err == nil {
		t.Fatal("a missing size, a non-PNG and an unknown size were passed on")
	}
	if _, err := m.RenderShots(context.Background(), "https://evil.example/"); err == nil {
		t.Fatal("another site was shot")
	}
}

// Behind the app's login: a sign-in link per size (each is used once).
func TestEachScreenSizeCanHaveItsOwnAddress(t *testing.T) {
	m := &Manager{cfg: Config{PlatformDomain: "codeinchrome.com"}}
	var got []string
	fakeShots(t, func(spec []map[string]any) string {
		out := ""
		for _, s := range spec {
			got = append(got, s["url"].(string))
			out += shotLine(s["name"].(string), int(s["width"].(float64)), fakePNG)
		}
		return out
	})
	links := []string{"https://shop.codeinchrome.com/__codeinchrome/sign-in?n=1", "https://shop.codeinchrome.com/__codeinchrome/sign-in?n=2", "https://shop.codeinchrome.com/__codeinchrome/sign-in?n=3"}
	if _, err := m.RenderShotsEach(context.Background(), links); err != nil {
		t.Fatal(err)
	}
	if strings.Join(got, " ") != strings.Join(links, " ") {
		t.Fatalf("each size did not get its own link: %v", got)
	}
	if _, err := m.RenderShotsEach(context.Background(), links[:2]); err == nil {
		t.Fatal("two addresses for three sizes were accepted")
	}
	if _, err := m.RenderShotsEach(context.Background(), []string{links[0], "https://evil.example/", links[2]}); err == nil {
		t.Fatal("another site was accepted among the addresses")
	}
}
