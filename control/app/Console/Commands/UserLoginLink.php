<?php

namespace App\Console\Commands;

use App\Auth\LoginLink;
use App\Models\User;
use Illuminate\Console\Command;

class UserLoginLink extends Command
{
    protected $signature = 'user:login-link {email} {--minutes=5 : how long the link lasts (1-30)}';

    protected $description = 'Print a one-time sign-in link for an account (server command line only)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }
        try {
            $this->line(LoginLink::issue($user, (int) $this->option('minutes')));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
