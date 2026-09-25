<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Every monitoring run pings the outside heartbeat, once it is configured. */
class HeartbeatTest extends TestCase
{
    private function afterMonitorRun(): void
    {
        $monitor = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'fleet:monitor'));
        $this->assertNotNull($monitor, 'fleet:monitor is scheduled');
        $monitor->callAfterCallbacks(app());
    }

    public function test_each_monitoring_run_pings_the_heartbeat(): void
    {
        config(['fleet.heartbeat_url' => 'https://hc.example.test/ping/abc']);
        Http::fake(['hc.example.test/*' => Http::response('OK')]);

        $this->afterMonitorRun();

        Http::assertSent(fn ($r) => $r->url() === 'https://hc.example.test/ping/abc' && $r->method() === 'GET');
    }

    public function test_without_a_url_nothing_is_sent(): void
    {
        config(['fleet.heartbeat_url' => null]);
        Http::fake();

        $this->afterMonitorRun();

        Http::assertNothingSent();
    }

    public function test_a_heartbeat_service_that_is_down_never_breaks_monitoring(): void
    {
        config(['fleet.heartbeat_url' => 'https://hc.example.test/ping/abc']);
        Http::fake(['hc.example.test/*' => fn () => throw new ConnectionException('down')]);

        $this->afterMonitorRun(); // no exception

        $this->assertTrue(true);
    }
}
