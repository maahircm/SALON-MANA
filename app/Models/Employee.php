<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Salary and user linkage are not mass-assignable; they are set explicitly behind permission checks.
#[Fillable(['name', 'phone', 'email', 'job_title', 'hire_date', 'is_bookable', 'calendar_color', 'commission_type', 'commission_rate',
    'emergency_contact_name', 'emergency_contact_phone', 'notes'])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory, SoftDeletes;

    public const STATUSES = ['active' => 'Active', 'on_leave' => 'On leave', 'suspended' => 'Suspended', 'terminated' => 'Terminated'];

    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'termination_date' => 'date',
            'is_bookable' => 'boolean',
            'commission_rate' => 'decimal:2',
            'salary_amount' => 'decimal:2',
        ];
    }

    protected $hidden = ['salary_amount'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class)->orderBy('weekday')->orderBy('start_time');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(EmployeeShift::class)->orderBy('shift_date');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    /** True when approved leave covers the given date. */
    public function isOnLeave(CarbonInterface $date): bool
    {
        return $this->leaveRequests()->where('status', 'approved')->whereDate('starts_on', '<=', $date->toDateString())->whereDate('ends_on', '>=', $date->toDateString())->exists();
    }

    public function appointmentServices(): HasMany
    {
        return $this->hasMany(AppointmentService::class);
    }

    /** Can accept new bookings now. */
    public function isAvailableForBooking(): bool
    {
        return $this->is_bookable && $this->status === 'active' && ! $this->trashed();
    }

    public function canPerform(Service $service): bool
    {
        return $service->required_skill_id === null
            || $this->skills->contains('id', $service->required_skill_id);
    }
}
