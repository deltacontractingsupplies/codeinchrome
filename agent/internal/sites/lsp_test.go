package sites

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

// A fake language server: the test binary run again with CIC_FAKE_LSP=1. It
// answers every request with {"echo": method}, sends a notification after
// "initialize", and never answers "slow".
func TestMain(m *testing.M) {
	if os.Getenv("CIC_FAKE_LSP") == "1" {
		fakeLSP()
		return
	}
	os.Exit(m.Run())
}

func fakeLSP() {
	r := bufio.NewReader(os.Stdin)
	send := func(v any) {
		b, _ := json.Marshal(v)
		fmt.Printf("Content-Length: %d\r\n\r\n%s", len(b), b)
	}
	for {
		n := -1
		for {
			line, err := r.ReadString('\n')
			if err != nil {
				return
			}
			line = strings.TrimSpace(line)
			if line == "" {
				break
			}
			if k, v, ok := strings.Cut(line, ":"); ok && k == "Content-Length" {
				n, _ = strconv.Atoi(strings.TrimSpace(v))
			}
		}
		body := make([]byte, n)
		if _, err := io.ReadFull(r, body); err != nil {
			return
		}
		var msg struct {
			ID     json.RawMessage `json:"id"`
			Method string          `json:"method"`
		}
		json.Unmarshal(body, &msg)
		if msg.Method == "initialize" {
			send(map[string]any{"jsonrpc": "2.0", "method": "window/logMessage", "params": map[string]any{"message": "ready"}})
		}
		if len(msg.ID) > 0 && msg.Method != "slow" {
			send(map[string]any{"jsonrpc": "2.0", "id": msg.ID, "result": map[string]any{"echo": msg.Method}})
		}
	}
}

var (
	killed   = &[]string{}
	killedMu sync.Mutex
)

func fakeLSPCommand(t *testing.T) *[]string {
	t.Helper()
	started := &[]string{}
	// Under the lock: a session closed by the previous test may still be
	// stopping on its own goroutine and appending here.
	killedMu.Lock()
	*killed = nil
	killedMu.Unlock()
	orig := lspCommand
	t.Cleanup(func() { lspCommand = orig })
	origKill := lspKill
	t.Cleanup(func() { lspKill = origKill })
	lspKill = func(container, session string) {
		killedMu.Lock()
		defer killedMu.Unlock()
		*killed = append(*killed, container+"/"+session)
	}
	lspCommand = func(ctx context.Context, container, session string) *exec.Cmd {
		*started = append(*started, container)
		cmd := exec.CommandContext(ctx, os.Args[0], "-test.run=^$")
		cmd.Env = append(os.Environ(), "CIC_FAKE_LSP=1")
		return cmd
	}
	return started
}

func raw(s string) json.RawMessage { return json.RawMessage(s) }

func TestLSPExchangeReturnsResponsesAndNotifications(t *testing.T) {
	m, id := historyManager(t)
	started := fakeLSPCommand(t)
	t.Cleanup(func() { m.LSPCloseSite(id) })

	out, err := m.LSPExchange(context.Background(), id, "tab0123456789abcd", []json.RawMessage{
		raw(`{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}`),
		raw(`{"jsonrpc":"2.0","method":"initialized","params":{}}`),
		raw(`{"jsonrpc":"2.0","id":"two","method":"textDocument/hover","params":{}}`),
	}, 5*time.Second)
	if err != nil {
		t.Fatal(err)
	}
	var parts []string
	for _, m := range out {
		parts = append(parts, string(m))
	}
	joined := strings.Join(parts, "\n")
	for _, want := range []string{`"echo":"initialize"`, `"echo":"textDocument/hover"`, `window/logMessage`} {
		if !strings.Contains(joined, want) {
			t.Fatalf("missing %s in %s", want, joined)
		}
	}
	// The same session reuses its process.
	if _, err := m.LSPExchange(context.Background(), id, "tab0123456789abcd", []json.RawMessage{raw(`{"jsonrpc":"2.0","id":3,"method":"x"}`)}, time.Second); err != nil {
		t.Fatal(err)
	}
	if len(*started) != 1 || *started != nil && (*started)[0] != "cic-"+id {
		t.Fatalf("processes started: %v", *started)
	}
}

func TestAnUnansweredRequestReturnsAtTheDeadline(t *testing.T) {
	m, id := historyManager(t)
	fakeLSPCommand(t)
	t.Cleanup(func() { m.LSPCloseSite(id) })
	start := time.Now()
	out, err := m.LSPExchange(context.Background(), id, "tab0123456789abcd", []json.RawMessage{raw(`{"jsonrpc":"2.0","id":9,"method":"slow"}`)}, 300*time.Millisecond)
	if err != nil || len(out) != 0 {
		t.Fatalf("got %v %v", out, err)
	}
	if d := time.Since(start); d < 250*time.Millisecond || d > 3*time.Second {
		t.Fatalf("returned after %v", d)
	}
}

func TestLSPRefusesBadInputAndCapsSessionsPerSite(t *testing.T) {
	m, id := historyManager(t)
	fakeLSPCommand(t)
	t.Cleanup(func() { m.LSPCloseSite(id) })
	if _, err := m.LSPExchange(context.Background(), id, "../../etc", nil, 0); err == nil {
		t.Fatal("a bad session id was accepted")
	}
	if _, err := m.LSPExchange(context.Background(), "../x", "tab0123456789abcd", nil, 0); err == nil {
		t.Fatal("a bad site id was accepted")
	}
	if _, err := m.LSPExchange(context.Background(), id, "tab0123456789abcd", []json.RawMessage{raw(`not json`)}, 0); err == nil {
		t.Fatal("a non-JSON message was accepted")
	}
	for i := 0; i < lspMaxSessions; i++ {
		if _, err := m.LSPExchange(context.Background(), id, fmt.Sprintf("tab%016d", i+1), nil, 0); err != nil {
			t.Fatalf("session %d: %v", i, err)
		}
	}
	if _, err := m.LSPExchange(context.Background(), id, "tabxxxxxxxxxxxxxxxx", nil, 0); err != ErrLSPSessions {
		t.Fatalf("one session too many: %v", err)
	}
	m.LSPClose(id, fmt.Sprintf("tab%016d", 1))
	if _, err := m.LSPExchange(context.Background(), id, "tabxxxxxxxxxxxxxxxx", nil, 0); err != nil {
		t.Fatalf("a closed session did not free its slot: %v", err)
	}
}

func TestClosingASessionKillsItsProcessInsideTheContainer(t *testing.T) {
	m, id := historyManager(t)
	fakeLSPCommand(t)
	if _, err := m.LSPExchange(context.Background(), id, "tab0123456789abcd", nil, 0); err != nil {
		t.Fatal(err)
	}
	m.LSPCloseSite(id)
	killedMu.Lock()
	defer killedMu.Unlock()
	// This session, killed exactly once. Counted, not compared as the whole
	// list: a session an earlier test closed may still be stopping on its own
	// goroutine and land in the list too.
	want, n := "cic-"+id+"/tab0123456789abcd", 0
	for _, k := range *killed {
		if k == want {
			n++
		}
	}
	if n != 1 {
		t.Fatalf("%s killed %d times inside the container (all kills: %v)", want, n, *killed)
	}
}
