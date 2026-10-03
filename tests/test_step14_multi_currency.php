<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Env.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Services/CurrencyService.php';
require_once __DIR__ . '/../src/Utils/Money.php';

use App\Core\Env;
use App\Core\Database;
use App\Services\CurrencyService;
use App\Utils\Money;

Env::load(dirname(__DIR__) . '/.env');
$pdo = Database::getConnection();

$baseUrl = 'http://localhost:8000';

function postJson(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($res ?: '', true)];
}

function putJson(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($res ?: '', true)];
}

function getJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'body' => json_decode($res ?: '', true)];
}

echo "\n====================================================================\n";
echo " STEP 14: MULTI-CURRENCY ENGINE & CROSS-CURRENCY RATES TEST\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

$assertCheck = function (bool $condition, string $msg) use (&$passed, &$failed) {
    if ($condition) {
        echo "  [PASS] {$msg}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failed++;
    }
};

try {
    // 1. CurrencyService Unit Assertions
    $supported = CurrencyService::getSupportedCurrencies();
    $assertCheck(count($supported) === 12, "CurrencyService supports 12 world benchmark currencies");
    $assertCheck(isset($supported['INR']) && isset($supported['USD']) && isset($supported['EUR']) && isset($supported['GBP']), "Key currencies (INR, USD, EUR, GBP) present");
    $assertCheck(CurrencyService::isValid('USD'), "USD is validated as supported currency");
    $assertCheck(!CurrencyService::isValid('XYZ'), "Invalid currency code XYZ rejected");

    $rateUsdInr = CurrencyService::getExchangeRate('USD', 'INR');
    $assertCheck($rateUsdInr > 80.0 && $rateUsdInr < 90.0, "Benchmark USD -> INR exchange rate is ~83.50 (got: {$rateUsdInr})");

    $rateInrUsd = CurrencyService::getExchangeRate('INR', 'USD');
    $assertCheck($rateInrUsd > 0.01 && $rateInrUsd < 0.02, "Benchmark INR -> USD exchange rate is ~0.011976 (got: {$rateInrUsd})");

    $rateSame = CurrencyService::getExchangeRate('EUR', 'EUR');
    $assertCheck($rateSame === 1.0, "Same currency exchange rate is exactly 1.0");

    // Conversion tests
    $convertedInr = CurrencyService::convert(10000, 'USD', 'INR', 83.50);
    $assertCheck($convertedInr['converted_cents'] === 835000, "Converted $100.00 (10,000 cents) @ 83.50 to ₹8,350.00 (835,000 paise)");

    $convertedEur = CurrencyService::convert(835000, 'INR', 'USD', 1 / 83.50);
    $assertCheck($convertedEur['converted_cents'] === 10000, "Converted ₹8,350.00 back to $100.00 (10,000 cents)");

    // Money utils symbol checks
    $assertCheck(Money::getSymbol('USD') === '$', "Money::getSymbol('USD') returns '$'");
    $assertCheck(Money::getSymbol('EUR') === '€', "Money::getSymbol('EUR') returns '€'");
    $assertCheck(Money::getSymbol('GBP') === '£', "Money::getSymbol('GBP') returns '£'");
    $assertCheck(Money::getSymbol('INR') === '₹', "Money::getSymbol('INR') returns '₹'");

    // 2. Currency API Endpoints
    $currenciesRes = getJson("{$baseUrl}/api/currencies");
    $assertCheck($currenciesRes['status'] === 200, "GET /api/currencies returned HTTP 200");
    $assertCheck(count($currenciesRes['body']['data']['currencies']) === 12, "API returned 12 supported currencies");

    $fxRes = getJson("{$baseUrl}/api/exchange-rates?from=USD&to=INR");
    $assertCheck($fxRes['status'] === 200, "GET /api/exchange-rates?from=USD&to=INR returned HTTP 200");
    $assertCheck($fxRes['body']['data']['from'] === 'USD' && $fxRes['body']['data']['to'] === 'INR', "Exchange rate pair USD->INR verified");
    $assertCheck($fxRes['body']['data']['rate'] > 80.0, "API returned valid rate");

    $allRatesRes = getJson("{$baseUrl}/api/exchange-rates?from=EUR");
    $assertCheck($allRatesRes['status'] === 200, "GET /api/exchange-rates?from=EUR returned HTTP 200");
    $assertCheck(isset($allRatesRes['body']['data']['rates']['USD']), "All target exchange rates returned for EUR base");

    // 3. Foreign Currency Expense in INR Group
    $grpRes = postJson("{$baseUrl}/api/groups", [
        'name' => 'EuroTrip & US Conference ' . time(),
        'creator_name' => 'Aditya Explorer',
        'currency' => 'INR',
    ]);
    $assertCheck($grpRes['status'] === 201, "Created INR Group for Foreign Currency Test (HTTP 201)");
    $token = $grpRes['body']['data']['group']['invite_token'];
    $adityaId = $grpRes['body']['data']['creator']['id'];

    $m2Res = postJson("{$baseUrl}/api/groups/{$token}/members", ['name' => 'Bianca']);
    $biancaId = $m2Res['body']['data']['member']['id'];
    $m3Res = postJson("{$baseUrl}/api/groups/{$token}/members", ['name' => 'Carlos']);
    $carlosId = $m3Res['body']['data']['member']['id'];

    // Create foreign currency expense: $150.00 USD (15,000 cents) paid by Aditya, split equal among Aditya, Bianca, Carlos
    // Custom exchange rate: 84.00 INR per USD -> Total INR = 150 * 84 = ₹12,600.00 (1,260,000 paise)
    $exp1Res = postJson("{$baseUrl}/api/groups/{$token}/expenses", [
        'title' => 'Broadway Show Tickets NYC',
        'currency' => 'USD',
        'amount' => 150.00,
        'exchange_rate' => 84.00,
        'expense_date' => '2026-09-21',
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $adityaId, 'amount_paid_cents' => 1260000]],
        'participants' => [
            ['member_id' => $adityaId],
            ['member_id' => $biancaId],
            ['member_id' => $carlosId],
        ],
    ]);
    $assertCheck($exp1Res['status'] === 201, "Created Foreign Currency Expense ($150.00 USD @ 84.00 = ₹12,600.00) (HTTP 201)");
    $exp1Data = $exp1Res['body']['data']['expense'];
    $assertCheck($exp1Data['original_currency_code'] === 'USD', "Expense persists original_currency_code = 'USD'");
    $assertCheck((int)$exp1Data['original_amount_cents'] === 15000, "Expense persists original_amount_cents = 15,000 ($150.00)");
    $assertCheck(abs((float)$exp1Data['exchange_rate'] - 84.00) < 0.001, "Expense persists custom exchange_rate = 84.00");
    $assertCheck((int)$exp1Data['total_amount_cents'] === 1260000, "Expense base ledger total_amount_cents = 1,260,000 paise (₹12,600.00)");

    // Verify Splits: 1260000 / 3 = 420000 paise each
    $splits1 = $exp1Data['splits'];
    $assertCheck(count($splits1) === 3, "Expense has 3 splits");
    $assertCheck((int)$splits1[0]['amount_owed_cents'] === 420000 && (int)$splits1[1]['amount_owed_cents'] === 420000 && (int)$splits1[2]['amount_owed_cents'] === 420000, "Splits equal 420,000 paise (₹4,200.00) each");

    // 4. Verify Balances in Group
    $balRes = getJson("{$baseUrl}/api/groups/{$token}/balances");
    $assertCheck($balRes['status'] === 200, "Fetched balances (HTTP 200)");
    $membersBal = $balRes['body']['data']['members'] ?? [];
    $netSum = 0;
    foreach ($membersBal as $b) {
        $netSum += (int)$b['net_balance_cents'];
    }
    $assertCheck($netSum === 0, "Ledger zero-sum invariant strictly preserved across foreign transactions (net sum = 0)");

    // 5. Test Expense Update with Foreign Currency modification
    // Change to EUR €200.00 at exchange rate 91.00 -> Base INR = 200 * 91 = ₹18,200.00 (1,820,000 paise)
    $updateRes = putJson("{$baseUrl}/api/groups/{$token}/expenses/{$exp1Data['id']}", [
        'title' => 'Louvre Museum Guided Tour Paris',
        'currency' => 'EUR',
        'amount' => 200.00,
        'exchange_rate' => 91.00,
        'expense_date' => '2026-09-22',
        'split_type' => 'EQUAL',
        'payers' => [['member_id' => $adityaId, 'amount_paid_cents' => 1820000]],
        'participants' => [
            ['member_id' => $adityaId],
            ['member_id' => $biancaId],
            ['member_id' => $carlosId],
        ],
    ]);
    $assertCheck($updateRes['status'] === 200, "Updated Expense to EUR €200.00 @ 91.00 (HTTP 200)");
    $updatedExp = $updateRes['body']['data']['expense'];
    $assertCheck($updatedExp['original_currency_code'] === 'EUR', "Updated original_currency_code = 'EUR'");
    $assertCheck((int)$updatedExp['original_amount_cents'] === 20000, "Updated original_amount_cents = 20,000 (€200.00)");
    $assertCheck((int)$updatedExp['total_amount_cents'] === 1820000, "Updated base ledger total_amount_cents = 1,820,000 paise (₹18,200.00)");

    // 6. Verify Activity Log Audit Entry Narrates Foreign Currency
    $activityRes = getJson("{$baseUrl}/api/groups/{$token}/activity-feed");
    $assertCheck($activityRes['status'] === 200, "Fetched activity timeline (HTTP 200)");
    $activities = $activityRes['body']['data']['activities'] ?? [];
    $hasFxActivity = false;
    foreach ($activities as $act) {
        $desc = $act['narrative'] ?? $act['description'] ?? '';
        if (str_contains($desc, '$') || str_contains($desc, '€') || str_contains($desc, 'USD') || str_contains($desc, 'EUR')) {
            $hasFxActivity = true;
            break;
        }
    }
    $assertCheck($hasFxActivity, "Activity audit timeline accurately documents foreign currency transaction narratives");

} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n--------------------------------------------------------------------\n";
echo " STEP 14 SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "--------------------------------------------------------------------\n\n";

exit($failed > 0 ? 1 : 0);
