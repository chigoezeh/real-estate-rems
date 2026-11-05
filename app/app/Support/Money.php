<?php

namespace App\Support;

use InvalidArgumentException;
use OverflowException;

final class Money
{
    public static function toMinorUnits(string|int $amount): int
    {
        $amount = (string) $amount;

        if (preg_match('/\A\d+(?:\.\d{1,2})?\z/', $amount) !== 1) {
            throw new InvalidArgumentException('Money must be a non-negative decimal amount with at most two decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $maxWhole = (string) intdiv(PHP_INT_MAX, 100);

        if (strlen($whole) > strlen($maxWhole) || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0)) {
            throw new OverflowException('Money amount exceeds the supported range.');
        }

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function fromMinorUnits(int $amount): string
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function percentageOf(int $amount, string|int $percentage): int
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        $basisPoints = self::toMinorUnits($percentage);
        $whole = intdiv($amount, 10000);
        $remainder = $amount % 10000;

        if ($basisPoints > 0 && $whole > intdiv(PHP_INT_MAX, $basisPoints)) {
            throw new OverflowException('Percentage calculation exceeds the supported range.');
        }

        return ($whole * $basisPoints) + intdiv(($remainder * $basisPoints) + 5000, 10000);
    }

    public static function multiplyByInteger(int $amount, int $quantity): int
    {
        if ($amount < 0 || $quantity < 0) {
            throw new InvalidArgumentException('Money amount and quantity cannot be negative.');
        }

        if ($amount > 0 && $quantity > intdiv(PHP_INT_MAX, $amount)) {
            throw new OverflowException('Money calculation exceeds the supported range.');
        }

        return $amount * $quantity;
    }

    public static function perArea(int $rate, string|int $area): int
    {
        if ($rate < 0) {
            throw new InvalidArgumentException('Money rate cannot be negative.');
        }

        $areaHundredths = self::toMinorUnits($area);
        if ($rate === 0 || $areaHundredths === 0) {
            return 0;
        }

        if ($rate > intdiv(PHP_INT_MAX - 50, $areaHundredths)) {
            throw new OverflowException('Per-area calculation exceeds the supported range.');
        }

        return intdiv(($rate * $areaHundredths) + 50, 100);
    }
}
