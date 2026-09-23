package sites

import (
	"fmt"
	"os"
	"strings"
	"testing"
)

func TestBackgroundProcessesComeOutOfTheWebBudget(t *testing.T) {
	// 512 MB with a queue worker and the scheduler: (512-64-96)/24 = 14.
	if got := WorkersFor("512m", 2); got != 14 {
		t.Errorf("WorkersFor(512m, 2) = %d, want 14", got)
	}
}

func TestWorkersFitThePlansMemory(t *testing.T) {
	for limit, want := range map[string]int{
		"256m": 8, "512m": 18, "1024m": 40, "2048m": 82, "2g": 82,
		"64m": minWorkers, "64g": maxWorkers, "": 40, // unknown: sized as 1 GB
	} {
		if got := WorkersFor(limit, 0); got != want {
			t.Errorf("WorkersFor(%q) = %d, want %d", limit, got, want)
		}
	}
}

func TestTheDatabaseAlwaysAllowsMoreConnectionsThanTheWorkers(t *testing.T) {
	for _, limit := range []string{"256m", "512m", "1024m", "2048m"} {
		for bg := 0; bg <= 3; bg++ {
			if ConnectionsFor(limit, bg) <= WorkersFor(limit, bg)+bg {
				t.Errorf("%s with %d background: %d connections for %d workers - a burst would fail on max_user_connections",
					limit, bg, ConnectionsFor(limit, bg), WorkersFor(limit, bg))
			}
		}
	}
}

// The container's start script computes the same numbers in shell. If the two
// drift, Apache and MySQL disagree about how many connections a site needs.
func TestTheImageUsesTheSameFormula(t *testing.T) {
	b, err := os.ReadFile("../../../infra/images/laravel-8.3/cic-start")
	if err != nil {
		t.Fatal(err)
	}
	s := string(b)
	for _, want := range []string{
		fmt.Sprintf("workers=$(( (mb - %d - bg) / %d ))", sizingBaseMB, sizingPerWorkerMB),
		fmt.Sprintf("bg=$((bg + %d))", backgroundMB),
		fmt.Sprintf(`[ "$workers" -lt %d ] && workers=%d`, minWorkers, minWorkers),
		fmt.Sprintf(`[ "$workers" -gt %d ] && workers=%d`, maxWorkers, maxWorkers),
		"then mb=1024",
	} {
		if !strings.Contains(s, want) {
			t.Errorf("cic-start does not contain %q - it must match sizing.go", want)
		}
	}
}
