<?php

namespace App\Console\Commands;

use App\Fleet\AgentClient;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * New free sites are kept out of search engines for a week (owner's decision,
 * 2026-09-25; set by the Provisioner). This lifts it when the week is over -
 * or at once for a site whose account moved to a paid plan - and re-applies
 * it to a site still inside its week, should the host have missed it.
 */
class SitesIndexing extends Command
{
    protected $signature = 'sites:indexing';

    protected $description = 'Let search engines index free sites after their first week';

    public function handle(): int
    {
        $failed = 0;
        foreach (Site::with('user')->where('status', 'live')->whereNotNull('noindex_until')->get() as $site) {
            $lift = $site->noindex_until->isPast() || $site->user?->isPaid();
            try {
                AgentClient::for($site->host)->setNoIndex($site->site_id, ! $lift);
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("{$site->site_id}: ".$e->getMessage());

                continue;
            }
            if ($lift) {
                $site->update(['noindex_until' => null]);
                $this->line("{$site->site_id}: now indexable");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
