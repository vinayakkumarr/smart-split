<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Request;
use App\Services\CurrencyService;
use InvalidArgumentException;

/**
 * Controller providing Currency Metadata and Cross-Exchange Rate Inquiries.
 */
class CurrencyController extends BaseController
{
    /**
     * GET /api/currencies
     * List all supported global currencies with symbols, flags, and names.
     */
    public function index(Request $request): void
    {
        $currencies = array_values(CurrencyService::getSupportedCurrencies());
        $this->json(['currencies' => $currencies]);
    }

    /**
     * GET /api/exchange-rates?from=USD&to=INR&amount_cents=10000
     * Return calculated exchange rate and converted equivalent amount.
     */
    public function exchangeRate(Request $request): void
    {
        $from = (string) $request->getQueryParam('from', 'USD');
        $to = $request->getQueryParam('to');

        if (!CurrencyService::isSupported($from)) {
            throw new InvalidArgumentException("Unsupported 'from' currency code '{$from}'.", 422);
        }

        if ($to !== null && $to !== '') {
            $to = (string) $to;
            if (!CurrencyService::isSupported($to)) {
                throw new InvalidArgumentException("Unsupported 'to' currency code '{$to}'.", 422);
            }
            $amountCents = (int) $request->getQueryParam('amount_cents', 10000);
            $rate = CurrencyService::getExchangeRate($from, $to);
            $conversion = CurrencyService::convert($amountCents, $from, $to, $rate);

            $this->json([
                'from' => strtoupper($from),
                'to' => strtoupper($to),
                'exchange_rate' => $rate,
                'rate' => $rate,
                'amount_cents' => $amountCents,
                'converted_cents' => $conversion['converted_cents'],
            ]);
            return;
        }

        // Return all exchange rates from $from
        $rates = [];
        foreach (CurrencyService::getSupportedCurrencies() as $code => $meta) {
            $rates[$code] = CurrencyService::getExchangeRate($from, $code);
        }

        $this->json([
            'from' => strtoupper($from),
            'rates' => $rates,
        ]);
    }
}
