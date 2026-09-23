package sites

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Commands and logs for the editor.
//
// Commands run inside the site's OWN container, as www-data, with argv passed
// straight to docker exec - no shell anywhere, so there is nothing to inject
// into. They are still an allow-list rather than "any artisan command":
//
//   - `tinker`, `serve`, `down`/`up` and anything that opens a REPL or a
//     listener are not on it. tinker in particular is an arbitrary-code
//     prompt; the customer can run any code they like by writing it into
//     their app, and that path is the one that goes through the editor.
//   - `--env` is refused on every command: it silently switches which .env
//     is read, which is a way to run against a configuration nobody sees.
//
// One command per site at a time. Output is capped and the time is bounded;
// the answer always says whether either limit was hit.

var (
	// Artisan commands, and whether each needs --force to run non-interactively
	// in production (Laravel refuses otherwise and waits for a prompt).
	artisanAllowed = map[string]bool{
		"about": false, "migrate": true, "migrate:status": false, "migrate:rollback": true,
		"migrate:fresh": true, "db:seed": true, "cache:clear": false, "config:clear": false,
		"config:cache": false, "route:clear": false, "route:cache": false, "route:list": false,
		"view:clear": false, "view:cache": false, "event:clear": false, "optimize": false,
		"optimize:clear": false, "storage:link": false, "queue:restart": false,
		"schedule:list": false, "key:generate": true, "package:discover": false,
		"list": false, "help": false,
	}
	composerAllowed = map[string]bool{
		"install": true, "update": true, "require": true, "remove": true,
		"dump-autoload": true, "show": true, "outdated": true, "validate": true,
	}

	// An argument is a word, an option, or a package constraint. Nothing that
	// could be read as a path outside the app or as another command.
	safeArg     = regexp.MustCompile(`^[A-Za-z0-9_@:./=^~*<>,|+-]{1,200}$`)
	makeCommand = regexp.MustCompile(`^make:[a-z-]{2,30}$`)

	commandLocks sync.Map // site id -> *sync.Mutex
)

const (
	maxCommandOutput = 256 << 10
	artisanTimeout   = 3 * time.Minute
	composerTimeout  = 10 * time.Minute
)

// ErrBusy means another command is already running for the site.
var ErrBusy = errors.New("busy")

// ErrNeedsConfirm means the command destroys data and confirm was not set.
var ErrNeedsConfirm = errors.New("needs confirm")

// Commands that destroy data. Allowed - the data is the customer's - but not
// without an explicit confirm, the same rule as a write in the database
// browser: an agent exploring a site must not be one careless call away from
// dropping every table.
var destructive = map[string]bool{
	"migrate:fresh": true, "migrate:rollback": true, "db:seed": true, "key:generate": true,
}

type CommandResult struct {
	Tool      string   `json:"tool"`
	Args      []string `json:"args"`
	ExitCode  int      `json:"exitCode"`
	Output    string   `json:"output"`
	Truncated bool     `json:"truncated"`
	TimedOut  bool     `json:"timedOut"`
	ElapsedMs int64    `json:"elapsedMs"`
}

// cappedBuffer keeps the first N bytes and counts the rest.
type cappedBuffer struct {
	buf       bytes.Buffer
	limit     int
	truncated bool
}

func (c *cappedBuffer) Write(p []byte) (int, error) {
	room := c.limit - c.buf.Len()
	if room <= 0 {
		c.truncated = true
		return len(p), nil
	}
	if len(p) > room {
		c.buf.Write(p[:room])
		c.truncated = true
		return len(p), nil
	}
	return c.buf.Write(p)
}

