<?php

namespace App\Fleet;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The dead-man's switch for the scheduler. Monitoring is itself a scheduled
 * job, so if cron stopped it would stop too and say nothing. After every
 * fleet:monitor run - whatever it found; a failed check is monitoring's own
 * alert - this pings an outside service (Healthchecks.io, Better Stack) that
 * alerts when the pings STOP. Off until CIC_HEARTBEAT_URL is set.
 */
class Heartbeat
{
    public static function ping(): void
    {
        $url = config('fleet.heartbeat_url');
        if (! $url) {
            return;
        }
        try {
            Http::timeout(10)->retry(2, 1000, throw: false)->get($url);
        } catch (\Throwable $e) {
            // The heartbeat service being down must never break monitoring;
            // it alerts on the missing pings by itself.
            Log::warning('heartbeat ping failed', ['error' => $e->getMessage()]);
        }
    }
}
