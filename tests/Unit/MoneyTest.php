<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_exact_addition_without_float_drift(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('1000000.01', Money::add('999999.99', '0.02'));
        $this->assertSame('0.00', Money::add());
    }

    public function test_subtraction_and_comparison(): void
    {
        $this->assertSame('-0.01', Money::sub('1.00', '1.01'));
        $this->assertSame(0, Money::compare('2', '2.00'));
        $this->assertSame(1, Money::compare('2.01', '2'));
    }

    public function test_percentage_rounds_half_up(): void
    {
        $this->assertSame('1.50', Money::percent('30.00', '5'));
        $this->assertSame('0.89', Money::percent('9.99', '8.875')); // 0.8866 -> 0.89
        $this->assertSame('0.01', Money::percent('0.10', '5')); // 0.005 -> 0.01
        $this->assertSame('0.00', Money::percent('0.00', '15'));
    }

    public function test_times_and_share_round_to_the_cent_without_floats(): void
    {
        $this->assertSame('99.99', Money::times('33.33', 3));
        $this->assertSame('3.33', Money::share('9.99', 1, 3));
        $this->assertSame('0.02', Money::share('0.05', 1, 3));   // 0.0166 -> 0.02
        $this->assertSame('0.03', Money::share('0.05', 1, 2));   // 0.025 -> 0.03 (half up)
        $this->assertSame('10.00', Money::share('10.00', 3, 3));
    }

    public function test_rejects_garbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::toMinor('12,50');
    }
}
