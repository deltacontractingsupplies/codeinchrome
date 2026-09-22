<?php

namespace App\Console\Commands;

use App\Fleet\AgentClient;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Compare what we believe against what each host actually has.
 *
 * Drift is not hypothetical: a provision that fails after the container is
 * created, or an AgentUnreachable mid-flight, can leave a container running
 * that no row claims. That container serves nobody, consumes a slot, and -
 * worst of all - holds a name another customer could later be told is free.
 */
class FleetAudit extends Command
{
    protected $signature = 'fleet:audit {--host= : audit one host only}';

    protected $description = 'Find sites on a host that we do not know about, and rows whose site is missing';

    public function handle(): int
    {
        $hosts = $this->option('host') ? [$this->option('host')] : array_keys(config('fleet.hosts'));
        $problems = 0;

        foreach ($hosts as $host) {
            try {
                $onHost = collect(AgentClient::for($host)->sites())->keyBy('id');
            } catch (\Throwable $e) {
                $this->error("$host: unreachable, so its contents are UNKNOWN - {$e->getMessage()}");
                $problems++;

                continue;
            }

            $ours = Site::where('host', $host)->get()->keyBy('site_id');

            foreach ($onHost->keys()->diff($ours->keys()) as $orphan) {
                $this->warn("$host: \"$orphan\" exists on the host but in no record. It is serving nothing and holding a name.");
                $problems++;
            }

            foreach ($ours->keys()->diff($onHost->keys()) as $missing) {
                $row = $ours[$missing];
                $this->warn("$host: \"$missing\" is recorded as {$row->status} but is not on the host.");
                $problems++;
            }

            foreach ($onHost->intersectByKeys($ours) as $id => $remote) {
                if (($remote['state'] ?? '') !== 'running' && $ours[$id]->status === 'live') {
                    $this->warn("$host: \"$id\" is recorded live but the container is {$remote['state']}.");
                    $problems++;
                }
            }
        }

        if ($problems === 0) {
            $this->info('Every host matches our records.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error("$problems discrepancy(ies). Nothing was changed: this command only reports.");

        return self::FAILURE;
    }
}
