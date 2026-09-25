<?php

namespace App\Console\Commands;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\Suspension;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A site reaching out to hundreds of hosts - a port scan or a vulnerability
 * sweep, the netscans Hetzner suspends servers for - is paused at once and
 * the owner told (agent sites/egress.go). Paused, not banned: the owner
 * decides, with the numbers in front of them.
 */
class AbuseEgress extends Command
{
    /** Distinct public hosts in the last two minutes: an ordinary app talks to a handful. */
    public const MAX_HOSTS = 150;

    /** Distinct destination ports: a port scan of one machine. */
    public const MAX_PORTS = 100;

    /**
     * Refused connections logged in ten minutes (the log keeps at most 6 a
     * minute per site): at this many the site has hammered a refused target
     * for most of the window - an SSH brute force, a scan answered with
     * resets, spam. The owner is told, once an hour; a buggy app can do it
     * too, so nothing is paused for it.
     */
    public const MAX_REFUSALS = 30;

    /**
     * Bytes a site may send in ten minutes (about 13 Mbit/s held that long):
     * past it a free site is paused, a paid one reported. An app answering
     * its visitors sends that through Caddy, not out of its bridge, so this
     * is the site pushing data to the internet itself - a flood, or a relay
     * (the second security audit, 2026-09-25: nothing measured volume).
     */
    public const MAX_SENT_10_MIN = 1_000_000_000;

    /**
     * Open connections to ONE destination: an ordinary app keeps a handful
     * to an API; hundreds are a flood or credential stuffing against one
     * target, which the distinct-host count never saw. Reported, not paused.
     */
    public const MAX_TO_ONE_TARGET = 200;

    protected $signature = 'abuse:egress';

    protected $description = 'Pause a site that reaches out to hundreds of hosts or ports (a scan)';

