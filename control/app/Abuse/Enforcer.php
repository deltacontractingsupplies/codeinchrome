<?php

namespace App\Abuse;

use App\Audit\Audit;
use App\Fleet\Suspension;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Malware or encrypted PHP on a site (owner's decision, 2026-09-25: every
 * site is free, so there is no appeal to wait for): the account is banned and
 * every one of its sites taken down at once. The owner is emailed what was
 * found, and can reverse it with `php artisan abuse:unban`.
 *
 * Nothing on the sites' disks or databases is deleted: that is evidence, and
 * a mistaken ban must be reversible.
 */
class Enforcer
{
    public function __construct(private Suspension $suspension) {}

    /** @param list<array{path?: string, kind?: string, detail?: string}> $findings */
    public function malware(Site $site, array $findings, string $source): void
    {
        $user = $site->user;
        if (! $user) {
            return;
        }
        $lines = collect($findings)->take(20)->map(fn ($f) => sprintf('%s  %s  (%s)', $f['kind'] ?? '?', $f['path'] ?? '?', $f['detail'] ?? ''))->all();
        $this->ban($user, "Malware or obfuscated code on {$site->domain} ($source):\n".implode("\n", $lines));
    }

    public function ban(User $user, string $reason): void
    {
        $first = $user->banned_at === null;
        $user->forceFill(['banned_at' => $user->banned_at ?? now(), 'banned_reason' => $reason])->save();

        $down = [];
        foreach ($user->sites()->where('status', 'live')->get() as $site) {
            $down[] = $site->domain.($this->suspension->pause($site, 'abuse') ? '' : ' (NOT taken down: host unreachable - retried by abuse:scan)');
        }
        Audit::record('abuse.banned', $user, actor: null, detail: ['reason' => mb_substr($reason, 0, 500), 'sites' => $down]);
        Log::warning('account banned', ['user' => $user->id, 'sites' => $down]);

        // The platform's own test accounts (the e2e suite bans one each run)
        // are not news for the owner.
        $isTest = collect(config('showcase.explore.exclude_email_suffixes', []))->contains(fn ($s) => str_ends_with($user->email, $s));
        if ($first && ! $isTest) {
            $this->tellOwner("Account banned: {$user->email}", "Account: {$user->email}\nSites taken down: ".(implode(', ', $down) ?: 'none')."\n\n$reason\n\n"
                ."Nothing was deleted. To reverse this: php artisan abuse:unban {$user->email}");
        }
    }

    public function unban(User $user): array
    {
        $user->forceFill(['banned_at' => null, 'banned_reason' => null])->save();
        $back = [];
        foreach ($user->sites()->where('status', 'suspended')->get() as $site) {
            if ($this->suspension->resume($site)) {
                $back[] = $site->domain;
            }
        }
        Audit::record('abuse.unbanned', $user, actor: null, detail: ['sites' => $back]);

        return $back;
    }

    private function tellOwner(string $subject, string $body): void
    {
        $to = config('fleet.owner_notify_email') ?: config('fleet.admin_emails');
        if (! $to || ! config('fleet.mail_enabled')) {
            return;
        }
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject('[codeinchrome] '.$subject));
        } catch (\Throwable $e) {
            Log::error('ban notification failed', ['error' => $e->getMessage()]);
        }
    }
}
