<?php

namespace App\Console\Commands;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Fleet\Suspension;
use App\Models\Site;
use Illuminate\Console\Command;
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
                Log::warning('site paused: scanning', ['site' => $site->site_id] + $e);
                $this->error("{$site->site_id}: $what - paused");
                $this->tellOwner($site, $what, $paused);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
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
