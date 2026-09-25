package sites

import (
	"context"
	"errors"
	"fmt"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Running PHP in the site's own application - what `php artisan tinker
// --execute` does, without tinker's interactive shell (which wants a writable
// home the container does not have).
//
// This gives the owner nothing they did not have: the site's own code already
// runs arbitrary PHP in this container, as this user, with this database. It
// runs as www-data in the site's container - never on the host, never in
// another site - bounded in time and output, one at a time per site like
// every other command. The code arrives on stdin, so nothing of it passes
// through a shell.

const (
	evalTimeout   = 60 * time.Second
	maxEvalOutput = 256 << 10
	maxEvalCode   = 256 << 10
)

// evalPrelude boots the site's Laravel application, then runs the caller's
// code - which follows it on stdin - as the body of a closure, so a `return`
// in it is printed as the result.
const evalPrelude = `<?php
chdir('/var/www/html');
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$__cic = function () {
`

const evalEpilogue = `
};
$__r = $__cic();
if ($__r !== null) {
    echo is_scalar($__r) ? $__r : json_encode($__r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
`

// evalCommand runs php in a site's container reading the script on stdin; a
// variable so tests can stand in for Docker.
var evalCommand = func(ctx context.Context, container string) *exec.Cmd {
	// timeout INSIDE the container: killing the docker CLI on the host leaves
	// the process in the container running (found by the audit); timeout
	// kills php and its process group itself.
	return exec.CommandContext(ctx, "docker", "exec", "-i", "-u", "33:33", "-e", "HOME=/tmp", container,
		"timeout", "--kill-after=5", strconv.Itoa(int(evalTimeout/time.Second)),
		"php", "-d", "display_errors=stderr", "-d", "log_errors=0", "-d", "memory_limit=256M")
}

var guardRe = regexp.MustCompile(`^[a-z][a-z0-9_]{0,30}$`)

// EvalResult is what the code printed, and how PHP exited.
type EvalResult struct {
	Output    string `json:"output"`
	ExitCode  int    `json:"exitCode"`
	Truncated bool   `json:"truncated"`
	TimedOut  bool   `json:"timedOut"`
	ElapsedMs int64  `json:"elapsedMs"`
}

// Eval runs PHP code inside the site's booted application.
func (m *Manager) Eval(ctx context.Context, id, code string) (EvalResult, error) {
	if err := ValidID(id); err != nil {
		return EvalResult{}, err
	}
	site, err := m.load(id)
	if err != nil {
		return EvalResult{}, fmt.Errorf("no such site %q", id)
	}
	if site.Suspended {
		return EvalResult{}, fmt.Errorf("the site is paused")
	}
	code = strings.TrimSpace(code)
	code = strings.TrimPrefix(code, "<?php")
	if code == "" {
		return EvalResult{}, fmt.Errorf("no code given")
	}
	if len(code) > maxEvalCode {
		return EvalResult{}, fmt.Errorf("code is %d bytes; the limit is %d", len(code), maxEvalCode)
	}

	lock, _ := commandLocks.LoadOrStore(id, &sync.Mutex{})
	if !lock.(*sync.Mutex).TryLock() {
		return EvalResult{}, ErrBusy
	}
	defer lock.(*sync.Mutex).Unlock()

	ctx, cancel := context.WithTimeout(ctx, evalTimeout)
	defer cancel()
	cmd := evalCommand(ctx, m.container(id))
	cmd.Stdin = strings.NewReader(evalPrelude + code + ";\n" + evalEpilogue)
	out := &cappedBuffer{limit: maxEvalOutput}
	cmd.Stdout, cmd.Stderr = out, out
	started := time.Now()
	runErr := cmd.Run()
	res := EvalResult{Output: out.buf.String(), Truncated: out.truncated, ElapsedMs: time.Since(started).Milliseconds()}
	if ctx.Err() == context.DeadlineExceeded {
		res.TimedOut, res.ExitCode = true, -1
		return res, nil
	}
	// Code may change files (it is the app's own code): record whatever it did.
	m.record(context.WithoutCancel(ctx), id, "run code")
	var exitErr *exec.ExitError
	switch {
	case runErr == nil:
	case errors.As(runErr, &exitErr):
		res.ExitCode = exitErr.ExitCode()
	default:
		return res, fmt.Errorf("could not run the code: %w", runErr)
	}
	return res, nil
}

// LoginCookie signs a user of the site's OWN application in, the way its
// login would, and returns the session cookie for it - so an agent can test
// the pages behind the site's login without anyone's password. The session is
// written by the app's own session driver; the cookie is encrypted with the
// app's own key, exactly as Laravel's EncryptCookies does.
func (m *Manager) LoginCookie(ctx context.Context, id string, userID int64, guard string) (name, value string, err error) {
	if userID <= 0 {
		return "", "", fmt.Errorf("a user id is needed")
	}
	if guard == "" {
		guard = "web"
	}
	if !guardRe.MatchString(guard) {
		return "", "", fmt.Errorf("invalid guard %q", guard)
	}
	code := `$user = Illuminate\Support\Facades\Auth::guard(` + strconv.Quote(guard) + `)->getProvider()->retrieveById(` + strconv.FormatInt(userID, 10) + `);
if (! $user) { fwrite(STDERR, "no user with id ` + strconv.FormatInt(userID, 10) + `"); exit(3); }
$session = app('session')->driver();
$session->start();
$session->put('login_` + guard + `_'.sha1(Illuminate\Auth\SessionGuard::class), $user->getAuthIdentifier());
$session->put('_token', Illuminate\Support\Str::random(40));
$session->save();
$name = config('session.cookie');
$plain = Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId();
return '__CIC_COOKIE__'.$name."\n".app('encrypter')->encrypt($plain, false);`
	res, err := m.Eval(ctx, id, code)
	if err != nil {
		return "", "", err
	}
	i := strings.LastIndex(res.Output, "__CIC_COOKIE__")
	if res.ExitCode != 0 || i < 0 {
		reason := strings.TrimSpace(res.Output)
		if len(reason) > 300 {
			reason = reason[:300]
		}
		return "", "", fmt.Errorf("could not sign in as user %d: %s", userID, reason)
	}
	parts := strings.SplitN(strings.TrimSpace(res.Output[i+len("__CIC_COOKIE__"):]), "\n", 2)
	if len(parts) != 2 {
		return "", "", fmt.Errorf("could not sign in: unexpected answer")
	}
	return parts[0], parts[1], nil
}
