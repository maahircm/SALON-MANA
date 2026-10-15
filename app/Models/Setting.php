<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'salon_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
