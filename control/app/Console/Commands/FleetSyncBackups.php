<?php

namespace App\Console\Commands;

use App\Fleet\AgentClient;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Record each live site's newest complete backup (files AND database), as the
 * backup server itself lists it - not "the nightly job ran", which says
 * nothing about whether a snapshot was actually written.
 *
 * Found 2026-09-24: a stale lock on one host's repository refused every backup
 * there, and nothing noticed. Monitoring now alerts when a site's newest
 * backup is older than it should be (Monitoring::checkBackups).
 */
class FleetSyncBackups extends Command
{
    protected $signature = 'fleet:sync-backups';

    protected $description = "Record every live site's newest complete backup";

    public function handle(): int
    {
        $failed = 0;
        foreach (Site::where('status', 'live')->get() as $site) {
            try {
                $backups = AgentClient::for($site->host)->backups($site->site_id)['backups'];
            } catch (\Throwable $e) {
                // Keep the last known time: if this goes on, the backup ages
                // past the limit and monitoring says so.
                Log::warning('backups not listed', ['site' => $site->site_id, 'error' => $e->getMessage()]);
                $this->warn("{$site->site_id}: not listed - {$e->getMessage()}");
                $failed++;

                continue;
            }
            $newest = collect($backups)->filter(fn ($b) => ($b['hasDatabase'] ?? false) && ! empty($b['time']))
                ->map(fn ($b) => CarbonImmutable::parse($b['time']))->max();
            $site->forceFill(['last_backup_at' => $newest])->save();
            $this->line("{$site->site_id}: ".($newest ? $newest->diffForHumans() : 'no complete backup yet'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
