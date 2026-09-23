package sites

import (
	"strings"
	"testing"
)

func TestPHPSettingsAreBoundedByThePlan(t *testing.T) {
	for _, c := range []struct {
		p   PHPSettings
		mem string
		ok  bool
	}{
		{PHPSettings{}, "256m", true},
		{PHPSettings{MemoryMB: 192}, "256m", true},
		{PHPSettings{MemoryMB: 193}, "256m", false}, // leaves no room for Apache
		{PHPSettings{MemoryMB: 1024}, "2048m", true},
		{PHPSettings{MemoryMB: 1100}, "2048m", false}, // capped at 1 GB
		{PHPSettings{MemoryMB: 32}, "1024m", false},
		{PHPSettings{MaxExecutionSecs: 300}, "512m", true},
		{PHPSettings{MaxExecutionSecs: 301}, "512m", false},
		{PHPSettings{MaxExecutionSecs: 5}, "512m", false},
		{PHPSettings{UploadMB: 96}, "512m", true},
		{PHPSettings{UploadMB: 97}, "512m", false},
	} {
		if err := c.p.validate(c.mem); (err == nil) != c.ok {
			t.Errorf("%+v on %s: err=%v, want ok=%v", c.p, c.mem, err, c.ok)
		}
	}
}

func TestThePHPIniHoldsOnlyWhatWasSet(t *testing.T) {
	ini := PHPSettings{MemoryMB: 200, UploadMB: 48}.ini()
	for _, want := range []string{"memory_limit=200M", "upload_max_filesize=48M", "post_max_size=49M"} {
		if !strings.Contains(ini, want) {
			t.Errorf("ini lacks %q:\n%s", want, ini)
		}
	}
	if strings.Contains(ini, "max_execution_time") || strings.Contains(ini, "display_errors") {
		t.Errorf("ini has a setting that was not chosen:\n%s", ini)
	}
}

func TestPHPSettingsAreMountedReadOnlyOnlyWhenSet(t *testing.T) {
	m := &Manager{cfg: Config{HostID: "h9", Root: "/srv/customers"}}
	base := Site{ID: "shop", Container: "cic-shop", CPULimit: "1", MemLimit: "512m", Port: 20000}
	if strings.Contains(strings.Join(m.runArgs(base), " "), "zz-site.ini") {
		t.Fatal("a site with no PHP settings mounts an ini")
	}
	base.PHP = PHPSettings{MemoryMB: 200}
	args := strings.Join(m.runArgs(base), " ")
	if !strings.Contains(args, "/srv/customers/shop/php.ini:/usr/local/etc/php/conf.d/zz-site.ini:ro") {
		t.Fatalf("not mounted read-only: %s", args)
	}
}
