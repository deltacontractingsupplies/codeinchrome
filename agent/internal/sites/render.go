package sites

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/url"
	"os/exec"
	"strings"
	"time"
)

// Render returns a hosted page as a browser has it once its scripts have
// run (audit A15): the link scanner reads raw HTML, and a kit that builds
// its form, its iframe or its "press Win+R" in JavaScript was invisible.
//
// Chromium reads hostile pages, so it runs in a throwaway container with
// every privilege taken away - non-root, no capabilities, a read-only root,
// bounded memory, CPU and processes, and the same egress chain as a site
// (its network is a br-* bridge). Chromium's own sandbox cannot work inside
// that; the container is the boundary, at the trust level of a customer's
// PHP. Only the platform's own site names can be rendered - never another
// URL - so the renderer is no way to reach anything else. The control
// plane asks a DIFFERENT host than the site's, so a kit cannot hide from
// the one address it can learn (its own host's), and it sends a browser's
// own User-Agent: "HeadlessChrome" is the first thing a kit looks for.

const (
	renderImage   = "codeinchrome/render:1"
	renderNetwork = "cic-render"
	maxRenderDOM  = 2 << 20
	renderUA      = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36"
)

// renderSlots: two renders at a time per host; each is a browser.
var renderSlots = make(chan struct{}, 2)

// docker, a variable so a test needs no Docker.
var renderDocker = func(ctx context.Context, args ...string) *exec.Cmd {
	return exec.CommandContext(ctx, "docker", args...)
}

// RenderResult is the page's DOM after its scripts ran.
type RenderResult struct {
	DOM       string `json:"dom"`
	Truncated bool   `json:"truncated"`
	Millis    int64  `json:"ms"`
}

// renderable: https, no credentials, the default port, a platform site name.
func (m *Manager) renderable(raw string) (string, error) {
	u, err := url.Parse(raw)
	if err != nil || u.Scheme != "https" || u.User != nil || (u.Port() != "" && u.Port() != "443") || u.Opaque != "" {
		return "", fmt.Errorf("only https://<site>.%s/... pages can be rendered", m.cfg.PlatformDomain)
	}
	if !onPlatform(m.cfg, strings.ToLower(u.Hostname())) {
		return "", fmt.Errorf("only this platform's own site names can be rendered")
	}
	u.Fragment = ""
	return u.String(), nil
}

// RenderURL renders one page of a hosted site.
func (m *Manager) RenderURL(ctx context.Context, raw string) (RenderResult, error) {
	target, err := m.renderable(raw)
	if err != nil {
		return RenderResult{}, err
	}
	select {
	case renderSlots <- struct{}{}:
		defer func() { <-renderSlots }()
	case <-time.After(20 * time.Second):
		return RenderResult{}, fmt.Errorf("the renderer is busy; try again shortly")
	case <-ctx.Done():
		return RenderResult{}, ctx.Err()
	}
	// Its own network: a br-* bridge, so the container egress chain applies.
	if renderDocker(ctx, "network", "inspect", renderNetwork).Run() != nil {
		_ = renderDocker(ctx, "network", "create", renderNetwork).Run()
	}
	b := make([]byte, 6)
	_, _ = rand.Read(b)
	name := "cic-render-" + hex.EncodeToString(b)
	runCtx, cancel := context.WithTimeout(ctx, 45*time.Second)
	defer cancel()
	// A container outlives the docker CLI that started it: removed however
	// this ends.
	defer func() { _ = renderDocker(context.Background(), "rm", "-f", name).Run() }()
	start := time.Now()
	cmd := renderDocker(runCtx, "run", "--rm", "--name", name, "--network", renderNetwork,
		"--memory", "768m", "--memory-swap", "768m", "--cpus", "1", "--pids-limit", "256",
		"--read-only", "--tmpfs", "/tmp:size=256m,mode=1777", "--cap-drop", "ALL",
		"--security-opt", "no-new-privileges", "--user", "10001:10001",
		"-e", "HOME=/tmp", "-e", "XDG_CONFIG_HOME=/tmp", "-e", "XDG_CACHE_HOME=/tmp",
		renderImage,
		"--disable-crash-reporter", "--crash-dumps-dir=/tmp/crash", "--user-agent="+renderUA,
		"--virtual-time-budget=8000", "--dump-dom", target)
	out := &cappedBuffer{limit: maxRenderDOM}
	cmd.Stdout = out
	if err := cmd.Run(); err != nil && out.buf.Len() == 0 {
		return RenderResult{}, fmt.Errorf("the page could not be rendered: %v", err)
	}
	return RenderResult{DOM: out.buf.String(), Truncated: out.truncated, Millis: time.Since(start).Milliseconds()}, nil
}

// Screen sizes a page is checked at (owner, 2026-09-26: every page must work
// on every device): a phone, a tablet and a laptop, portrait.
var ScreenSizes = []ScreenSize{{"phone", 390, 844}, {"tablet", 820, 1180}, {"desktop", 1440, 900}}

