<?php

namespace Tests\Feature;

use App\Abuse\CpuWatch;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A site held at its CPU limit (mining) is flagged, then paused. */
class CpuWatchTest extends TestCase
{
    private array $sent = [];

    private array $paused = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.owner_notify_email' => 'owner@example.com', 'fleet.mail_enabled' => true, 'fleet.tokens' => ['h1' => 't']]);
        $user = User::factory()->create(['email' => 'miner@gmail.com']);
        Site::create(['user_id' => $user->id, 'site_id' => 'hot', 'domain' => 'hot.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20300]);
        Site::create(['user_id' => $user->id, 'site_id' => 'calm', 'domain' => 'calm.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20301]);
        Event::listen(MessageSent::class, fn ($e) => $this->sent[] = $e->message->getSubject());
        $this->sent = [];
        Http::fake(['127.0.0.1:9441/*' => function (ClientRequest $r) {
            if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/suspended')) {
                $this->paused[] = explode('/', parse_url($r->url(), PHP_URL_PATH))[3];
            }

            return Http::response(['ok' => true, 'applied' => []]);
        }]);
    }

    /** Readings every five minutes: "hot" at 100% of 0.5 CPU, "calm" at 20%. */
    private function runHot(int $minutes): array
    {
        $watch = app(CpuWatch::class);
        $start = Carbon::parse('2026-09-25 10:00:00');
        $last = [];
        for ($m = 0; $m <= $minutes; $m += 5) {
            $last = $watch->observe([
                ['site' => 'hot', 'usage_usec' => (int) ($m * 60 * 1e6 * 0.5), 'quota_cpus' => 0.5, 'started' => 's1'],
                ['site' => 'calm', 'usage_usec' => (int) ($m * 60 * 1e6 * 0.1), 'quota_cpus' => 0.5, 'started' => 's1'],
            ], $start->copy()->addMinutes($m));
        }

        return $last;
    }

    public function test_the_share_of_the_limit_is_measured_from_two_readings(): void
    {
        $shares = $this->runHot(5);
        $this->assertSame(1.0, $shares['hot']);
        $this->assertSame(0.2, $shares['calm']);
    }

    public function test_half_an_hour_hot_alerts_the_owner_once_and_pauses_nothing(): void
    {
        $this->runHot(60);
        $this->assertSame(['[codeinchrome] Possible crypto mining: hot.codeinchrome.com'], $this->sent);
        $this->assertSame([], $this->paused);
        $this->assertSame('live', Site::where('site_id', 'hot')->value('status'));
    }

    public function test_two_hours_hot_pauses_that_site_only(): void
    {
        $this->runHot(125);
        $this->assertSame(['hot'], $this->paused);
        $this->assertSame('suspended', Site::where('site_id', 'hot')->value('status'));
        $this->assertSame('live', Site::where('site_id', 'calm')->value('status'));
        $this->assertContains('[codeinchrome] Site paused for sustained CPU: hot.codeinchrome.com', $this->sent);
    }

    public function test_a_restart_resets_the_counter_and_is_not_read_as_negative_or_hot(): void
    {
        $watch = app(CpuWatch::class);
        $t = Carbon::parse('2026-09-25 10:00:00');
        $watch->observe([['site' => 'hot', 'usage_usec' => 900_000_000, 'quota_cpus' => 0.5, 'started' => 's1']], $t);
        $shares = $watch->observe([['site' => 'hot', 'usage_usec' => 1_000, 'quota_cpus' => 0.5, 'started' => 's2']], $t->copy()->addMinutes(5));
        $this->assertArrayNotHasKey('hot', $shares, 'a new container has no previous reading to compare with');
    }

    public function test_a_paused_site_comes_back_with_abuse_resume_but_not_a_banned_accounts(): void
    {
        $site = Site::where('site_id', 'hot')->first();
        $site->update(['status' => 'suspended']);
        $this->artisan('abuse:resume', ['site' => 'hot'])->assertSuccessful();
        $this->assertSame('live', $site->fresh()->status);

        $site->refresh()->update(['status' => 'suspended']);
        $site->user->forceFill(['banned_at' => now()])->save();
        $this->artisan('abuse:resume', ['site' => 'hot'])->assertFailed();
        $this->assertSame('suspended', $site->fresh()->status);
    }

    /** Readings every five minutes for $minutes; $share(minute) is "hot"'s share, $started(minute) its container start. */
    private function feed(int $minutes, callable $share, ?callable $started = null, int $from = 0): void
    {
        $watch = app(CpuWatch::class);
        $start = Carbon::parse('2026-09-25 10:00:00');
        static $usage = 0;
        if ($from === 0) {
            $usage = 0;
        }
        for ($m = $from; $m <= $from + $minutes; $m += 5) {
            $usage += (int) (300 * 1e6 * 0.5 * $share($m));
            $watch->observe([['site' => 'hot', 'usage_usec' => $usage, 'quota_cpus' => 0.5, 'started' => $started ? $started($m) : 's1']],
                $start->copy()->addMinutes($m));
        }
    }

    public function test_a_miner_throttled_below_the_hot_line_is_alerted_at_two_hours_and_paused_at_six(): void
    {
        $this->feed(125, fn () => 0.85);
        $this->assertSame(['[codeinchrome] Possible crypto mining: hot.codeinchrome.com'], $this->sent);
        $this->assertSame([], $this->paused);

        $this->feed(245, fn () => 0.85, from: 130);
        $this->assertSame(['hot'], $this->paused, 'six hours averaging 85% of the limit');
    }

    public function test_restarting_itself_does_not_reset_a_miners_streak(): void
    {
        // A new container start every 50 minutes (a PHP setting change restarts it).
        $this->feed(125, fn () => 1.0, fn ($m) => 's'.intdiv($m, 50));
        $this->assertSame(['hot'], $this->paused);
    }

    public function test_a_real_gap_in_the_readings_does_start_a_new_streak(): void
    {
        $this->feed(60, fn () => 1.0);
        // Host unreachable for 30 minutes, then a different container.
        $this->feed(70, fn () => 1.0, fn () => 's2', from: 95);
        $this->assertSame([], $this->paused, 'two separate hot hours are not two hot hours in a row');
    }

    public function test_a_busy_ordinary_site_is_never_touched(): void
    {
        $this->feed(420, fn ($m) => $m % 60 < 30 ? 0.7 : 0.3);
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->paused);
    }
}
