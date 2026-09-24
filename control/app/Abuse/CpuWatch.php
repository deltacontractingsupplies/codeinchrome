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
 * A site at HOT_SHARE of its limit or more, continuously:
 *   - for ALERT_AFTER minutes: the owner is emailed, once
 *   - for SUSPEND_AFTER minutes: the site is paused (not the account banned -
 *     a busy app can run hot too), the owner told, and `abuse:resume` brings
 *     it back
 */
class CpuWatch
{
    public const HOT_SHARE = 0.9;

    public const ALERT_AFTER = 30;

    public const SUSPEND_AFTER = 120;

    public function __construct(private Suspension $suspension) {}

    /**
     * @param  array<int, array{site: string, usage_usec: int, quota_cpus: float, started?: string}>  $readings
     * @return array<string, float> each site's share of its limit over the last interval
     */
    public function observe(array $readings, Carbon $at): array
    {
        $shares = [];
        foreach ($readings as $r) {
            $site = Site::where('site_id', $r['site'])->where('status', 'live')->first();
            if (! $site || ($r['quota_cpus'] ?? 0) <= 0) {
                continue;
            }
            $key = "abuse.cpu.{$site->site_id}";
            $prev = Cache::get($key);
            $state = ['usage' => (int) $r['usage_usec'], 'at' => $at->getTimestamp(), 'started' => $r['started'] ?? '',
                'hot_since' => null, 'alerted' => false];
            $share = null;
            if ($prev && $prev['started'] === $state['started'] && $at->getTimestamp() > $prev['at'] && $state['usage'] >= $prev['usage']) {
                $share = ($state['usage'] - $prev['usage']) / (($at->getTimestamp() - $prev['at']) * 1e6) / $r['quota_cpus'];
                $shares[$site->site_id] = round($share, 3);
            }
            if ($share !== null && $share >= self::HOT_SHARE) {
                $state['hot_since'] = $prev['hot_since'] ?? $prev['at'];
                $state['alerted'] = $prev['alerted'] ?? false;
                $minutes = ($at->getTimestamp() - $state['hot_since']) / 60;
                if ($minutes >= self::SUSPEND_AFTER) {
                    $this->suspend($site, $minutes);
                    Cache::forget($key);

                    continue;
                }
                if ($minutes >= self::ALERT_AFTER && ! $state['alerted']) {
                    $this->tellOwner("Possible crypto mining: {$site->domain}", "https://{$site->domain} has used "
                        .round($share * 100).'% of its CPU limit for '.round($minutes)." minutes.\nAccount: ".($site->user?->email ?? '?')
                        ."\n\nIt is paused automatically at ".self::SUSPEND_AFTER." minutes. To take the account down now:\n"
                        .'php artisan abuse:ban '.($site->user?->email ?? '<email>').' --reason="crypto mining"');
                    $state['alerted'] = true;
                }
            }
            Cache::put($key, $state, now()->addHours(6));
        }

        return $shares;
    }

    private function suspend(Site $site, float $minutes): void
    {
        $paused = $this->suspension->pause($site);
        Audit::record('abuse.cpu_paused', $site->user, $site, detail: ['minutes' => round($minutes), 'paused' => $paused]);
        Log::warning('site paused for sustained CPU', ['site' => $site->site_id, 'minutes' => round($minutes), 'paused' => $paused]);
        $this->tellOwner("Site paused for sustained CPU: {$site->domain}", "https://{$site->domain} ran at its CPU limit for "
            .round($minutes)." minutes and was paused (".($paused ? 'done' : 'NOT done: host unreachable').")."
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
