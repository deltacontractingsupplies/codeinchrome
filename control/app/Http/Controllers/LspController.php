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
 * The editor's PHP language server (Phpactor, in the site's own container),
 * proxied for the site's owner. Messages are JSON-RPC and pass through
 * byte-faithful: decoded as objects, not arrays, so an empty {} stays {} -
 * turned into [] it breaks the protocol.
 */
class LspController extends Controller
{
    private const SESSION = '/^[a-z0-9]{16,40}$/';

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 404);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');
    }

    public function exchange(Request $request, Site $site): Response|JsonResponse
    {
        $this->authorizeSite($request, $site);
        $body = json_decode($request->getContent());
        $session = is_object($body) ? ($body->session ?? null) : null;
        $messages = is_object($body) ? ($body->messages ?? null) : null;
        if (! is_string($session) || ! preg_match(self::SESSION, $session) || ! is_array($messages) || count($messages) > 200) {
            return response()->json(['ok' => false, 'error' => 'bad_request', 'hint' => 'body must be {session, messages: [...] (at most 200), waitMs}'], 422);
        }
        foreach ($messages as $m) {
            if (! is_object($m) || ($m->jsonrpc ?? null) !== '2.0') {
                return response()->json(['ok' => false, 'error' => 'bad_request', 'hint' => 'every message must be a JSON-RPC 2.0 object'], 422);
            }
        }
        $wait = max(0, min(10000, (int) ($body->waitMs ?? 0)));

        try {
            $out = AgentClient::for($site->host)->lsp($site->site_id, $session,
                json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $wait);
        } catch (AgentRefused $e) {
            return response()->json(['ok' => false] + $e->detail, 409);
        } catch (AgentUnreachable) {
            return response()->json(['ok' => false, 'error' => 'host_unreachable', 'hint' => 'The site\'s server is not responding.'], 503);
        }

        return response('{"ok":true,"messages":'.$out.'}', 200, ['Content-Type' => 'application/json']);
    }

    public function close(Request $request, Site $site, string $session): JsonResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless(preg_match(self::SESSION, $session) === 1, 404);
        try {
            AgentClient::for($site->host)->lspClose($site->site_id, $session);
        } catch (AgentRefused|AgentUnreachable) {
            // It is reaped after ten idle minutes anyway.
        }

        return response()->json(['ok' => true]);
    }
}
