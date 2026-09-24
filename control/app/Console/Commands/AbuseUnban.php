<?php

namespace App\Console\Commands;

use App\Abuse\Enforcer;
use App\Models\User;
use Illuminate\Console\Command;

class AbuseUnban extends Command
{
    protected $signature = 'abuse:unban {email}';

    protected $description = 'Lift a ban and bring the account\'s sites back';

    public function handle(Enforcer $enforcer): int
    {
        $user = User::where('email', strtolower((string) $this->argument('email')))->first();
        if (! $user?->banned_at) {
            $this->error('No banned account with that address.');

            return self::FAILURE;
        }
        $back = $enforcer->unban($user);
        $this->info("{$user->email} unbanned; back up: ".(implode(', ', $back) ?: 'no sites'));

        return self::SUCCESS;
    }
}
