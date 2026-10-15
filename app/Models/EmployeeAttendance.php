<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAttendance extends Model
{
    public const STATUSES = ['present' => 'Present', 'late' => 'Late', 'half_day' => 'Half day', 'absent' => 'Absent'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'clock_in' => 'datetime', 'clock_out' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function minutesWorked(): int
    {
        return $this->clock_in && $this->clock_out ? max(0, (int) $this->clock_in->diffInMinutes($this->clock_out)) : 0;
    }
}
