<?php

namespace App\Console\Commands;

use App\Abuse\Enforcer;
use App\Fleet\Suspension;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Our sites in the world's threat feeds (audit A6). Hosted sites share
 * codeinchrome.com with the dashboard, so a site other people have already
 * seen serving malware or phishing is ours to act on at once: a free site
 * is paused and a person looks; a paid one goes to a person (a third-party
 * list can be wrong about a paying customer). Our own dashboard in a feed
 * is an alarm. Public feeds need no key; Google Safe Browsing's verdicts
 * are added when a key is configured.
 */
class AbuseFeeds extends Command
{
    protected $signature = 'abuse:feeds';

    protected $description = 'Act on our sites listed in public threat feeds';

    public function handle(Suspension $suspension, Enforcer $enforcer): int
    {
        $ours = $this->ourHosts();
        $listed = [];
        $fetched = 0;
        foreach (config('fleet.threat_feeds', []) as $feed => $url) {
            try {
                $res = Http::timeout(30)->withHeaders(['User-Agent' => 'codeinchrome abuse monitor'])->get($url);
            } catch (\Throwable $e) {
                $this->warn("$feed: ".$e->getMessage());

                continue;
            }
            if (! $res->successful()) {
                $this->warn("$feed: HTTP {$res->status()}");

                continue;
            }
            $fetched++;
            foreach (preg_split('/\R/', substr($res->body(), 0, 20 << 20)) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $host = strtolower((string) parse_url($line, PHP_URL_HOST));
                if ($host !== '' && array_key_exists($host, $ours)) {
                    $listed[] = [$host, $line, $feed];
                }
            }
        }
        foreach ($this->safeBrowsing($ours) as [$host, $url, $feed]) {
            $listed[] = [$host, $url, $feed];
            $fetched = max($fetched, 1);
        }

        foreach ($listed as [$host, $url, $feed]) {
            $site = $ours[$host];
            if ($site === null) {
                // The dashboard itself: nothing to pause, everything to know.
                Log::critical('the platform itself is in a threat feed', ['host' => $host, 'url' => $url, 'feed' => $feed]);
                $this->error("PLATFORM LISTED: $url ($feed)");

                continue;
            }
            if (! Cache::add('abuse.feed.'.$site->id.'.'.sha1($url), true, now()->addWeek())) {
                continue; // acted on this week
            }
            $what = "listed by $feed: $url";
            if (! $site->user?->isPaid() && $site->status === 'live' && $suspension->pause($site, 'abuse')) {
                $enforcer->review($site, "paused - $what. Look, then: php artisan abuse:resume {$site->site_id}   or   php artisan abuse:ban ".($site->user?->email ?? '<email>'));
                $this->line("{$site->site_id}: paused ($what)");
            } else {
                $enforcer->review($site, $what);
                $this->line("{$site->site_id}: for review ($what)");
            }
        }
        $this->info(count($listed).' listing(s) of our sites');

        return $fetched ? self::SUCCESS : self::FAILURE;
    }

    /** host => Site for every hosted name; null for the platform's own names. */
    private function ourHosts(): array
    {
        $zone = strtolower((string) config('fleet.zone', 'codeinchrome.com'));
        $ours = [$zone => null, "www.$zone" => null, strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)) => null];
        foreach (Site::with('user')->whereIn('status', ['live', 'suspended'])->get() as $site) {
            $ours[strtolower($site->domain)] = $site;
        }
        // Verified domains only: anyone can CLAIM a name they do not own, and a
        // listing of that name is not about this site.
        foreach (SiteDomain::with('site.user')->whereNotNull('verified_at')->get() as $d) {
            if ($d->site && in_array($d->site->status, ['live', 'suspended'], true)) {
                $ours[strtolower($d->domain)] = $d->site;
            }
        }
        unset($ours['']);

        return $ours;
    }

    /** Google Safe Browsing Lookup API (v4), when a key is configured. */
    private function safeBrowsing(array $ours): array
    {
        $key = config('fleet.safe_browsing_key');
        if (! $key) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_keys($ours), 400) as $chunk) {
            try {
                $res = Http::timeout(30)->post('https://safebrowsing.googleapis.com/v4/threatMatches:find?key='.urlencode($key), [
                    'client' => ['clientId' => 'codeinchrome', 'clientVersion' => '1'],
                    'threatInfo' => [
                        'threatTypes' => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                        'platformTypes' => ['ANY_PLATFORM'],
                        'threatEntryTypes' => ['URL'],
                        'threatEntries' => array_map(fn ($h) => ['url' => "https://$h/"], $chunk),
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->warn('Safe Browsing: '.$e->getMessage());

                continue;
            }
            foreach ($res->json('matches') ?? [] as $m) {
                $url = (string) ($m['threat']['url'] ?? '');
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if (array_key_exists($host, $ours)) {
                    $out[] = [$host, $url, 'Google Safe Browsing ('.($m['threatType'] ?? 'threat').')'];
                }
            }
        }

        return $out;
    }
}
