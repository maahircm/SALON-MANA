<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServicePriceHistory extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