    public function handle(Suspension $suspension): int
    {
        $failed = 0;
        foreach (array_keys(config('fleet.hosts')) as $host) {
            try {
                $sites = AgentClient::for($host)->egress()['sites'] ?? [];
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("$host: ".$e->getMessage());

                continue;
            }
            foreach ($sites as $e) {
                if (($e['refusals'] ?? 0) >= self::MAX_REFUSALS) {
                    $this->refusals($e);
                }
                if (isset($e['sent_bytes'])) {
                    $this->volume($e, $suspension);
                }
                if (($e['top_target_conns'] ?? 0) >= self::MAX_TO_ONE_TARGET
                    && Cache::add("abuse.target.{$e['site']}", true, now()->addHour())
                    && ($site = Site::with('user')->where('site_id', $e['site'])->where('status', 'live')->first())) {
                    $this->warn("{$site->site_id}: {$e['top_target_conns']} connections to one target - owner told");
                    app(\App\Abuse\Enforcer::class)->review($site, "{$e['top_target_conns']} open connections to one destination ({$e['top_target']}): a flood or credential stuffing, or an app with a runaway connection pool");
                }
                if (($e['distinct_hosts'] ?? 0) < self::MAX_HOSTS && ($e['distinct_ports'] ?? 0) < self::MAX_PORTS) {
                    continue;
                }
                $site = Site::where('site_id', $e['site'])->where('status', 'live')->first();
                if (! $site) {
                    continue;
                }
                $paused = $suspension->pause($site, 'egress');
                $what = "{$e['distinct_hosts']} distinct hosts, {$e['distinct_ports']} distinct ports, {$e['connections']} connections in about two minutes";
                Audit::record('abuse.scan_paused', $site->user, $site, detail: $e + ['paused' => $paused]);
                if ($site->user) {
                    app(\App\Abuse\Enforcer::class)->escalateRepeatPause($site->user, "scanning from {$site->domain}");
                }
                Log::warning('site paused: scanning', ['site' => $site->site_id] + $e);
                $this->error("{$site->site_id}: $what - paused");
                $this->tellOwner($site, $what, $paused);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function volume(array $e, Suspension $suspension): void
    {
        $key = "abuse.sent.{$e['site']}";
        $now = now()->getTimestamp();
        // [timestamp, counter] readings of the last ten minutes. A counter that
        // went down is a recreated bridge: start again from it.
        $history = array_values(array_filter(Cache::get($key, []), fn ($h) => $h[0] >= $now - 660 && $h[1] <= $e['sent_bytes']));
        $history[] = [$now, (int) $e['sent_bytes']];
        Cache::put($key, $history, now()->addMinutes(15));
        $sent = $e['sent_bytes'] - $history[0][1];
        if ($sent < self::MAX_SENT_10_MIN || ! Cache::add("abuse.sent.acted.{$e['site']}", true, now()->addHour())) {
            return;
        }
        $site = Site::with('user')->where('site_id', $e['site'])->where('status', 'live')->first();
        if (! $site) {
            return;
        }
        $what = round($sent / 1e9, 1).' GB sent to the internet in about '.round(($now - $history[0][0]) / 60).' minutes';
        $paused = ! $site->user?->isPaid() && $suspension->pause($site, 'egress');
        Audit::record('abuse.volume', $site->user, $site, detail: ['bytes' => $sent, 'paused' => $paused]);
        $this->error("{$site->site_id}: $what".($paused ? ' - paused' : ' - reported'));
        if ($paused && $site->user) {
            app(\App\Abuse\Enforcer::class)->escalateRepeatPause($site->user, "sending $what");
        }
        app(\App\Abuse\Enforcer::class)->review($site, "$what".($paused ? ' - the site is paused (php artisan abuse:resume '.$site->site_id.')' : ' (a paid site: not paused)'));
    }

    private function refusals(array $e): void
    {
        $site = Site::where('site_id', $e['site'])->where('status', 'live')->first();
        if (! $site || ! \Illuminate\Support\Facades\Cache::add("abuse.refusals.{$site->site_id}", true, now()->addHour())) {
            return;
        }
        Audit::record('abuse.egress_refused', $site->user, $site, detail: ['refusals' => $e['refusals']]);
        $this->warn("{$site->site_id}: {$e['refusals']} refused connections in ten minutes - owner told");
        $to = config('fleet.owner_notify_email') ?: config('fleet.admin_emails');
        if (! $to || ! config('fleet.mail_enabled')) {
            return;
        }
        $body = "https://{$site->domain} kept trying connections the platform refuses - {$e['refusals']} logged in ten minutes "
            ."(the log keeps at most 6 a minute). That is how an SSH brute force, a scan or spam looks; a broken app can do it too.\n"
            .'Account: '.($site->user?->email ?? '?')."\nThe host's kernel log has each one (\"cic-egress: \").\n\n"
            ."To pause the site: php artisan abuse:ban ".($site->user?->email ?? '<email>').' --reason="brute force"';
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject("[codeinchrome] Refused connections from {$site->domain}"));
        } catch (\Throwable $ex) {
            Log::error('egress email failed', ['error' => $ex->getMessage()]);
        }
    }

    private function tellOwner(Site $site, string $what, bool $paused): void
    {
        $to = config('fleet.owner_notify_email') ?: config('fleet.admin_emails');
        if (! $to || ! config('fleet.mail_enabled')) {
            return;
        }
        $body = "https://{$site->domain} reached $what - a scan - and was paused (".($paused ? 'done' : 'NOT done: host unreachable').").\n"
            .'Account: '.($site->user?->email ?? '?')."\n\nIf it is legitimate: php artisan abuse:resume {$site->site_id}\n"
            .'If it is abuse: php artisan abuse:ban '.($site->user?->email ?? '<email>').' --reason="network scanning"';
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject("[codeinchrome] Site paused for scanning: {$site->domain}"));
        } catch (\Throwable $e) {
            Log::error('egress email failed', ['error' => $e->getMessage()]);
        }
    }
}
