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
	"unicode/utf8"
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
		// The app's own test suite, never against its live data: see commandEnv.
		"test": false,
	}
	composerAllowed = map[string]bool{
		"install": true, "update": true, "require": true, "remove": true,
		"dump-autoload": true, "show": true, "outdated": true, "validate": true,
	}

	// An argument is a word, an option, or a package constraint. Nothing that
	// could be read as a path outside the app or as another command.
	safeArg     = regexp.MustCompile(`^[A-Za-z0-9_@:./=^~*<>,|+-]{1,200}$`)
	makeCommand = regexp.MustCompile(`^make:[a-z-]{2,30}$`)
	appCommand  = regexp.MustCompile(`^app:[a-z0-9][a-z0-9:-]{0,40}$`)

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
	mu        sync.Mutex // Write runs as the command prints; Since reads it meanwhile
	buf       bytes.Buffer
	limit     int
	truncated bool
}

func (c *cappedBuffer) Write(p []byte) (int, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
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

// Since is what was written from byte `from` on, at most max bytes, and the
// offset to ask from next - cut on a character boundary, so a chunk never
// ends in half a UTF-8 character.
func (c *cappedBuffer) Since(from, max int) (string, int) {
	c.mu.Lock()
	defer c.mu.Unlock()
	b := c.buf.Bytes()
	if from < 0 || from > len(b) {
		from = len(b)
	}
	end := len(b)
	if end-from > max {
		end = from + max
	}
	// Cut inside the buffer: back off to the start of that character.
	for end > from && end < len(b) && !utf8.RuneStart(b[end]) {
		end--
	}
	// At the end: the command may be half-way through printing a character;
	// leave it for the next call.
	if end == len(b) && end > from {
		start := end - 1
		for start > from && end-start < utf8.UTFMax && !utf8.RuneStart(b[start]) {
			start--
		}
		if !utf8.FullRune(b[start:end]) {
			end = start
		}
	}
	return string(b[from:end]), end
}

// liveCommands: each site's running command's output, readable while it
// runs (CommandLive), so the editor shows it line by line like a terminal
// instead of all at once at the end (owner, 2026-09-26). Keyed by the run:
// the caller names its run (WithLiveKey), and only that name reads it - a
// command queued behind another never shows the other's output as its own.
var liveCommands sync.Map // site id -> liveRun

type liveRun struct {
	key string
	out *cappedBuffer
}

type liveKeyCtx struct{}

var liveKeyRe = regexp.MustCompile(`^[A-Za-z0-9]{8,64}$`)

// WithLiveKey names a command run so its output can be read while it runs.
// A key that is not 8-64 letters and digits is ignored.
func WithLiveKey(ctx context.Context, key string) context.Context {
	if !liveKeyRe.MatchString(key) {
		return ctx
	}
	return context.WithValue(ctx, liveKeyCtx{}, key)
}

// LiveOutput is a running command's output from an offset on.
type LiveOutput struct {
	Running bool   `json:"running"`
	Output  string `json:"output"`
	Next    int    `json:"next"`
}

// CommandLive is what the site's run `key` printed from byte `from` on (64 KB
// at most a call). Not running (or another run): nothing, and the caller
// takes the rest from the command's own answer.
func (m *Manager) CommandLive(id, key string, from int) (LiveOutput, error) {
	if err := ValidID(id); err != nil {
		return LiveOutput{}, err
	}
	v, ok := liveCommands.Load(id)
	if !ok || key == "" || v.(liveRun).key != key {
		return LiveOutput{Next: from}, nil
	}
	out, next := v.(liveRun).out.Since(from, 64<<10)
	return LiveOutput{Running: true, Output: out, Next: next}, nil
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
		// The app's own commands (app:*) run the site's own code, as cic.eval
		// does: nothing the owner could not already do. The skill has every app
		// carry an app:check that requests all its pages.
		if !ok && !makeCommand.MatchString(name) && !appCommand.MatchString(name) {
			return nil, 0, fmt.Errorf("artisan %s is not available here", name)
		}
		out := append([]string{"php", "/var/www/html/artisan"}, args...)
		// `artisan test` hands options it does not know to PHPUnit, which
		// refuses --no-interaction ("Unknown option"), so a test run never
		// started. A test prompts for nothing, and there is no TTY anyway.
		if name != "test" {
			out = append(out, "--no-interaction")
		}
		out = append(out, "--ansi")
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

	// A migration or seeder rewrites the live database: it is saved first
	// (dbsnapshots.go), and if it cannot be saved nothing runs.
	if tool == "artisan" && snapshotBefore[args[0]] && m.hasDB(id) {
		if _, err := m.SnapshotDB(ctx, id, "before-"+args[0]); err != nil {
			return CommandResult{}, fmt.Errorf("nothing was run: the database could not be saved first (%v)", err)
		}
	}

	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	full := []string{"exec", "-u", "33:33",
		"-e", "HOME=/tmp", "-e", "COMPOSER_HOME=/tmp/composer", "-e", "TERM=xterm-256color"}
	for _, e := range commandEnv(tool, args) {
		full = append(full, "-e", e)
	}
	// Bounded INSIDE the container too (see evalCommand): the docker CLI
	// dying on its timeout does not stop the process in the container.
	full = append(full, m.container(id), "timeout", "--kill-after=5", strconv.Itoa(int(timeout/time.Second)))
	full = append(full, argv...)
	cmd := exec.CommandContext(ctx, "docker", full...)
	out := &cappedBuffer{limit: maxCommandOutput}
	cmd.Stdout, cmd.Stderr = out, out
	if key, _ := ctx.Value(liveKeyCtx{}).(string); key != "" {
		liveCommands.Store(id, liveRun{key: key, out: out})
		defer liveCommands.Delete(id)
	}

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
	// What composer installed is what the scan exempts in vendor/ (dependencies.go).
	if tool == "composer" && res.ExitCode == 0 {
		m.rememberVendor(id)
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
	return tailOpen(f, n)
}

// tailOpen is tailFile on a file already opened (and closes it).
func tailOpen(f *os.File, n int) (string, bool, error) {
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
	// For the app log: which file was read and how long it was, so a caller
	// can later ask for exactly what was written after this (LogsSince).
	File string `json:"file,omitempty"`
	Size int64  `json:"size,omitempty"`
}

// Logs returns the tail of one of the site's logs:
//
//	app        storage/logs/laravel.log or the newest daily laravel-*.log
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
		// The newest of laravel.log and the daily laravel-YYYY-MM-DD.log files
		// (sites log daily since 0.18: one file used to grow without end).
		// Listed and opened through kernel-checked handles, so a symlink
		// planted at storage/logs cannot point this read at the host.
		f, name, err := m.newestAppLog(id)
		if os.IsNotExist(err) {
			return res, nil // no log yet is an empty log, not an error
		}
		if err != nil {
			return res, fmt.Errorf("cannot read the application log")
		}
		if info, err := f.Stat(); err == nil {
			res.File, res.Size = name, info.Size()
		}
		lines, clipped, err := tailOpen(f, n)
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

var appLogName = regexp.MustCompile(`^laravel(-\d{4}-\d{2}-\d{2})?\.log$`)

// newestAppLog opens the most recently written Laravel log of the site.
func (m *Manager) newestAppLog(id string) (*os.File, string, error) {
	root, err := m.realRoot(id)
	if err != nil {
		return nil, "", err
	}
	dir, err := openBeneath(root, "/storage/logs")
	if err != nil {
		return nil, "", err
	}
	entries, err := dir.ReadDir(-1)
	if err != nil {
		dir.Close()
		return nil, "", err
	}
	best, bestTime := "", time.Time{}
	for _, e := range entries {
		if !e.Type().IsRegular() || !appLogName.MatchString(e.Name()) {
			continue
		}
		if info, err := statAt(dir, e.Name()); err == nil && info.ModTime().After(bestTime) {
			best, bestTime = e.Name(), info.ModTime()
		}
	}
	dir.Close()
	if best == "" {
		return nil, "", os.ErrNotExist
	}
	f, err := openBeneath(root, "/storage/logs/"+best)
	return f, best, err
}

// LogsSince returns what the app log gained after a mark taken by Logs (its
// file and size): exactly the new entries, so a check reports the errors it
// caused and never an older one that happens to read the same. A different
// newest file (the day turned) or a shorter one (it was cleared) is new
// from its start. At most maxLogBytes, the newest kept.
func (m *Manager) LogsSince(id, file string, since int64) (LogResult, error) {
	res := LogResult{Source: "app"}
	if err := ValidID(id); err != nil {
		return res, err
	}
	f, name, err := m.newestAppLog(id)
	if os.IsNotExist(err) {
		return res, nil
	}
	if err != nil {
		return res, fmt.Errorf("cannot read the application log")
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil {
		return res, fmt.Errorf("cannot read the application log")
	}
	res.File, res.Size = name, info.Size()
	start := since
	if name != file || since < 0 || since > info.Size() {
		start = 0
	}
	if info.Size()-start > maxLogBytes {
		start, res.Truncated = info.Size()-maxLogBytes, true
	}
	if _, err := f.Seek(start, io.SeekStart); err != nil {
		return res, fmt.Errorf("cannot read the application log")
	}
	b, err := io.ReadAll(io.LimitReader(f, info.Size()-start))
	if err != nil {
		return res, fmt.Errorf("cannot read the application log")
	}
	res.Lines = strings.TrimRight(string(b), "\n")
	return res, nil
}

// commandEnv is the extra environment one command runs with. `artisan test`
// runs the site's test suite, which may well refresh or truncate its database:
// real environment variables beat .env (Laravel's dotenv is immutable), so the
// run is pointed at an in-memory SQLite, and MySQL at a host that does not
// resolve - a test that insists on MySQL fails rather than finding live data.
func commandEnv(tool string, args []string) []string {
	if tool == "artisan" && len(args) > 0 && args[0] == "test" {
		// DB_URL too: every connection in Laravel's config reads it, and a URL
		// overrides driver, host and database - one in .env or .env.testing
		// would point the run at the live MySQL. Empty is ignored by Laravel.
		return []string{"APP_ENV=testing", "DB_CONNECTION=sqlite", "DB_DATABASE=:memory:", "DB_HOST=db.invalid", "DB_URL=",
			"CACHE_STORE=array", "SESSION_DRIVER=array", "QUEUE_CONNECTION=sync", "MAIL_MAILER=array"}
	}
	return nil
}
