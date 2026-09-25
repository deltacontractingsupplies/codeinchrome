<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Tests\TestCase;

/**
 * Creating a site takes seconds. The page says so the moment Create is
 * pressed, and afterwards says what happened, in elements an agent reading
 * the page can find (it once read "No sites yet" 3 s after a click that had
 * worked).
 */
class SiteCreateFeedbackTest extends TestCase
{
    public function test_the_form_has_a_status_an_agent_can_read(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('data-create-site', false)
            ->assertSee('data-create-status', false)
            ->assertSee('role="status"', false);
    }

    public function test_each_site_says_its_name_and_status(): void
    {
        $user = User::factory()->create();
        Site::create(['user_id' => $user->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'provisioning', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('data-site-id="shop" data-site-status="provisioning"', false);
    }

    public function test_the_flash_is_a_status(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['status' => 'shop.codeinchrome.com is building.'])->get(route('dashboard'))
            ->assertOk()->assertSee('role="status" data-flash="status"', false);
    }
}
