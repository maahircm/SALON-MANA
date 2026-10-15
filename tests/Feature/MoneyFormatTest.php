<?php

namespace Tests\Feature;

use Tests\TestCase;

class MoneyFormatTest extends TestCase
{
    public function test_amounts_are_formatted_with_the_symbol_first_and_the_sign_in_front(): void
    {
        config(['salon.currency_symbol' => '$']);

        $this->assertSame('$12.50', money('12.5'));
        $this->assertSame('-$35.00', money('-35'));
        $this->assertSame('$1,234,567.89', money('1234567.89'));
        $this->assertSame('$0.00', money(null));
        $this->assertSame('$0.00', money('-0.004'), 'a negative amount that rounds to zero shows no sign');
    }
}
