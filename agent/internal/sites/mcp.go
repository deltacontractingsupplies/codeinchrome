package sites

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os/exec"
	"strings"
	"time"
)

// Laravel Boost's MCP server (MIT) for the editor's agent: routes, schema,
// read-only queries, config, the last error, logs and version-specific docs,
// answered by the site's own application.
//
// It runs inside the site's container as the site's own user - it can do
// nothing the site's code could not - one short-lived process per call:
// initialize, one request, exit. Only tools/list and tools/call are passed.

const (
	mcpTimeout   = 60 * time.Second // search-docs goes out to the network
	mcpMaxOutput = 2 << 20
)

// ErrMCPNotInstalled means the site does not have laravel/boost.
var ErrMCPNotInstalled = errors.New("Laravel Boost is not installed in this site; run cic.run('composer', ['require', '--dev', '-W', 'laravel/boost']) and try again")

// mcpCommand starts Boost's MCP server. A variable so tests need no docker.
var mcpCommand = func(ctx context.Context, container string) *exec.Cmd {
	return exec.CommandContext(ctx, "docker", "exec", "-i", "-u", "33:33", "-w", "/var/www/html",
		"-e", "HOME=/tmp",
		// Boost only runs where the app is "local" or has debug on. The site
		// itself stays production with debug off - debug would show visitors
		// stack traces - so only THIS process is told it is local, and it
		// ignores a cached (production) config: Laravel reads real env vars
		// before .env, and APP_CONFIG_CACHE points at a file that does not
		// exist. Web requests and workers are untouched.
		"-e", "APP_ENV=local", "-e", "APP_CONFIG_CACHE=/tmp/cic-mcp-no-config-cache.php",
		container, "php", "artisan", "boost:mcp")
}

// MCP makes one request to the site's Laravel Boost MCP server and returns
// its JSON-RPC result.
func (m *Manager) MCP(ctx context.Context, id, method string, params json.RawMessage) (json.RawMessage, error) {
	if err := ValidID(id); err != nil {
		return nil, err
	}
	if method != "tools/list" && method != "tools/call" {
		return nil, fmt.Errorf("only tools/list and tools/call are available")
	}
	if len(params) == 0 {
		params = json.RawMessage(`{}`)
	}
	if !json.Valid(params) {
		return nil, fmt.Errorf("params must be JSON")
	}

	ctx, cancel := context.WithTimeout(ctx, mcpTimeout)
	defer cancel()
	cmd := mcpCommand(ctx, m.container(id))
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return nil, err
	}
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return nil, err
	}
	var stderr cappedBuffer
	stderr.limit = 8 << 10
	cmd.Stderr = &stderr
	if err := cmd.Start(); err != nil {
		return nil, fmt.Errorf("the MCP server could not start")
	}
	waited := false
	defer func() {
		_ = stdin.Close()
		cancel()
		if !waited {
			_ = cmd.Wait()
		}
	}()

	req, _ := json.Marshal(map[string]any{"jsonrpc": "2.0", "id": 2, "method": method, "params": params})
	fmt.Fprintf(stdin, `{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"codeinchrome","version":"1"}}}`+"\n")
	fmt.Fprintf(stdin, `{"jsonrpc":"2.0","method":"notifications/initialized"}`+"\n")
	fmt.Fprintf(stdin, "%s\n", req)

	r := bufio.NewReaderSize(io.LimitReader(stdout, mcpMaxOutput), 64<<10)
	// Artisan reports an unknown command on STDOUT, not stderr; kept to say why.
	var other strings.Builder
	for {
		line, err := r.ReadBytes('\n')
		if len(line) > 0 && line[0] != '{' && other.Len() < 4<<10 {
			other.Write(line)
		}
		if len(line) > 0 {
			var msg struct {
				ID     json.RawMessage `json:"id"`
				Result json.RawMessage `json:"result"`
				Error  *struct {
					Message string `json:"message"`
				} `json:"error"`
			}
			if json.Unmarshal(line, &msg) == nil && string(msg.ID) == "2" {
				if msg.Error != nil {
					return nil, fmt.Errorf("%s", msg.Error.Message)
				}
				return msg.Result, nil
			}
		}
		if err != nil {
			// stderr is filled by exec's own copier; only Wait says it is done.
			_ = stdin.Close()
			_ = cmd.Wait()
			waited = true
			out := other.String() + stderr.buf.String()
			if strings.Contains(out, `"boost" namespace`) || (strings.Contains(out, "boost:mcp") && strings.Contains(out, "not defined")) {
				return nil, ErrMCPNotInstalled
			}
			if ctx.Err() != nil {
				return nil, fmt.Errorf("the MCP server did not answer within %s", mcpTimeout)
			}
			return nil, fmt.Errorf("the MCP server stopped without answering: %s", firstLine(out, err))
		}
	}
}
