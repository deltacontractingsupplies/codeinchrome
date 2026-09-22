<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['renews_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Statuses that entitle the customer to paid service RIGHT NOW.
     *
     * `past_due` is deliberately included: a failed card is a billing problem,
     * and taking a customer's sites offline over one - while Lemon Squeezy is
     * still retrying the charge - loses the customer as well as the payment.
     * `cancelled` is included too, because Lemon Squeezy keeps a cancelled
     * subscription active until `ends_at`; entitlement stops there, not at the
     * moment the customer clicks cancel.
     */
    public const ENTITLED = ['active', 'on_trial', 'past_due', 'cancelled'];

    public function entitled(): bool
    {
        if (! in_array($this->status, self::ENTITLED, true)) {
            return false;
        }

        return ! $this->ends_at || $this->ends_at->isFuture();
    }
}
