<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'price', 'duration_days', 'discount_percent', 'points_multiplier', 'is_active'])]
class MembershipPlan extends Model
{
    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'discount_percent' => 'decimal:2', 'points_multiplier' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerMembership::class);
    }
}
