<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Remove the accounts the end-to-end suite creates in production.
 *
 * Scoped by construction, not by care: only addresses at codeinchrome.test,
 * a name under the reserved .test top-level domain (RFC 2606), which cannot
 * receive mail - so no real person can ever hold one. And an account that
 * still owns a site is refused: sites are reaped first, through their own
 * path, and this never becomes a way to orphan a container.
 */
class AccountsPurgeTest extends Command
{
    protected $signature = 'accounts:purge-test';

    protected $description = 'Delete e2e test accounts (@codeinchrome.test) that own no sites';

    private const DOMAIN = '@codeinchrome.test';

    public function handle(): int
    {
        $candidates = User::where('email', 'like', '%' . self::DOMAIN)->withCount('sites')->get();

        $deleted = 0;
        $kept = 0;
        foreach ($candidates as $user) {
            // Belt and braces against a LIKE that matched more than intended.
            if (! str_ends_with(strtolower($user->email), self::DOMAIN)) {
                continue;
            }
            if ($user->sites_count > 0) {
                $this->warn("kept {$user->email}: still owns {$user->sites_count} site(s)");
                $kept++;

                continue;
            }
            $user->delete();
            $deleted++;
        }

        $this->info("deleted $deleted test account(s), kept $kept");

        return self::SUCCESS;
    }
}
