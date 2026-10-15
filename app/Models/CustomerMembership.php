<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMembership extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date', 'price_paid' => 'decimal:2', 'discount_percent' => 'decimal:2',
            'points_multiplier' => 'decimal:2', 'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function isCurrent(): bool
    {
        return $this->status === 'active' && $this->starts_on->lte(today()) && $this->ends_on->gte(today());
    }
}
