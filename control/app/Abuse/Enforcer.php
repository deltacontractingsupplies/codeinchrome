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
        // Other accounts signed up from the same network (ClientNet::signal):
        // named for the owner to judge, never banned automatically.
        $related = $user->signup_net ? User::where('signup_net', $user->signup_net)->whereKeyNot($user->getKey())
            ->where('created_at', '>=', now()->subDays(90))->limit(10)->pluck('email')->all() : [];
        // And from the same browser (App\Auth\Device), by either hash.
        $sameBrowser = [];
        foreach (array_filter([$user->signup_device, $user->last_device]) as $device) {
            $sameBrowser = array_merge($sameBrowser, \App\Auth\Device::sameBrowser($device)->whereKeyNot($user->getKey())->limit(10)->pluck('email')->all());
        }
        $sameBrowser = array_values(array_unique($sameBrowser));
        if ($first && ! $isTest) {
            $this->tellOwner("Account banned: {$user->email}", "Account: {$user->email}\nSites taken down: ".(implode(', ', $down) ?: 'none')."\n\n$reason\n\n"
                .($related ? 'Accounts signed up from the same network in the last 90 days: '.implode(', ', $related)."\n\n" : '')
                .($sameBrowser ? 'Accounts made or used in the same browser: '.implode(', ', $sameBrowser)."\n\n" : '')
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

    /** An account that may not create sites until a person has looked. */
    public function holdForReview(User $user, string $why): void
    {
        Audit::record('abuse.held', $user, actor: null, detail: ['why' => $why]);
        $this->tellOwner("Account held for review: {$user->email}", "Account: {$user->email}\nWhy: $why\n\n"
            .'It can sign in but cannot create sites. If it is fine, nothing needs doing once the other ban is 30 days old; '
            ."to clear it now: php artisan tinker --execute=\"App\\Models\\User::where('email','{$user->email}')->update(['signup_net' => null]);\"\n"
            .'To take it down: php artisan abuse:ban '.$user->email);
    }

    /**
     * An account paused by an automatic check (CPU, scanning) a second time
     * within 30 days is banned: a pause alone let it start over each time.
     */
    public function escalateRepeatPause(User $user, string $what): void
    {
        // An EARLIER pause: two sites paused in the same run are one incident.
        $earlier = \App\Models\AuditEvent::query()->where('account_id', $user->id)
            ->whereIn('action', ['abuse.cpu_paused', 'abuse.scan_paused'])
            ->whereBetween('created_at', [now()->subDays(30), now()->subMinutes(10)])->count();
        if ($earlier >= 1) {
            $this->ban($user, "Paused by the automatic checks again within 30 days ($earlier time(s) before), this time for $what.");
        }
    }

    /** Something a person should look at, with nothing done to the site. */
    public function review(Site $site, string $what): void
    {
        Audit::record('abuse.review', $site->user, $site, detail: ['what' => mb_substr($what, 0, 500)]);
        $this->tellOwner("For review: {$site->domain}", "https://{$site->domain}: $what\nAccount: ".($site->user?->email ?? '?')
            ."\n\nNothing was done to the site. To take the account down: php artisan abuse:ban ".($site->user?->email ?? '<email>'));
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
