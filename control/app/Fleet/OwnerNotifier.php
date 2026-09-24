<?php

namespace App\Fleet;

use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the owner about every new site and every domain added (owner's
 * request, 2026-09-25): sites are free and public, so each one gets a look.
 * config('fleet.owner_notify_email'); unset (development, tests) sends
 * nothing. The platform's own test accounts are left out - the e2e suite
 * makes dozens of sites a run.
 *
 * Never fails the action that triggered it: a mail that cannot go out is
 * logged, and the site is still created.
 */
class OwnerNotifier
{
    public static function newSite(Site $site): void
    {
        self::send("New site: {$site->domain}", $site, [
            'Site' => 'https://'.$site->domain,
            'Created' => (string) $site->created_at,
        ]);
    }

    public static function newDomain(SiteDomain $domain): void
    {
        $site = $domain->site;
        self::send("New domain: {$domain->domain} on {$site->domain}", $site, [
            'Domain' => $domain->domain,
            'Site' => 'https://'.$site->domain,
        ]);
    }

    /** @param array<string, string> $facts */
    private static function send(string $subject, Site $site, array $facts): void
    {
        $to = config('fleet.owner_notify_email');
        $account = $site->user;
        if (! $to || ! config('fleet.mail_enabled') || ! $account) {
            return;
        }
        foreach (config('showcase.explore.exclude_email_suffixes', []) as $suffix) {
            if (str_ends_with($account->email, $suffix)) {
                return;
            }
        }
        $facts += [
            'Account' => $account->email,
            'Plan' => $account->plan,
            'Signed up' => (string) $account->created_at,
        ];
        $body = collect($facts)->map(fn ($v, $k) => "$k: $v")->implode("\n")
            ."\n\nCheck it, and take it down from the operator page if it breaks the terms:\nhttps://app.codeinchrome.com/status";
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject('[codeinchrome] '.$subject));
        } catch (\Throwable $e) {
            Log::error('owner notification failed', ['subject' => $subject, 'error' => $e->getMessage()]);
        }
    }
}
