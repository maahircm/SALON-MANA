<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// base_price / promo fields are deliberately excluded: they change only via ServicePricing (services.price).
#[Fillable(['service_category_id', 'name', 'description', 'duration_minutes', 'tax_rate', 'required_skill_id', 'is_active'])]
class Service extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'promo_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'promo_starts_on' => 'date',
            'promo_ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function requiredSkill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'required_skill_id');
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ServiceAddon::class);
    }

    public function consumables(): HasMany
    {
        return $this->hasMany(ServiceConsumable::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(ServicePriceHistory::class)->latest('id');
    }

    public function hasActivePromo(?CarbonInterface $on = null): bool
    {
        if ($this->promo_price === null) {
            return false;
        }
        $day = ($on ?? now())->startOfDay();
        if ($this->promo_starts_on && $day->lt($this->promo_starts_on)) {
            return false;
        }
        if ($this->promo_ends_on && $day->gt($this->promo_ends_on)) {
            return false;
        }

        return true;
    }

    /** Price a customer pays on the given day, as a decimal string. */
    public function currentPrice(?CarbonInterface $on = null): string
    {
        return $this->hasActivePromo($on) ? (string) $this->promo_price : (string) $this->base_price;
    }
}
