<?php

namespace App\Console\Commands;

use App\Fleet\Suspension;
use App\Models\Site;
use Illuminate\Console\Command;

class AbuseResume extends Command
{
    protected $signature = 'abuse:resume {site : the site id}';

    protected $description = 'Bring back a site paused for sustained CPU (not a banned account: abuse:unban)';

    public function handle(Suspension $suspension): int
    {
        $site = Site::where('site_id', $this->argument('site'))->first();
        if (! $site || $site->status !== 'suspended') {
            $this->error('No paused site with that id.');

            return self::FAILURE;
        }
        if ($site->user?->banned_at) {
            $this->error('Its account is banned: php artisan abuse:unban '.$site->user->email);

            return self::FAILURE;
        }
        if (! $suspension->resume($site)) {
            $this->error('The host could not be reached; nothing changed.');

            return self::FAILURE;
        }
        $this->info("{$site->domain} is back.");

        return self::SUCCESS;
    }
}
