<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'phone', 'email', 'date_of_birth', 'gender', 'address', 'notes', 'preferred_employee_id', 'status', 'marketing_consent'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, SoftDeletes;

    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'registered_on' => 'date',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : $digits;
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = $value;
        $this->attributes['phone_normalized'] = self::normalizePhone($value);
    }

    public function preferredEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'preferred_employee_id');
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(CustomerPreference::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function isArchived(): bool
    {
        return $this->deleted_at !== null;
    }

    /** Customers that can currently be booked. */
    public function isBookable(): bool
    {
        return ! $this->isArchived() && $this->status === 'active' && $this->anonymized_at === null;
    }
}
