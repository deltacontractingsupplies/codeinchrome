<?php

namespace App\Http\Controllers;

use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Fleet\RenderHost;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A page of the site at phone, tablet and desktop size, side by side in the
 * editor (owner, 2026-09-26: every page must work on every device): one call,
 * one look. Rendered on another host than the site's (RenderHost), in the
 * link scanner's locked-down browser (agent render.go).
 */
class ScreensController extends Controller
{
    public function show(Request $request, Site $site): JsonResponse
    {
        abort_unless($site->user_id === $request->user()->id, 404);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');
        $data = $request->validate([
            // A path on the site only: never another address.
            'path' => ['nullable', 'string', 'max:2000', 'regex:~^/(?![/\\\\])[^\s]*$~'],
        ]);
        $url = 'https://'.$site->domain.($data['path'] ?? '/');
        if (! app()->runningInConsole()) {
            set_time_limit(180);
        }

        try {
            $shots = AgentClient::for(RenderHost::for($site))->renderShots($url);
        } catch (AgentRefused $e) {
            return response()->json(['ok' => false, 'error' => 'cannot_render', 'hint' => $e->detail['hint'] ?? $e->getMessage()], 422);
        } catch (AgentUnreachable) {
            return response()->json(['ok' => false, 'error' => 'host_unreachable', 'hint' => 'The renderer is not responding. Try again in a minute.'], 503);
        }

        return response()->json(['ok' => true, 'url' => $url, 'shots' => $shots]);
    }
}
