<?php

namespace App\Console\Commands;

use App\Fleet\Monitoring;
use App\Models\Incident;
use Illuminate\Console\Command;

class FleetMonitor extends Command
{
    protected $signature = 'fleet:monitor';

    protected $description = 'Check every host and site from outside, and open or close incidents';

    public function handle(Monitoring $monitoring): int
    {
        $results = $monitoring->run();
        $down = array_filter($results, fn ($r) => ! $r[1]);
        foreach ($down as $key => [$label, , $detail]) {
            $this->warn("$label: $detail");
        }
        $this->line(count($results) . ' checked, ' . count($down) . ' failing, ' . Incident::whereNull('resolved_at')->count() . ' open incident(s)');

        return self::SUCCESS;
    }
}
