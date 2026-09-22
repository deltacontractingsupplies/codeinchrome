package sites

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"time"
)

// Moving running sites onto a rebuilt base image.
//
// Rebuilding codeinchrome/laravel pulls the latest php:8.3-apache, which is
// how PHP and Apache security fixes arrive - but a running container keeps the
// image it was started from until it is recreated. Recreate does that while
// keeping everything that makes the site the site: its disk (the app and its
// .env), its database, its fixed port (so the vhost need not change), its
// network, its limits and its domains. Only the container is replaced.

type ImageStatus struct {
	ID      string `json:"id"`
	Current bool   `json:"current"`
	Image   string `json:"image"` // the image id the container runs
}

func (m *Manager) currentImageID(ctx context.Context) (string, error) {
	out, err := run(ctx, 15*time.Second, "docker", "image", "inspect", laravelImage, "--format", "{{.Id}}")
	if err != nil {
		return "", fmt.Errorf("base image %s is not present: %w", laravelImage, err)
	}
	return strings.TrimSpace(out), nil
}

// ImageStatuses reports, per site, whether its container runs the current image.
func (m *Manager) ImageStatuses(ctx context.Context) ([]ImageStatus, error) {
	current, err := m.currentImageID(ctx)
	if err != nil {
		return nil, err
	}
	list, err := m.List(ctx)
	if err != nil {
		return nil, err
	}
	out := make([]ImageStatus, 0, len(list))
	for _, s := range list {
		img, err := run(ctx, 15*time.Second, "docker", "container", "inspect", m.container(s.ID), "--format", "{{.Image}}")
		img = strings.TrimSpace(img)
		out = append(out, ImageStatus{ID: s.ID, Image: img, Current: err == nil && img == current})
	}
	return out, nil
}

// Recreate replaces a site's container with one on the current image and
// confirms the new one answers HTTP before reporting success. Returns
// "current" without touching anything if it is already up to date.
func (m *Manager) Recreate(ctx context.Context, id string) (string, error) {
	if err := ValidID(id); err != nil {
		return "", err
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	site, err := m.load(id)
	if err != nil {
		return "", fmt.Errorf("no such site %q", id)
	}
	current, err := m.currentImageID(ctx)
	if err != nil {
		return "", err
	}
	running, _ := run(ctx, 15*time.Second, "docker", "container", "inspect", m.container(id), "--format", "{{.Image}}")
	if strings.TrimSpace(running) == current {
		return "current", nil
	}
	// The container is replaced; its disk must be there for the new one.
	if !isMounted(m.volume(id)) {
		return "", fmt.Errorf("the disk for %s is not mounted; refusing to start a container on an empty directory", id)
	}

	if _, err := run(ctx, 60*time.Second, "docker", "rm", "-f", m.container(id)); err != nil {
		return "", fmt.Errorf("remove old container: %w", err)
	}
	if err := m.startContainer(ctx, site); err != nil {
		return "", fmt.Errorf("start new container (the site is DOWN until this is fixed): %w", err)
	}
	if err := waitForHTTP(ctx, site.Port, 60*time.Second); err != nil {
		return "", fmt.Errorf("the new container did not answer (the site may be DOWN): %w", err)
	}
	return "recreated", nil
}

// waitForHTTP returns once the site's port answers any HTTP response below
// 500 - its own 404 counts; Apache or PHP failing to start does not.
func waitForHTTP(ctx context.Context, port int, limit time.Duration) error {
	client := &http.Client{Timeout: 5 * time.Second}
	deadline := time.Now().Add(limit)
	var last error
	for time.Now().Before(deadline) {
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, fmt.Sprintf("http://127.0.0.1:%d/", port), nil)
		resp, err := client.Do(req)
		if err == nil {
			resp.Body.Close()
			if resp.StatusCode < 500 {
				return nil
			}
			last = fmt.Errorf("HTTP %d", resp.StatusCode)
		} else {
			last = err
		}
		time.Sleep(time.Second)
	}
	return fmt.Errorf("no healthy answer within %s (last: %v)", limit, last)
}
