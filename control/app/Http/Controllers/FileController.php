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
 * The panel's file operations, proxied to the site's host.
 *
 * This controller's entire job is authorisation. Containment - that a path
 * cannot escape the site - is the agent's job and is enforced there, on the
 * resolved path. Doing it here as well would be a second implementation of
 * the same rule that could drift from the first; the agent must never depend
 * on this layer having checked anything.
 *
 * What this layer owns, and the agent cannot know, is WHOSE site it is.
 */
class FileController extends Controller
{
    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $path = (string) $request->query('path', '/');

        return $this->attempt(function () use ($site, $path, $request) {
            $agent = AgentClient::for($site->host);

            if ($request->boolean('read')) {
                return ['path' => $path] + $agent->readFileWithRevision($site->site_id, $path);
            }

            return ['listing' => $agent->listFiles($site->site_id, $path)];
        });
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $data = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            // `nullable`, not `string`. Laravel's ConvertEmptyStringsToNull
            // middleware turns "" into null before validation ever runs, so a
            // `string` rule rejects an empty body - and emptying a file is a
            // perfectly ordinary edit. Coerced back below.
            'content' => ['present', 'nullable', 'string', 'max:2097152'],
            'expect' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->attempt(fn () => AgentClient::for($site->host)
            ->writeFile($site->site_id, $data['path'], $data['content'] ?? '', $data['expect'] ?? ''));
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSite($request, $site);

        $path = (string) $request->query('path', '');
        if ($path === '') {
            return response()->json(['ok' => false, 'error' => 'no_path', 'hint' => 'pass ?path='], 422);
        }

        return $this->attempt(function () use ($site, $path) {
            $deleted = AgentClient::for($site->host)->deleteFile($site->site_id, $path);
            // Deletes are recorded; saves are not (every keystroke-save would
            // bury everything else).
            Audit::record('file.deleted', site: $site, detail: ['path' => $path]);

            return ['deleted' => $deleted];
        });
    }

    /**
     * 404, not 403. A 403 confirms the site exists and belongs to someone
     * else, which lets anyone enumerate which names are taken and by whom.
     */
    protected function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 404);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');
    }

    /**
     * Keeps the agent's two failure kinds apart all the way to the browser.
     * "The host could not be reached" and "the host refused this" need
     * different responses from the panel: one is worth retrying, the other
     * never is until the request changes.
     */
    protected function attempt(callable $work): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $work());
        } catch (AgentRefused $e) {
            $error = $e->detail['error'] ?? 'refused';

            return response()->json([
                'ok' => false,
                'error' => $error,
                'hint' => $e->detail['hint'] ?? $e->getMessage(),
                // 409 for a stale save, so a client can tell "someone else
                // changed this" from "this request is invalid" by status alone.
            ], $error === 'conflict' ? 409 : 422);
        } catch (AgentUnreachable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'host_unreachable',
                'hint' => 'The host is not responding. Nothing was changed, but its state is unknown.',
            ], 503);
        }
    }
}
