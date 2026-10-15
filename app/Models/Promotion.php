<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'discount_type', 'discount_value', 'min_purchase', 'starts_at', 'ends_at', 'max_redemptions', 'per_customer_limit', 'is_active'])]
class Promotion extends Model
{
    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2', 'min_purchase' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isRunning(): bool
    {
        return $this->is_active && now()->between($this->starts_at, $this->ends_at);
    }
}
