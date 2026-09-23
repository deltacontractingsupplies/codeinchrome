<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\AgentRefused;
use App\Fleet\AgentUnreachable;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A site's settings: what it runs beside its web server.
 */
class SiteSettingsController extends Controller
{
    private function authorizeSite(Request $request, Site $site): void
    {
        // 404, not 403: a stranger learns nothing about whether the site exists.
        abort_unless($site->user_id === $request->user()->id, 404);
    }

    public function show(Request $request, Site $site): View
    {
        $this->authorizeSite($request, $site);

        return view('sites.settings', [
            'site' => $site,
            'allowed' => (bool) ($request->user()->planConfig()['background'] ?? false),
        ]);
    }

    public function background(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');
        if (! ($request->user()->planConfig()['background'] ?? false)) {
            return back()->with('error', 'Background processes come with the paid plans.');
        }
        $want = [
            'queue' => $request->boolean('queue'),
            'scheduler' => $request->boolean('scheduler'),
            'reverb' => $request->boolean('reverb'),
        ];

        try {
            $applied = AgentClient::for($site->host)->setBackground($site->site_id, ...array_values($want));
        } catch (AgentRefused|AgentUnreachable $e) {
            return back()->with('error', 'The change was not applied: '.($e instanceof AgentRefused ? ($e->detail['hint'] ?? $e->getMessage()) : 'the host is not responding.'));
        }
        $site->update($want);
        Audit::record('site.background', site: $site, detail: $want);

        return back()->with('status', ($applied['changed'] ?? '') === 'no'
            ? 'Nothing changed.'
            : 'Applied. The site restarted with its new processes'.(isset($applied['workers']) ? " ({$applied['workers']} web workers)." : '.'));
    }
}
