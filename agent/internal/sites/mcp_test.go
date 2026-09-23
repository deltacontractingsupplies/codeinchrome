package sites

import (
	"context"
	"encoding/json"
	"os/exec"
	"strings"
	"testing"
)

func fakeMCP(t *testing.T, script string) *[]string {
	t.Helper()
	calls := &[]string{}
	orig := mcpCommand
	t.Cleanup(func() { mcpCommand = orig })
	mcpCommand = func(ctx context.Context, container string) *exec.Cmd {
		*calls = append(*calls, container)
		return exec.CommandContext(ctx, "sh", "-c", script)
	}
	return calls
}

func TestMCPReturnsTheResultOfTheOneRequest(t *testing.T) {
	m, id := historyManager(t)
	// Echo an initialize answer, a log notification, then answer id 2 with
	// what it was asked (the third input line).
	calls := fakeMCP(t, `read a; read b; read c; echo '{"jsonrpc":"2.0","id":1,"result":{}}'; echo '{"jsonrpc":"2.0","method":"notifications/message"}'; printf '{"jsonrpc":"2.0","id":2,"result":{"asked":%s}}\n' "$c"`)
	res, err := m.MCP(context.Background(), id, "tools/call", json.RawMessage(`{"name":"database-schema","arguments":{}}`))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(res), `"method":"tools/call"`) || !strings.Contains(string(res), `"database-schema"`) {
		t.Fatalf("result %s", res)
	}
	if (*calls)[0] != "cic-"+id {
		t.Fatalf("ran in %v", *calls)
	}
}

func TestMCPOnlyPassesToolsAndSaysHowToInstallBoost(t *testing.T) {
	m, id := historyManager(t)
	calls := fakeMCP(t, `echo 'ERROR  Command "boost:mcp" is not defined.' >&2; exit 1`)
	for _, method := range []string{"initialize", "resources/read", "prompts/get", "shutdown"} {
		if _, err := m.MCP(context.Background(), id, method, nil); err == nil {
			t.Fatalf("%s was passed through", method)
		}
	}
	if len(*calls) != 0 {
		t.Fatal("a refused method started a process")
	}
	if _, err := m.MCP(context.Background(), id, "tools/list", nil); err != ErrMCPNotInstalled {
		t.Fatalf("got %v, want the install hint", err)
	}
	// What artisan actually prints, on stdout, when Boost is missing.
	fakeMCP(t, `printf '\n   ERROR  There are no commands defined in the "boost" namespace.  \n\n'; exit 1`)
	if _, err := m.MCP(context.Background(), id, "tools/list", nil); err != ErrMCPNotInstalled {
		t.Fatalf("stdout form: got %v, want the install hint", err)
	}
	if _, err := m.MCP(context.Background(), "../x", "tools/list", nil); err == nil {
		t.Fatal("a bad site id was accepted")
	}
}
