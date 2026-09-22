<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Monitor extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['up' => 'boolean', 'checked_at' => 'datetime'];
    }
}
