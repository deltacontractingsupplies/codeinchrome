<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Tests\TestCase;

/**
 * The webhook is the only unauthenticated, internet-facing, money-carrying
 * endpoint in the application, so these tests are deliberately hostile.
 */
class WebhookTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    private function send(array $payload, string $event = 'subscription_created', ?string $signature = null)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST', '/webhooks/lemonsqueezy', [], [], [],
            [
                'HTTP_X_Signature' => $signature ?? hash_hmac('sha256', $body, self::SECRET),
                'HTTP_X_Event_Name' => $event,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );
    }

    private function payload(User $user, string $variant, string $status = 'active', string $id = 'sub_1'): array
    {
        return [
            'meta' => ['event_name' => 'subscription_created', 'custom_data' => ['user_id' => (string) $user->id]],
            'data' => [
                'id' => $id,
                'attributes' => [
                    'variant_id' => $variant,
                    'status' => $status,
                    'user_email' => $user->email,
                    'customer_id' => '999',
                    'renews_at' => now()->addMonth()->toIso8601String(),
                    'ends_at' => null,
                ],
            ],
        ];
    }

    public function test_it_rejects_a_forged_signature(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        config(['billing.plans.pro.variant_id' => '777']);

        $this->send($this->payload($user, '777'), signature: 'not-the-signature')
            ->assertStatus(401);

        $this->assertSame('free', $user->fresh()->plan, 'A forged webhook must never change a plan.');
        $this->assertSame(0, WebhookEvent::count(), 'An unverified payload must not even be stored.');
    }

    public function test_it_rejects_a_signature_for_different_bytes(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        config(['billing.plans.pro.variant_id' => '777']);

        // A valid signature, but for a cheaper plan than the body now claims.
        $cheap = json_encode($this->payload($user, '111'));
        $signature = hash_hmac('sha256', $cheap, self::SECRET);

        $this->send($this->payload($user, '777'), signature: $signature)->assertStatus(401);
        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_it_refuses_everything_when_no_secret_is_configured(): void
    {
        config(['billing.webhook_secret' => null]);
        $user = User::factory()->create(['plan' => 'free']);

        $this->send($this->payload($user, '777'))->assertStatus(503);
        $this->assertSame('free', $user->fresh()->plan, 'A missing secret must not mean "accept anything".');
    }

    public function test_it_upgrades_the_plan_on_a_valid_subscription(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        config(['billing.plans.pro.variant_id' => '777']);

        $this->send($this->payload($user, '777'))->assertOk()->assertSee('upgraded_to_pro');

        $this->assertSame('pro', $user->fresh()->plan);
        $this->assertSame('active', Subscription::where('ls_subscription_id', 'sub_1')->first()->status);
    }

    public function test_a_replayed_delivery_is_processed_once(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        config(['billing.plans.pro.variant_id' => '777']);
        $payload = $this->payload($user, '777');

        $this->send($payload)->assertOk()->assertSee('upgraded_to_pro');
        $this->send($payload)->assertOk()->assertSee('already_processed');

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(1, Subscription::count());
    }

    public function test_past_due_keeps_service_but_expired_does_not(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        config(['billing.plans.pro.variant_id' => '777']);

        // A failed card is a billing problem, not grounds to take sites down
        // while Lemon Squeezy is still retrying the charge.
        $this->send($this->payload($user, '777', 'past_due', 'sub_pd'), 'subscription_updated')->assertOk();
        $this->assertSame('pro', $user->fresh()->plan);

        $this->send($this->payload($user, '777', 'expired', 'sub_pd'), 'subscription_expired')->assertOk();
        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_a_cancelled_subscription_keeps_service_until_it_ends(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        config(['billing.plans.pro.variant_id' => '777']);

        $payload = $this->payload($user, '777', 'cancelled', 'sub_c');
        $payload['data']['attributes']['ends_at'] = now()->addDays(20)->toIso8601String();
        $this->send($payload, 'subscription_cancelled')->assertOk();
        $this->assertSame('pro', $user->fresh()->plan, 'Cancelled runs to the end of the paid period.');

        $payload['data']['attributes']['ends_at'] = now()->subDay()->toIso8601String();
        $this->send($payload, 'subscription_cancelled')->assertOk();
        $this->assertSame('free', $user->fresh()->plan, 'Once the period is over, entitlement stops.');
    }

    public function test_an_unknown_variant_records_but_does_not_guess_a_plan(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        config(['billing.plans.pro.variant_id' => '777']);

        $this->send($this->payload($user, 'a-variant-nobody-wired-up'))->assertOk()->assertSee('unknown_variant');

        $this->assertSame('free', $user->fresh()->plan);
        $this->assertSame(1, Subscription::count(), 'The subscription is still recorded so the payment is not lost.');
    }

    public function test_an_unmatchable_subscription_is_kept_for_replay_not_discarded(): void
    {
        config(['billing.plans.pro.variant_id' => '777']);
        $orphan = new User(['email' => 'nobody@example.com', 'id' => 99999]);
        $orphan->id = 99999;

        $this->send($this->payload($orphan, '777'))->assertStatus(500);

        $event = WebhookEvent::first();
        $this->assertNotNull($event, 'A payment we could not match must still be recorded.');
        $this->assertNull($event->processed_at);
        $this->assertStringContainsString('No user matches', $event->error);
    }
}
