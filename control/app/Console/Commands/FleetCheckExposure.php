<?php

namespace App\Console\Commands;

use App\Fleet\ExposureCheck;
use App\Fleet\Monitoring;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Every live site, asked from outside for its private files, every day. Any
 * path that gives something away is an alert - it should never happen, and
 * if it does, it is the first thing to know.
 */
class FleetCheckExposure extends Command
{
    protected $signature = 'fleet:check-exposure {site? : one site only}';

    protected $description = 'Prove from outside that no site serves anything but public/';

    public function handle(Monitoring $monitoring): int
    {
        $sites = Site::where('status', 'live')->when($this->argument('site'), fn ($q, $id) => $q->where('site_id', $id))->get();
        $failed = 0;
        foreach ($sites as $site) {
            try {
                $r = (new ExposureCheck($site))->run();
            } catch (\Throwable $e) {
                $this->warn("{$site->site_id}: not checked - {$e->getMessage()}");

                continue;
            }
            if ($r['passed']) {
                $this->line("{$site->site_id}: {$r['checked']} paths, nothing given away");

                continue;
            }
            $failed++;
            $bad = collect($r['results'])->where('ok', false)->map(fn ($x) => "{$x['path']} ({$x['status']}: {$x['why']})")->join('; ');
            $this->error("{$site->site_id}: $bad");
            $monitoring->alert("EXPOSURE: {$site->domain} gives away private files: $bad");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
