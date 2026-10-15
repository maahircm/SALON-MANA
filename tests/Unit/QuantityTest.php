<?php

namespace Tests\Unit;

use App\Support\Quantity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QuantityTest extends TestCase
{
    public function test_arithmetic_is_exact_to_three_decimals(): void
    {
        $this->assertSame('0.300', Quantity::add('0.1', '0.2'));
        $this->assertSame('-0.001', Quantity::sub('1.000', '1.001'));
        $this->assertSame(1, Quantity::compare('2.5', '2.499'));
        $this->assertSame('12.500', Quantity::fromMilli(Quantity::toMilli('12.5')));
    }

    public function test_value_rounds_half_up_to_the_cent(): void
    {
        $this->assertSame('12.50', Quantity::value('2.5', '5.00'));
        $this->assertSame('0.01', Quantity::value('0.001', '5.00'));    // 0.005 -> 0.01
        $this->assertSame('0.00', Quantity::value('0.000', '5.00'));
    }

    public function test_weighted_average_cost(): void
    {
        $this->assertSame('6.00', Quantity::weightedAverageCost('10', '5.00', '10', '7.00'));
        $this->assertSame('7.00', Quantity::weightedAverageCost('0', '5.00', '4', '7.00'), 'empty stock takes the new cost');
        $this->assertSame('7.00', Quantity::weightedAverageCost('-3', '5.00', '4', '7.00'), 'negative stock is ignored');
        $this->assertSame('5.67', Quantity::weightedAverageCost('2', '5.00', '1', '7.00'));
    }

    public function test_rejects_malformed_or_over_precise_quantities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::toMilli('1.2345');
    }
}
