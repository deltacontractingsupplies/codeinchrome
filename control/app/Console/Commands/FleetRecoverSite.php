<?php

namespace App\Console\Commands;

use App\Fleet\SiteMover;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Put one site of a LOST host back on a healthy one, from files recovered
 * out of the backup repository. Run by infra/recover-host.sh, which does the
 * recovering (as root, with the escrowed password) and then calls this.
 */
class FleetRecoverSite extends Command
{
    protected $signature = 'fleet:recover-site {site} {--to= : the healthy host} {--db= : database dump (.sql.gz)} {--files= : files archive (.tar.gz)} {--history= : history archive (.tar.gz), if there is one}';

    protected $description = "Recover a site from a lost host's backup onto another host";

    public function handle(): int
    {
        $site = Site::where('site_id', $this->argument('site'))->first();
        if (! $site || ! $this->option('to') || ! $this->option('db') || ! $this->option('files')) {
            $this->error('Usage: fleet:recover-site <site> --to=hN --db=... --files=... [--history=...]');

            return self::FAILURE;
        }
        try {
            SiteMover::make()->recover($site, $this->option('to'), $this->option('db'), $this->option('files'),
                $this->option('history') ?: null, fn (string $s) => $this->line("  $s"));
        } catch (\Throwable $e) {
            $this->error("{$site->site_id} not recovered: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info("{$site->site_id} recovered on {$this->option('to')}.");

        return self::SUCCESS;
    }
}
