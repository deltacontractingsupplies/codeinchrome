<?php

namespace App\Http\Controllers;

use App\Abuse\Enforcer;
use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Http\Middleware\BannedAccount;
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
            // Names this run, so its output can be read while it runs (live()).
            'live' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9]{8,64}$/'],
        ]);

        try {
            $result = AgentClient::for($site->host)
                ->runCommand($site->site_id, $data['tool'], $data['args'], (bool) ($data['confirm'] ?? false), $data['live'] ?? null);
            Audit::record('command.run', site: $site, detail: [
                'tool' => $data['tool'], 'args' => $data['args'], 'confirmed' => (bool) ($data['confirm'] ?? false),
                'exit' => $result['result']['exitCode'] ?? null,
            ]);

            return response()->json($result);
        } catch (AgentRefused $e) {
            $error = $e->detail['error'] ?? 'refused';
            if ($error === 'malware') {
                // The command wrote PHP the rules refuse (agent ScanChangedSince):
                // the same as saving it (the second security audit, 2026-09-25).
                app(Enforcer::class)->malware($site, $e->detail['findings'] ?? [], 'written by a command');

                return response()->json(['ok' => false, 'error' => 'malware',
                    'hint' => ($e->detail['hint'] ?? 'Malware refused.').' '.BannedAccount::MESSAGE], 403);
            }

            return response()->json(['ok' => false, 'error' => $error, 'hint' => $e->detail['hint'] ?? $e->getMessage()],
                in_array($error, ['needs_confirm', 'busy'], true) ? 409 : 422);
        } catch (AgentUnreachable $e) {
            return response()->json(['ok' => false, 'error' => 'host_unreachable', 'hint' => $e->getMessage()], 503);
        }
    }

    /** The running command's output so far, from a byte offset: polled while it runs. */
    public function live(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8,64}$/'],
            'from' => ['required', 'integer', 'min:0'],
        ]);

        try {
            return response()->json(['ok' => true] + AgentClient::for($site->host)->commandLive($site->site_id, $data['key'], (int) $data['from']));
        } catch (AgentRefused $e) {
            return response()->json(['ok' => false, 'error' => 'refused', 'hint' => $e->detail['hint'] ?? $e->getMessage()], 422);
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
            // A mark from an earlier read (its file and size): only what was written after it.
            'since' => ['sometimes', 'integer', 'min:0'],
            'file' => ['required_with:since', 'string', 'max:64', 'regex:/^laravel(-\d{4}-\d{2}-\d{2})?\.log$/'],
        ]);

        try {
            return response()->json(['ok' => true, 'log' => AgentClient::for($site->host)
                ->logs($site->site_id, $data['source'], (int) ($data['lines'] ?? 200),
                    isset($data['since']) ? ['since' => (int) $data['since'], 'file' => $data['file']] : [])]);
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
