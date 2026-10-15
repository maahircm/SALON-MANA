<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact stock quantity arithmetic in thousandths of a unit (3 decimals), without floats or bcmath.
 */
final class Quantity
{
    public static function toMilli(string|int|float|null $quantity): int
    {
        $s = trim((string) ($quantity ?? '0'));
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,3}))?$/', $s, $m)) {
            throw new InvalidArgumentException("Invalid quantity: {$s}");
        }
        $milli = ((int) $m[2]) * 1000 + (int) str_pad($m[3] ?? '', 3, '0');

        return $m[1] === '-' ? -$milli : $milli;
    }

    public static function fromMilli(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $abs = abs($milli);

        return sprintf('%s%d.%03d', $sign, intdiv($abs, 1000), $abs % 1000);
    }

    public static function add(string|int|float|null ...$quantities): string
    {
        return self::fromMilli(array_sum(array_map([self::class, 'toMilli'], $quantities)));
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::fromMilli(self::toMilli($a) - self::toMilli($b));
    }

    public static function compare(string|int|float|null $a, string|int|float|null $b): int
    {
        return self::toMilli($a) <=> self::toMilli($b);
    }

    /**
     * Monetary value of a quantity at a unit cost: round(quantity x cost), half up to the cent.
     */
    public static function value(string|int|float|null $quantity, string|int|float|null $unitCost): string
    {
        $num = self::toMilli($quantity) * Money::toMinor($unitCost);

        return Money::fromMinor(intdiv(abs($num) * 2 + 1000, 2000) * ($num < 0 ? -1 : 1));
    }

    /**
     * Weighted-average unit cost after receiving $receivedQty at $receivedCost on top of $onHandQty at $onHandCost.
     */
    public static function weightedAverageCost(string $onHandQty, string $onHandCost, string $receivedQty, string $receivedCost): string
    {
        $onHand = max(0, self::toMilli($onHandQty));
        $received = self::toMilli($receivedQty);
        if ($onHand + $received <= 0) {
            return Money::fromMinor(Money::toMinor($receivedCost));
        }
        $totalMinor = $onHand * Money::toMinor($onHandCost) + $received * Money::toMinor($receivedCost);
        $divisor = $onHand + $received;

        return Money::fromMinor(intdiv($totalMinor * 2 + $divisor, $divisor * 2));
    }
}
