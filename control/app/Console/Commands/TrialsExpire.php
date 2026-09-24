<?php

namespace App\Console\Commands;

use App\Audit\Audit;
use App\Fleet\Provisioner;
use App\Fleet\Suspension;
use App\Models\User;
use App\Notifications\PlanNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The free trial's clock, run every ten minutes.
 *
 *   payment failed         the paid plan continues for payment_grace_days;
 *                          a day before that ends, an email; then the
 *                          account moves to free and the steps below apply
 *   a day before it ends   one email saying when
 *   when it ends           the sites are paused (stopped, a "paused" page
 *                          served) and an email gives the deletion date
 *   grace_days later       the sites, their files and their databases are
 *                          deleted; the account itself stays, so the same
 *                          address cannot start a second trial
 *   paid again at any time the paused sites are resumed as they were
 *
 * Every step is idempotent and retried on the next run: a host that is down
 * delays a pause or a resume, it never skips one, and nothing is deleted
 * before its date. Operators and accounts with no trial clock are never
 * touched.
 */
class TrialsExpire extends Command
{
    protected $signature = 'trials:expire {--dry-run : say what would happen, change nothing}';

    protected $description = 'Warn, pause and finally delete free trials that ended unpaid; resume paid ones';

    public function handle(Suspension $suspension): int
    {
        $dry = (bool) $this->option('dry-run');
        $paid = array_keys(array_filter(config('billing.plans'), fn ($p) => $p['price'] > 0));

        // Paid again: bring back anything still paused.
        User::whereIn('plan', $paid)->whereNotNull('suspended_at')->each(function (User $user) use ($suspension, $dry) {
            $this->line("{$user->email}: paid again, resuming");
            if (! $dry && ! $suspension->resumeAll($user)) {
                $this->warn("{$user->email}: not every site resumed; retrying next run");
            }
        });

        $this->paymentGrace($paid, $dry);

        // No paid plan for sale: trials do not run out (App\Billing\Sales).
        if (! \App\Billing\Sales::open()) {
            $this->line('paid plans are not for sale: no trial is warned, paused or deleted');

            return self::SUCCESS;
        }

        $free = User::whereNotIn('plan', $paid)->whereNotNull('trial_ends_at');

        // A day to go: say so, once.
        (clone $free)->whereNull('trial_warned_at')
            ->whereBetween('trial_ends_at', [now(), now()->addHours((int) config('billing.trial.warn_hours'))])
            ->each(function (User $user) use ($dry) {
                if ($user->isOperator()) {
                    return;
                }
                $this->line("{$user->email}: trial ends {$user->trial_ends_at}, warning");
                if (! $dry) {
                    $user->forceFill(['trial_warned_at' => now()])->save();
                    $user->notify(new PlanNotice('ending', $user->trial_ends_at));
                }
            });

        // Over: pause, and after the grace period delete.
        (clone $free)->where('trial_ends_at', '<=', now())->each(function (User $user) use ($suspension, $dry) {
            if (! $user->trialExpired()) {
                return; // an operator
            }
            $live = $user->sites()->whereIn('status', ['provisioning', 'live'])->get();

            if (! $user->suspended_at) {
                $this->line("{$user->email}: trial over, pausing {$live->count()} site(s)");
                if ($dry) {
                    return;
                }
                $user->forceFill(['suspended_at' => now()])->save();
                Audit::record('trial.ended', $user, detail: ['sites' => $live->pluck('site_id')->all()]);
                if ($live->isNotEmpty()) {
                    // A former customer is told why in billing terms, not trial terms.
                    $user->notify(new PlanNotice($user->subscriptions()->exists() ? 'downgraded' : 'paused', $user->deletesAt()));
                }
            }
            foreach ($live as $site) {
                // One still provisioning is paused on a later run, once it is live.
                if ($site->status === 'live' && ! $dry && ! $suspension->pause($site)) {
                    $this->warn("{$site->site_id}: not paused; retrying next run");
                }
            }

            if ($user->deletesAt()?->isPast()) {
                $this->delete($user, $dry);
            }
        });

        return self::SUCCESS;
    }

