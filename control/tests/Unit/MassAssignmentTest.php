<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

/**
 * Eloquent discards a non-fillable attribute on update() SILENTLY - no error,
 * no exception, just a value that never lands. It cost real debugging time
 * here: the billing webhook reported `upgraded_to_pro`, wrote a correct
 * subscription row, and left the user on the free plan, because Laravel 13
 * declares fillable through the #[Fillable] attribute rather than a $fillable
 * array and an edit to the array had no effect at all.
 *
 * Every column the billing path writes is asserted here, so the next time that
 * list and the attribute drift apart a test says so instead of a customer
 * paying for a plan they do not receive.
 */
class MassAssignmentTest extends TestCase
{
    public function test_every_attribute_billing_writes_is_actually_fillable(): void
    {
        $user = new User;

        foreach (['name', 'email', 'password', 'plan', 'ls_customer_id'] as $attribute) {
            $this->assertTrue(
                $user->isFillable($attribute),
                "User::\$fillable is missing [$attribute]. update() would discard it silently."
            );
        }
    }

    public function test_updating_the_plan_actually_persists(): void
    {
        $user = User::factory()->create(['plan' => 'free']);

        $user->update(['plan' => 'pro']);

        $this->assertSame('pro', $user->fresh()->plan, 'update() accepted the value and did not store it.');
    }
}
