<?php

namespace App\Fleet;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Creates and destroys customer sites across the fleet.
 *
 * Provisioning touches three systems that cannot be made atomic together: a
 * database row, a DNS record and a container on a host. So the ordering is
 * chosen so that a failure at any point leaves nothing that SERVES:
 *
 *   1. row (status=provisioning)  - visible, not serving
 *   2. DNS                        - resolves to a host with no vhost: connection refused
 *   3. container + vhost          - now it serves
 *   4. row (status=live)          - and now we say so
 *
 * A half-provisioned site that answers on a domain is worse than none, which
 * is the same reason the agent's own Create cleans up after itself.
 */
class Provisioner
{
    public function __construct(private readonly Dns $dns) {}

    public static function make(): self
    {
        return new self(Dns::make());
    }

    /**
     * @throws RuntimeException with a message fit to show the customer
     */
    public function provision(User $user, string $siteId): Site
    {
        if ($error = Site::validId($siteId)) {
            throw new RuntimeException($error);
        }

        $plan = $this->planFor($user);

        $owned = $user->sites()->whereIn('status', ['provisioning', 'live'])->count();
        if ($owned >= $plan['sites']) {
            throw new RuntimeException(
                "The {$plan['name']} plan includes {$plan['sites']} " .
                ($plan['sites'] === 1 ? 'site' : 'sites') . " and you have $owned. Upgrade to add another."
            );
        }

        // Unique across the fleet: the subdomain is shared space, so two
        // customers cannot both hold `shop`.
        if (Site::where('site_id', $siteId)->exists()) {
            throw new RuntimeException("The name \"$siteId\" is taken. Try another.");
        }

        $host = $this->pickHost();
        $domain = $siteId . '.' . config('fleet.zone');

        $site = Site::create([
            'user_id' => $user->id,
            'site_id' => $siteId,
            'domain' => $domain,
            'host' => $host,
            'status' => 'provisioning',
            'cpu_limit' => $plan['cpu'],
            'memory_limit' => $plan['memory'],
        ]);

        try {
            $this->dns->upsert($siteId, config("fleet.hosts.$host.ip"));

            $created = AgentClient::for($host)->createSite($siteId, $domain, $plan['cpu'], $plan['memory']);

            $site->update([
                'port' => $created['port'] ?? null,
                'status' => 'live',
                'provisioned_at' => now(),
                'last_error' => null,
            ]);
        } catch (\Throwable $e) {
            // Record WHY before cleaning up, so a failure that repeats is
            // diagnosable from the row rather than only from the log.
            $site->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
            Log::error('provisioning failed', ['site' => $siteId, 'host' => $host, 'error' => $e->getMessage()]);

            $this->rollback($site, $e);

            throw new RuntimeException("Could not create \"$siteId\": {$e->getMessage()}");
        }

        return $site->fresh();
    }

    /**
     * Undo a failed provision, and be honest when the undo itself fails.
     *
     * An AgentUnreachable during provisioning means the state of the host is
     * UNKNOWN - the container may well exist. Deleting the DNS record in that
     * case is right (nothing should resolve to a site we are not tracking),
     * but the row is kept as `failed` rather than removed, because deleting it
     * would lose the only record that something may be running out there.
     */
    private function rollback(Site $site, \Throwable $cause): void
    {
        try {
            $this->dns->delete($site->site_id);
        } catch (\Throwable $e) {
            Log::error('rollback could not remove DNS', ['site' => $site->site_id, 'error' => $e->getMessage()]);
            $site->update(['last_error' => $site->last_error . ' | DNS record may remain: ' . $e->getMessage()]);
        }

        if ($cause instanceof AgentUnreachable) {
            $site->update(['last_error' => $site->last_error . ' | Host state UNKNOWN; a container may exist. Run fleet:audit.']);

            return;
        }

        try {
            AgentClient::for($site->host)->deleteSite($site->site_id);
        } catch (AgentRefused) {
            // The agent cleans up after its own failed Create, so "no such
            // site" here is the expected and correct answer.
        } catch (\Throwable $e) {
            Log::error('rollback could not remove site', ['site' => $site->site_id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Remove a site completely, reporting per part.
     *
     * Never claims success it cannot see: if the agent reports that the
     * container went but the data did not, the row stays and says so.
     */
    public function destroy(Site $site): array
    {
        $site->update(['status' => 'deleting']);

        $removed = AgentClient::for($site->host)->deleteSite($site->site_id);
        $dnsGone = false;

        try {
            $dnsGone = $this->dns->delete($site->site_id);
        } catch (\Throwable $e) {
            Log::error('could not remove DNS', ['site' => $site->site_id, 'error' => $e->getMessage()]);
        }

        $parts = $removed + ['dns' => $dnsGone];

        if (! in_array(false, $parts, true)) {
            $site->delete();
        } else {
            $failed = implode(', ', array_keys(array_filter($parts, fn ($ok) => ! $ok)));
            $site->update(['status' => 'failed', 'last_error' => "Not fully removed: $failed"]);
        }

        return $parts;
    }

    /**
     * Least-loaded host with capacity.
     *
     * Counts from our own rows rather than asking each agent, because a host
     * that is unreachable would otherwise look empty and attract every new
     * site. Packing is the point - the margin comes from filling hosts rather
     * than giving each customer a VM - but a host is never filled past its
     * declared capacity.
     */
    private function pickHost(): string
    {
        $counts = Site::whereIn('status', ['provisioning', 'live'])
            ->selectRaw('host, count(*) as total')
            ->groupBy('host')
            ->pluck('total', 'host');

        $best = null;
        $bestLoad = PHP_INT_MAX;

        foreach (config('fleet.hosts') as $name => $cfg) {
            $used = (int) ($counts[$name] ?? 0);
            if ($used >= $cfg['capacity']) {
                continue;
            }
            if ($used < $bestLoad) {
                $best = $name;
                $bestLoad = $used;
            }
        }

        if (! $best) {
            // Deliberately not a silent overflow onto a full host. Running out
            // of capacity is an operations problem, and it should look like one.
            throw new RuntimeException('The fleet is at capacity. No new sites can be created until a host is added.');
        }

        return $best;
    }

    private function planFor(User $user): array
    {
        $plan = config("billing.plans.{$user->plan}");

        return $plan ?: config('billing.plans.free');
    }
}
