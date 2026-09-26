package sites

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"os/exec"
	"strings"
)

// A new free site's first week (audit A21: "no restricted tier for new
// accounts"). The site's own bridge goes through CIC-RESTRICT before the
// ordinary container egress chain (infra/bootstrap.sh defines both): only
// TCP 80 and 443 out, no UDP at all, and about 8 Mbit/s. Names still
// resolve (Docker's resolver asks from the host's side). Lifted by the
// control plane when it lifts the site's first-week noindex.

// iptablesRun, a variable so a test needs no firewall.
var iptablesRun = func(ctx context.Context, args ...string) error {
	return exec.CommandContext(ctx, "iptables", args...).Run()
}

// bridgeOf names the Linux bridge of the site's own network: br- and the
// first 12 characters of the Docker network's id.
var bridgeOf = func(ctx context.Context, network string) (string, error) {
	out, err := exec.CommandContext(ctx, "docker", "network", "inspect", "-f", "{{.Id}}", network).Output()
	id := strings.TrimSpace(string(out))
	if err != nil || len(id) < 12 {
		return "", fmt.Errorf("no network %s", network)
	}
	return "br-" + id[:12], nil
}

// SetRestrictedEgress records the choice and applies it.
func (m *Manager) SetRestrictedEgress(ctx context.Context, id string, on bool) error {
	if err := ValidID(id); err != nil {
		return err
	}
	m.mu.Lock()
	site, err := m.load(id)
	if err != nil {
		m.mu.Unlock()
		return fmt.Errorf("no such site %q", id)
	}
	site.RestrictedEgress = on
	err = m.save(site)
	m.mu.Unlock()
	if err != nil {
		return err
	}
	return m.applyRestrictedEgress(ctx, site)
}

// applyRestrictedEgress makes the firewall match the record: idempotent,
// run again for every site when the agent starts (a reboot clears it).
func (m *Manager) applyRestrictedEgress(ctx context.Context, s Site) error {
	br, err := bridgeOf(ctx, m.network(s.ID))
	if err != nil {
		return err
	}
	rule := []string{"DOCKER-USER", "-i", br, "-j", "CIC-RESTRICT"}
	present := iptablesRun(ctx, append([]string{"-C"}, rule...)...) == nil
	switch {
	case s.RestrictedEgress && !present:
		// First in DOCKER-USER: before the ordinary chain, which still
		// applies after (CIC-RESTRICT returns what it allows).
		return iptablesRun(ctx, append([]string{"-I", rule[0], "1"}, rule[1:]...)...)
	case !s.RestrictedEgress && present:
		return iptablesRun(ctx, append([]string{"-D"}, rule...)...)
	}
	return nil
}

// ApplyAllRestrictedEgress re-applies every site's record, at start.
func (m *Manager) ApplyAllRestrictedEgress(ctx context.Context) {
	// From the records themselves, not Docker's list: the records say what
	// should be, and they are there even if Docker is slow to answer.
	entries, err := os.ReadDir(m.cfg.Root)
	if err != nil {
		return
	}
	for _, e := range entries {
		if !e.IsDir() || ValidID(e.Name()) != nil {
			continue
		}
		if s, err := m.load(e.Name()); err == nil && s.RestrictedEgress {
			if err := m.applyRestrictedEgress(ctx, s); err != nil {
				slog.Warn("restricted egress not applied", "site", s.ID, "err", err)
			}
		}
	}
}
