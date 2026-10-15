<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Portable (MySQL / MariaDB / SQLite) SQL expressions for grouping timestamps into reporting periods. */
final class SqlDates
{
    /** @param  'day'|'week'|'month'  $period  weeks start on Monday and are labelled by that Monday */
    public static function period(string $column, string $period): string
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        return match ($period) {
            'month' => $sqlite ? "strftime('%Y-%m', {$column})" : "DATE_FORMAT({$column}, '%Y-%m')",
            'week' => $sqlite ? "date({$column}, '-6 days', 'weekday 1')" : "DATE_FORMAT(DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')",
            default => $sqlite ? "date({$column})" : "DATE({$column})",
        };
    }
}
