<?php

namespace App\Billing;

/**
 * Whether paid plans are for sale - ONE switch, CIC_PAID_PLANS_OPEN.
 *
 * Off (the default until the payment provider approves the store): only the
 * free plan is shown or sold anywhere, nobody can start a checkout, and free
 * trials do not run out - with nothing to upgrade to, ending them would take
 * people's sites away. Accounts that already pay are untouched.
 *
 * To open paid plans: set CIC_PAID_PLANS_OPEN=true, deploy, then run
 * `php artisan trials:restart` - every free account gets a fresh trial and an
 * email saying so, before any clock runs out.
 */
final class Sales
{
    public static function open(): bool
    {
        return (bool) config('billing.paid_open');
    }

    /** A plan's name as shown: while nothing is for sale the free plan is no trial, just "Free". */
    public static function name(array $plan): string
    {
        return ! self::open() && (int) $plan['price'] === 0 ? 'Free' : $plan['name'];
    }

    /** The plans shown and sold right now, keyed like config('billing.plans'). */
    public static function plans(): array
    {
        $plans = config('billing.plans');

        return self::open() ? $plans : array_filter($plans, fn (array $plan) => (int) $plan['price'] === 0);
    }
}
