<?php

declare(strict_types=1);

namespace OrderInsights\Import;

/**
 * Converts decimal money strings to integer cents using string arithmetic only.
 * Floats are never involved, so "19.99" is exactly 1999 cents.
 */
final class Money
{
    /** Up to 9 integer digits and at most 2 decimals: "0", "14.5", "129.00". */
    private const PATTERN = '/^(\d{1,9})(?:\.(\d{1,2}))?$/D';

    public static function toCents(string $value): ?int
    {
        if (!preg_match(self::PATTERN, $value, $m)) {
            return null;
        }
        $fraction = str_pad($m[2] ?? '', 2, '0');
        return ((int) $m[1]) * 100 + (int) $fraction;
    }
}
