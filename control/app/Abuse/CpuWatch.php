<?php

namespace App\Abuse;

use App\Audit\Audit;
use App\Fleet\Suspension;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Crypto mining - banned outright by Hetzner - looks like a container held at
 * its CPU limit for hours. Two readings of the site's cumulative CPU counter
 * (agent sites/cpu.go) give its real use over the interval.
 *
 * Two rules, over a history kept per SITE (not per container start):
 *   - continuously at HOT_SHARE or more: alert at ALERT_AFTER minutes, pause
 *     at SUSPEND_AFTER. A restart within RESTART_GAP minutes continues the
 *     streak: restarting the container (a PHP setting change does it) used to
 *     reset it, so a miner could restart itself out of every pause;
 *   - on AVERAGE at SUSTAINED_SHARE or more: alert over SUSTAINED_ALERT hours,
 *     pause over SUSTAINED_SUSPEND hours. A miner throttled to 85%, or one
 *     that dips below 90% once an hour, never tripped the first rule
 *     (the second security audit, 2026-09-25).
 * A pause is not a ban - a busy app can run hot too - and `abuse:resume`
 * brings the site back.
 */
class CpuWatch
{
    public const HOT_SHARE = 0.9;

    public const ALERT_AFTER = 30;

    public const SUSPEND_AFTER = 120;

    public const RESTART_GAP = 15;

    public const SUSTAINED_SHARE = 0.75;

    public const SUSTAINED_ALERT = 2;

    public const SUSTAINED_SUSPEND = 6;

    /** A window counts only if readings cover this much of it. */
    private const COVERAGE = 0.8;

    public function __construct(private Suspension $suspension) {}

    /**
     * @param  array<int, array{site: string, usage_usec: int, quota_cpus: float, started?: string}>  $readings
     * @return array<string, float> each site's share of its limit over the last interval
     */
    public function observe(array $readings, Carbon $at): array
    {
        $shares = [];
        $now = $at->getTimestamp();
        foreach ($readings as $r) {
            $site = Site::where('site_id', $r['site'])->where('status', 'live')->first();
            if (! $site || ($r['quota_cpus'] ?? 0) <= 0) {
                continue;
            }
            $key = "abuse.cpu.{$site->site_id}";
            $prev = Cache::get($key) ?? [];
            $state = [
                'usage' => (int) $r['usage_usec'], 'at' => $now, 'started' => $r['started'] ?? '',
                'hot_since' => null, 'alerted' => $prev['alerted'] ?? false,
                // [timestamp, seconds covered, share] for the last SUSTAINED_SUSPEND hours.
                'history' => array_values(array_filter($prev['history'] ?? [], fn ($h) => $h[0] > $now - self::SUSTAINED_SUSPEND * 3600)),
            ];
            $sameContainer = ($prev['started'] ?? null) === $state['started'];
            $share = null;
            if ($prev && $sameContainer && $now > $prev['at'] && $state['usage'] >= $prev['usage']) {
                $share = ($state['usage'] - $prev['usage']) / (($now - $prev['at']) * 1e6) / $r['quota_cpus'];
                $shares[$site->site_id] = round($share, 3);
                $state['history'][] = [$now, $now - $prev['at'], $share];
            }

            if ($share !== null && $share >= self::HOT_SHARE) {
                $state['hot_since'] = $prev['hot_since'] ?? $prev['at'];
            } elseif ($share === null && $prev && ! $sameContainer && ($now - $prev['at']) <= self::RESTART_GAP * 60) {
                // A restart: no reading for this interval, but the streak goes on.
                $state['hot_since'] = $prev['hot_since'] ?? null;
            }

            $streak = $state['hot_since'] ? ($now - $state['hot_since']) / 60 : 0;
            $avg2 = $this->average($state['history'], $now, self::SUSTAINED_ALERT * 3600);
            $avg6 = $this->average($state['history'], $now, self::SUSTAINED_SUSPEND * 3600);

            if ($streak >= self::SUSPEND_AFTER || ($avg6 !== null && $avg6 >= self::SUSTAINED_SHARE)) {
                $this->suspend($site, $streak >= self::SUSPEND_AFTER
                    ? 'ran at its CPU limit for '.round($streak).' minutes'
                    : 'averaged '.round($avg6 * 100).'% of its CPU limit for '.self::SUSTAINED_SUSPEND.' hours');
                Cache::forget($key);

                continue;
            }
            $hot = $streak >= self::ALERT_AFTER || ($avg2 !== null && $avg2 >= self::SUSTAINED_SHARE);
            if ($hot && ! $state['alerted']) {
                $what = $streak >= self::ALERT_AFTER
                    ? 'has used '.round(($share ?? self::HOT_SHARE) * 100).'% of its CPU limit for '.round($streak).' minutes'
                    : 'has averaged '.round($avg2 * 100).'% of its CPU limit for '.self::SUSTAINED_ALERT.' hours';
                $this->tellOwner("Possible crypto mining: {$site->domain}", "https://{$site->domain} $what.\nAccount: ".($site->user?->email ?? '?')
                    ."\n\nIt is paused automatically at ".self::SUSPEND_AFTER.' minutes at its limit, or '.self::SUSTAINED_SUSPEND
                    .' hours on average. To take the account down now:'."\n"
                    .'php artisan abuse:ban '.($site->user?->email ?? '<email>').' --reason="crypto mining"');
                $state['alerted'] = true;
            } elseif (! $hot && ($avg2 === null || $avg2 < self::SUSTAINED_SHARE / 2)) {
                $state['alerted'] = false; // a new episode may alert again
            }
            Cache::put($key, $state, now()->addHours(self::SUSTAINED_SUSPEND + 1));
        }

        return $shares;
    }

    /** The mean share over the last $window seconds, or null if the readings cover too little of it. */
    private function average(array $history, int $now, int $window): ?float
    {
        $seconds = 0;
        $weighted = 0.0;
        foreach ($history as [$at, $covered, $share]) {
            if ($at > $now - $window) {
                $seconds += $covered;
                $weighted += $share * $covered;
            }
        }

        return $seconds >= $window * self::COVERAGE ? $weighted / $seconds : null;
    }

    private function suspend(Site $site, string $why): void
    {
        $paused = $this->suspension->pause($site, 'cpu');
        Audit::record('abuse.cpu_paused', $site->user, $site, detail: ['why' => $why, 'paused' => $paused]);
        Log::warning('site paused for sustained CPU', ['site' => $site->site_id, 'why' => $why, 'paused' => $paused]);
        $this->tellOwner("Site paused for sustained CPU: {$site->domain}", "https://{$site->domain} $why and was paused (".($paused ? 'done' : 'NOT done: host unreachable').")."
            ."\nAccount: ".($site->user?->email ?? '?')."\n\nIf it is legitimate: php artisan abuse:resume {$site->site_id}\n"
            .'If it is mining: php artisan abuse:ban '.($site->user?->email ?? '<email>').' --reason="crypto mining"');
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
            Log::error('cpu watch email failed', ['error' => $e->getMessage()]);
        }
    }
}
