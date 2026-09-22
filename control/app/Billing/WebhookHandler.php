<?php

namespace App\Billing;

use App\Audit\Audit;
use App\Fleet\PlanLimits;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a verified Lemon Squeezy webhook.
 *
 * Signature verification happens in the controller, BEFORE anything here runs.
 * Nothing in this class may be reached by an unverified payload.
 */
class WebhookHandler
{
    /**
     * Lemon Squeezy retries, and a retry of a succeeded delivery is normal
     * rather than exceptional. The event id is stored unique, so a replay
     * returns the stored outcome instead of upgrading a plan twice.
     */
    public function handle(string $eventId, string $eventName, array $payload): string
    {
        $existing = WebhookEvent::where('event_id', $eventId)->first();
        if ($existing?->processed_at) {
            return 'already_processed';
        }

        $event = $existing ?: WebhookEvent::create([
            'event_id' => $eventId,
            'event_name' => $eventName,
            'payload' => $payload,
        ]);

        try {
            $result = DB::transaction(fn () => $this->apply($eventName, $payload));
            $event->update(['processed_at' => now(), 'error' => null]);

            return $result;
        } catch (\Throwable $e) {
            // Recorded, not swallowed. An unprocessed row with an error is a
            // queue of work someone can replay; a 500 with nothing stored is a
            // payment we never learned about.
            $event->update(['error' => $e->getMessage()]);
            Log::error('webhook failed', ['event' => $eventName, 'id' => $eventId, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    private function apply(string $eventName, array $payload): string
    {
        $attributes = $payload['data']['attributes'] ?? [];
        $custom = $payload['meta']['custom_data'] ?? [];

        return match ($eventName) {
            'subscription_created',
            'subscription_updated',
            'subscription_resumed',
            'subscription_unpaused',
            'subscription_paused',
            'subscription_cancelled',
            'subscription_expired' => $this->syncSubscription($payload, $attributes, $custom),
            default => 'ignored',
        };
    }

    private function syncSubscription(array $payload, array $attributes, array $custom): string
    {
        $user = $this->resolveUser($attributes, $custom);
        if (! $user) {
            // Better to fail loudly and keep the row for replay than to
            // discard a payment because we could not match it to an account.
            throw new \RuntimeException(
                'No user matches this subscription (email: ' . ($attributes['user_email'] ?? 'none') .
                ', custom_data.user_id: ' . ($custom['user_id'] ?? 'none') . ')'
            );
        }

        $variantId = (string) ($attributes['variant_id'] ?? '');
        $plan = $this->planForVariant($variantId);
        $status = $attributes['status'] ?? 'unknown';

        $subscription = Subscription::updateOrCreate(
            ['ls_subscription_id' => (string) $payload['data']['id']],
            [
                'user_id' => $user->id,
                'ls_variant_id' => $variantId,
                'plan' => $plan ?? 'free',
                'status' => $status,
                'renews_at' => $attributes['renews_at'] ?? null,
                'ends_at' => $attributes['ends_at'] ?? null,
                'portal_url' => $attributes['urls']['customer_portal'] ?? null,
            ],
        );

        if ($plan === null) {
            // The variant is real but not in our catalog - someone added a
            // product in the dashboard and did not wire it up. Recording the
            // subscription and refusing to guess a plan is the honest outcome.
            Log::warning('subscription for an unknown variant; plan not changed', [
                'variant_id' => $variantId, 'user_id' => $user->id,
            ]);

            return 'unknown_variant';
        }

        $before = $user->plan;
        $user->update([
            'plan' => $subscription->entitled() ? $plan : 'free',
            'ls_customer_id' => (string) ($attributes['customer_id'] ?? $user->ls_customer_id),
        ]);

        // Paid for, so applied - to the sites that already exist, not only to
        // new ones. Runs AFTER the plan is committed (afterCommit), and never
        // throws: a host being down must not make Lemon Squeezy retry a
        // payment we have already recorded. Sites it could not reach are
        // marked limits_pending and fleet:apply-limits finishes the job.
        if ($user->plan !== $before) {
            Audit::record('billing.plan_changed', $user, detail: ['from' => $before, 'to' => $user->plan, 'status' => $status]);
            DB::afterCommit(fn () => app(PlanLimits::class)->applyTo($user->fresh()));
        }

        // Downgrading does NOT delete sites that now exceed the new limit.
        // Deleting a paying-customer-turned-free customer's work on a webhook
        // is irreversible and is not a decision a payment event gets to make.
        // They keep what exists and cannot create more; see Provisioner.
        return $subscription->entitled() ? "upgraded_to_$plan" : 'downgraded_to_free';
    }

    private function resolveUser(array $attributes, array $custom): ?User
    {
        // custom_data carries the id we put on the checkout, so it is the
        // trustworthy link. Email is the fallback: a customer can pay with a
        // different address than the one they signed up with.
        if ($id = ($custom['user_id'] ?? null)) {
            if ($user = User::find($id)) {
                return $user;
            }
        }

        if ($email = ($attributes['user_email'] ?? null)) {
            return User::where('email', $email)->first();
        }

        return null;
    }

    private function planForVariant(string $variantId): ?string
    {
        foreach (config('billing.plans') as $key => $plan) {
            if ($plan['variant_id'] && (string) $plan['variant_id'] === $variantId) {
                return $key;
            }
        }

        return null;
    }
}
