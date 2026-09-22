<?php

namespace App\Console\Commands;

use App\Fleet\AgentClient;
use App\Models\Site;
use Illuminate\Console\Command;

class FleetStatus extends Command
{
    protected $signature = 'fleet:status';

    protected $description = 'Show every host, as the host reports itself';

    public function handle(): int
    {
        $rows = [];
        $unreachable = 0;

        foreach (array_keys(config('fleet.hosts')) as $host) {
            $ours = Site::where('host', $host)->whereIn('status', ['provisioning', 'live'])->count();
            $capacity = config("fleet.hosts.$host.capacity");

            try {
                $info = AgentClient::for($host)->hostInfo();
                $rows[] = [
                    $host,
                    config("fleet.hosts.$host.ip"),
                    'up',
                    $info['version'] ?? '?',
                    // Shown side by side deliberately. A host that disagrees
                    // with our records is the interesting case, and averaging
                    // or preferring one would hide it.
                    $info['sites'] . ' / ' . $ours,
                    $info['running'],
                    $ours . '/' . $capacity,
                ];
            } catch (\Throwable $e) {
                $unreachable++;
                $rows[] = [$host, config("fleet.hosts.$host.ip"), 'UNREACHABLE', '-', '- / ' . $ours, '-', $ours . '/' . $capacity];
            }
        }

        $this->table(['host', 'ip', 'state', 'agent', 'sites host/ours', 'running', 'load'], $rows);

        if ($unreachable) {
            $this->warn("$unreachable host(s) unreachable. Check `bash infra/tunnels.sh check`.");
            $this->warn('Their real state is UNKNOWN; the counts above are only what we have recorded.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
