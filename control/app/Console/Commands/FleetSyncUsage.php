<?php

namespace App\Console\Commands;

use App\Fleet\AgentClient;
use App\Models\Site;
use App\Models\User;
use App\Notifications\PlanNotice;
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
                    'inodes_used' => $u['diskMounted'] ? ($u['inodesUsed'] ?? null) : null,
                    'inodes_total' => $u['diskMounted'] ? ($u['inodesTotal'] ?? null) : null,
                    'usage_at' => now(),
                ]);
            }
            $this->line("$host: " . count($usage) . ' site(s) measured');
        }

        $this->checkStorage();

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A plan's storage is its total across every site's files and database.
     * Each site's disk has its own kernel ceiling; the total is enforced here,
     * from the measurements just taken: over it, an account cannot add a site
     * or upload in bulk (see StorageLimit) until it is back under, and is told
     * once. Sites keep running either way - nothing a customer's visitors see
     * changes because of it.
     */
    private function checkStorage(): void
    {
        $totals = Site::whereNotIn('status', ['deleting', 'failed'])
            ->selectRaw('user_id, SUM(COALESCE(disk_used_bytes, 0) + COALESCE(database_bytes, 0)) AS used')
            ->groupBy('user_id')->pluck('used', 'user_id');

        User::whereIn('id', $totals->keys())->orWhereNotNull('storage_over_at')->each(function (User $user) use ($totals) {
            $limit = (int) ($user->planConfig()['storage_gb'] ?? 0) * 1024 ** 3;
            $over = $limit > 0 && (int) ($totals[$user->id] ?? 0) > $limit;
            if ($over && ! $user->storage_over_at) {
                $user->forceFill(['storage_over_at' => now()])->save();
                $user->notify(new PlanNotice('storage'));
                $this->warn("{$user->email}: over the plan's storage");
            } elseif (! $over && $user->storage_over_at) {
                $user->forceFill(['storage_over_at' => null])->save();
            }
        });
    }
}
