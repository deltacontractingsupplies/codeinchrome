<?php

namespace App\Console\Commands;

use App\Audit\Audit;
use App\Fleet\AgentClient;
use App\Models\Site;
use App\Support\BoundedSink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Move sites running an outdated base image onto the current one.
 *
 * One site at a time, per host. After each, the site is checked from OUTSIDE
 * (HTTPS to its public name, as a visitor reaches it). The first failure
 * stops the roll on that host: a broken image should cost one site a few
 * minutes, not take every site on the host down in a loop.
 */
class FleetRollImage extends Command
{
    protected $signature = 'fleet:roll-image {--limit=20 : most sites to move per host in one run}';

    protected $description = 'Recreate sites on an outdated base image, one at a time, verifying each';

    public function handle(): int
    {
        $failed = false;
        foreach (array_keys(config('fleet.hosts')) as $host) {
            try {
                $outdated = array_values(array_filter(AgentClient::for($host)->imageStatuses(), fn ($s) => ! $s['current']));
            } catch (\Throwable $e) {
                $this->warn("$host: unreachable, skipped - {$e->getMessage()}");

                continue;
            }
            $this->line("$host: ".count($outdated).' site(s) on an outdated image');

            foreach (array_slice($outdated, 0, (int) $this->option('limit')) as $s) {
                $site = Site::where('host', $host)->where('site_id', $s['id'])->first();
                try {
                    $outcome = AgentClient::for($host)->recreate($s['id']);
                    if ($site && $outcome === 'recreated') {
                        $this->answersFromOutside($site);
                    }
                    $this->line("  {$s['id']}: $outcome");
                    Audit::record('site.image_updated', $site?->user, $s['id'], ['host' => $host, 'outcome' => $outcome]);
                } catch (\Throwable $e) {
                    $this->error("  {$s['id']}: FAILED - {$e->getMessage()}. Stopping the roll on $host.");
                    Log::error('image roll stopped', ['host' => $host, 'site' => $s['id'], 'error' => $e->getMessage()]);
                    $failed = true;
                    break;
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Polled for up to 90 seconds, not tried once. The first version made a
     * single 20-second attempt and failed a move that had worked: the site's
     * certificate was being issued at that moment (first HTTPS contact), and
     * it answered 200 seconds later.
     */
    private function answersFromOutside(Site $site): void
    {
        $deadline = now()->addSeconds((int) config('fleet.roll_check_seconds', 90));
        $last = 'no attempt';
        while (now()->lt($deadline)) {
            // The status is all that is used: 64 KB of the answer at most (BoundedSink).
            $answer = BoundedSink::get(Http::timeout(15)->withOptions(['allow_redirects' => false]), $site->url(), 64 << 10);
            if ($answer !== null && $answer['status'] < 500) {
                return;
            }
            $last = $answer === null ? 'no answer' : "HTTP {$answer['status']}";
            // Never below a second: a zero interval is a tight loop against a
            // customer's site (and, in a test, exhausted memory in seconds).
            sleep(max(1, (int) config('fleet.roll_check_interval', 5)));
        }
        throw new \RuntimeException("does not answer from outside after the move (last: $last)");
    }
}
