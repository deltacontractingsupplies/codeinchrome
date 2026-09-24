<?php

namespace App\Console\Commands;

use App\Billing\Sales;
use App\Models\User;
use App\Notifications\PlanNotice;
use Illuminate\Console\Command;

/**
 * Run once when paid plans open (App\Billing\Sales): every free account gets a
 * trial that starts NOW, and an email saying so - whatever its clock said
 * while nothing was for sale, nobody loses a site without a full trial and a
 * warning first. Operators have no trial. Accounts that pay are untouched.
 */
class TrialsRestart extends Command
{
    protected $signature = 'trials:restart {--dry-run : say who would get a trial, change nothing}';

    protected $description = 'When paid plans open: give every free account a fresh trial, and tell them';

    public function handle(): int
    {
        if (! Sales::open()) {
            $this->error('Paid plans are not open (CIC_PAID_PLANS_OPEN): a trial started now would end with nothing to buy.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $paid = array_keys(array_filter(config('billing.plans'), fn ($p) => $p['price'] > 0));
        $ends = now()->addDays((int) config('billing.trial.days'));
        $count = 0;

        User::whereNotIn('plan', $paid)->each(function (User $user) use ($dry, $ends, &$count) {
            if ($user->isOperator()) {
                return;
            }
            $count++;
            $this->line("{$user->email}: trial until {$ends->utc()}");
            if (! $dry) {
                $user->forceFill(['trial_ends_at' => $ends, 'trial_warned_at' => null])->save();
                $user->notify(new PlanNotice('trial_started', $ends));
            }
        });
        $this->info(($dry ? 'would start ' : 'started ')."$count trial(s)");

        return self::SUCCESS;
    }
}
