package sites

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
)

// Per-site PHP settings, as a hosting panel offers them: memory limit,
// maximum execution time and upload size. Each is bounded - memory by the
// container's own limit, less the room Apache and PHP need to run at all -
// and written to <site dir>/php.ini, which the container mounts READ-ONLY
// over the image's settings. The site's code can read it and cannot change
// it. display_errors is deliberately not a setting: it would show visitors
// stack traces.

type PHPSettings struct {
	MemoryMB         int `json:"memoryMB,omitempty"`
	MaxExecutionSecs int `json:"maxExecutionSeconds,omitempty"`
	UploadMB         int `json:"uploadMB,omitempty"`
}

const (
	phpMinMemoryMB  = 64
	phpMaxExecution = 300
	// The largest upload a visitor's browser can get through to the site:
	// Cloudflare, in front, refuses request bodies over 100 MB.
	phpMaxUploadMB = 96
)

func (m *Manager) phpIni(id string) string { return filepath.Join(m.dir(id), "php.ini") }

// validate checks the settings against the site's memory limit.
func (p PHPSettings) validate(memLimit string) error {
	maxMem := memoryMB(memLimit) - 64
	if maxMem > 1024 {
		maxMem = 1024
	}
	switch {
	case p.MemoryMB != 0 && (p.MemoryMB < phpMinMemoryMB || p.MemoryMB > maxMem):
		return fmt.Errorf("memory limit must be %d to %d MB on this plan", phpMinMemoryMB, maxMem)
	case p.MaxExecutionSecs != 0 && (p.MaxExecutionSecs < 10 || p.MaxExecutionSecs > phpMaxExecution):
		return fmt.Errorf("maximum execution time must be 10 to %d seconds", phpMaxExecution)
	case p.UploadMB != 0 && (p.UploadMB < 1 || p.UploadMB > phpMaxUploadMB):
		return fmt.Errorf("upload size must be 1 to %d MB", phpMaxUploadMB)
	}
	return nil
}

func (p PHPSettings) ini() string {
	out := "; Written by codeinchrome from the site's settings. Read-only in the container.\n"
	if p.MemoryMB != 0 {
		out += fmt.Sprintf("memory_limit=%dM\n", p.MemoryMB)
	}
	if p.MaxExecutionSecs != 0 {
		out += fmt.Sprintf("max_execution_time=%d\n", p.MaxExecutionSecs)
	}
	if p.UploadMB != 0 {
		// A POST carries the file plus the form around it.
		out += fmt.Sprintf("upload_max_filesize=%dM\npost_max_size=%dM\n", p.UploadMB, p.UploadMB+1)
	}
	return out
}

// SetPHP applies a site's PHP settings: validates, writes the ini, and
// replaces the container so PHP reads it.
func (m *Manager) SetPHP(ctx context.Context, id string, p PHPSettings) (map[string]string, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	site, err := m.load(id)
	if err != nil {
		return nil, fmt.Errorf("no such site %q", id)
	}
	if err := p.validate(site.MemLimit); err != nil {
		return nil, err
	}
	if site.PHP == p {
		return map[string]string{"changed": "no"}, nil
	}
	if err := writeFileAtomic(m.phpIni(id), []byte(p.ini()), 0o644); err != nil {
		return nil, fmt.Errorf("write the PHP settings: %w", err)
	}
	site.PHP = p
	if err := m.save(site); err != nil {
		return nil, err
	}
	if err := m.replaceContainer(ctx, site); err != nil {
		return map[string]string{"changed": "yes"}, err
	}
	return map[string]string{"changed": "yes"}, nil
}

// writeFileAtomic writes via a temporary file and a rename, in a directory
// only root can write (the site's host directory, not its app volume).
func writeFileAtomic(path string, b []byte, perm os.FileMode) error {
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, b, perm); err != nil {
		return err
	}
	return os.Rename(tmp, path)
}
