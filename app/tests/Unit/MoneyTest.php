<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_decimal_amounts_are_converted_without_float_arithmetic(): void
    {
        $this->assertSame(123456789, Money::toMinorUnits('1234567.89'));
        $this->assertSame('1234567.89', Money::fromMinorUnits(123456789));
        $this->assertSame(250000, Money::percentageOf(2500000, '10.00'));
        $this->assertSame(1, Money::percentageOf(1, '50.00'));
    }

    public function test_amounts_with_more_than_two_decimal_places_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMinorUnits('10.001');
    }
}
