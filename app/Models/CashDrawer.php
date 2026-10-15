<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_active'])]
class CashDrawer extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashDrawerSession::class);
    }
}
