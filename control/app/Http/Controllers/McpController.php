<?php

namespace App\Http\Controllers;

use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Laravel Boost's MCP tools for the editor's agent, answered by the site's
 * own application inside its container. Only tools/list and tools/call; the
 * params pass through as objects, so {} stays {}.
 */
class McpController extends Controller
{
    public function call(Request $request, Site $site): Response|JsonResponse
    {
        abort_unless($site->user_id === $request->user()->id, 404);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');
        $body = json_decode($request->getContent());
        $method = is_object($body) ? ($body->method ?? null) : null;
        $params = is_object($body) ? ($body->params ?? new \stdClass) : null;
        if (! in_array($method, ['tools/list', 'tools/call'], true) || ! is_object($params)) {
            return response()->json(['ok' => false, 'error' => 'bad_request', 'hint' => 'body must be {method: "tools/list" | "tools/call", params: {...}}'], 422);
        }

        try {
            $result = AgentClient::for($site->host)->mcp($site->site_id, $method, json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (AgentRefused $e) {
            return response()->json(['ok' => false] + $e->detail, ($e->detail['error'] ?? '') === 'boost_not_installed' ? 409 : 422);
        } catch (AgentUnreachable) {
            return response()->json(['ok' => false, 'error' => 'host_unreachable', 'hint' => 'The site\'s server is not responding.'], 503);
        }

        return response('{"ok":true,"result":'.$result.'}', 200, ['Content-Type' => 'application/json']);
    }
}
