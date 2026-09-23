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
        $file = tempnam(sys_get_temp_dir(), 'cap');
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
}
