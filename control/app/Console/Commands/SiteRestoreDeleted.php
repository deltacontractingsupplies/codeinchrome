<?php

namespace App\Console\Commands;

use App\Fleet\AgentClient;
use App\Fleet\Provisioner;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Bring back a paying customer's site that was deleted after its grace
 * period, from the final backup taken before the delete (kept 30 days by
 * infra/cic-backup-maintain).
 *
 * The site is created again under the same name, on the host whose backup
 * repository holds the snapshot, for the same account - which must be able
 * to own it again (on a paid plan) - and the snapshot's files and database
 * are restored into it.
 */
class SiteRestoreDeleted extends Command
{
    protected $signature = 'site:restore-deleted {site} {--snapshot= : a files snapshot id (default: the final backup)} {--host= : the host whose repository holds it}';

    protected $description = 'Restore a deleted site from its final backup';

    public function handle(): int
    {
        $id = $this->argument('site');
        if (Site::where('site_id', $id)->whereNotIn('status', ['failed'])->exists()) {
            $this->error("A site named $id exists; nothing to restore into.");

            return self::FAILURE;
        }
        $event = AuditEvent::where('site', $id)->where('action', 'site.final_backup')->latest('id')->first();
        $snapshot = $this->option('snapshot') ?: ($event->detail['snapshot'] ?? null);
        $host = $this->option('host') ?: ($event->detail['host'] ?? null);
        $user = $event ? User::find($event->account_id) : null;
        if (! $snapshot || ! $host || ! $user) {
            $this->error("No final backup is recorded for $id; pass --snapshot and --host.");

            return self::FAILURE;
        }

        $this->line("restoring $id for {$user->email} on $host from $snapshot");
        try {
            $site = Provisioner::make()->provision($user, $id, $host);
        } catch (\Throwable $e) {
            $this->error("Could not create $id again: {$e->getMessage()}");

            return self::FAILURE;
        }
        $agent = AgentClient::for($host);
        $agent->restoreBackup($id, $snapshot);
        for ($i = 0; $i < 720; $i++) {
            sleep((int) config('fleet.poll_seconds', 5));
            $op = $agent->backups($id)['operation'] ?? null;
            if (($op['kind'] ?? '') === 'restore' && in_array($op['state'] ?? '', ['done', 'failed'], true)) {
                if ($op['state'] === 'failed') {
                    $this->error('Restore failed: '.($op['message'] ?? 'no reason given').'. The new site is left for inspection.');

                    return self::FAILURE;
                }
                \App\Audit\Audit::record('site.restored_deleted', $user, $site, ['snapshot' => $snapshot]);
                $this->info("$id is back: {$site->url()}");

                return self::SUCCESS;
            }
        }
        $this->error("The restore did not finish within an hour; check the site's backups page.");

        return self::FAILURE;
    }
}
