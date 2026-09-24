<?php

namespace App\Fleet;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Site;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Checks the fleet from OUTSIDE each host, and turns failures into incidents.
 *
 * A site is checked the way a visitor reaches it: HTTPS to its public name,
 * from the control host. "Up" means the stack answered with anything below
 * 500 - a customer's app may well return 404 at /, and that is not an outage.
 *
 * One failed check is not an outage. An incident opens after FAIL_THRESHOLD
 * consecutive failures and closes on the first success, and an alert is sent
 * on each transition - never once per failed check, which is how alerts get
 * ignored.
 */
class Monitoring
{
    public const FAIL_THRESHOLD = 2;

    // 25%, not 10%: disk is overcommitted (config/fleet.php), so the alert has
    // to leave time to move sites off a filling host (fleet:move-site).
    public const DISK_FREE_MIN = 0.25;

    public function run(): array
    {
        $results = [];

        foreach (array_keys(config('fleet.hosts')) as $host) {
            $results += $this->checkHost($host);
        }

        $results += $this->checkSites();
        $results += $this->checkStock();
        $results += $this->checkFiles();
        $results += $this->checkBackups();

        foreach ($results as $key => [$label, $up, $detail, $latency]) {
            $this->record($key, $label, $up, $detail, $latency);
        }

        $this->retireGone(array_keys($results));

        return $results;
    }

    /**
     * Stock running low is an incident like any other: alerted once when it
     * drops below the threshold, and again when capacity is added - before
     * it sells out, while there is still time to add a host (infra/add-host.sh).
     *
     * @return array<string, array{0: string, 1: bool, 2: string, 3: ?int}>
     */
    private function checkStock(): array
    {
        $stock = app(Stock::class);
        $min = (int) config('fleet.stock.alert_below', 3);
        $out = [];
        foreach (config('billing.plans') as $key => $plan) {
            if ($plan['price'] <= 0) {
                continue;
            }
            $left = $stock->available($key);
            $out["stock:$key"] = ["{$plan['name']} stock", $left >= $min,
                $left >= $min ? "$left available" : "only $left left - add a host with infra/add-host.sh", null];
        }

        return $out;
    }

    /**
     * A site's disk also fills up with FILES: past its inodes it cannot save
     * anything, with bytes to spare (found on a live 1 GB disk). From the
     * figures fleet:sync-usage keeps, alerted at 90%.
     *
     * @return array<string, array{0: string, 1: bool, 2: string, 3: ?int}>
     */
    /**
     * Every live site has a complete backup (files and database) younger than
     * BACKUP_MAX_AGE_HOURS: nightly, with room for a slow night. Watched from
     * its first backup, or once it is that old without one.
     */
    public const BACKUP_MAX_AGE_HOURS = 30;

    private function checkBackups(): array
    {
        $out = [];
        $limit = now()->subHours(self::BACKUP_MAX_AGE_HOURS);
        $sites = \App\Models\Site::where('status', 'live')
            ->where(fn ($q) => $q->where('created_at', '<', $limit)->orWhereNotNull('last_backup_at'))->get();
        foreach ($sites as $site) {
            $last = $site->last_backup_at;
            $out["site:{$site->site_id}:backup"] = ["{$site->domain} backups", $last !== null && $last->greaterThan($limit),
                $last ? 'newest complete backup '.$last->diffForHumans() : 'no complete backup yet', null];
        }

        return $out;
    }

    private function checkFiles(): array
    {
        $out = [];
        foreach (\App\Models\Site::where('status', 'live')->where('inodes_total', '>', 0)->get() as $site) {
            $pct = (int) round(100 * $site->inodes_used / $site->inodes_total);
            $out["site:{$site->site_id}:files"] = ["{$site->domain} files", $pct < 90,
                "$pct% of its file slots used ({$site->inodes_used} of {$site->inodes_total})", null];
        }

        return $out;
    }

    /** @return array<string, array{0: string, 1: bool, 2: string, 3: ?int}> */
    private function checkHost(string $host): array
    {
        $label = "host $host (" . config("fleet.hosts.$host.ip") . ')';
        $started = microtime(true);
        try {
            $s = AgentClient::for($host)->hostStats();
        } catch (\Throwable $e) {
            return ["host:$host" => [$label, false, 'agent unreachable: ' . $e->getMessage(), null]];
        }
        Stock::remember($host, $s);
        $ms = (int) round((microtime(true) - $started) * 1000);

        $out = ["host:$host" => [$label, true, sprintf('load %.2f on %d CPUs, %d%% memory available',
            $s['load1'], $s['cpus'], $s['memTotalBytes'] ? 100 * $s['memAvailableBytes'] / $s['memTotalBytes'] : 0), $ms]];

        $free = $s['diskTotalBytes'] ? $s['diskFreeBytes'] / $s['diskTotalBytes'] : 0;
        $out["host:$host:disk"] = ["$label disk", $free >= self::DISK_FREE_MIN,
            sprintf('%.0f%% free (%s GB of %s GB)', 100 * $free, round($s['diskFreeBytes'] / 1e9), round($s['diskTotalBytes'] / 1e9)), null];
        $out["host:$host:mysql"] = ["$label MySQL", (bool) $s['mysqlUp'], $s['mysqlUp'] ? 'responding' : 'not responding', null];
        $out["host:$host:caddy"] = ["$label proxy", (bool) $s['caddyUp'], $s['caddyUp'] ? 'active' : 'not active', null];

        // A container stopped or a disk unmounted is a customer down, whatever
        // the HTTPS check says (it may be served an error page by the proxy).
        $bad = array_merge(
            array_map(fn ($id) => "$id container not running", $s['sitesNotRunning'] ?? []),
            array_map(fn ($id) => "$id disk NOT MOUNTED", $s['disksUnmounted'] ?? []),
        );
        $out["host:$host:sites"] = ["$label sites", $bad === [], $bad ? implode('; ', $bad) : 'all containers running, all disks mounted', null];

        return $out;
    }

