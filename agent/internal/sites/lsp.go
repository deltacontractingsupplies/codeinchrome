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

var lspSessionID = regexp.MustCompile(`^[a-z0-9]{16,40}$`)

// ErrLSPSessions means the site already has as many language servers as it may.
var ErrLSPSessions = errors.New("too many editor sessions are open for this site; close one and try again")

// lspCommand starts the language server. A variable so tests can stand in a
// fake server without docker.
var lspCommand = func(ctx context.Context, container string) *exec.Cmd {
	return exec.CommandContext(ctx, "docker", "exec", "-i", "-u", "33:33", "-w", "/var/www/html",
		// Its cache (the class index) lives on the site's own disk, under its
		// quota, and outside history (storage/ is excluded).
		"-e", "XDG_CACHE_HOME="+lspCacheInstall, "-e", "HOME=/tmp",
		container, "php", "-d", "memory_limit="+lspPHPMemory, "/usr/local/bin/phpactor", "language-server")
}

type lspSession struct {
	site     string
	cmd      *exec.Cmd
	cancel   context.CancelFunc
	stdin    io.WriteCloser
	writeMu  sync.Mutex
	mu       sync.Mutex
	queue    []json.RawMessage
	arrived  chan struct{} // closed and replaced each time a message arrives
	lastUsed time.Time
	done     chan struct{}
	err      error
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
	cmd := lspCommand(ctx, m.container(id))
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
	s := &lspSession{site: id, cmd: cmd, cancel: cancel, stdin: stdin,
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

// LSPClose ends an editor session's language server.
func (m *Manager) LSPClose(id, session string) {
	lspMu.Lock()
	s := lspSessions[id+"/"+session]
	delete(lspSessions, id+"/"+session)
	lspMu.Unlock()
	if s != nil {
		_ = s.stdin.Close()
		s.cancel()
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
		_ = s.stdin.Close()
		s.cancel()
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
				_ = s.stdin.Close()
				s.cancel()
			}
		}
		lspMu.Unlock()
	}
}
