<?php

namespace App\Console\Commands;

use App\Fleet\Provisioner;
use App\Models\User;
use Illuminate\Console\Command;

class SiteProvision extends Command
{
    protected $signature = 'site:provision {email} {name} {--host= : put it on this host (probing a new one)} {--create : create the account if missing - @codeinchrome.test addresses only}';

    protected $description = 'Create a site for a user, end to end';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if ($this->option('create') && str_ends_with($email, '@codeinchrome.test')) {
            // An operator's probe account: free, with no trial clock, so it
            // reserves nothing and nothing it owns ever expires on its own.
            User::firstOrCreate(['email' => $email], ['name' => 'Probe', 'password' => \Illuminate\Support\Str::random(40), 'plan' => 'free'])
                ->forceFill(['email_verified_at' => now()])->save();
        }
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        try {
            $site = Provisioner::make()->provision($user, $this->argument('name'), $this->option('host') ?: null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created {$site->domain} on {$site->host}, port {$site->port}.");
        // Deliberately not "your site is live". The container is running and
        // the vhost is written, but the certificate is issued by Caddy on the
        // FIRST request, so TLS is not yet proven at this moment.
        $this->line('The certificate is issued on the first request. The name usually resolves within a minute, but some resolvers take several (a carrier resolver took 382s when measured).');

        return self::SUCCESS;
    }
}
