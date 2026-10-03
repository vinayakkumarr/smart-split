<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Multi-Currency & Cross-Exchange Rate Conversion Service.
 */
class CurrencyService
{
    /**
     * Benchmark exchange rates relative to USD (1.0 USD base).
     * @var array<string, float>
     */
    private static array $ratesAgainstUsd = [
        'USD' => 1.0,
        'INR' => 83.50,
        'EUR' => 0.92,
        'GBP' => 0.79,
        'CAD' => 1.36,
        'AUD' => 1.52,
        'SGD' => 1.34,
        'AED' => 3.67,
        'JPY' => 155.00,
        'CHF' => 0.90,
        'CNY' => 7.23,
        'NZD' => 1.64,
    ];

    /**
     * Supported currency metadata.
     * @var array<string, array{code: string, name: string, symbol: string, flag: string}>
     */
    private static array $supportedCurrencies = [
        'INR' => ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'flag' => '🇮🇳'],
        'USD' => ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'flag' => '🇺🇸'],
        'EUR' => ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'flag' => '🇪🇺'],
        'GBP' => ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'flag' => '🇬🇧'],
        'CAD' => ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'CA$', 'flag' => '🇨🇦'],
        'AUD' => ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'AU$', 'flag' => '🇦🇺'],
        'SGD' => ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'SG$', 'flag' => '🇸🇬'],
        'AED' => ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'AED', 'flag' => '🇦🇪'],
        'JPY' => ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'flag' => '🇯🇵'],
        'CHF' => ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'flag' => '🇨🇭'],
        'CNY' => ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'flag' => '🇨🇳'],
        'NZD' => ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'flag' => '🇳🇿'],
    ];

    /**
     * Get list of all supported currencies.
     * @return array<string, array{code: string, name: string, symbol: string, flag: string}>
     */
    public static function getSupportedCurrencies(): array
    {
        return self::$supportedCurrencies;
    }

    /**
     * Validate whether a currency code is supported.
     * @param string $currencyCode
     * @return bool
     */
    public static function isSupported(string $currencyCode): bool
    {
        return isset(self::$supportedCurrencies[strtoupper(trim($currencyCode))]);
    }

    /**
     * Alias for isSupported.
     * @param string $currencyCode
     * @return bool
     */
    public static function isValid(string $currencyCode): bool
    {
        return self::isSupported($currencyCode);
    }

    /**
     * Calculate benchmark exchange rate between two currencies (1 unit of $from = X units of $to).
     * E.g. getExchangeRate('USD', 'INR') -> 83.50
     *      getExchangeRate('INR', 'USD') -> 0.011976
     *      getExchangeRate('EUR', 'INR') -> 90.760869
     *
     * @param string $from
     * @param string $to
     * @return float
     * @throws InvalidArgumentException
     */
    public static function getExchangeRate(string $from, string $to): float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return 1.0;
        }

        if (!isset(self::$ratesAgainstUsd[$from])) {
            throw new InvalidArgumentException("Unsupported currency code '{$from}'.", 422);
        }

        if (!isset(self::$ratesAgainstUsd[$to])) {
            throw new InvalidArgumentException("Unsupported currency code '{$to}'.", 422);
        }

        $fromToUsd = self::$ratesAgainstUsd[$from];
        $toToUsd = self::$ratesAgainstUsd[$to];

        // 1 FROM = (toToUsd / fromToUsd) TO
        $rate = $toToUsd / $fromToUsd;

        return round($rate, 6);
    }

    /**
     * Convert an integer-cent monetary amount from one currency to another.
     *
     * @param int $amountCents Amount in original currency (cents/paise)
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param float|null $customRate Optional explicit user rate override
     * @return array{converted_cents: int, exchange_rate: float, from_currency: string, to_currency: string}
     */
    public static function convert(int $amountCents, string $fromCurrency, string $toCurrency, ?float $customRate = null): array
    {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === $to) {
            return [
                'converted_cents' => $amountCents,
                'exchange_rate' => 1.0,
                'from_currency' => $from,
                'to_currency' => $to,
            ];
        }

        $rate = ($customRate !== null && $customRate > 0)
            ? $customRate
            : self::getExchangeRate($from, $to);

        $convertedCents = (int) round($amountCents * $rate);

        return [
            'converted_cents' => $convertedCents,
            'exchange_rate' => $rate,
            'from_currency' => $from,
            'to_currency' => $to,
        ];
    }
}
