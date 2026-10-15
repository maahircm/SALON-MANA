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

if (! function_exists('qty')) {
    /** Display a stock quantity without pointless trailing zeros (12.500 -> 12.5, 3.000 -> 3). */
    function qty(string|int|float|null $quantity): string
    {
        $s = number_format((float) $quantity, 3, '.', '');

        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }
}
