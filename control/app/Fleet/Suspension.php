<?php

namespace App\Fleet;

use App\Audit\Audit;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Pausing and resuming a site: the agent stops the container and serves a
 * plain "paused" page, or starts it again. Nothing on the site's disk or in
 * its database is touched either way.
 *
 * Neither call throws. A host that is down leaves the site as it was, and the
 * next trials:expire run tries again - a payment must never fail, and a trial
 * must never be deleted early, because a host happened to be unreachable.
 */
class Suspension
{
    /** $reason: trial | abuse | cpu | egress | idle - only an idle pause can be undone by its owner (SiteController::wake). */
    public function pause(Site $site, string $reason): bool
    {
        try {
            AgentClient::for($site->host)->setSuspended($site->site_id, true);
        } catch (\Throwable $e) {
            Log::warning('site not paused', ['site' => $site->site_id, 'error' => $e->getMessage()]);

            return false;
        }
        $site->update(['status' => 'suspended', 'paused_reason' => $reason]);
        Audit::record('site.paused', $site->user, $site, ['reason' => $reason]);

        return true;
    }

    public function resume(Site $site): bool
    {
        // A ban is lifted only by abuse:unban (App\Abuse\Enforcer), never by a payment.
        if ($site->user?->banned_at) {
            return false;
        }
        try {
            AgentClient::for($site->host)->setSuspended($site->site_id, false);
        } catch (\Throwable $e) {
            Log::warning('site not resumed', ['site' => $site->site_id, 'error' => $e->getMessage()]);

            return false;
        }
        $site->update(['status' => 'live', 'paused_reason' => null]);
        Audit::record('site.resumed', $site->user, $site);

        return true;
    }

    /**
     * Brings back every paused site of an account that is paid again, then
     * gives them the plan's limits. True when nothing is left paused.
     */
    public function resumeAll(User $user): bool
    {
        $all = true;
        foreach ($user->sites()->where('status', 'suspended')->get() as $site) {
            if ($this->resume($site)) {
                app(PlanLimits::class)->applyToSite($site, $user->planConfig());
            } else {
                $all = false;
            }
        }
        if ($all && $user->suspended_at) {
            $user->forceFill(['suspended_at' => null])->save();
        }

        return $all;
    }
}
