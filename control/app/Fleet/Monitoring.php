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

    public const DISK_FREE_MIN = 0.10;

    public function run(): array
    {
        $results = [];

        foreach (array_keys(config('fleet.hosts')) as $host) {
            $results += $this->checkHost($host);
        }

        $results += $this->checkSites();

        foreach ($results as $key => [$label, $up, $detail, $latency]) {
            $this->record($key, $label, $up, $detail, $latency);
        }

        $this->retireGone(array_keys($results));

        return $results;
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
        $live = Site::pluck('site_id')->map(fn ($id) => "site:$id")->all();
        $gone = Monitor::where('key', 'like', 'site:%')->whereNotIn('key', $live)->pluck('key');

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
    private function alert(string $text): bool
    {
        Log::warning('monitoring alert', ['text' => $text]);

        $url = config('fleet.alert_webhook');
        if (! $url) {
            return false;
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
