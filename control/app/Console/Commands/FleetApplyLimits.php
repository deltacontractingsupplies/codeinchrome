<?php

namespace App\Console\Commands;

use App\Fleet\PlanLimits;
use App\Models\Site;
use Illuminate\Console\Command;

/** Retry plan changes a host could not accept at the time. */
class FleetApplyLimits extends Command
{
    protected $signature = 'fleet:apply-limits';

    protected $description = 'Apply plan limits to sites still marked limits_pending';

    public function handle(PlanLimits $limits): int
    {
        $pending = Site::with('user')->where('limits_pending', true)->where('status', 'live')->get();

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
