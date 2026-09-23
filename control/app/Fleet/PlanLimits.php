<?php

namespace App\Fleet;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Makes a customer's running sites match their plan.
 *
 * Called when a plan changes, and retried by fleet:apply-limits. A failure on
 * one host never fails the whole operation - and never fails the billing
 * webhook that triggered it: the payment is recorded regardless, and the site
 * is marked limits_pending until the host accepts the new ceilings.
 */
class PlanLimits
{
    /** @return array<string, string> site id => outcome */
    public function applyTo(User $user): array
    {
        $outcomes = [];
        foreach ($user->sites()->where('status', 'live')->get() as $site) {
            $outcomes[$site->site_id] = $this->applyToSite($site, $user->planConfig());
        }

        return $outcomes;
    }

    public function applyToSite(Site $site, array $plan): string
    {
        try {
            $applied = AgentClient::for($site->host)->setLimits(
                $site->site_id, $plan['cpu'], $plan['memory'], (int) $plan['disk_gb'],
            );
        } catch (\Throwable $e) {
            $site->update(['limits_pending' => true]);
            Log::warning('plan limits not applied', ['site' => $site->site_id, 'error' => $e->getMessage()]);

            return 'pending: '.$e->getMessage();
        }

        // The disk only grows. Record what the host actually has, not what
        // the plan asked for: after a downgrade they differ, and the row must
        // describe the site as it is.
        // A plan without background processes: switch any off (a downgrade).
        if (! ($plan['background'] ?? false) && ($site->queue || $site->scheduler || $site->reverb)) {
            try {
                AgentClient::for($site->host)->setBackground($site->site_id, false, false, false);
                $site->update(['queue' => false, 'scheduler' => false, 'reverb' => false]);
            } catch (\Throwable $e) {
                $site->update(['limits_pending' => true]);
                Log::warning('background processes not switched off', ['site' => $site->site_id, 'error' => $e->getMessage()]);
            }
        }

        $site->update([
            'cpu_limit' => $plan['cpu'],
            'memory_limit' => $plan['memory'],
            'disk_gb' => max((int) $site->disk_gb, (int) $plan['disk_gb']),
            'limits_pending' => false,
        ]);

        return json_encode($applied);
    }
}
