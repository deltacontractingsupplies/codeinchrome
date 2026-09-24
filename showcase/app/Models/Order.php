<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = ['reference', 'email', 'name', 'phone', 'address', 'payment_method', 'total_cents', 'status', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function total(): string
    {
        return '$'.number_format($this->total_cents / 100, 2);
    }

    /** An email as the public demo admin may see it: j***@example.com. */
    public function maskedEmail(): string
    {
        if (! $this->email || ! str_contains($this->email, '@')) {
            return '-';
        }
        [$local, $domain] = explode('@', $this->email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    /** A name as the public demo admin may see it: first letter and the rest hidden. */
    public function maskedName(): string
    {
        return $this->name ? mb_substr($this->name, 0, 1).'***' : '-';
    }

    /** A phone number with all but its last two digits hidden. */
    public function maskedPhone(): string
    {
        return $this->phone ? '*** '.mb_substr(preg_replace('/\D/', '', $this->phone), -2) : '-';
    }
}
