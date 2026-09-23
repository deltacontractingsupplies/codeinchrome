<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['slug', 'name', 'origin', 'roast', 'notes', 'description', 'price_cents', 'stock', 'hue', 'featured'];

    protected function casts(): array
    {
        return ['featured' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function price(): string
    {
        return '$'.number_format($this->price_cents / 100, 2);
    }
}
