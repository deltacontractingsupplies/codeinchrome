<?php

namespace Tests\Feature;

use App\Billing\Capacity;
use Tests\TestCase;

/**
 * The home page is the first thing a visitor sees, so it has to show what the
 * product is - the editor and the agent - and must never make a claim nothing
 * backs: capacity only from a measurement, the demo store only once it exists.
 */
class HomePageTest extends TestCase
{
    public function test_it_shows_the_editor_and_the_agent_working_in_it(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('The agent\'s steps', false)
            ->assertSee("cic.write('resources/views/shop/index.blade.php')", false)
            ->assertSee('Every change kept');
    }

    public function test_the_blade_sample_in_the_editor_is_printed_not_executed(): void
    {
        // Rendered through @verbatim; if that ever breaks, $products is
        // undefined and the home page 500s rather than showing the sample.
        $this->get('/')->assertOk()->assertSee('@foreach ($products as $product)', false)
            ->assertSee('{{ $products->links() }}', false);
    }

    public function test_capacity_appears_only_for_measured_plans(): void
    {
        $file = tempnam(storage_path('framework/testing'), 'cap') ?: $this->fail('no temp file');
        try {
            file_put_contents($file, json_encode(['plans' => ['pro' => ['page_views_per_second' => 80, 'p95_ms' => 120]]]));
            $this->app->instance(Capacity::class, new Capacity($file));

            $this->get('/')->assertOk()->assertSee('How much traffic each plan handles')
                ->assertSee('~800')->assertSee('120 ms');

            $this->app->instance(Capacity::class, new Capacity($file . '.missing'));
            $this->get('/')->assertOk()->assertDontSee('How much traffic each plan handles');
        } finally {
            @unlink($file);
        }
    }

    public function test_the_showcase_store_is_hidden_until_configured_then_shows_its_demo_login(): void
    {
        config(['showcase.url' => null]);
        $this->get('/')->assertOk()->assertDontSee('A store the agent built');

        config(['showcase' => [
            'url' => 'https://shop.codeinchrome.com',
            'admin_url' => 'https://shop.codeinchrome.com/admin',
            'admin_email' => 'demo@shop.test',
            'admin_password' => 'read-only-demo',
            'recording' => '/showcase/build.gif',
            'checkout' => false,
        ]]);
        $this->get('/')->assertOk()->assertSee('A store the agent built')
            ->assertSee('shop.codeinchrome.com')->assertSee('demo@shop.test')
            ->assertSee('read-only-demo')->assertSee('/showcase/build.gif', false);
    }

    public function test_the_test_card_is_only_offered_when_checkout_works(): void
    {
        config(['showcase.url' => 'https://shop.codeinchrome.com', 'showcase.checkout' => false]);
        $this->get('/')->assertOk()->assertDontSee('4242 4242 4242 4242');
        config(['showcase.checkout' => true]);
        $this->get('/')->assertOk()->assertSee('4242 4242 4242 4242');
    }
}