    /**
     * A failed payment keeps the plan for billing.payment_grace_days. A day
     * before that ends the customer is told; when it ends the account moves
     * to free, and the trial stages below pause it and, later, delete it.
     * Accounts on a paid plan with no subscription at all (set by an
     * operator) are never touched here.
     */
    private function paymentGrace(array $paid, bool $dry): void
    {
        User::whereIn('plan', $paid)->whereHas('subscriptions')->each(function (User $user) use ($dry) {
            if ($user->isOperator()) {
                return;
            }
            $sub = $user->subscriptions()->latest('updated_at')->first();
            if ($sub->entitled()) {
                $ends = $sub->inPaymentGrace() ? $sub->graceEndsAt() : null;
                if ($ends && ! $sub->payment_warned_at && $ends->lessThanOrEqualTo(now()->addDay())) {
                    $this->line("{$user->email}: payment grace ends {$ends}, warning");
                    if (! $dry) {
                        $sub->update(['payment_warned_at' => now()]);
                        $user->notify(new PlanNotice('payment_final', $ends));
                    }
                }

                return;
            }
            $this->line("{$user->email}: no longer entitled ({$sub->status}), moving to free");
            if ($dry) {
                return;
            }
            $user->forceFill(['plan' => 'free', 'trial_ends_at' => now()])->save();
            Audit::record('billing.plan_changed', $user, detail: ['from' => 'paid', 'to' => 'free', 'reason' => "payment grace over ({$sub->status})"]);
            app(\App\Fleet\PlanLimits::class)->applyTo($user);
        });
    }

    /**
     * A site that belonged to someone who ever paid is not deleted until a
     * backup taken for the purpose is confirmed - kept 30 days by the
     * retention job, restorable by an operator. True once it exists.
     */
    private function finalBackup(\App\Models\Site $site): bool
    {
        if ($site->final_backup) {
            return true;
        }
        $agent = \App\Fleet\AgentClient::for($site->host);
        try {
            if ($site->final_backup_started_at) {
                $op = $agent->backups($site->site_id)['operation'] ?? null;
                if (($op['kind'] ?? '') === 'backup' && ($op['state'] ?? '') === 'done'
                    && strtotime($op['started'] ?? '') >= $site->final_backup_started_at->getTimestamp() - 5) {
                    $site->update(['final_backup' => $op['snapshot']]);
                    Audit::record('site.final_backup', $site->user, $site, ['snapshot' => $op['snapshot'], 'host' => $site->host]);

                    return true;
                }
                if (($op['state'] ?? '') === 'failed' || $site->final_backup_started_at->lessThan(now()->subHours(3))) {
                    Log::error('final backup failed; the site is NOT deleted', ['site' => $site->site_id, 'op' => $op]);
                    $this->warn("{$site->site_id}: final backup failed - not deleting; retrying");
                    $site->update(['final_backup_started_at' => null]);
                }

                return false;
            }
            $agent->backupNow($site->site_id);
            $site->update(['final_backup_started_at' => now()]);
            $this->line("{$site->site_id}: final backup started");
        } catch (\Throwable $e) {
            Log::warning('final backup not started', ['site' => $site->site_id, 'error' => $e->getMessage()]);
        }

        return false;
    }

    private function delete(User $user, bool $dry): void
    {
        // 'deleting' included: a host that was down mid-delete leaves the row
        // there, and this is what finishes it.
        $sites = $user->sites()->where('status', '!=', 'provisioning')->get();
        if ($sites->isEmpty()) {
            return;
        }
        $this->line("{$user->email}: grace over, deleting ".$sites->pluck('site_id')->join(', '));
        if ($dry) {
            return;
        }
        $removed = 0;
        $everPaid = $user->subscriptions()->exists();
        foreach ($sites as $site) {
            if ($everPaid && ! in_array($site->status, ['deleting', 'failed'], true) && ! $this->finalBackup($site)) {
                continue; // deleted on a later run, once its final backup exists
            }
            try {
                $parts = Provisioner::make()->destroy($site);
                if (! in_array('failed', $parts, true)) {
                    $removed++;
                }
            } catch (\Throwable $e) {
                // destroy() leaves the row marked failed; retried next run.
                Log::error('expired trial site not deleted', ['site' => $site->site_id, 'error' => $e->getMessage()]);
                $this->warn("{$site->site_id}: not deleted - {$e->getMessage()}");
            }
        }
        if ($removed === 0) {
            return;
        }
        Audit::record('trial.deleted', $user, detail: ['removed' => $removed]);
        if (! $user->sites()->exists()) {
            $user->notify(new PlanNotice('deleted'));
        }
    }
}
