<?php

namespace App\Http\Controllers;

use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Artisan/composer commands and logs for the editor. What may run is the agent's allow-list. */
class ConsoleController extends Controller
{
    public function run(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate([
            'tool' => ['required', 'in:artisan,composer'],
            'args' => ['required', 'array', 'min:1', 'max:20'],
            'args.*' => ['string', 'max:200'],
            'confirm' => ['sometimes', 'boolean'],
        ]);

        try {
            return response()->json(AgentClient::for($site->host)
                ->runCommand($site->site_id, $data['tool'], $data['args'], (bool) ($data['confirm'] ?? false)));
        } catch (AgentRefused $e) {
            $error = $e->detail['error'] ?? 'refused';

            return response()->json(['ok' => false, 'error' => $error, 'hint' => $e->detail['hint'] ?? $e->getMessage()],
                in_array($error, ['needs_confirm', 'busy'], true) ? 409 : 422);
        } catch (AgentUnreachable $e) {
            return response()->json(['ok' => false, 'error' => 'host_unreachable', 'hint' => $e->getMessage()], 503);
        }
    }

    public function logs(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate([
            'source' => ['required', 'in:app,access,container'],
            'lines' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            return response()->json(['ok' => true, 'log' => AgentClient::for($site->host)
                ->logs($site->site_id, $data['source'], (int) ($data['lines'] ?? 200))]);
        } catch (AgentRefused $e) {
            return response()->json(['ok' => false, 'error' => 'logs_unavailable', 'hint' => $e->detail['hint'] ?? $e->getMessage()], 422);
        } catch (AgentUnreachable $e) {
            return response()->json(['ok' => false, 'error' => 'host_unreachable', 'hint' => $e->getMessage()], 503);
        }
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 404);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');
    }
}
