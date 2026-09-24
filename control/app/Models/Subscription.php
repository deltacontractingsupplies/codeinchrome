<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['renews_at' => 'datetime', 'ends_at' => 'datetime', 'payment_failed_at' => 'datetime', 'payment_warned_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Statuses that mean a payment failed and has not yet been made good. */
    public const PAYMENT_FAILED = ['past_due', 'unpaid'];

    /**
     * Whether the customer is entitled to paid service RIGHT NOW.
     *
     * A failed payment keeps the plan for billing.payment_grace_days from the
     * moment it first failed: a declined card is a billing problem, and taking
     * a customer's sites offline over one loses the customer as well as the
     * payment. `cancelled` runs to ends_at, because Lemon Squeezy keeps a
     * cancelled subscription active until then. An `expired` subscription that
     * expired because of a failed payment still gets the rest of its grace.
     *
     * Time-based, so trials:expire re-checks it on a schedule: nothing has to
     * arrive from Lemon Squeezy for a grace period to end.
     */
    public function entitled(): bool
    {
        return match ($this->status) {
            'active', 'on_trial' => true,
            'cancelled' => ! $this->ends_at || $this->ends_at->isFuture(),
            'past_due', 'unpaid', 'expired' => $this->inPaymentGrace(),
            default => false,
        };
    }

    public function inPaymentGrace(): bool
    {
        return $this->payment_failed_at !== null && $this->graceEndsAt()->isFuture();
    }

    public function graceEndsAt(): ?\Illuminate\Support\Carbon
    {
        return $this->payment_failed_at?->copy()->addDays((int) config('billing.payment_grace_days'));
    }
}
