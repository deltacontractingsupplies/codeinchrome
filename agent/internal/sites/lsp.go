package sites

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Language servers for the editor: Phpactor (MIT) running inside the site's
// own container, as the site's own user (uid 33), so it reads that site's
// vendor/ and nothing else - it cannot see anything the site's PHP could not.
//
// The browser cannot hold a socket open to it (the control plane is PHP-FPM),
// so the agent keeps the process and speaks to it over its stdio, and the
// browser exchanges batches of JSON-RPC messages over plain HTTP:
//
//	Exchange(send, wait): writes the messages, then waits - at most `wait` -
//	for the responses to every request among them, and returns those plus
//	anything else the server has said meanwhile (diagnostics, logs).
//
// One process per editor session (a browser tab), reaped after lspIdle
// without a message, and when the site is deleted or recreated it dies with
// the container.

const (
	lspIdle         = 10 * time.Minute
	lspMaxSessions  = 3 // per site
	lspMaxQueued    = 2000
	lspMaxMessage   = 8 << 20
	lspMaxExchange  = 15 * time.Second
	lspPHPMemory    = "256M"
	lspCacheInstall = "/var/www/html/storage/framework/cache/phpactor"
)

func lspPidFile(session string) string { return lspCacheInstall + "/lsp-" + session + ".pid" }

// lspKill stops a session's language server INSIDE the container, by the pid
// it recorded. A variable so the tests need no docker.
var lspKill = func(container, session string) {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = exec.CommandContext(ctx, "docker", "exec", "-u", "33:33", container, "sh", "-c",
		`f="$1"; [ -f "$f" ] && kill "$(cat "$f")" 2>/dev/null; rm -f "$f"`, "sh", lspPidFile(session)).Run()
}

var lspSessionID = regexp.MustCompile(`^[a-z0-9]{16,40}$`)

// ErrLSPSessions means the site already has as many language servers as it may.
var ErrLSPSessions = errors.New("too many editor sessions are open for this site; close one and try again")

// lspCommand starts the language server. A variable so tests can stand in a
// fake server without docker.
// lspConfig is Phpactor's own settings: index the application, never its
// dependencies, caches or build output.
const lspConfig = `{"indexer.exclude_patterns":["/vendor/**/*","/node_modules/**/*","/storage/**/*","/bootstrap/cache/**/*","/public/build/**/*"]}`

// lspMaxCacheFiles bounds Phpactor's cache on the site's disk.
const lspMaxCacheFiles = "30000"

var lspCommand = func(ctx context.Context, container, session string) *exec.Cmd {
	return exec.CommandContext(ctx, "docker", "exec", "-i", "-u", "33:33", "-w", "/var/www/html",
		// Its cache (the class index) AND its temporary files live on the
		// site's own disk, under its quota and outside history (storage/ is
		// excluded). Not /tmp: that is a 64 MB tmpfs - memory, charged to the
		// site - and where PHP stages the site's own uploads. Phpactor's temp
		// files (several MB per process) filled it, which broke Phpactor
		// itself ("No space left on device", then "phar corruption") and
		// would have broken the site's uploads. Stale ones are swept at start.
		"-e", "XDG_CACHE_HOME="+lspCacheInstall, "-e", "HOME=/tmp", "-e", "TMPDIR="+lspCacheInstall+"/tmp",
		// The pid file is how the process is stopped later: killing this
		// `docker exec` client does NOT stop what runs inside the container,
		// and a language server left behind holds up to 256 MB of the
		// site's own memory limit. exec keeps the pid: sh becomes php.
		"-e", "CIC_LSP_PID="+lspPidFile(session),
		// Its settings, written to the container's own /tmp - never into the
		// site's code. The index covers the APP, not vendor/: indexing
		// vendor/ wrote ~54,000 tiny files per site and exhausted the inodes
		// of a 1 GB disk, so the site could no longer save anything (found on
		// a live site). Completion, hover and definition into vendor/ do not
		// need the index - Phpactor finds those classes through Composer.
		"-e", "XDG_CONFIG_HOME=/tmp/cic-phpactor-config",
		container, "sh", "-c",
		lspPrelude+`exec php -d memory_limit=`+lspPHPMemory+` -d sys_temp_dir="$TMPDIR" -d upload_tmp_dir="$TMPDIR" /usr/local/bin/phpactor language-server`)
}

