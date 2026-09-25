<?php

namespace App\Console\Commands;

use App\Fleet\AgentClient;
use App\Fleet\Suspension;
use App\Models\Site;
use App\Notifications\SiteIdleNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pauses free sites that nobody has visited or worked on for 30 days (the
 * owner's decision, 2026-09-25), so the fleet's free room goes to sites in use.
 *
 * Visits come from each host's access logs (agent /v1/visits: people only -
 * no crawlers, scanners, or our own checks); work from SiteActivity. A site is
 * never paused without a warning at least three days before, so the first run
 * after this shipped - when no visit had been recorded yet - warned rather than
 * paused. A host that cannot be asked about visits pauses nothing: no
 * evidence of visitors is not evidence of none. The owner brings a site back
 * with one click (SiteController::wake); nothing is ever deleted here.
 */
class SitesIdle extends Command
{
    protected $signature = 'sites:idle {--dry-run : report, change nothing}';

    protected $description = 'Warn, then pause, free sites with no visitors and no edits for 30 days';

    public const IDLE_DAYS = 30;

    public const WARN_DAYS = 3;

    public function handle(Suspension $suspension): int
    {
        $dry = (bool) $this->option('dry-run');
        $sites = Site::with('user')->where('status', 'live')->get()
            ->filter(fn (Site $s) => $s->user && ! $s->user->isPaid() && ! $s->user->isOperator() && ! $s->user->banned_at);

        $reachable = [];
        foreach ($sites->pluck('host')->unique() as $host) {
            try {
                $reported = collect(AgentClient::for($host)->visits()['sites'] ?? [])->pluck('last', 'site');
                $reachable[$host] = true;
            } catch (\Throwable $e) {
                $this->warn("$host: visits unreadable, its sites are left alone: {$e->getMessage()}");

                continue;
            }
            foreach ($sites->where('host', $host) as $site) {
                $last = (int) ($reported[$site->site_id] ?? 0);
                if ($last > 0 && ($site->last_visit_at === null || $site->last_visit_at->getTimestamp() < $last)) {
                    $site->last_visit_at = Carbon::createFromTimestamp($last);
                    if (! $dry) {
                        $site->save();
                    }
                }
            }
        }

        foreach ($sites as $site) {
            if (! isset($reachable[$site->host])) {
                continue;
            }
            $active = collect([$site->provisioned_at ?? $site->created_at, $site->last_worked_at, $site->last_visit_at])->filter()->max();
            if ($active->gt(now()->subDays(self::IDLE_DAYS - self::WARN_DAYS))) {
                continue;
            }

            $warned = $site->idle_warned_at && $site->idle_warned_at->gte($active);
            if (! $warned) {
                $pauseAt = max($active->copy()->addDays(self::IDLE_DAYS), now()->addDays(self::WARN_DAYS));
                $this->line("{$site->site_id}: idle since {$active}, warned; paused from {$pauseAt}");
                if (! $dry) {
                    $site->update(['idle_warned_at' => now()]);
                    $site->user->notify(new SiteIdleNotice($site, 'warning', $pauseAt));
                }

                continue;
            }
            if ($active->gt(now()->subDays(self::IDLE_DAYS)) || $site->idle_warned_at->gt(now()->subDays(self::WARN_DAYS))) {
                continue; // warned; its time is not up yet
            }
            $this->line("{$site->site_id}: idle since {$active}, pausing");
            if ($dry) {
                continue;
            }
            if ($suspension->pause($site, 'idle')) {
                $site->user->notify(new SiteIdleNotice($site, 'paused'));
            } else {
                $this->warn("{$site->site_id}: not paused (host unreachable); next run");
            }
        }

        return self::SUCCESS;
    }
}
