<?php

namespace App\Fleet;

use App\Audit\Audit;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Moves a live site to another host: to drain a host before retiring it, or
 * to rebalance. The site comes up on the target exactly as it was - files,
 * version history, database, settings, APP_KEY - and only then does its name
 * point there. Until that moment the source is the site; any failure before
 * it removes the half-made copy and takes the source out of maintenance, so a
 * failed move costs a few minutes of "back soon" and nothing else.
 *
 * Order, and why:
 *   1. a fresh site on the target (its own database, password, network)
 *   2. the source into maintenance: no writes while it is copied
 *   3. database, files, history - streamed through a temporary file here,
 *      each checked as a complete gzip before it is sent on
 *   4. the source's settings applied to the target: domains, background
 *      processes, PHP settings, plan limits
 *   5. the target out of maintenance, and asked for a page: it must answer
 *   6. the name switched to the target, and the row with it
 *   7. only then, the source deleted
 */
class SiteMover
{
    public function __construct(private readonly Dns $dns) {}

    public static function make(): self
    {
        return new self(Dns::make());
    }

    /** @param  callable(string): void  $say  progress, for the operator */
    public function move(Site $site, ?string $to = null, bool $withCustomDomains = false, ?callable $say = null): string
    {
        $say ??= fn () => null;
        $from = $site->host;
        if ($site->status !== 'live') {
            throw new RuntimeException("{$site->site_id} is {$site->status}; only a live site is moved.");
        }
        if ($site->domains()->whereNotNull('verified_at')->exists() && ! $withCustomDomains) {
            throw new RuntimeException("{$site->site_id} has custom domains whose DNS points at $from. Moving it breaks them until the owner updates their DNS; pass --with-custom-domains once they have been told.");
        }
        $provisioner = Provisioner::make();
        $to ??= $provisioner->chooseHost((string) $site->memory_limit, $from);
        if ($to === $from || ! config("fleet.hosts.$to")) {
            throw new RuntimeException("Cannot move {$site->site_id} from $from to $to.");
        }
        $provisioner->requireCapableAgent($to);
        $source = AgentClient::for($from);
        $target = AgentClient::for($to);
        $id = $site->site_id;

        // A copy left on the target by an earlier, failed attempt is never the
        // live site (the name and the row point at the source); start clean.
        try {
            $target->site($id);
            $say("a copy from an earlier attempt is on $to; removing it");
            $target->deleteSite($id);
        } catch (AgentRefused) {
            // not there: the usual case
        }

        $say("creating $id on $to");
        $created = $target->createSite($id, $site->domain, (string) $site->cpu_limit, (string) $site->memory_limit, (int) $site->disk_gb);
        $port = $created['port'] ?? null;

        $maintenance = false;
        try {
            $say('maintenance mode on the source');
            $source->setMaintenance($id, true);
            $maintenance = true;

            $say('copying the database');
            $this->relay($source->dbExport($id)['body'], fn ($in) => $target->dbImport($id, $in));
            $say('copying the files');
            $this->relay($source->transferExport($id, 'files'), fn ($in) => $target->transferImport($id, 'files', $in));
            $say('copying the version history');
            try {
                $this->relay($source->transferExport($id, 'history'), fn ($in) => $target->transferImport($id, 'history', $in));
            } catch (AgentRefused $e) {
                if (! str_contains($e->getMessage(), 'no history')) {
                    throw $e;
                }
            }

            $say('applying settings');
            $this->applySettings($site, $target);

            $target->setMaintenance($id, false);
            $say('checking the site answers on the target');
            $answer = $target->siteRequest($id, ['method' => 'GET', 'path' => '/', 'headers' => (object) [], 'body' => '']);
            if (($answer['status'] ?? 0) >= 500 || ($answer['status'] ?? 0) === 0) {
                throw new RuntimeException("the site answered {$answer['status']} on $to; not switching to it");
            }
        } catch (\Throwable $e) {
            $say('failed; removing the copy and restoring the source');
            try {
                $target->deleteSite($id);
            } catch (\Throwable $cleanup) {
                Log::error('move: the copy on the target was not removed', ['site' => $id, 'host' => $to, 'error' => $cleanup->getMessage()]);
            }
            if ($maintenance) {
                $source->setMaintenance($id, false);
            }
            Audit::record('site.move_failed', $site->user, $site, ['from' => $from, 'to' => $to, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }

        // The switch. From here the target is the site.
        $say('pointing the name at '.$to);
        $this->dns->upsert($id, config("fleet.hosts.$to.ip"));
        $site->update(['host' => $to, 'port' => $port]);
        Audit::record('site.moved', $site->user, $site, ['from' => $from, 'to' => $to]);

        $say("removing the old copy from $from");
        try {
            $source->deleteSite($id);
        } catch (\Throwable $e) {
            // The site is live on the target; the leftover is found by fleet:audit.
            Log::error('move: the source copy was not removed', ['site' => $id, 'host' => $from, 'error' => $e->getMessage()]);
            $say("the old copy on $from was NOT removed: {$e->getMessage()}");
        }

        return $to;
    }

    /**
     * Bring a site back on another host from files recovered out of a lost
     * host's backup (infra/recover-host.sh): its database dump and files and
     * history archives, as gzip files readable here. The dead host is not
     * contacted. The same checks as a move: every archive complete, the copy
     * answering, and only then the name switched to it.
     *
     * @param  callable(string): void  $say
     */
    public function recover(Site $site, string $to, string $dbGz, string $filesTgz, ?string $historyTgz = null, ?callable $say = null): string
    {
        $say ??= fn () => null;
        $id = $site->site_id;
        foreach (array_filter([$dbGz, $filesTgz, $historyTgz]) as $file) {
            if (! self::completeGzip($file)) {
                throw new RuntimeException("$file is missing or not a complete gzip file");
            }
        }
        if ($to === $site->host || ! config("fleet.hosts.$to")) {
            throw new RuntimeException("Cannot recover $id onto $to.");
        }
        Provisioner::make()->requireCapableAgent($to);
        $target = AgentClient::for($to);
        try {
            $target->site($id);
            $say("a copy from an earlier attempt is on $to; removing it");
            $target->deleteSite($id);
        } catch (AgentRefused) {
        }

        $say("creating $id on $to");
        $created = $target->createSite($id, $site->domain, (string) $site->cpu_limit, (string) $site->memory_limit, (int) $site->disk_gb);
        try {
            $say('loading the database');
            $this->sendFile($dbGz, fn ($in) => $target->dbImport($id, $in));
            $say('loading the files');
            $this->sendFile($filesTgz, fn ($in) => $target->transferImport($id, 'files', $in));
            if ($historyTgz) {
                $say('loading the version history');
                $this->sendFile($historyTgz, fn ($in) => $target->transferImport($id, 'history', $in));
            }
            $say('applying settings');
            $this->applySettings($site, $target);
            $answer = $target->siteRequest($id, ['method' => 'GET', 'path' => '/', 'headers' => (object) [], 'body' => '']);
            if (($answer['status'] ?? 0) >= 500 || ($answer['status'] ?? 0) === 0) {
                throw new RuntimeException("the recovered site answered {$answer['status']} on $to; not switching to it");
            }
        } catch (\Throwable $e) {
            try {
                $target->deleteSite($id);
            } catch (\Throwable) {
            }
            Audit::record('site.recover_failed', $site->user, $site, ['from' => $site->host, 'to' => $to, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }

        $say('pointing the name at '.$to);
        $from = $site->host;
        $this->dns->upsert($id, config("fleet.hosts.$to.ip"));
        $site->update(['host' => $to, 'port' => $created['port'] ?? null]);
        Audit::record('site.recovered', $site->user, $site, ['from' => $from, 'to' => $to]);

        return $to;
    }

    private function applySettings(Site $site, AgentClient $target): void
    {
        $id = $site->site_id;
        $aliases = $site->domains()->whereNotNull('verified_at')->pluck('domain')->all();
        if ($aliases) {
            $target->setAliases($id, $aliases);
        }
        if ($site->queue || $site->scheduler || $site->reverb) {
            $target->setBackground($id, (bool) $site->queue, (bool) $site->scheduler, (bool) $site->reverb);
        }
        if ($php = $site->php_settings) {
            $target->setPhp($id, (int) ($php['memoryMB'] ?? 0), (int) ($php['maxExecutionSeconds'] ?? 0), (int) ($php['uploadMB'] ?? 0));
        }
    }

    private function sendFile(string $file, callable $send): void
    {
        $in = fopen($file, 'rb');
        try {
            $send($in);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    /**
     * Source to a temporary file, checked, then to the target: a dump or
     * archive cut off part-way has no gzip trailer, and is caught here rather
     * than half-imported there.
     */
    private function relay($stream, callable $send): void
    {
        $dir = storage_path('app/private/moves');
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $file = tempnam($dir, 'move');
        try {
            $out = fopen($file, 'wb');
            while (! $stream->eof()) {
                fwrite($out, $stream->read(1 << 20));
            }
            fclose($out);
            if (! self::completeGzip($file)) {
                throw new RuntimeException('the export arrived incomplete (its gzip trailer is missing)');
            }
            $in = fopen($file, 'rb');
            try {
                $send($in);
            } finally {
                if (is_resource($in)) {
                    fclose($in);
                }
            }
        } finally {
            @unlink($file);
        }
    }

    /** gzip -t: reads the whole stream, and fails on a missing trailer or bad CRC. */
    public static function completeGzip(string $file): bool
    {
        if (! is_file($file) || filesize($file) < 20) {
            return false;
        }

        return (new \Symfony\Component\Process\Process(['gzip', '-t', $file]))->setTimeout(3600)->run() === 0;
    }
}
