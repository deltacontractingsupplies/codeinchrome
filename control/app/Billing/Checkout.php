<?php

namespace App\Billing;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Creates a Lemon Squeezy checkout for a plan.
 *
 * The user id travels in checkout_data.custom and comes back on every webhook
 * as meta.custom_data.user_id - the trustworthy link between a payment and an
 * account, since a customer can pay with a different email address than the
 * one they signed up with.
 *
 * Nothing about payment is handled here: card details go to Lemon Squeezy's
 * page and never touch this application.
 */
class Checkout
{
    public function urlFor(User $user, string $planKey): string
    {
        $plan = config("billing.plans.$planKey");
        if (! $plan || ! $plan['price']) {
            throw new RuntimeException('That plan cannot be bought.');
        }
        if (! $plan['variant_id']) {
            // Products exist only once someone creates them in the Lemon
            // Squeezy dashboard; until then the plan says so rather than
            // sending the customer to a checkout that will fail.
            throw new RuntimeException("The {$plan['name']} plan is not available to buy yet.");
        }
        foreach (['api_key', 'store_id'] as $key) {
            if (! config("billing.$key")) {
                throw new RuntimeException("Billing is not configured (billing.$key is empty).");
            }
        }

        // Retried on a CONNECTION failure only (seen in production: an empty
        // reply from the API, fine a second later). Safe to repeat - an unused
        // checkout just expires - and an HTTP error is never retried.
        try {
            $response = Http::withToken(config('billing.api_key'))
                ->withHeaders(['Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/vnd.api+json'])
                ->timeout(20)
                ->retry(3, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post('https://api.lemonsqueezy.com/v1/checkouts', [
                    'data' => [
                        'type' => 'checkouts',
                        'attributes' => [
                            'checkout_data' => [
                                'email' => $user->email,
                                'name' => $user->name,
                                'custom' => ['user_id' => (string) $user->id],
                            ],
                            'product_options' => [
                                'redirect_url' => route('billing.return'),
                            ],
                        ],
                        'relationships' => [
                            'store' => ['data' => ['type' => 'stores', 'id' => (string) config('billing.store_id')]],
                            'variant' => ['data' => ['type' => 'variants', 'id' => (string) $plan['variant_id']]],
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('The payment provider could not be reached. Please try again in a minute.', previous: $e);
        }

        $url = $response->json('data.attributes.url');
        if (! $response->successful() || ! $url) {
            $why = collect($response->json('errors') ?? [])->pluck('detail')->implode('; ') ?: "HTTP {$response->status()}";
            throw new RuntimeException("Lemon Squeezy did not create a checkout: $why");
        }

        return $url;
    }
}
