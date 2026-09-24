<?php

namespace App\Models;

use App\Notifications\VerifyEmailWithCode;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

#[Fillable(['name', 'email', 'password', 'plan', 'ls_customer_id'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'email_code_hash'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Operators are listed in CIC_ADMIN_EMAILS; nothing in the app can grant it.
     * And the address must be PROVED: anyone can register an unverified account
     * under an operator's address that has no account yet (found 2026-09-24 -
     * /status is open to unverified accounts, as email verification is).
     */
    public function isOperator(): bool
    {
        return $this->hasVerifiedEmail()
            && in_array(strtolower($this->email), array_map('strtolower', config('fleet.admin_emails')), true);
    }

    /**
     * The confirmation email carries a fresh six-digit code as well as the
     * link. Sending a new one replaces the old code and resets its tries.
     */
    public function sendEmailVerificationNotification(): void
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $this->forceFill([
            'email_code_hash' => Hash::make($code),
            'email_code_expires_at' => now()->addMinutes(15),
            'email_code_attempts' => 0,
        ])->save();

        $this->notify(new VerifyEmailWithCode($code));
    }

    /** The plan config this user is entitled to right now. */
    public function planConfig(): array
    {
        return config("billing.plans.{$this->plan}") ?: config('billing.plans.free');
    }

    /** Starts the free trial of a new account. Never extends one. */
    public function startTrial(): void
    {
        if ($this->trial_ends_at === null) {
            $this->forceFill(['trial_ends_at' => now()->addDays((int) config('billing.trial.days'))])->save();
        }
    }

    public function isPaid(): bool
    {
        return (int) $this->planConfig()['price'] > 0;
    }

    /**
     * A free account whose trial clock is still running. While paid plans are
     * not for sale (Sales) no clock runs: there is nothing to upgrade to.
     */
    public function onTrial(): bool
    {
        return \App\Billing\Sales::open() && ! $this->isPaid() && $this->trial_ends_at?->isFuture() === true;
    }

    /**
     * A free account whose trial is over: it may not build, and its sites are
     * paused, then deleted (trials:expire). Accounts with no trial clock -
     * operators, and accounts from before trials existed - never expire.
     */
    public function trialExpired(): bool
    {
        return \App\Billing\Sales::open() && ! $this->isPaid() && ! $this->isOperator() && $this->trial_ends_at?->isPast() === true;
    }

    /** When a paused account's sites are deleted, or null if none are paused. */
    public function deletesAt(): ?\Illuminate\Support\Carbon
    {
        if (! $this->suspended_at) {
            return null;
        }
        $lapsed = $this->subscriptions()->exists();

        return $this->suspended_at->copy()->addDays((int) config($lapsed ? 'billing.trial.lapsed_grace_days' : 'billing.trial.grace_days'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_code_expires_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'trial_warned_at' => 'datetime',
            'suspended_at' => 'datetime',
            'storage_over_at' => 'datetime',
        ];
    }
}
