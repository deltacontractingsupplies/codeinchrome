<?php

namespace App\Console\Commands;

use App\Abuse\Enforcer;
use App\Fleet\AgentClient;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * What sites looked up (audit A21): each host's DNS forwarder logs which
 * site asked for which name. A site asking for a place stolen data is sent
 * to - a Telegram bot, a Discord webhook, a paste or tunnel service - or a
 * mining pool goes to a person. Never a ban on its own: a shop may well
 * send its orders to its owner's Telegram.
 */
class AbuseDns extends Command
{
    protected $signature = 'abuse:dns';

    protected $description = 'Report sites that looked up exfiltration or mining endpoints';

    /** Suffixes: the name itself or any name under it. */
    public const WATCHED = [
        'api.telegram.org' => 'the Telegram bot API (where phishing kits send what they steal)',
        'discord.com' => 'Discord (its webhooks receive stolen data)',
        'discordapp.com' => 'Discord (its webhooks receive stolen data)',
        'pastebin.com' => 'a paste site', 'paste.ee' => 'a paste site', 'hastebin.com' => 'a paste site',
        'transfer.sh' => 'an anonymous file drop', 'file.io' => 'an anonymous file drop', 'anonfiles.com' => 'an anonymous file drop',
        'webhook.site' => 'a request catcher', 'requestbin.com' => 'a request catcher', 'pipedream.net' => 'a request catcher',
        'ngrok.io' => 'a tunnel', 'ngrok-free.app' => 'a tunnel', 'trycloudflare.com' => 'a tunnel', 'serveo.net' => 'a tunnel', 'localhost.run' => 'a tunnel',
        'supportxmr.com' => 'a mining pool', 'nanopool.org' => 'a mining pool', '2miners.com' => 'a mining pool',
        'minexmr.com' => 'a mining pool', 'hashvault.pro' => 'a mining pool', 'herominers.com' => 'a mining pool', 'moneroocean.stream' => 'a mining pool',
    ];

    public function handle(Enforcer $enforcer): int
    {
        $since = now()->subHour()->subMinutes(5)->getTimestamp();
        $failed = 0;
        foreach (array_keys(config('fleet.hosts', [])) as $host) {
            try {
                $lookups = AgentClient::for($host)->dnsLookups($since);
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("$host: ".$e->getMessage());

                continue;
            }
            foreach ($lookups as $siteId => $names) {
                $hits = [];
                foreach (array_keys((array) $names) as $name) {
                    if ($why = self::watched((string) $name)) {
                        $hits[$name] = $why;
                    }
                }
                $site = $hits ? Site::where('site_id', $siteId)->where('host', $host)->first() : null;
                if (! $site) {
                    continue;
                }
                // Once a week per site and name: a person has been told.
                $new = array_filter($hits, fn ($why, $name) => Cache::add("abuse.dns.{$site->id}.$name", true, now()->addWeek()), ARRAY_FILTER_USE_BOTH);
                if ($new) {
                    $enforcer->review($site, 'looked up '.implode('; ', array_map(fn ($n, $w) => "$n ($w)", array_keys($new), $new)));
                    $this->line("{$site->site_id}: ".implode(', ', array_keys($new)));
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    public static function watched(string $name): ?string
    {
        $name = strtolower(rtrim($name, '.'));
        foreach (self::WATCHED as $suffix => $why) {
            if ($name === $suffix || str_ends_with($name, '.'.$suffix)) {
                return $why;
            }
        }

        return null;
    }
}
