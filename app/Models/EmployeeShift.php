<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['shift_date', 'type', 'start_time', 'end_time', 'notes'])]
class EmployeeShift extends Model
{
    protected function casts(): array
    {
        return ['shift_date' => 'date'];
    }
}
