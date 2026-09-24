<?php

namespace Tests\Feature;

use App\Fleet\Stock;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A plan is sold only when the fleet can hold ALL of it: every site the plan
 * allows, at the plan's per-site CPU, memory and disk. Selling first and
 * failing at "create site" after the customer has paid is what this prevents.
 */
class StockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => [
                'h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 60],
                'h3' => ['ip' => '10.0.0.3', 'tunnel_port' => 9443, 'capacity' => 60],
            ],
            // No overcommit and a round reserve, so the arithmetic is readable.
            'fleet.stock' => [
                'overcommit' => ['cpu' => 1.0, 'memory' => 1.0, 'disk' => 1.0],
                'reserve' => ['memory_mb' => 1024, 'disk_gb' => 10],
                'fresh_seconds' => 600,
            ],
            'billing.plans' => [
                'free' => ['name' => 'Free', 'price' => 0, 'variant_id' => null, 'sites' => 1, 'cpu' => '0.25', 'memory' => '256m', 'disk_gb' => 1, 'storage_gb' => 1, 'custom_domains' => false],
                'starter' => ['name' => 'Starter', 'price' => 12, 'variant_id' => '1', 'sites' => 3, 'cpu' => '0.5', 'memory' => '512m', 'disk_gb' => 5, 'storage_gb' => 15, 'custom_domains' => true],
                'pro' => ['name' => 'Pro', 'price' => 29, 'variant_id' => '2', 'sites' => 10, 'cpu' => '1.0', 'memory' => '1024m', 'disk_gb' => 20, 'storage_gb' => 200, 'custom_domains' => true],
            ],
            'billing.api_key' => 'key', 'billing.store_id' => '1',
        ]);
        // Two hosts: 4 CPUs, 9 GB (8 GB after the reserve), 110 GB disk (100 usable).
        $this->hostReports('h1', cpus: 4, memGb: 9, diskGb: 110);
        $this->hostReports('h3', cpus: 4, memGb: 9, diskGb: 110);
    }

    private function hostReports(string $host, int $cpus, int $memGb, int $diskGb, ?int $ageSeconds = 0): void
    {
        Stock::remember($host, ['cpus' => $cpus, 'memTotalBytes' => $memGb * 1024 ** 3, 'diskTotalBytes' => $diskGb * 1024 ** 3]);
        if ($ageSeconds) {
            $this->travel($ageSeconds)->seconds();
        }
    }

    public function test_capacity_is_the_hosts_real_resources_minus_the_reserve(): void
    {
        $cap = app(Stock::class)->capacity();
        $this->assertSame(8.0, $cap['cpu']);
        $this->assertSame(16 * 1024, $cap['memory_mb']);
        $this->assertSame(200, $cap['disk_gb']);
    }

    public function test_how_many_of_a_plan_fit_counts_its_whole_allowance(): void
    {
        // Pro = 10 sites x (1 CPU, 1024 MB, 20 GB) = 10 CPU, 10 GB, 200 GB.
        // The fleet has 8 CPUs: not one Pro fits.
        $this->assertSame(0, app(Stock::class)->available('pro'));
        // Starter = 3 x (0.5, 512 MB, 5 GB) = 1.5 CPU, 1.5 GB, 15 GB -> CPU allows 5.
        $this->assertSame(5, app(Stock::class)->available('starter'));
    }

    public function test_paid_accounts_reserve_their_whole_plan_and_trials_reserve_nothing(): void
    {
        User::factory()->count(2)->create(['plan' => 'starter']); // 3.0 CPU reserved
        $free = User::factory()->create(['plan' => 'free']);
        Site::create(['user_id' => $free->id, 'site_id' => 'hobby', 'domain' => 'hobby.codeinchrome.com', 'host' => 'h1',
            'status' => 'live', 'cpu_limit' => '2.0', 'memory_limit' => '256m']);

        // 8 - 3 = 5 CPU left for buyers: three more Starters (1.5 each). The
        // trial's 2 CPUs are not held against them (they would leave 2).
        $this->assertSame(3, app(Stock::class)->available('starter'));
        $this->assertSame(3, app(Stock::class)->availableFor(User::factory()->create(), 'starter'));

        // But a trial only gets what is really left: 8 - 3 - 2 = 3 CPU.
        $this->assertTrue(app(Stock::class)->siteFits('free'));
        Site::create(['user_id' => $free->id, 'site_id' => 'hobby2', 'domain' => 'hobby2.codeinchrome.com', 'host' => 'h1',
            'status' => 'live', 'cpu_limit' => '2.9', 'memory_limit' => '256m']);
        $this->assertFalse(app(Stock::class)->siteFits('free'), '0.1 CPU left cannot hold a 0.25 CPU trial site.');

        // A paused trial site holds no CPU or memory, only its disk.
        Site::where('site_id', 'hobby2')->update(['status' => 'suspended']);
        $this->assertTrue(app(Stock::class)->siteFits('free'));
    }

    public function test_a_plan_with_a_storage_total_reserves_the_total_not_every_sites_ceiling(): void
    {
        config(['billing.plans.starter.storage_gb' => 6]); // 3 sites x 5 GB ceilings, 6 GB between them
        $this->assertSame(6, Stock::footprint('starter')['disk_gb']);
        $this->assertSame(5, Stock::footprint('starter', 1)['disk_gb']);
    }

    public function test_an_upgrading_customer_is_not_counted_twice(): void
    {
        config(['fleet.stock.overcommit.cpu' => 2.0]); // 16 CPUs, so a Pro (10) fits once
        $this->hostReports('h1', cpus: 4, memGb: 9, diskGb: 220); // and disk for one (200 GB)
        $this->hostReports('h3', cpus: 4, memGb: 9, diskGb: 220);
        $user = User::factory()->create(['plan' => 'starter']);
        User::factory()->create(['plan' => 'starter']);

        // 16 - 3 = 13: one Pro fits for a newcomer...
        $this->assertSame(1, app(Stock::class)->available('pro'));
        // ...and for the Starter moving up, whose own 1.5 is freed by the move.
        $this->assertSame(1, app(Stock::class)->availableFor($user, 'pro'));
    }

    public function test_a_host_with_stale_or_missing_figures_adds_no_capacity(): void
    {
        Cache::flush();
        $this->assertSame(0, app(Stock::class)->available('starter'), 'No host figures at all must mean nothing to sell.');

        $this->hostReports('h1', cpus: 4, memGb: 9, diskGb: 110);
        $this->travel(11)->minutes();
        $this->assertSame(0, app(Stock::class)->available('starter'), 'Figures older than fresh_seconds must not be trusted.');
    }

    public function test_checkout_refuses_a_plan_that_is_out_of_stock_and_calls_nothing(): void
    {
        Http::fake();
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)->from(route('billing'))->post(route('billing.checkout'), ['plan' => 'pro'])
            ->assertRedirect(route('billing'))
            ->assertSessionHas('error', 'Pro is out of stock right now. We are adding capacity; please check back soon.');
        Http::assertNothingSent();
    }

    public function test_the_pages_say_out_of_stock_and_how_many_are_left(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        $this->actingAs($user)->get(route('billing'))
            ->assertSee('Out of stock')
            ->assertDontSee('Choose Pro')
            ->assertSee('Choose Starter');

        $this->get(route('pricing'))->assertSee('Out of stock');

        User::factory()->count(3)->create(['plan' => 'starter']); // 4.5 CPU reserved -> 2 Starters left
        $this->get(route('pricing'))->assertSee('Only 2 left');
    }

    public function test_a_free_site_is_refused_when_the_fleet_is_full(): void
    {
        $this->hostReports('h1', cpus: 1, memGb: 1, diskGb: 11);   // nothing after the 1 GB reserve
        $this->hostReports('h3', cpus: 1, memGb: 1, diskGb: 11);
        $user = User::factory()->create(['plan' => 'free', 'email_verified_at' => now()]);

        $this->actingAs($user)->post('/sites', ['site_id' => 'full'])
            ->assertSessionHasErrors(['site_id' => 'New sites are out of stock right now. We are adding capacity; please check back soon.']);
        $this->assertSame(0, Site::count());
    }
}
