<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'value', 'is_sensitive'])]
class CustomerPreference extends Model
{
    /** type => [label, is_sensitive] */
    public const TYPES = [
        'preference' => ['Preference', false],
        'allergy' => ['Allergy', true],
        'medical_note' => ['Medical note', true],
        'service_history' => ['Service history note', true],
    ];

    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
