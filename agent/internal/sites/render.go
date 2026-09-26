package sites

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"io"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
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

// Shot is a page as one screen size shows it.
type Shot struct {
	ScreenSize
	PNG []byte `json:"png"` // base64 in JSON
}

const maxShotPNG = 8 << 20

// RenderShots screenshots a hosted page at every screen size, in the same
// locked-down container as RenderURL - one browser run per size, one at a
// time. The container writes only the picture, into a directory of its own
// that is removed afterwards.
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
	targets := make([]string, len(ScreenSizes))
	for i := range ScreenSizes {
		raw := raws[0]
		if len(raws) > 1 {
			raw = raws[i]
		}
		t, err := m.renderable(raw)
		if err != nil {
			return nil, err
		}
		targets[i] = t
	}
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
	out, err := os.MkdirTemp("", "cic-shot-")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(out)
	if err := os.Chown(out, 10001, 10001); err != nil && !os.IsPermission(err) {
		return nil, err
	}
	var shots []Shot
	for i, size := range ScreenSizes {
		target := targets[i]
		b := make([]byte, 6)
		_, _ = rand.Read(b)
		name := "cic-render-" + hex.EncodeToString(b)
		runCtx, cancel := context.WithTimeout(ctx, 45*time.Second)
		cmd := renderDocker(runCtx, "run", "--rm", "--name", name, "--network", renderNetwork,
			"--memory", "768m", "--memory-swap", "768m", "--cpus", "1", "--pids-limit", "256",
			"--read-only", "--tmpfs", "/tmp:size=256m,mode=1777", "--cap-drop", "ALL",
			"--security-opt", "no-new-privileges", "--user", "10001:10001",
			"-v", out+":/out",
			"-e", "HOME=/tmp", "-e", "XDG_CONFIG_HOME=/tmp", "-e", "XDG_CACHE_HOME=/tmp",
			renderImage,
			"--disable-crash-reporter", "--crash-dumps-dir=/tmp/crash", "--user-agent="+renderUA,
			fmt.Sprintf("--window-size=%d,%d", size.Width, size.Height),
			"--virtual-time-budget=8000", "--screenshot=/out/"+size.Name+".png", target)
		runErr := cmd.Run()
		_ = renderDocker(context.Background(), "rm", "-f", name).Run()
		cancel()
		png, err := readShot(filepath.Join(out, size.Name+".png"))
		if err != nil {
			return nil, fmt.Errorf("the page could not be shown at %s size: %v", size.Name, firstNonNil(err, runErr))
		}
		shots = append(shots, Shot{ScreenSize: size, PNG: png})
	}
	return shots, nil
}

func readShot(path string) ([]byte, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	b, err := io.ReadAll(io.LimitReader(f, maxShotPNG+1))
	if err != nil {
		return nil, err
	}
	if len(b) > maxShotPNG || len(b) < 8 || string(b[1:4]) != "PNG" {
		return nil, fmt.Errorf("no picture came back")
	}
	return b, nil
}

func firstNonNil(errs ...error) error {
	for _, e := range errs {
		if e != nil {
			return e
		}
	}
	return nil
}
