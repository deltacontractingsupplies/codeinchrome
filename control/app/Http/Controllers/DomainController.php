<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\DomainVerifier;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DomainController extends Controller
{
    public function index(Request $request, Site $site): View
    {
        $this->authorizeSite($request, $site);

        return view('sites.domains', [
            'site' => $site,
            'domains' => $site->domains()->orderBy('created_at')->get(),
            'hostIp' => config("fleet.hosts.{$site->host}.ip"),
            'allowed' => (bool) $request->user()->planConfig()['custom_domains'],
        ]);
    }

    public function store(Request $request, Site $site): RedirectResponse
    {
        $this->authorizeSite($request, $site);
        abort_unless($request->user()->planConfig()['custom_domains'], 403, 'Custom domains need a paid plan.');
        if (! config('fleet.custom_domains')) {
            return back()->withInput()->withErrors(['domain' => 'Custom domains are coming soon: they are being moved behind Cloudflare. Your site is live on its codeinchrome.com address meanwhile.']);
        }

        $request->validate(['domain' => ['required', 'string', 'max:255']]);
        [$domain, $error] = SiteDomain::normalise($request->input('domain'));
        if ($error) {
            return back()->withInput()->withErrors(['domain' => $error]);
        }
        if ($site->domains()->count() >= 10) {
            return back()->withErrors(['domain' => 'A site can have at most 10 custom domains.']);
        }

        $existing = SiteDomain::where('domain', $domain)->first();
        if ($existing) {
            // An unverified claim by someone else must not lock a domain away
            // from its real owner forever: after 7 days it can be taken over.
            // A verified one cannot.
            $stale = ! $existing->verified_at && $existing->created_at->lt(now()->subDays(7));
            if (! $stale) {
                return back()->withInput()->withErrors(['domain' => $existing->site_id === $site->id
                    ? 'That domain is already on this site.'
                    : 'That domain is already in use on codeinchrome.']);
            }
            $existing->delete();
        }

        $added = $site->domains()->create(['domain' => $domain, 'token' => bin2hex(random_bytes(20))]);
        Audit::record('domain.added', site: $site, detail: ['domain' => $domain]);
        \App\Fleet\OwnerNotifier::newDomain($added);

        return redirect()->route('domains.index', $site)->with('status', "Add the two DNS records below for $domain, then press Verify.");
    }

    public function verify(Request $request, Site $site, SiteDomain $domain, DomainVerifier $verifier): RedirectResponse
    {
        $this->authorizeDomain($request, $site, $domain);

        $result = $verifier->check($domain, config("fleet.hosts.{$site->host}.ip"));
        $domain->update(['last_check' => $result['message']]);

        if (! $result['ok']) {
            return back()->with('error', "{$domain->domain}: {$result['message']}");
        }

        // Verified: record it, then tell the host. If the host refuses, the
        // verification is rolled back so the record never says "attached"
        // for a domain the site is not actually serving.
        $domain->update(['verified_at' => now()]);
        try {
            $this->syncAliases($site);
        } catch (\Throwable $e) {
            $domain->update(['verified_at' => null, 'last_check' => 'Verified, but the host could not attach it: ' . $e->getMessage()]);

            return back()->with('error', "{$domain->domain} was verified but could not be attached: {$e->getMessage()}");
        }

        Audit::record('domain.verified', site: $site, detail: ['domain' => $domain->domain]);

        return back()->with('status', "{$domain->domain} is attached. Its certificate is issued on the first visit.");
    }

    public function destroy(Request $request, Site $site, SiteDomain $domain): RedirectResponse
    {
        $this->authorizeDomain($request, $site, $domain);

        $wasVerified = (bool) $domain->verified_at;
        DB::transaction(fn () => $domain->delete());
        Audit::record('domain.removed', site: $site, detail: ['domain' => $domain->domain]);

        if ($wasVerified) {
            try {
                $this->syncAliases($site);
            } catch (\Throwable $e) {
                // The row is gone but the host may still serve the name: say
                // so, and the next successful sync will remove it.
                return back()->with('error', "{$domain->domain} was removed from your account, but the host did not confirm it stopped serving it: {$e->getMessage()}");
            }
        }

        return back()->with('status', "{$domain->domain} was removed.");
    }

    /** The host is always sent the complete verified list, never a delta. */
    private function syncAliases(Site $site): void
    {
        AgentClient::for($site->host)->setAliases(
            $site->site_id,
            $site->domains()->whereNotNull('verified_at')->pluck('domain')->all(),
        );
    }

    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($site->user_id === $request->user()->id, 404);
    }

    private function authorizeDomain(Request $request, Site $site, SiteDomain $domain): void
    {
        $this->authorizeSite($request, $site);
        abort_unless($domain->site_id === $site->id, 404);
    }
}
