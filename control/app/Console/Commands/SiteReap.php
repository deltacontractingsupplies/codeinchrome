<?php

namespace App\Console\Commands;

use App\Fleet\AgentClient;
use App\Fleet\Dns;
use App\Fleet\Provisioner;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Remove a site by name, from wherever it exists, in whatever state.
 *
 * Distinct from the normal delete: that one goes through a Site row and
 * reports per part. This one is for cleaning up after a failure, so it works
 * even when there is no row - it will ask every host and withdraw the DNS
 * record regardless. Used by the e2e suite, which provisions against the real
 * fleet and must not leave anything behind when it fails.
 */
class SiteReap extends Command
{
    protected $signature = 'site:reap {name} {--all-e2e : remove every site named e2e-*}';

    protected $description = 'Force-remove a site from the fleet and DNS, with or without a record';

    public function handle(): int
    {
        if ($this->option('all-e2e')) {
            $names = Site::where('site_id', 'like', 'e2e-%')->pluck('site_id')->all();
            foreach (array_keys(config('fleet.hosts')) as $host) {
                try {
                    foreach (AgentClient::for($host)->sites() as $site) {
                        if (str_starts_with($site['id'], 'e2e-')) {
                            $names[] = $site['id'];
                        }
                    }
                } catch (\Throwable) {
                    $this->warn("$host unreachable; anything of its is left alone.");
                }
            }
            $names = array_unique($names);
        } else {
            $names = [$this->argument('name')];
        }

        foreach ($names as $name) {
            $this->reap($name);
        }

        return self::SUCCESS;
    }

    private function reap(string $name): void
    {
        if ($site = Site::where('site_id', $name)->first()) {
            try {
                Provisioner::make()->destroy($site);
                $this->info("$name: removed via its record.");

                return;
            } catch (\Throwable $e) {
                $this->warn("$name: record-based removal failed ({$e->getMessage()}); falling back to a sweep.");
            }
        }

        // No row, or the row-based path failed. Ask every host directly.
        foreach (array_keys(config('fleet.hosts')) as $host) {
            try {
                $removed = AgentClient::for($host)->deleteSite($name);
                $this->info("$name: removed from $host (" . json_encode($removed) . ').');
            } catch (\Throwable) {
                // Expected on the hosts that never had it.
            }
        }

        try {
            Dns::make()->delete($name);
            $this->info("$name: DNS withdrawn.");
        } catch (\Throwable $e) {
            $this->warn("$name: DNS not withdrawn - {$e->getMessage()}");
        }

        Site::where('site_id', $name)->delete();
    }
}
