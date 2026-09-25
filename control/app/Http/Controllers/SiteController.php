<?php

namespace App\Http\Controllers;

use App\Fleet\Provisioner;
use App\Fleet\Stock;
use App\Fleet\Suspension;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function index(Request $request): View
    {
        $sites = $request->user()->sites()->latest()->get();

        return view('sites.index', [
            'sites' => $sites,
            'checks' => \App\Models\Monitor::whereIn('key', $sites->map(fn ($s) => "site:{$s->site_id}"))->get()->keyBy('key'),
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
            // Not "your site is live", and not "a few seconds" either.
            //
            // The certificate is issued on the first request, so TLS is not
            // proven at this moment. And a brand-new address reaches different
            // networks at very different speeds: measured on 2026-09-22, a new
            // record was answered by 8.8.8.8 within 5 seconds, by 1.1.1.1 after
            // 31, and by a mobile carrier's resolver after 382 - over six
            // minutes. Promising seconds would be true for some customers and
            // plainly false for others.
            "{$site->domain} is building. It is usually reachable within a minute, but on some networks a brand-new address can take a few minutes to appear."
        );
    }

    public function edit(Request $request, Site $site): View
    {
        // 404 rather than 403, for the same reason as the file API: a 403
        // confirms the name exists and belongs to somebody else.
        abort_unless($site->user_id === $request->user()->id, 404);
        abort_unless($site->status === 'live', 409, 'This site is not live yet.');

        $nonce = base64_encode(random_bytes(18));
        $request->attributes->set('csp_style_nonce', $nonce);

        return view('sites.editor', ['site' => $site, 'cspNonce' => $nonce]);
    }

    /**
     * One click brings back a site paused for 30 days without visits or edits
     * (sites:idle). Only that pause: a trial, CPU or abuse pause has its own
     * way back, and this must never be a way round one of them.
     */
    public function wake(Request $request, Site $site, Suspension $suspension): RedirectResponse
    {
        abort_unless($site->user_id === $request->user()->id, 404);
        if ($site->status !== 'suspended' || $site->paused_reason !== 'idle') {
            return back()->with('error', "{$site->domain} is not paused for being idle.");
        }
        if ($request->user()->trialExpired()) {
            return redirect()->route('billing')->with('error', 'Your free trial has ended. Upgrade to Starter to bring your site back.');
        }
        // Free sites only take room paying customers have left (Stock), the
        // same rule as creating one.
        if (! $request->user()->isPaid() && ! app(Stock::class)->siteFits($request->user()->plan ?: 'free')) {
            return back()->with('error', 'There is no room for free sites right now. Please try again later.');
        }
        if (! $suspension->resume($site)) {
            return back()->with('error', "{$site->domain} could not be brought back just now. Please try again in a minute.");
        }
        $site->update(['last_worked_at' => now(), 'idle_warned_at' => null]);

        return redirect()->route('dashboard')->with('status', "{$site->domain} is back.");
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
