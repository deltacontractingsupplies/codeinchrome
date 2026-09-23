<?php

namespace Tests\Feature;

use App\Billing\Capacity;
use Tests\TestCase;

/**
 * Plans are described by what they were MEASURED to serve, never by CPU and
 * memory, and never by an estimate: no measurement, no claim.
 */
class CapacityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Not sys_get_temp_dir(): on the dev machine that is the internal disk.
        $file = tempnam(storage_path('framework/testing'), 'cap');
        $this->beforeApplicationDestroyed(fn () => @unlink($file));
        file_put_contents($file, json_encode([
            'measured_at' => '20260923T1200Z', 'bar' => ['p95_ms' => 500, 'errors' => 0.01],
            'workload' => 'a storefront page',
            'plans' => ['starter' => ['page_views_per_second' => 30, 'p95_ms' => 20,
                'steps' => [['rate' => 30, 'achieved_rps' => 30.0, 'p95_ms' => 20, 'failed_ratio' => 0, 'dropped' => 0]]]],
        ]));
        $this->app->singleton(Capacity::class, fn () => new Capacity($file));
    }

    public function test_a_measured_plan_shows_visitors_and_no_cpu_or_memory(): void
    {
        $page = $this->get('/pricing')->assertOk();
        $page->assertSee('~300 visitors at once')->assertSee('30 page views/s, measured');
        $page->assertDontSee(' CPU')->assertDontSee(' RAM');
    }

    public function test_an_unmeasured_plan_makes_no_capacity_claim(): void
    {
        $html = $this->get('/pricing')->getContent();
        // Only Starter was measured: exactly one visitors claim in the cards.
        $this->assertSame(1, substr_count($html, 'page views/s, measured'));
    }

    public function test_the_method_and_every_step_are_published(): void
    {
        $this->get('/pricing')->assertSee('How we measured')->assertSee('View every step of the test')
            ->assertSee('p95 under')->assertSee('every 10 seconds');
    }

    public function test_websocket_connections_show_only_once_measured(): void
    {
        $this->get('/pricing')->assertOk()->assertDontSee('live WebSocket connections')->assertSee('not measured yet');

        $file = tempnam(storage_path('framework/testing'), 'cap');
        file_put_contents($file, json_encode([
            'measured_at' => '20260923T1200Z', 'bar' => ['p95_ms' => 500, 'errors' => 0.01], 'workload' => 'a storefront page',
            'websocket_bar' => ['subscribed' => 0.99, 'closed_early' => 0.01, 'p95_delivery_ms' => 500, 'workload' => 'Reverb broadcasts'],
            'plans' => ['starter' => ['page_views_per_second' => 30, 'p95_ms' => 20, 'steps' => [],
                'websocket_connections' => 2000,
                'websocket_steps' => [['conns' => 2000, 'subscribed' => 2000, 'closed_early' => 0, 'ticks' => 150000, 'p95_ms' => 41.2]]]],
        ]));
        try {
            $this->app->instance(Capacity::class, new Capacity($file));
            $this->get('/pricing')->assertOk()->assertSee('~2,000 live WebSocket connections')
                ->assertSee('Reverb broadcasts')->assertSee('41 ms');
            $this->get('/')->assertOk()->assertSee('Live WebSocket connections')->assertSee('~2,000');
        } finally {
            @unlink($file);
        }
    }

    public function test_a_plan_that_passed_the_top_step_is_shown_as_at_least(): void
    {
        $file = tempnam(storage_path('framework/testing'), 'cap');
        file_put_contents($file, json_encode(['measured_at' => '20260923T1200Z', 'bar' => ['p95_ms' => 500, 'errors' => 0.01], 'workload' => 'w',
            'plans' => ['pro' => ['page_views_per_second' => 80, 'p95_ms' => 20, 'steps' => [], 'websocket_connections' => 10000, 'websocket_at_least' => true],
                'starter' => ['page_views_per_second' => 40, 'p95_ms' => 20, 'steps' => [], 'websocket_connections' => 4000]]]));
        try {
            $this->app->instance(Capacity::class, new Capacity($file));
            $this->get('/pricing')->assertOk()->assertSee('~10,000+ live WebSocket connections')->assertSee('~4,000 live WebSocket connections')
                ->assertDontSee('~4,000+');
        } finally {
            @unlink($file);
        }
    }
}
