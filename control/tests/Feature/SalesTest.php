<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PlanNotice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Paid plans OFF (CIC_PAID_PLANS_OPEN=false, App\Billing\Sales): only the free
 * plan exists anywhere, nothing can be bought, and no trial runs out - there
 * is nothing to upgrade to. Then the one command for the day they open.
 */
class SalesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.paid_open' => false]);
    }

    public function test_no_paid_plan_is_shown_anywhere_and_the_free_card_is_honest(): void
    {
        foreach (['/', '/pricing'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $this->assertStringNotContainsString('data-plan="starter"', $html, "$page shows Starter");
            $this->assertStringNotContainsString('$'.config('billing.plans.starter.price').'<span', $html, "$page shows Starter's price");
            $this->assertStringContainsString('data-plan="free"', $html);
            $this->assertStringContainsString('Paid plans open soon', $html);
            // Free runs at Starter's limits, so Starter's measurement is its own - but no
            // WebSocket claim: the free plan has no background processes, so no Reverb.
            $this->assertStringContainsString('Your site: up to ~', $html);
            $this->assertStringNotContainsString('live WebSocket connections', $html);
            $this->assertStringNotContainsString('The same speed as Starter', $html);
        }
        $this->get('/register')->assertOk()->assertSee('Paid plans open soon.');
        $this->get('/terms')->assertOk()->assertSee('Paid plans are not on sale yet.');
    }

    public function test_nothing_can_be_bought(): void
    {
        Http::fake();
        // Decisive whatever else is configured: the checkout itself must never be asked for.
        $this->mock(\App\Billing\Checkout::class)->shouldNotReceive('urlFor');
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)->get(route('billing'))->assertOk()->assertDontSee('Choose Starter');
        $this->actingAs($user)->from(route('billing'))->post(route('billing.checkout'), ['plan' => 'starter'])
            ->assertRedirect(route('billing'))->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_a_customer_who_already_pays_still_sees_their_plan(): void
    {
        $paying = User::factory()->create(['plan' => 'starter']);

        $this->actingAs($paying)->get(route('billing'))->assertOk()
            ->assertSee('You are on the <strong class="text-neutral-200">Starter</strong> plan.', false)
            ->assertSee('Current plan');
        $this->actingAs($paying)->get(route('dashboard'))->assertOk()->assertSee('Starter plan');
    }

    public function test_no_trial_runs_out_while_nothing_is_for_sale(): void
    {
        Notification::fake();
        $user = User::factory()->create(['plan' => 'free', 'trial_ends_at' => now()->subDays(5)]);

        $this->assertFalse($user->trialExpired());
        $this->assertFalse($user->onTrial());
        $this->artisan('trials:expire')->assertSuccessful();

        $this->assertNull($user->fresh()->suspended_at);
        Notification::assertNothingSent();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('data-trial="free-for-now"', false);
    }

    public function test_no_stock_alert_for_a_plan_that_is_not_for_sale(): void
    {
        $this->assertArrayNotHasKey('stock:starter', app(\App\Fleet\Monitoring::class)->run());
    }

    public function test_trials_restart_refuses_while_closed_and_gives_every_free_account_a_trial_when_open(): void
    {
        Notification::fake();
        config(['fleet.admin_emails' => ['ops@example.test']]);
        $free = User::factory()->create(['plan' => 'free', 'trial_ends_at' => now()->subDays(5), 'trial_warned_at' => now()->subDays(6)]);
        $never = User::factory()->create(['plan' => 'free', 'trial_ends_at' => null]);
        $paying = User::factory()->create(['plan' => 'starter', 'trial_ends_at' => null]);
        $operator = User::factory()->create(['plan' => 'free', 'email' => 'ops@example.test', 'trial_ends_at' => null]);

        $this->artisan('trials:restart')->assertFailed();
        $this->assertTrue($free->fresh()->trial_ends_at->isPast(), 'nothing changes while paid plans are closed');

        config(['billing.paid_open' => true]);
        $this->artisan('trials:restart')->assertSuccessful();

        foreach ([$free, $never] as $user) {
            $user->refresh();
            $this->assertTrue($user->trial_ends_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()), 'a full trial, from now');
            $this->assertNull($user->trial_warned_at);
            $this->assertTrue($user->onTrial());
            Notification::assertSentTo($user, PlanNotice::class, fn ($n) => $n->kind === 'trial_started');
        }
        $this->assertNull($paying->fresh()->trial_ends_at);
        $this->assertNull($operator->fresh()->trial_ends_at);
        Notification::assertNotSentTo([$paying, $operator], PlanNotice::class);
    }
}