    /** @return array<string, array{0: string, 1: bool, 2: string, 3: ?int}> */
    private function checkSites(): array
    {
        $sites = Site::where('status', 'live')->get();
        if ($sites->isEmpty()) {
            return [];
        }

        $timings = [];
        $responses = Http::pool(function (Pool $pool) use ($sites, &$timings) {
            foreach ($sites as $site) {
                $timings[$site->site_id] = microtime(true);
                $pool->as($site->site_id)->timeout(10)->withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'codeinchrome-monitor'])->get($site->url());
            }
        });

        $out = [];
        foreach ($sites as $site) {
            $r = $responses[$site->site_id] ?? null;
            $label = "site {$site->domain}";
            if ($r instanceof \Throwable || $r === null) {
                $out["site:{$site->site_id}"] = [$label, false, 'no answer: ' . ($r ? $r->getMessage() : 'unknown'), null];

                continue;
            }
            $ms = (int) round((microtime(true) - $timings[$site->site_id]) * 1000);
            $up = $r->status() < 500;
            $out["site:{$site->site_id}"] = [$label, $up, "HTTP {$r->status()}", $ms];
        }

        return $out;
    }

    /**
     * A site that no longer exists is never checked again, so its monitor and
     * any open incident would otherwise stay as they were forever - an
     * incident that can never close, which teaches whoever reads the status
     * page to ignore it. Found on production: a deleted test site had been
     * "down" for hours.
     *
     * Only SITE monitors are retired, and only when the site's row is gone. A
     * host missing from this run is unreachable, not deleted, and keeps its
     * state.
     */
    private function retireGone(array $checkedKeys): void
    {
        // A site's checks are "site:{id}" and "site:{id}:<what>" (its files, ...):
        // retired when the site itself is gone, whatever the suffix.
        $live = array_flip(Site::pluck('site_id')->all());
        $gone = Monitor::where('key', 'like', 'site:%')->pluck('key')
            ->filter(fn ($key) => ! isset($live[explode(':', $key)[1] ?? '']));

        foreach ($gone as $key) {
            Incident::where('monitor_key', $key)->whereNull('resolved_at')->get()->each(function ($i) {
                $i->update(['resolved_at' => now(), 'detail' => $i->detail . ' [closed: the site was deleted]']);
            });
            Monitor::where('key', $key)->delete();
        }
    }

    private function record(string $key, string $label, bool $up, string $detail, ?int $latency): void
    {
        $m = Monitor::firstOrNew(['key' => $key]);
        $m->fill([
            'label' => $label, 'detail' => $detail, 'latency_ms' => $latency, 'checked_at' => now(),
            'fail_streak' => $up ? 0 : ($m->fail_streak ?? 0) + 1,
        ]);

        $open = Incident::where('monitor_key', $key)->whereNull('resolved_at')->first();

        if (! $up && $m->fail_streak >= self::FAIL_THRESHOLD && ! $open) {
            $incident = Incident::create(['monitor_key' => $key, 'label' => $label, 'detail' => $detail, 'started_at' => now()]);
            $incident->update(['alerted' => $this->alert("DOWN: $label - $detail")]);
        }
        if ($up && $open) {
            $open->update(['resolved_at' => now()]);
            $minutes = max(1, (int) round($open->started_at->diffInMinutes(now())));
            $this->alert("RECOVERED: $label after about $minutes min");
        }

        $m->up = $up || $m->fail_streak < self::FAIL_THRESHOLD;
        $m->save();
    }

    /** Returns whether the alert was delivered. Logged either way. */
    public function alert(string $text): bool
    {
        Log::warning('monitoring alert', ['text' => $text]);

        $url = config('fleet.alert_webhook');
        if (! $url) {
            // No webhook: email the operators, if mail really goes out.
            $to = config('fleet.admin_emails');
            if (! config('fleet.mail_enabled') || ! $to) {
                return false;
            }
            try {
                \Illuminate\Support\Facades\Mail::raw($text . "\n\nhttps://app.codeinchrome.com/status", function ($m) use ($to, $text) {
                    $m->to($to)->subject('[codeinchrome] ' . mb_substr($text, 0, 120));
                });

                return true;
            } catch (\Throwable $e) {
                Log::error('alert email failed', ['error' => $e->getMessage()]);

                return false;
            }
        }
        try {
            // {"text": ...} is accepted by Slack incoming webhooks and by
            // Discord's /slack-compatible endpoint.
            return Http::timeout(10)->post($url, ['text' => "[codeinchrome] $text"])->successful();
        } catch (\Throwable $e) {
            Log::error('alert delivery failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
