<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Fleet\RenderHost;
use App\Fleet\SignInLink;
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
            // Signed in as the app's user `as`: a one-time sign-in link per
            // screen size (App\Fleet\SignInLink), each opened by the renderer.
            'as' => ['nullable', 'integer', 'min:1'],
            'guard' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,30}$/'],
        ]);
        $path = $data['path'] ?? '/';
        $url = 'https://'.$site->domain.$path;
        $target = $url;
        if (isset($data['as'])) {
            $target = array_map(fn () => SignInLink::make($site, (int) $data['as'], $data['guard'] ?? 'web', $path)['url'], [1, 2, 3]);
            Audit::record('site.signin_link', site: $site, detail: ['user' => (int) $data['as'], 'for' => 'screens']);
        }
        if (! app()->runningInConsole()) {
            set_time_limit(180);
        }

        try {
            $shots = AgentClient::for(RenderHost::for($site))->renderShots($target);
        } catch (AgentRefused $e) {
            return response()->json(['ok' => false, 'error' => 'cannot_render', 'hint' => $e->detail['hint'] ?? $e->getMessage()], 422);
        } catch (AgentUnreachable) {
            return response()->json(['ok' => false, 'error' => 'host_unreachable', 'hint' => 'The renderer is not responding. Try again in a minute.'], 503);
        }

        return response()->json(['ok' => true, 'url' => $url, 'signedInAs' => $data['as'] ?? null, 'shots' => $shots]);
    }
}
