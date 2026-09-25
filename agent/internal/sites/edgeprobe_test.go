package sites

import (
	"reflect"
	"sort"
	"testing"
)

func TestOnlyASiteThatWasFineAndNowFailsCountsAsBrokenByAReload(t *testing.T) {
	before := map[string]int{"ok.example": 200, "redirects.example": 302, "was-down.example": 502, "slow.example": 200, "new.example": 0, "broken.example": 200, "missing.example": 404}
	after := map[string]int{"ok.example": 200, "redirects.example": 502, "was-down.example": 502, "slow.example": 0, "new.example": 500, "broken.example": 503, "missing.example": 404}
	got := brokenByReload(before, after)
	sort.Strings(got)
	if want := []string{"broken.example", "redirects.example"}; !reflect.DeepEqual(got, want) {
		t.Fatalf("broken = %v, want %v", got, want)
	}
}