func validateCommand(tool string, args []string) ([]string, time.Duration, error) {
	if len(args) == 0 {
		return nil, 0, fmt.Errorf("no command given")
	}
	if len(args) > 20 {
		return nil, 0, fmt.Errorf("too many arguments")
	}
	for _, a := range args {
		if !safeArg.MatchString(a) {
			return nil, 0, fmt.Errorf("argument %q is not allowed", a)
		}
		if a == "--env" || strings.HasPrefix(a, "--env=") {
			return nil, 0, fmt.Errorf("--env is not allowed: it switches which configuration is read")
		}
		if strings.Contains(a, "..") {
			return nil, 0, fmt.Errorf("argument %q is not allowed", a)
		}
	}

	switch tool {
	case "artisan":
		name := args[0]
		force, ok := artisanAllowed[name]
		if !ok && !makeCommand.MatchString(name) {
			return nil, 0, fmt.Errorf("artisan %s is not available here", name)
		}
		out := append([]string{"php", "/var/www/html/artisan"}, args...)
		out = append(out, "--no-interaction", "--ansi")
		if force && !contains(args, "--force") {
			out = append(out, "--force")
		}
		return out, artisanTimeout, nil
	case "composer":
		if !composerAllowed[args[0]] {
			return nil, 0, fmt.Errorf("composer %s is not available here", args[0])
		}
		// The working directory is ours to set: a second --working-dir (or
		// -d) from the caller would point composer somewhere else.
		for _, a := range args {
			if a == "-d" || strings.HasPrefix(a, "--working-dir") || (strings.HasPrefix(a, "-d") && !strings.HasPrefix(a, "--")) {
				return nil, 0, fmt.Errorf("the working directory is fixed to the site")
			}
		}
		// --no-cache: the container's /tmp is a small tmpfs and its root is
		// read-only, so there is nowhere sensible for a cache to live.
		out := append([]string{"composer", "--working-dir=/var/www/html", "--no-interaction", "--no-cache", "--ansi"}, args...)
		return out, composerTimeout, nil
	}
	return nil, 0, fmt.Errorf("unknown tool %q", tool)
}

func contains(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}

// RunCommand runs one allow-listed artisan or composer command in the site's
// container and returns its output.
func (m *Manager) RunCommand(ctx context.Context, id, tool string, args []string, confirm bool) (CommandResult, error) {
	if err := ValidID(id); err != nil {
		return CommandResult{}, err
	}
	if tool == "artisan" && len(args) > 0 && destructive[args[0]] && !confirm {
		return CommandResult{}, ErrNeedsConfirm
	}
	argv, timeout, err := validateCommand(tool, args)
	if err != nil {
		return CommandResult{}, err
	}

	lock, _ := commandLocks.LoadOrStore(id, &sync.Mutex{})
	if !lock.(*sync.Mutex).TryLock() {
		return CommandResult{}, ErrBusy
	}
	defer lock.(*sync.Mutex).Unlock()

	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	full := append([]string{"exec", "-u", "33:33",
		"-e", "HOME=/tmp", "-e", "COMPOSER_HOME=/tmp/composer", "-e", "TERM=xterm-256color",
		m.container(id)}, argv...)
	cmd := exec.CommandContext(ctx, "docker", full...)
	out := &cappedBuffer{limit: maxCommandOutput}
	cmd.Stdout, cmd.Stderr = out, out

	started := time.Now()
	runErr := cmd.Run()
	res := CommandResult{
		Tool: tool, Args: args, Output: out.buf.String(), Truncated: out.truncated,
		ElapsedMs: time.Since(started).Milliseconds(),
	}
	if ctx.Err() == context.DeadlineExceeded {
		res.TimedOut = true
		res.ExitCode = -1
		return res, nil
	}
	// composer and artisan change files (composer.json, migrations, make:*):
	// whatever they changed becomes a version, successful or not.
	m.record(context.WithoutCancel(ctx), id, tool+" "+strings.Join(args, " "))

	var exitErr *exec.ExitError
	switch {
	case runErr == nil:
		res.ExitCode = 0
	case errors.As(runErr, &exitErr):
		// A non-zero exit is a RESULT (a failed migration, a missing
		// package), reported with its output - not an agent error.
		res.ExitCode = exitErr.ExitCode()
	default:
		return res, fmt.Errorf("could not run the command: %w", runErr)
	}
	return res, nil
}

// ── logs ─────────────────────────────────────────────────────────────────

