<?php

namespace App\Console\Commands;

use App\Showcase\Explore;
use Illuminate\Console\Command;

class ExploreRefresh extends Command
{
    protected $signature = 'explore:refresh';

    protected $description = 'Find which free sites answer with a page of their own, for the public Explore list';

    public function handle(Explore $explore): int
    {
        $built = $explore->refresh();
        $this->info(count($built).' site(s) listed on Explore.');

        return self::SUCCESS;
    }
}