// ScreenSize is one device's viewport.
type ScreenSize struct {
	Name   string `json:"name"`
	Width  int    `json:"width"`
	Height int    `json:"height"`
}

// Shot is a page as one screen size shows it, and how wide its content is:
// wider than the screen means it scrolls sideways there (Overflow).
type Shot struct {
	ScreenSize
	PNG          []byte `json:"png"` // base64 in JSON
	ContentWidth int    `json:"contentWidth"`
	Overflow     bool   `json:"overflow"`
}

const (
	maxShotPNG    = 8 << 20
	maxShotsOut   = 48 << 20 // three screenshots, base64
	shotsDriver   = "/opt/cic/shots.py"
	shotsDeadline = 90 * time.Second
)

// RenderShots screenshots a hosted page at every screen size, in the same
// locked-down container as RenderURL, as those DEVICES show it: Chromium's
// own window cannot be narrower than 500 px, so the image's driver (shots.py)
// uses its DevTools device emulation - a phone is a real 390 px layout - and
// measures the width the content takes. One browser run for every size.
func (m *Manager) RenderShots(ctx context.Context, raw string) ([]Shot, error) {
	return m.RenderShotsEach(ctx, []string{raw})
}

// RenderShotsEach is RenderShots with an address per screen size - one
// sign-in link each, for a page behind the app's login: a link is used once.
// One address is used for every size.
func (m *Manager) RenderShotsEach(ctx context.Context, raws []string) ([]Shot, error) {
	if len(raws) != 1 && len(raws) != len(ScreenSizes) {
		return nil, fmt.Errorf("one address, or one for each of the %d screen sizes", len(ScreenSizes))
	}
	type job struct {
		ScreenSize
		URL string `json:"url"`
	}
	jobs := make([]job, len(ScreenSizes))
	for i, size := range ScreenSizes {
		raw := raws[0]
		if len(raws) > 1 {
			raw = raws[i]
		}
		t, err := m.renderable(raw)
		if err != nil {
			return nil, err
		}
		jobs[i] = job{size, t}
	}
	spec, _ := json.Marshal(jobs)
	select {
	case renderSlots <- struct{}{}:
		defer func() { <-renderSlots }()
	case <-time.After(20 * time.Second):
		return nil, fmt.Errorf("the renderer is busy; try again shortly")
	case <-ctx.Done():
		return nil, ctx.Err()
	}
	if renderDocker(ctx, "network", "inspect", renderNetwork).Run() != nil {
		_ = renderDocker(ctx, "network", "create", renderNetwork).Run()
	}
	b := make([]byte, 6)
	_, _ = rand.Read(b)
	name := "cic-render-" + hex.EncodeToString(b)
	runCtx, cancel := context.WithTimeout(ctx, shotsDeadline)
	defer cancel()
	defer func() { _ = renderDocker(context.Background(), "rm", "-f", name).Run() }()
	cmd := renderDocker(runCtx, "run", "--rm", "--name", name, "--network", renderNetwork,
		"--memory", "768m", "--memory-swap", "768m", "--cpus", "1", "--pids-limit", "256",
		"--read-only", "--tmpfs", "/tmp:size=256m,mode=1777", "--cap-drop", "ALL",
		"--security-opt", "no-new-privileges", "--user", "10001:10001",
		"-e", "HOME=/tmp", "-e", "XDG_CONFIG_HOME=/tmp", "-e", "XDG_CACHE_HOME=/tmp",
		"--entrypoint", "python3", renderImage, shotsDriver, string(spec))
	out := &cappedBuffer{limit: maxShotsOut}
	cmd.Stdout = out
	runErr := cmd.Run()
	var shots []Shot
	for _, line := range strings.Split(strings.TrimSpace(out.buf.String()), "\n") {
		var got struct {
			Name         string `json:"name"`
			PNG          []byte `json:"png"`
			ContentWidth int    `json:"contentWidth"`
		}
		if line == "" || json.Unmarshal([]byte(line), &got) != nil {
			continue
		}
		size, ok := sizeNamed(got.Name)
		if !ok || len(got.PNG) < 8 || len(got.PNG) > maxShotPNG || string(got.PNG[1:4]) != "PNG" {
			continue // only a picture of one of OUR sizes is passed on
		}
		shots = append(shots, Shot{ScreenSize: size, PNG: got.PNG, ContentWidth: got.ContentWidth,
			Overflow: got.ContentWidth > size.Width+1})
	}
	if len(shots) != len(ScreenSizes) {
		return nil, fmt.Errorf("the page could not be shown at every size (%d of %d): %v", len(shots), len(ScreenSizes), firstNonNil(runErr, fmt.Errorf("no picture came back")))
	}
	return shots, nil
}

func sizeNamed(name string) (ScreenSize, bool) {
	for _, s := range ScreenSizes {
		if s.Name == name {
			return s, true
		}
	}
	return ScreenSize{}, false
}

func firstNonNil(errs ...error) error {
	for _, e := range errs {
		if e != nil {
			return e
		}
	}
	return nil
}