const (
	maxLogLines = 1000
	maxLogBytes = 1 << 20
)

// tailFile returns up to n final lines of a file, reading at most maxLogBytes
// from its end - a log of any size costs the same to read.
func tailFile(path string, n int) (string, bool, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", false, err
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil {
		return "", false, err
	}
	start := info.Size() - maxLogBytes
	clipped := start > 0
	if start < 0 {
		start = 0
	}
	if _, err := f.Seek(start, io.SeekStart); err != nil {
		return "", false, err
	}
	b, err := io.ReadAll(io.LimitReader(f, maxLogBytes))
	if err != nil {
		return "", false, err
	}
	lines := strings.Split(strings.TrimRight(string(b), "\n"), "\n")
	if clipped && len(lines) > 0 {
		lines = lines[1:] // the first line was cut part-way
	}
	if len(lines) > n {
		lines = lines[len(lines)-n:]
		clipped = true
	}
	return strings.Join(lines, "\n"), clipped, nil
}

type LogResult struct {
	Source    string `json:"source"`
	Lines     string `json:"lines"`
	Truncated bool   `json:"truncated"`
}

// Logs returns the tail of one of the site's logs:
//
//	app        storage/logs/laravel.log, from the site's own disk
//	access     every request Caddy served for the site, one compact line each
//	container  Apache and PHP's own output (what display_errors=Off sends to stderr)
func (m *Manager) Logs(ctx context.Context, id, source string, n int) (LogResult, error) {
	if err := ValidID(id); err != nil {
		return LogResult{}, err
	}
	if n <= 0 || n > maxLogLines {
		n = 200
	}
	res := LogResult{Source: source}

	switch source {
	case "app":
		// Through resolve(), so a symlink planted at storage/logs cannot point
		// this read somewhere else on the host.
		path, err := m.resolve(id, "storage/logs/laravel.log")
		if err != nil {
			return res, err
		}
		lines, clipped, err := tailFile(path, n)
		if os.IsNotExist(err) {
			return res, nil // no log yet is an empty log, not an error
		}
		if err != nil {
			return res, fmt.Errorf("cannot read the application log")
		}
		res.Lines, res.Truncated = lines, clipped
	case "access":
		raw, clipped, err := tailFile(filepath.Join(caddyLogDir, id+".log"), n)
		if os.IsNotExist(err) {
			return res, nil
		}
		if err != nil {
			return res, fmt.Errorf("cannot read the access log")
		}
		res.Lines, res.Truncated = compactAccessLog(raw), clipped
	case "container":
		out, err := run(ctx, 20*time.Second, "docker", "logs", "--tail", strconv.Itoa(n), m.container(id))
		if err != nil {
			return res, fmt.Errorf("cannot read the container log")
		}
		if len(out) > maxLogBytes {
			out, res.Truncated = out[len(out)-maxLogBytes:], true
		}
		res.Lines = out
	default:
		return res, fmt.Errorf("unknown log %q: use app, access or container", source)
	}
	return res, nil
}

// compactAccessLog turns Caddy's JSON lines into one readable line each.
func compactAccessLog(raw string) string {
	var out []string
	for _, line := range strings.Split(raw, "\n") {
		var e struct {
			TS      float64 `json:"ts"`
			Status  int     `json:"status"`
			Dur     float64 `json:"duration"`
			Size    int     `json:"size"`
			Request struct {
				RemoteIP string `json:"remote_ip"`
				Method   string `json:"method"`
				Host     string `json:"host"`
				URI      string `json:"uri"`
			} `json:"request"`
		}
		if json.Unmarshal([]byte(line), &e) != nil || e.Request.Method == "" {
			continue
		}
		out = append(out, fmt.Sprintf("%s %d %s %s%s %dB %.0fms %s",
			time.Unix(int64(e.TS), 0).UTC().Format("2006-01-02 15:04:05"),
			e.Status, e.Request.Method, e.Request.Host, e.Request.URI, e.Size, e.Dur*1000, e.Request.RemoteIP))
	}
	return strings.Join(out, "\n")
}