// lspPrelude readies a site for its language server, in the container:
// temp files swept, settings written, and the index kept to what the
// settings say.
//
// An index built under OTHER settings is dropped whole, and rebuilt: Phpactor
// only adds to an index, so records from before a setting changed stay for
// ever. (Found on a live site: an index from before vendor/ was excluded kept
// its 2,752 vendor files and grew past 32,000 files; a fresh one holds 15,700,
// 12,000 of them PHP's own functions and constants.) The cap is a backstop
// for an index that grows past it anyway.
const lspPrelude = `mkdir -p "$TMPDIR" "$XDG_CONFIG_HOME/phpactor" && find "$TMPDIR" -type f -mmin +60 -delete 2>/dev/null; ` +
	`printf '%s' '` + lspConfig + `' > "$XDG_CONFIG_HOME/phpactor/phpactor.json"; ` +
	`[ "$(cat "$XDG_CACHE_HOME/cic-index-settings" 2>/dev/null)" = '` + lspConfig + `' ] || ` +
	`{ rm -rf "$XDG_CACHE_HOME/phpactor"; printf '%s' '` + lspConfig + `' > "$XDG_CACHE_HOME/cic-index-settings"; }; ` +
	`[ "$(find "$XDG_CACHE_HOME" -type f 2>/dev/null | head -n ` + lspMaxCacheFiles + ` | wc -l)" -ge ` + lspMaxCacheFiles + ` ] && rm -rf "$XDG_CACHE_HOME/phpactor"; ` +
	`echo $$ > "$CIC_LSP_PID"; `

type lspSession struct {
	site      string
	session   string
	container string
	cmd       *exec.Cmd
	cancel    context.CancelFunc
	stdin     io.WriteCloser
	writeMu   sync.Mutex
	mu        sync.Mutex
	queue     []json.RawMessage
	arrived   chan struct{} // closed and replaced each time a message arrives
	lastUsed  time.Time
	done      chan struct{}
	err       error
}

var (
	lspMu       sync.Mutex
	lspSessions = map[string]*lspSession{} // site + "/" + session id
	lspReaper   sync.Once
)

func (m *Manager) lspSession(id, session string) (*lspSession, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	if !lspSessionID.MatchString(session) {
		return nil, fmt.Errorf("invalid session id")
	}
	lspReaper.Do(func() { go reapLSP() })

	key := id + "/" + session
	lspMu.Lock()
	defer lspMu.Unlock()
	if s, ok := lspSessions[key]; ok {
		select {
		case <-s.done:
			delete(lspSessions, key)
		default:
			return s, nil
		}
	}
	n := 0
	for k := range lspSessions {
		if strings.HasPrefix(k, id+"/") {
			n++
		}
	}
	if n >= lspMaxSessions {
		return nil, ErrLSPSessions
	}

	ctx, cancel := context.WithCancel(context.Background())
	cmd := lspCommand(ctx, m.container(id), session)
	stdin, err := cmd.StdinPipe()
	if err != nil {
		cancel()
		return nil, err
	}
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		cancel()
		return nil, err
	}
	cmd.Stderr = io.Discard
	if err := cmd.Start(); err != nil {
		cancel()
		return nil, fmt.Errorf("the language server could not start")
	}
	s := &lspSession{site: id, session: session, container: m.container(id), cmd: cmd, cancel: cancel, stdin: stdin,
		arrived: make(chan struct{}), lastUsed: time.Now(), done: make(chan struct{})}
	go s.read(bufio.NewReaderSize(stdout, 64<<10))
	lspSessions[key] = s
	return s, nil
}

// read turns the server's Content-Length framed output into queued messages.
func (s *lspSession) read(r *bufio.Reader) {
	defer func() {
		s.cancel()
		_ = s.cmd.Wait()
		close(s.done)
		s.notify()
	}()
	for {
		length := -1
		for {
			line, err := r.ReadString('\n')
			if err != nil {
				s.err = err
				return
			}
			line = strings.TrimRight(line, "\r\n")
			if line == "" {
				break
			}
			if k, v, ok := strings.Cut(line, ":"); ok && strings.EqualFold(strings.TrimSpace(k), "Content-Length") {
				length, _ = strconv.Atoi(strings.TrimSpace(v))
			}
		}
		if length < 0 || length > lspMaxMessage {
			s.err = fmt.Errorf("bad frame from the language server")
			return
		}
		body := make([]byte, length)
		if _, err := io.ReadFull(r, body); err != nil {
			s.err = err
			return
		}
		s.mu.Lock()
		if len(s.queue) >= lspMaxQueued {
			s.queue = s.queue[1:] // a client that never collects must not grow this forever
		}
		s.queue = append(s.queue, json.RawMessage(body))
		s.mu.Unlock()
		s.notify()
	}
}

func (s *lspSession) notify() {
	s.mu.Lock()
	close(s.arrived)
	s.arrived = make(chan struct{})
	s.mu.Unlock()
}

