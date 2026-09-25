<?php

namespace App\Http\Controllers;

use App\Models\AbuseReport;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Anyone can report a site hosted here (owner's decision, 2026-09-25: free,
 * public sites need a way in for the people who find the bad ones). Saved,
 * and emailed to the owner at once. Only sites we host are accepted; the
 * reporter's address is optional, and their IP is kept only as a keyed hash.
 */
class AbuseReportController extends Controller
{
    public function show(Request $request): View
    {
        return view('report', ['reasons' => AbuseReport::REASONS, 'url' => (string) $request->query('url', '')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:http,https', 'max:2048'],
            'reason' => ['required', Rule::in(array_keys(AbuseReport::REASONS))],
            'details' => ['nullable', 'string', 'max:4000'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);
        $site = $this->hostedSite((string) parse_url($data['url'], PHP_URL_HOST));
        if (! $site) {
            return back()->withInput()->withErrors(['url' => 'That address is not a site hosted on codeinchrome.']);
        }

        $report = AbuseReport::create([
            'site_id' => $site->id, 'url' => $data['url'], 'reason' => $data['reason'],
            'details' => $data['details'] ?? null, 'reporter_email' => $data['email'] ?? null,
            // Per network (an IPv6 /64), so one person cannot be three reporters.
            'reporter_hash' => hash_hmac('sha256', \App\Auth\ClientNet::key($request->ip()), (string) config('app.key')),
        ]);
        $this->tellOwner($report, $site);

        // A report is acted on, not only filed (the second security audit,
        // 2026-09-25; Cloudflare expects a response within 24 hours):
        // three different reporters in a day pause the site - a pause, never
        // a ban, so a rival cannot delete anyone's work - and the site is
        // checked at once, as the hourly link check would.
        $reporters = AbuseReport::where('site_id', $site->id)->where('created_at', '>=', now()->subDay())
            ->distinct()->count('reporter_hash');
        if ($reporters >= 3 && $site->status === 'live' && app(\App\Fleet\Suspension::class)->pause($site, 'abuse')) {
            app(\App\Abuse\Enforcer::class)->review($site, "paused: $reporters different people reported it in 24 hours. "
                ."Look, then: php artisan abuse:resume {$site->site_id}   or   php artisan abuse:ban ".($site->user?->email ?? '<email>'));
        }
        dispatch(function () use ($site) {
            $r = app(\App\Abuse\LinkScanner::class)->scan($site);
            if ($r['ban'] !== []) {
                app(\App\Abuse\Enforcer::class)->ban($site->user, "After an abuse report, the site's pages:\n".implode("\n", $r['ban']));
            } elseif ($r['review'] !== []) {
                app(\App\Abuse\Enforcer::class)->review($site, "after an abuse report, its pages: ".implode('; ', $r['review']));
            }
        })->afterResponse();

        return redirect()->route('report')->with('status', 'Thank you. The report was received and will be looked at.');
    }

    private function hostedSite(string $host): ?Site
    {
        $host = strtolower($host);
        if ($host === '') {
            return null;
        }

        return Site::where('domain', $host)->first()
            ?? SiteDomain::where('domain', $host)->first()?->site;
    }

    private function tellOwner(AbuseReport $report, Site $site): void
    {
        $to = config('fleet.owner_notify_email') ?: config('fleet.admin_emails');
        if (! $to || ! config('fleet.mail_enabled')) {
            return;
        }
        $body = "Site: https://{$site->domain}\nReported address: {$report->url}\nReason: ".AbuseReport::REASONS[$report->reason]
            ."\nReporter: ".($report->reporter_email ?: 'anonymous')."\nAccount: ".($site->user?->email ?? '?')
            ."\n\n".($report->details ?: '(no details)')
            ."\n\nTake it down: php artisan abuse:ban ".($site->user?->email ?? '<email>').' --reason="..."';
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject("[codeinchrome] Abuse report ({$report->reason}): {$site->domain}"));
        } catch (\Throwable $e) {
            Log::error('abuse report email failed', ['report' => $report->id, 'error' => $e->getMessage()]);
        }
    }
}
