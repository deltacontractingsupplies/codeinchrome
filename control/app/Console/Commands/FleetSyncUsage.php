<?php

namespace App\Console\Commands;

use App\Fleet\AgentClient;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Record each site's disk and database size, one request per host.
 *
 * The dashboard reads these rows rather than calling every host on every page
 * view, and shows usage_at beside them - a measurement is only as good as the
 * moment it was taken, and the page should say which moment that was.
 */
class FleetSyncUsage extends Command
{
    protected $signature = 'fleet:sync-usage';

    protected $description = 'Measure disk and database usage of every site';

    public function handle(): int
    {
        $failed = 0;
        foreach (array_keys(config('fleet.hosts')) as $host) {
            try {
                $usage = AgentClient::for($host)->usage();
            } catch (\Throwable $e) {
                // Keep the last known numbers; usage_at already says how old they are.
                $this->warn("$host: unreachable, usage not refreshed - {$e->getMessage()}");
                // Scheduled output goes nowhere; the log is where someone looks.
                \Illuminate\Support\Facades\Log::warning('usage not refreshed', ['host' => $host, 'error' => $e->getMessage()]);
                $failed++;

                continue;
            }

            foreach ($usage as $u) {
                Site::where('host', $host)->where('site_id', $u['id'])->update([
                    'disk_used_bytes' => $u['diskMounted'] ? $u['diskUsedBytes'] : null,
                    'disk_size_bytes' => $u['diskMounted'] ? $u['diskSizeBytes'] : null,
                    'database_bytes' => $u['databaseBytes'],
                    'usage_at' => now(),
                ]);
            }
            $this->line("$host: " . count($usage) . ' site(s) measured');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
