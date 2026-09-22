<?php

namespace App\Console\Commands;

use App\Fleet\Provisioner;
use App\Models\User;
use Illuminate\Console\Command;

class SiteProvision extends Command
{
    protected $signature = 'site:provision {email} {name}';

    protected $description = 'Create a site for a user, end to end';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        try {
            $site = Provisioner::make()->provision($user, $this->argument('name'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created {$site->domain} on {$site->host}, port {$site->port}.");
        // Deliberately not "your site is live". The container is running and
        // the vhost is written, but the certificate is issued by Caddy on the
        // FIRST request, so TLS is not yet proven at this moment.
        $this->line('The certificate is issued on the first request, so allow a few seconds before it answers over HTTPS.');

        return self::SUCCESS;
    }
}
