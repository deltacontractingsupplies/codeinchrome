<?php

namespace App\Fleet;

use App\Audit\Audit;
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
    /** A new free site asks search engines not to index it for this long (owner's decision, 2026-09-25; sites:indexing). */
    public const NOINDEX_DAYS = 7;

    public function __construct(private readonly Dns $dns) {}

    public static function make(): self
    {
        return new self(Dns::make());
    }

    /**
     * @throws RuntimeException with a message fit to show the customer
     */
    /**
     * @param  string|null  $onHost  pin the site to one host (an operator's probe of a new host)
     */
    public function provision(User $user, string $siteId, ?string $onHost = null): Site
    {
        if ($error = Site::validId($siteId)) {
            throw new RuntimeException($error);
        }

        $plan = $this->planFor($user);

        if ($user->trialExpired()) {
            throw new RuntimeException('Your free trial has ended. Upgrade to Starter to keep building.');
        }
        // A site paused by the abuse checks keeps its account from starting
        // another: deleting it and creating a new one reset the pause (the
        // second security audit, 2026-09-25).
        if ($user->sites()->where('status', 'suspended')->whereIn('paused_reason', ['cpu', 'egress', 'abuse'])->exists()) {
            throw new RuntimeException('One of your sites is paused by our abuse checks. Write to support before creating another.');
        }
        // An account from the network or the browser of an account banned in
        // the last 30 days waits for a person (App\Auth\ClientNet, Device).
        $testSuffix = '@'.strtolower((string) config('signup.test_domain'));
        $isTest = fn (string $email) => config('signup.test_domain') && str_ends_with(strtolower($email), $testSuffix);
        $bannedLike = fn ($query) => $query->whereKeyNot($user->getKey())->where('banned_at', '>=', now()->subDays(30))
            ->get(['email'])->reject(fn ($u) => $isTest($u->email))->isNotEmpty();
        $why = null;
        if (! $user->isPaid() && ! $isTest($user->email)) {
            if ($user->signup_net && $bannedLike(User::where('signup_net', $user->signup_net))) {
                $why = 'signed up from the same network as an account banned in the last 30 days';
            } elseif ($devices = array_filter([$user->signup_device, $user->last_device])) {
                foreach ($devices as $device) {
                    if ($bannedLike(\App\Auth\Device::sameBrowser($device))) {
                        $why = 'uses the same browser as an account banned in the last 30 days';
                        break;
                    }
                }
            }
        }
        if ($why) {
            if (\Illuminate\Support\Facades\Cache::add("abuse.held.{$user->id}", true, now()->addDay())) {
                app(\App\Abuse\Enforcer::class)->holdForReview($user, $why);
            }
            throw new RuntimeException('Your account is being checked before it can create sites. We will be in touch by email.');
        }
        if ($user->storage_over_at) {
            throw new RuntimeException(
                "Your sites use more than the plan's {$plan['storage_gb']} GB of storage. ".
                'Free some space (or move uploads to Cloudflare R2 or S3) before adding a site.'
            );
        }

        $owned = $user->sites()->whereIn('status', ['provisioning', 'live', 'suspended'])->count();
        if ($owned >= $plan['sites']) {
            throw new RuntimeException(
                "The {$plan['name']} plan includes {$plan['sites']} ".
                ($plan['sites'] === 1 ? 'site' : 'sites')." and you have $owned. Upgrade to add another."
            );
        }

        // Paid plans reserved their whole allowance when bought (Stock); a
        // trial site takes room now, if paying customers have left any.
        if ((int) $plan['price'] === 0 && ! app(Stock::class)->siteFits($user->plan ?: 'free')) {
            throw new RuntimeException('New sites are out of stock right now. We are adding capacity; please check back soon.');
        }

        // Unique across the fleet: the subdomain is shared space, so two
        // customers cannot both hold `shop`.
        //
        // A `failed` row is the exception. It means provisioning rolled back
        // CLEANLY - nothing exists on any host and no DNS record resolves - so
        // holding the name against it would punish the customer for our
        // failure and leave the name dead for good. An `orphaned` row is the
        // opposite: the host was unreachable mid-flight, something may well be
        // running there, and the name must stay held until fleet:audit says
        // otherwise.
        $existing = Site::where('site_id', $siteId)->first();
        if ($existing && $existing->status !== 'failed') {
            throw new RuntimeException("The name \"$siteId\" is taken. Try another.");
        }
        $existing?->delete();

        $host = $onHost !== null ? $this->requireHost($onHost) : $this->pickHost($plan['memory']);
        $domain = $siteId.'.'.config('fleet.zone');

        $site = Site::create([
            'user_id' => $user->id,
            'site_id' => $siteId,
            'domain' => $domain,
            'host' => $host,
            'status' => 'provisioning',
            'cpu_limit' => $plan['cpu'],
            'memory_limit' => $plan['memory'],
            'disk_gb' => (int) $plan['disk_gb'],
        ]);

        try {
            $this->requireCapableAgent($host);
            $this->dns->upsert($siteId, config("fleet.hosts.$host.ip"));

            $created = AgentClient::for($host)->createSite($siteId, $domain, $plan['cpu'], $plan['memory'], (int) $plan['disk_gb']);

            $site->update([
                'port' => $created['port'] ?? null,
                'status' => 'live',
                'provisioned_at' => now(),
                'last_error' => null,
            ]);
            Audit::record('site.created', $user, $site, ['host' => $host, 'plan' => $user->plan]);

            // A new free site stays out of search engines for its first week
            // (owner's decision, 2026-09-25; lifted by sites:indexing). Never a
            // reason for the site itself to fail: retried by that command.
            if (! $user->isPaid()) {
                $site->update(['noindex_until' => now()->addDays(self::NOINDEX_DAYS)]);
                try {
                    AgentClient::for($host)->setNoIndex($siteId, true);
                } catch (\Throwable $e) {
                    Log::warning('noindex not applied yet', ['site' => $siteId, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            // Record WHY before cleaning up, so a failure that repeats is
            // diagnosable from the row rather than only from the log.
            $site->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
            Audit::record('site.create_failed', $user, $site, ['host' => $host, 'error' => mb_substr($e->getMessage(), 0, 500)]);
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
            $site->update(['last_error' => $site->last_error.' | DNS record may remain: '.$e->getMessage()]);
        }

        if ($cause instanceof AgentUnreachable) {
            // NOT `failed`. We do not know that nothing exists, and a status
            // that frees the name would hand it to another customer while a
            // container of the first one's may still be serving on it.
            $site->update([
                'status' => 'orphaned',
                'last_error' => $site->last_error.' | Host state UNKNOWN; a container may exist. Run fleet:audit.',
            ]);

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
     * Each part is "removed", "absent" or "failed". The row is deleted only
     * when nothing is left behind - and "absent" counts as nothing left
     * behind, because a part that was never there leaves nothing either.
     * Anything still present keeps the row, so a site whose data survived on
     * the host is never quietly dropped from our records.
     */
    public function destroy(Site $site): array
    {
        $site->update(['status' => 'deleting']);

        $parts = AgentClient::for($site->host)->deleteSite($site->site_id);

        try {
            $this->dns->delete($site->site_id);
            $parts['dns'] = 'removed';
        } catch (\Throwable $e) {
            Log::error('could not remove DNS', ['site' => $site->site_id, 'error' => $e->getMessage()]);
            $parts['dns'] = 'failed';
        }

        $failed = array_keys(array_filter($parts, fn ($state) => $state === 'failed'));

        if ($failed === []) {
            Audit::record('site.deleted', $site->user, $site, ['parts' => $parts]);
            $site->delete();
        } else {
            Audit::record('site.delete_incomplete', $site->user, $site, ['parts' => $parts]);
            $site->update([
                'status' => 'failed',
                'last_error' => 'Not fully removed: '.implode(', ', $failed),
            ]);
        }

        return $parts;
    }

    /**
     * The host with the most memory to spare that can take one more site of
     * this size.
     *
     * Counted from our own rows rather than asking each agent, because a host
     * that is unreachable would otherwise look empty and attract every new
     * site. Its capacity is its own last report to the monitor (Stock); a host
     * whose report is stale takes no new sites. The declared site count still
     * caps it.
     */
    private function requireHost(string $host): string
    {
        if (! config("fleet.hosts.$host")) {
            throw new RuntimeException("No host named $host in the fleet.");
        }

        return $host;
    }

    /** The host a new site of this size would go to, for moves as well as creation. */
    public function chooseHost(string $memoryLimit, ?string $except = null): string
    {
        return $this->pickHost($memoryLimit, $except);
    }

    private function pickHost(string $memoryLimit = '0m', ?string $except = null): string
    {
        $stock = app(Stock::class);
        $need = Stock::megabytes($memoryLimit);
        // Paused sites count: they keep their place on the host, and a resume
        // brings their memory back without asking where.
        $sites = Site::whereIn('status', ['provisioning', 'live', 'suspended'])->get(['host', 'memory_limit']);

        $best = null;
        $bestSpare = -1;

        foreach (config('fleet.hosts') as $name => $cfg) {
            // A draining host keeps its sites and takes no new ones.
            if (($cfg['state'] ?? 'active') !== 'active' || $name === $except) {
                continue;
            }
            $here = $sites->where('host', $name);
            if ($here->count() >= $cfg['capacity']) {
                continue;
            }
            $cap = $stock->hostMemoryMb($name);
            if ($cap === null) {
                continue;
            }
            $spare = $cap - $here->sum(fn ($s) => Stock::megabytes((string) $s->memory_limit)) - $need;
            if ($spare >= 0 && $spare > $bestSpare) {
                $best = $name;
                $bestSpare = $spare;
            }
        }

        if (! $best) {
            // Deliberately not a silent overflow onto a full host. Running out
            // of capacity is an operations problem, and it should look like one.
            throw new RuntimeException('The fleet is at capacity. No new sites can be created until a host is added.');
        }

        return $best;
    }

    /**
     * Refuse to create a site on an agent too old to build it properly.
     *
     * Checked at provision time rather than trusted from a deployment record,
     * because the only thing that proves what a host is running is asking it.
     */
    public function requireCapableAgent(string $host): void
    {
        $version = AgentClient::for($host)->hostInfo()['version'] ?? '0.0.0';
        $minimum = config('fleet.min_agent_version');

        if (version_compare($version, $minimum, '<')) {
            throw new RuntimeException(
                "Host [$host] runs agent $version and this needs at least $minimum. ".
                'An older agent creates a site with no application skeleton and no application key, '.
                "and reports success while doing it. Run: infra/deploy-host.sh $host ".
                config("fleet.hosts.$host.ip")
            );
        }
    }

    private function planFor(User $user): array
    {
        $plan = config("billing.plans.{$user->plan}");

        return $plan ?: config('billing.plans.free');
    }
}
