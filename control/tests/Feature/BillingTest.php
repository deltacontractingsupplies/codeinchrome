<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.api_key' => 'key', 'billing.store_id' => '100001', 'billing.plans.pro.variant_id' => '777']);
    }

    public function test_checkout_carries_the_user_id_and_redirects_to_lemon_squeezy(): void
    {
        Http::fake(['api.lemonsqueezy.com/v1/checkouts' => Http::response(['data' => ['attributes' => ['url' => 'https://codeinchrome.lemonsqueezy.com/checkout/abc']]], 201)]);
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)->post(route('billing.checkout'), ['plan' => 'pro'])
            ->assertRedirect('https://codeinchrome.lemonsqueezy.com/checkout/abc');

        Http::assertSent(fn ($r) => $r['data']['attributes']['checkout_data']['custom']['user_id'] === (string) $user->id
            && $r['data']['relationships']['variant']['data']['id'] === '777'
            && $r['data']['relationships']['store']['data']['id'] === '100001');
        $this->assertSame('free', $user->fresh()->plan, 'Starting a checkout must not change the plan; only the signed webhook does.');
    }

    public function test_a_plan_without_a_product_says_so_and_calls_nothing(): void
    {
        config(['billing.plans.studio.variant_id' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('billing.checkout'), ['plan' => 'studio'])->assertSessionHas('error');
        $this->actingAs($user)->get(route('billing'))->assertSee('Not available to buy yet');
        Http::assertNothingSent();
    }

    public function test_a_lemon_squeezy_refusal_is_shown_not_swallowed(): void
    {
        Http::fake(['api.lemonsqueezy.com/*' => Http::response(['errors' => [['detail' => 'The variant is not published.']]], 422)]);

        $this->actingAs(User::factory()->create())->post(route('billing.checkout'), ['plan' => 'pro'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'The variant is not published.'));
    }

    public function test_the_return_page_does_not_claim_the_upgrade_happened(): void
    {
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)->get(route('billing.return'))->assertRedirect(route('billing'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'being confirmed'));
        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_a_subscriber_changes_plan_in_the_portal_not_a_second_checkout(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);
        Subscription::create(['user_id' => $user->id, 'ls_subscription_id' => 's1', 'plan' => 'starter', 'status' => 'active',
            'portal_url' => 'https://codeinchrome.lemonsqueezy.com/billing?signed=1']);

        $this->actingAs($user)->get(route('billing'))
            ->assertSee('Change in billing portal')
            ->assertDontSee('Choose Pro');
    }
}
