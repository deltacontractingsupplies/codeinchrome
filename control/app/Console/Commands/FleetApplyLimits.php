<?php

namespace App\Console\Commands;

use App\Fleet\PlanLimits;
use App\Models\Site;
use Illuminate\Console\Command;

/** Retry plan changes a host could not accept at the time. */
class FleetApplyLimits extends Command
{
    protected $signature = 'fleet:apply-limits';

    protected $description = 'Apply plan limits to sites still pending, or whose limits are not their plan\'s';

    public function handle(PlanLimits $limits): int
    {
        // Pending ones, and any live site whose limits are not its owner's plan:
        // limits were only ever applied on a plan change, so sites made under
        // older plan settings ran with double the free plan's CPU and memory
        // (found by the security audit, 2026-09-25).
        $pending = Site::with('user')->where('status', 'live')->get()->filter(function (Site $site) {
            $plan = $site->user?->planConfig();

            return $site->limits_pending
                || ($plan && ((string) $site->cpu_limit !== (string) $plan['cpu'] || (string) $site->memory_limit !== (string) $plan['memory']));
        });

        foreach ($pending as $site) {
            $outcome = $limits->applyToSite($site, $site->user->planConfig());
            $this->line("{$site->site_id}: $outcome");
        }

        $still = Site::where('limits_pending', true)->count();
        if ($still > 0) {
            $this->warn("$still site(s) still pending.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
