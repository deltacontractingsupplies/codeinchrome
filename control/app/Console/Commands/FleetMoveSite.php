<?php

namespace App\Console\Commands;

use App\Fleet\SiteMover;
use App\Models\Site;
use Illuminate\Console\Command;

/** Move one live site to another host (App\Fleet\SiteMover). */
class FleetMoveSite extends Command
{
    protected $signature = 'fleet:move-site {site} {--to= : the target host (default: the one with most room)} {--with-custom-domains : the owner knows their DNS must change}';

    protected $description = 'Move a site, with its files, history and database, to another host';

    public function handle(): int
    {
        $site = Site::where('site_id', $this->argument('site'))->first();
        if (! $site) {
            $this->error('No such site.');

            return self::FAILURE;
        }
        try {
            $to = SiteMover::make()->move($site, $this->option('to') ?: null, (bool) $this->option('with-custom-domains'),
                fn (string $step) => $this->line("  $step"));
        } catch (\Throwable $e) {
            $this->error("{$site->site_id} not moved: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info("{$site->site_id} now runs on $to.");

        return self::SUCCESS;
    }
}
