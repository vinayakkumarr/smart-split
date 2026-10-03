<?php

declare(strict_types=1);

namespace App\Utils;

use InvalidArgumentException;

/**
 * High-precision currency converter and integer-cent utility.
 */
class Money
{
    /**
     * Convert decimal/floating amount or currency string to exact integer cents.
     * E.g., "10.50" -> 1050, 99.99 -> 9999, 10 -> 1000
     *
     * @param float|string|int $amount
     * @return int
     * @throws InvalidArgumentException If amount is invalid or negative.
     */
    public static function toCents(float|string|int $amount): int
    {
        if (is_string($amount)) {
            $cleaned = trim(str_ireplace(['₹', 'Rs.', 'Rs', 'INR', '$', '€', '£', ' ', ','], '', $amount));
            if (!is_numeric($cleaned)) {
                throw new InvalidArgumentException("Invalid currency format: '{$amount}'", 422);
            }
            $amount = (float) $cleaned;
        }

        $cents = (int) round(((float) $amount) * 100.0);

        if ($cents < 0) {
            throw new InvalidArgumentException("Monetary amounts cannot be negative: {$cents} cents.", 422);
        }

        return $cents;
    }

    /**
     * Convert integer cents to standard 2-decimal string.
     * E.g., 1050 -> "10.50", 5 -> "0.05", 0 -> "0.00"
     *
     * @param int $cents
     * @return string
     */
    public static function toDecimal(int $cents): string
    {
        return sprintf('%.2f', $cents / 100.0);
    }

    /**
     * Format integer paise/cents with currency symbol or code.
     * E.g., 1050 -> "₹10.50"
     *
     * @param int $cents
     * @param string $currencyCode
     * @return string
     */
    public static function format(int $cents, string $currencyCode = 'INR'): string
    {
        $symbols = [
            'INR' => '₹',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CAD' => 'CA$',
            'AUD' => 'AU$',
            'SGD' => 'SG$',
            'AED' => 'AED ',
            'JPY' => '¥',
            'CHF' => 'CHF ',
            'CNY' => '¥',
            'NZD' => 'NZ$',
        ];

        $code = strtoupper(trim($currencyCode));
        $symbol = $symbols[$code] ?? ($code . ' ');
        return $symbol . number_format($cents / 100.0, 2, '.', ',');
    }

    /**
     * Get standard symbol for a currency code.
     *
     * @param string $currencyCode
     * @return string
     */
    public static function getSymbol(string $currencyCode): string
    {
        $symbols = [
            'INR' => '₹',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'CAD' => 'CA$',
            'AUD' => 'AU$',
            'SGD' => 'SG$',
            'AED' => 'AED',
            'JPY' => '¥',
            'CHF' => 'CHF',
            'CNY' => '¥',
            'NZD' => 'NZ$',
        ];

        $code = strtoupper(trim($currencyCode));
        return $symbols[$code] ?? $code;
    }

    /**
     * Validate an array of integer cents amounts.
     *
     * @param array<int> $amounts
     * @return int Total sum in cents.
     */
    public static function sumCents(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += (int) $amount;
        }
        return $total;
    }
}
