<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The editor's database browser, proxied to the site's host.
 *
 * Like FileController, this layer's only job is WHOSE site it is. What a query
 * may do is decided by MySQL: it runs as the site's own user, so the browser
 * can do nothing the site's own code could not already do.
 */
class DatabaseController extends Controller
{
    public function tables(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        return $this->attempt(fn () => AgentClient::for($site->host)->dbTables($site->site_id));
    }

    public function query(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $data = $request->validate([
            'sql' => ['required', 'string', 'max:102400'],
            'write' => ['sometimes', 'boolean'],
        ]);

        return $this->attempt(function () use ($site, $data) {
            $result = AgentClient::for($site->host)->dbQuery($site->site_id, $data['sql'], (bool) ($data['write'] ?? false));
            // Writes only: reads are not an event anyone needs to reconstruct.
            if (($result['mode'] ?? '') === 'write') {
                Audit::record('db.write', site: $site, detail: [
                    'sql' => mb_substr($data['sql'], 0, 500), 'rows' => $result['rowsAffected'] ?? null,
                ]);
            }

            return ['result' => $result];
        });
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 404);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');
    }

    private function attempt(callable $work): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $work());
        } catch (AgentRefused $e) {
            $error = $e->detail['error'] ?? 'refused';

            return response()->json([
                'ok' => false,
                'error' => $error,
                'hint' => $e->detail['hint'] ?? $e->getMessage(),
            ], $error === 'needs_write' ? 409 : 422);
        } catch (AgentUnreachable) {
            return response()->json([
                'ok' => false,
                'error' => 'host_unreachable',
                'hint' => 'The host is not responding. Its state is unknown.',
            ], 503);
        }
    }
}
