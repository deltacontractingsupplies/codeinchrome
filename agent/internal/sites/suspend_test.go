package sites

import (
	"context"
	"strings"
	"testing"
)

// Nothing but a resume may start a suspended site: not the nightly image
// roll, not a settings change that replaces the container.
func TestASuspendedSiteIsNeverRestartedBehindTheResume(t *testing.T) {
	m, id := historyManager(t)
	if err := m.save(Site{ID: id, Domain: id + ".codeinchrome.com", Port: 20001, Suspended: true}); err != nil {
		t.Fatal(err)
	}
	out, err := m.Recreate(context.Background(), id)
	if err != nil || out != "suspended" {
		t.Fatalf("recreate of a suspended site: %q, %v; want \"suspended\" and no container touched", out, err)
	}
	site, _ := m.load(id)
	if err := m.replaceContainer(context.Background(), site); err == nil || !strings.Contains(err.Error(), "suspended") {
		t.Fatalf("replaceContainer started a suspended site: %v", err)
	}
	if _, err := m.SetSuspended(context.Background(), "../etc", true); err == nil {
		t.Fatal("a bad id was accepted")
	}
}
