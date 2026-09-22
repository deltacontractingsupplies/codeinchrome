<?php

namespace App\Http\Controllers;

use App\Fleet\Provisioner;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function index(Request $request): View
    {
        return view('sites.index', [
            'sites' => $request->user()->sites()->latest()->get(),
            'plan' => $request->user()->planConfig(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['site_id' => ['required', 'string', 'max:40']]);
        $siteId = strtolower(trim($request->input('site_id')));

        // Validated here for a good message, and again inside the Provisioner,
        // and once more by the agent. Each layer must hold on its own: this
        // one exists for the customer, the others exist for the system.
        if ($error = Site::validId($siteId)) {
            return back()->withInput()->withErrors(['site_id' => $error]);
        }

        try {
            $site = Provisioner::make()->provision($request->user(), $siteId);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['site_id' => $e->getMessage()]);
        }

        return redirect()->route('dashboard')->with(
            'status',
            // Not "your site is live": the certificate is issued on the first
            // request, so it is not proven at this moment.
            "{$site->domain} is building. The certificate is issued on the first request, so give it a few seconds."
        );
    }

    public function destroy(Request $request, Site $site): RedirectResponse
    {
        // Ownership, not just authentication. Without this any signed-in user
        // could delete any site by id.
        abort_unless($site->user_id === $request->user()->id, 404);

        try {
            $parts = Provisioner::make()->destroy($site);
        } catch (\Throwable $e) {
            return back()->with('error', "Could not fully remove {$site->domain}: {$e->getMessage()}");
        }

        $failed = array_keys(array_filter($parts, fn ($state) => $state === 'failed'));

        return redirect()->route('dashboard')->with(
            $failed ? 'error' : 'status',
            $failed
                ? "{$site->domain} was only partially removed (" . implode(', ', $failed) . '). It is kept in your list until that is resolved.'
                : "{$site->domain} was removed."
        );
    }
}
