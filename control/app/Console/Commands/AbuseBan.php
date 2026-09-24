<?php

namespace App\Console\Commands;

use App\Abuse\Enforcer;
use App\Models\User;
use Illuminate\Console\Command;

class AbuseBan extends Command
{
    protected $signature = 'abuse:ban {email} {--reason= : why, kept on the account and in the audit log}';

    protected $description = 'Ban an account and take all of its sites down (nothing is deleted)';

    public function handle(Enforcer $enforcer): int
    {
        $user = User::where('email', strtolower((string) $this->argument('email')))->first();
        if (! $user) {
            $this->error('No such account.');

            return self::FAILURE;
        }
        $reason = (string) $this->option('reason');
        if ($reason === '') {
            $this->error('Say why: --reason="..."');

            return self::FAILURE;
        }
        $enforcer->ban($user, 'By the operator: '.$reason);
        $this->info("{$user->email} banned; its sites are down.");

        return self::SUCCESS;
    }
}
