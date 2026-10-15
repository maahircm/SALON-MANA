<?php

if (! function_exists('money')) {
    /** Format a decimal string/number as salon currency (display only; never used for arithmetic). */
    function money(string|int|float|null $amount): string
    {
        return config('salon.currency_symbol').number_format((float) $amount, 2);
    }
}

if (! function_exists('salon_datetime')) {
    function salon_datetime(?DateTimeInterface $dt): string
    {
        return $dt ? $dt->format(config('salon.date_format').' '.config('salon.time_format')) : '—';
    }
}
