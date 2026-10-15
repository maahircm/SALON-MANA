<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic on money strings via integer minor units (2 decimals).
 * Avoids floats and does not require the bcmath extension.
 */
final class Money
{
    public static function toMinor(string|int|float|null $amount): int
    {
        $s = trim((string) ($amount ?? '0'));
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,}))?$/', $s, $m)) {
            throw new InvalidArgumentException("Invalid money amount: {$s}");
        }
        $frac = str_pad(substr($m[3] ?? '', 0, 2), 2, '0');
        // Round half up when more than two decimals were supplied.
        $roundUp = isset($m[3]) && strlen($m[3]) > 2 && (int) $m[3][2] >= 5 ? 1 : 0;
        $minor = ((int) $m[2]) * 100 + (int) $frac + $roundUp;

        return $m[1] === '-' ? -$minor : $minor;
    }

    public static function fromMinor(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    public static function add(string|int|float|null ...$amounts): string
    {
        return self::fromMinor(array_sum(array_map([self::class, 'toMinor'], $amounts)));
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::fromMinor(self::toMinor($a) - self::toMinor($b));
    }

    /** Multiply by a percentage rate (e.g. "5.00" => 5%), rounding half up to the cent. */
    public static function percent(string|int|float|null $amount, string|int|float|null $rate): string
    {
        // rate in basis points (hundredths of a percent) to stay in integers
        $bp = self::toMinor($rate);
        $num = self::toMinor($amount) * $bp;
        $minor = intdiv(abs($num) * 2 + 10000, 20000);

        return self::fromMinor($num < 0 ? -$minor : $minor);
    }

    /**
     * Multiply an amount by a whole quantity.
     */
    public static function times(string|int|float|null $amount, int $quantity): string
    {
        return self::fromMinor(self::toMinor($amount) * $quantity);
    }

    /**
     * Share of an amount: round(amount * numerator / denominator), half up, to the cent.
     */
    public static function share(string|int|float|null $amount, int $numerator, int $denominator): string
    {
        $minor = self::toMinor($amount) * $numerator;

        return self::fromMinor(intdiv(abs($minor) * 2 + $denominator, $denominator * 2) * ($minor < 0 ? -1 : 1));
    }

    public static function compare(string|int|float|null $a, string|int|float|null $b): int
    {
        return self::toMinor($a) <=> self::toMinor($b);
    }
}
