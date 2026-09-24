<?php

namespace App\Console\Commands;

use App\Fleet\SiteMover;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Empty a host so it can be retired: every live site on it is moved to the
 * host with the most room, one at a time, each verified before the next.
 *
 * The host must already be marked draining (CIC_HOST_STATES="hN:draining" in
 * infra/hosts.env, deployed), so nothing new lands on it while it empties.
 * Paused sites stay: they are resumed or deleted by their own clock; a host is
 * retired only when this reports it empty.
 */
class FleetDrain extends Command
{
    protected $signature = 'fleet:drain {host} {--dry-run}';

    protected $description = 'Move every live site off a draining host';

    public function handle(): int
    {
        $host = $this->argument('host');
        if (! config("fleet.hosts.$host")) {
            $this->error("No host $host.");

            return self::FAILURE;
        }
        if ((config("fleet.hosts.$host.state") ?? 'active') !== 'draining') {
            $this->error("$host is not marked draining. Set CIC_HOST_STATES=\"$host:draining\" in infra/hosts.env and deploy the control plane first.");

            return self::FAILURE;
        }
        $sites = Site::where('host', $host)->where('status', 'live')->get();
        $this->line("$host: {$sites->count()} live site(s) to move");
        $failed = 0;
        foreach ($sites as $site) {
            if ($this->option('dry-run')) {
                $this->line("  would move {$site->site_id}");

                continue;
            }
            $this->line("{$site->site_id}:");
            try {
                $to = SiteMover::make()->move($site, null, false, fn (string $step) => $this->line("  $step"));
                $this->info("  now on $to");
            } catch (\Throwable $e) {
                $this->error("  not moved: {$e->getMessage()}");
                $failed++;
            }
        }
        $left = Site::where('host', $host)->whereNotIn('status', ['failed'])->count();
        $this->line($left === 0 ? "$host is empty and can be retired." : "$host still holds $left site(s).");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
