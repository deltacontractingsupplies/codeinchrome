<?php

namespace App\Fleet;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * What the fleet can still sell.
 *
 * A plan is in stock only if the fleet can hold ALL of it - every site the
 * plan allows, at the plan's per-site CPU and memory, and the plan's storage -
 * on top of everything already promised to paying customers. Paid accounts
 * are promised their whole plan the moment they buy it (they may create their
 * sites at any time).
 *
 * Free trials reserve nothing. They are counted by the sites they actually
 * run, and only when deciding whether one more trial site fits: a trial takes
 * what paying customers have left over, never the other way round, so the
 * "N left" a buyer sees does not shrink because someone is trying us out.
 *
 * Capacity is each host's REAL resources, as the host itself last reported
 * them to the monitor (fleet:monitor, every minute), minus a reserve for the
 * host's own services, times a configured overcommit: sites rarely sit at
 * their memory ceiling, and CPU is shared by design. Disk is never
 * overcommitted - a quota is a promise the kernel enforces.
 *
 * A host whose figures are missing or older than fresh_seconds adds nothing:
 * when we cannot see the fleet, we do not sell into it.
 */
class Stock
{
    private const KEY = 'fleet.stock.host.';

    /** Called by the monitor with the host's own figures. */
    public static function remember(string $host, array $stats): void
    {
        Cache::put(self::KEY.$host, [
            'cpus' => (int) $stats['cpus'],
            'mem_mb' => intdiv((int) $stats['memTotalBytes'], 1024 ** 2),
            'disk_gb' => intdiv((int) $stats['diskTotalBytes'], 1024 ** 3),
            'at' => now()->getTimestamp(),
        ], now()->addDay());
    }

    /** @return array{cpu: float, memory_mb: int, disk_gb: int} */
    public function capacity(): array
    {
        $cfg = config('fleet.stock');
        $cap = ['cpu' => 0.0, 'memory_mb' => 0, 'disk_gb' => 0];

        foreach (config('fleet.hosts') as $host => $hostCfg) {
            // A draining host is on its way out: nothing new is sold into it.
            if (($hostCfg['state'] ?? 'active') !== 'active') {
                continue;
            }
            $h = Cache::get(self::KEY.$host);
            if (! $h || now()->getTimestamp() - $h['at'] > $cfg['fresh_seconds']) {
                continue;
            }
            $cap['cpu'] += $h['cpus'] * $cfg['overcommit']['cpu'];
            $cap['memory_mb'] += (int) (max(0, $h['mem_mb'] - $cfg['reserve']['memory_mb']) * $cfg['overcommit']['memory']);
            $cap['disk_gb'] += (int) (max(0, $h['disk_gb'] - $cfg['reserve']['disk_gb']) * $cfg['overcommit']['disk']);
        }

        return $cap;
    }

    /** One host's memory capacity in MB, or null if its figures are stale. */
    public function hostMemoryMb(string $host): ?int
    {
        $cfg = config('fleet.stock');
        $h = Cache::get(self::KEY.$host);
        if (! $h || now()->getTimestamp() - $h['at'] > $cfg['fresh_seconds']) {
            return null;
        }

        return (int) (max(0, $h['mem_mb'] - $cfg['reserve']['memory_mb']) * $cfg['overcommit']['memory']);
    }

    /** @return array{cpu: float, memory_mb: int, disk_gb: int} */
    public function committed(?User $except = null, bool $paidOnly = false): array
    {
        $sum = ['cpu' => 0.0, 'memory_mb' => 0, 'disk_gb' => 0];
        $add = function (array $f) use (&$sum) {
            foreach ($f as $k => $v) {
                $sum[$k] += $v;
            }
        };

        $paid = array_keys(array_filter(config('billing.plans'), fn ($p) => $p['price'] > 0));
        User::whereIn('plan', $paid)->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->pluck('plan')->each(fn ($plan) => $add(self::footprint($plan)));

        if ($paidOnly) {
            return $sum;
        }

        // Free accounts: the sites they run, at their own recorded limits. A
        // paused site holds no CPU or memory, only its disk.
        Site::whereIn('status', ['provisioning', 'live', 'suspended'])
            ->whereHas('user', fn ($q) => $q->whereNotIn('plan', $paid))
            ->when($except, fn ($q) => $q->where('user_id', '!=', $except->getKey()))
            ->get(['status', 'cpu_limit', 'memory_limit', 'disk_gb'])
            ->each(fn ($s) => $add([
                'cpu' => $s->status === 'suspended' ? 0.0 : (float) $s->cpu_limit,
                'memory_mb' => $s->status === 'suspended' ? 0 : self::megabytes((string) $s->memory_limit),
                'disk_gb' => (int) ($s->disk_gb ?: config('billing.plans.free.disk_gb')),
            ]));

        return $sum;
    }

    /**
     * Everything a plan may use: its site allowance at its per-site CPU and
     * memory, and its storage - the plan total where it has one, since each
     * site's own ceiling is only a ceiling and together they share the total.
     */
    public static function footprint(string $planKey, ?int $sites = null): array
    {
        $p = config("billing.plans.$planKey");
        $n = $sites ?? (int) $p['sites'];

        return [
            'cpu' => $n * (float) $p['cpu'],
            'memory_mb' => $n * self::megabytes($p['memory']),
            'disk_gb' => min($n * (int) $p['disk_gb'], (int) ($p['storage_gb'] ?? PHP_INT_MAX)),
        ];
    }

    /** How many more of this plan the fleet can take. 0 is out of stock. */
    public function available(string $planKey): int
    {
        return $this->fits(self::footprint($planKey), $this->committed(paidOnly: true));
    }

    /**
     * The same for one customer changing plan: what they hold now is released
     * by the change, so it is not counted against them.
     */
    public function availableFor(User $user, string $planKey): int
    {
        return $this->fits(self::footprint($planKey), $this->committed($user, paidOnly: true));
    }

    /**
     * Whether one more site of this plan's size fits (a trial's new site):
     * counted against everything, trials included, so trials only ever use
     * room that is really free.
     */
    public function siteFits(string $planKey): bool
    {
        return $this->fits(self::footprint($planKey, 1), $this->committed()) > 0;
    }

    /**
     * How many more free trial sites fit right now (owner, 2026-09-26: show
     * how many free places there are). The same room siteFits() gives one
     * trial - what is left after paid plans' whole allowance AND every
     * running trial - counted instead of yes/no.
     */
    public function trialsLeft(): int
    {
        return $this->fits(self::footprint('free', 1), $this->committed());
    }

    private function fits(array $need, array $committed): int
    {
        $cap = $this->capacity();
        $count = PHP_INT_MAX;
        foreach ($need as $k => $v) {
            if ($v <= 0) {
                continue;
            }
            $count = min($count, (int) floor(max(0, $cap[$k] - $committed[$k]) / $v + 1e-9));
        }

        return $count === PHP_INT_MAX ? 0 : $count;
    }

    /** "512m", "1024m", "2g" -> megabytes. */
    public static function megabytes(string $limit): int
    {
        $n = (float) $limit;

        return (int) round(match (strtolower(substr(trim($limit), -1))) {
            'g' => $n * 1024,
            'k' => $n / 1024,
            default => $n,
        });
    }
}
