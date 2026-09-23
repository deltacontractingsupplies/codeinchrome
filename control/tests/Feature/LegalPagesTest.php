<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The public pages a payment provider reviews before it lets the store take
 * money: pricing, terms, privacy, refunds, and a way to reach us.
 */
class LegalPagesTest extends TestCase
{
    public function test_every_public_page_is_reachable_signed_out_and_linked_from_the_home_page(): void
    {
        $home = $this->get('/')->assertOk();
        foreach (['pricing', 'terms', 'privacy', 'refunds'] as $page) {
            $this->get(route($page))->assertOk();
            $home->assertSee(route($page), false);
        }
        $home->assertSee('mailto:support@codeinchrome.com', false);
    }

    public function test_pricing_shows_every_plan_at_the_price_the_app_charges(): void
    {
        $page = $this->get('/pricing')->assertOk();
        foreach (config('billing.plans') as $plan) {
            $page->assertSee($plan['name'])->assertSee('$' . $plan['price']);
        }
    }

    public function test_the_refund_window_comes_from_config(): void
    {
        config(['legal.refund_days' => 30]);
        $this->get('/refunds')->assertOk()->assertSee('within 30 days');
    }

    public function test_an_unset_identity_is_left_out_not_invented(): void
    {
        config(['legal.operator' => null, 'legal.jurisdiction' => null]);
        $this->get('/terms')->assertOk()->assertDontSee('Governing law');

        config(['legal.operator' => 'Example Oy', 'legal.jurisdiction' => 'Finland']);
        $this->get('/terms')->assertOk()->assertSee('operated by Example Oy')->assertSee('governed by the law of Finland');
    }
}
