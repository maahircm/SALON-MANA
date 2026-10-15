<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// status and timestamps are changed only through the booking / status services.
#[Fillable(['notes'])]
class Appointment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'status' => AppointmentStatus::class,
            'is_late_cancellation' => 'boolean',
            'cancelled_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'service_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AppointmentService::class)->orderBy('sort_order');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalAmount(): string
    {
        return Money::add(...$this->lines->pluck('unit_price')->all());
    }

    public function involvesEmployee(?int $employeeId): bool
    {
        return $employeeId !== null && $this->lines->contains('employee_id', $employeeId);
    }
}