func (s *lspSession) write(msg json.RawMessage) error {
	s.writeMu.Lock()
	defer s.writeMu.Unlock()
	if _, err := fmt.Fprintf(s.stdin, "Content-Length: %d\r\n\r\n", len(msg)); err != nil {
		return err
	}
	_, err := s.stdin.Write(msg)
	return err
}

// LSPExchange sends messages to the site's language server for this editor
// session and returns what came back: every response to a request among
// them (waiting up to wait), plus anything else queued.
func (m *Manager) LSPExchange(ctx context.Context, id, session string, send []json.RawMessage, wait time.Duration) ([]json.RawMessage, error) {
	if wait > lspMaxExchange {
		wait = lspMaxExchange
	}
	// Every message is checked before anything starts or is written: a bad
	// batch must neither start a process nor reach the server half-sent.
	pending := map[string]bool{}
	for _, msg := range send {
		if len(msg) > lspMaxMessage {
			return nil, fmt.Errorf("a message is larger than %d MB", lspMaxMessage>>20)
		}
		var head struct {
			ID     json.RawMessage `json:"id"`
			Method string          `json:"method"`
		}
		if json.Unmarshal(msg, &head) != nil {
			return nil, fmt.Errorf("messages must be JSON-RPC objects")
		}
		if head.Method != "" && len(head.ID) > 0 && string(head.ID) != "null" {
			pending[string(head.ID)] = true
		}
	}
	s, err := m.lspSession(id, session)
	if err != nil {
		return nil, err
	}
	for _, msg := range send {
		if err := s.write(msg); err != nil {
			return nil, fmt.Errorf("the language server stopped")
		}
	}

	deadline := time.NewTimer(wait)
	defer deadline.Stop()
	var out []json.RawMessage
	for {
		s.mu.Lock()
		s.lastUsed = time.Now()
		for _, msg := range s.queue {
			var head struct {
				ID     json.RawMessage `json:"id"`
				Method string          `json:"method"`
			}
			if json.Unmarshal(msg, &head) == nil && head.Method == "" {
				delete(pending, string(head.ID))
			}
		}
		out = append(out, s.queue...)
		s.queue = nil
		arrived := s.arrived
		s.mu.Unlock()

		if len(pending) == 0 {
			return out, nil
		}
		select {
		case <-arrived:
		case <-s.done:
			return out, fmt.Errorf("the language server stopped")
		case <-deadline.C:
			return out, nil
		case <-ctx.Done():
			return out, nil
		}
	}
}

// stop ends the server: closes its input, kills it inside the container, and
// lets go of the docker client.
func (s *lspSession) stop() {
	_ = s.stdin.Close()
	lspKill(s.container, s.session)
	s.cancel()
}

// LSPClose ends an editor session's language server.
func (m *Manager) LSPClose(id, session string) {
	lspMu.Lock()
	s := lspSessions[id+"/"+session]
	delete(lspSessions, id+"/"+session)
	lspMu.Unlock()
	if s != nil {
		go s.stop()
	}
}

// LSPCloseSite ends every language server of a site (on delete or recreate).
func (m *Manager) LSPCloseSite(id string) {
	lspMu.Lock()
	var gone []*lspSession
	for k, s := range lspSessions {
		if strings.HasPrefix(k, id+"/") {
			gone = append(gone, s)
			delete(lspSessions, k)
		}
	}
	lspMu.Unlock()
	for _, s := range gone {
		s.stop()
	}
}

func reapLSP() {
	for range time.Tick(time.Minute) {
		lspMu.Lock()
		for k, s := range lspSessions {
			s.mu.Lock()
			idle := time.Since(s.lastUsed) > lspIdle
			s.mu.Unlock()
			select {
			case <-s.done:
				delete(lspSessions, k)
				continue
			default:
			}
			if idle {
				delete(lspSessions, k)
				go s.stop()
			}
		}
		lspMu.Unlock()
	}
}

// StopStrayLanguageServers stops every language server running in any site
// container. Called when the agent starts: it holds no sessions yet, so any
// such process was left by a previous run and nothing will ever talk to it.
func (m *Manager) StopStrayLanguageServers(ctx context.Context) int {
	out, err := exec.CommandContext(ctx, "docker", "ps", "-q", "--filter", "label=codeinchrome.site").Output()
	if err != nil {
		return 0
	}
	n := 0
	for _, c := range strings.Fields(string(out)) {
		if exec.CommandContext(ctx, "docker", "exec", "-u", "33:33", c, "pkill", "-f", "phpactor language-server").Run() == nil {
			n++
		}
	}
	return n
}
