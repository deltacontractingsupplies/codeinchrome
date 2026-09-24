<?php

namespace App\Billing;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        // Never take money for what the fleet cannot hold (App\Fleet\Stock).
        if (app(\App\Fleet\Stock::class)->availableFor($user, $planKey) < 1) {
            throw new RuntimeException("{$plan['name']} is out of stock right now. We are adding capacity; please check back soon.");
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
                ->retry(3, (int) config('billing.retry_sleep_ms', 500), fn ($e) => $e instanceof ConnectionException, throw: false)
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
                                // Written from the plan here, not typed in the Lemon Squeezy
                                // dashboard, so the checkout never drifts from what we sell.
                                'description' => self::description($plan),
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

    /**
     * What the customer is buying, in the words of the plans page: sites,
     * storage and what comes with them. Never CPU or memory figures - those
     * are ours to tune; what a plan holds is shown as measured capacity.
     */
    public static function description(array $plan): string
    {
        $parts = [
            $plan['sites'].' '.Str::plural('site', $plan['sites']),
            // Per site, with the account's total (disk_gb per site, storage_gb in all).
            $plan['sites'] > 1 ? $plan['disk_gb'].' GB of storage each ('.$plan['storage_gb'].' GB in all)' : $plan['storage_gb'].' GB of storage',
        ];
        if ($plan['custom_domains'] ?? false) {
            $parts[] = 'your own domains';
        }
        if ($plan['background'] ?? false) {
            $parts[] = 'queue worker, scheduler and WebSockets';
        }
        $parts[] = 'nightly backups and HTTPS';

        return 'Laravel hosting with an AI agent in the editor: '.implode(', ', $parts).'. Billed monthly.';
    }
}
