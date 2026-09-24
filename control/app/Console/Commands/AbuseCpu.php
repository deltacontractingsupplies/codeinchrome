<?php

namespace App\Console\Commands;

use App\Abuse\CpuWatch;
use App\Fleet\AgentClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AbuseCpu extends Command
{
    protected $signature = 'abuse:cpu';

    protected $description = 'Read every site\'s CPU use; alert on, then pause, a site held at its limit (mining)';

    public function handle(CpuWatch $watch): int
    {
        $failed = 0;
        foreach (array_keys(config('fleet.hosts')) as $host) {
            try {
                $answer = AgentClient::for($host)->cpu();
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("$host: ".$e->getMessage());

                continue;
            }
            foreach ($watch->observe($answer['sites'] ?? [], Carbon::parse($answer['at'] ?? now())) as $site => $share) {
                $this->line(sprintf('%-30s %3d%% of its CPU limit', $site, (int) round($share * 100)));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
