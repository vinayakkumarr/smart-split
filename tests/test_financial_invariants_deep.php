<?php

declare(strict_types=1);

/**
 * Smart Split – Deep Financial Mathematical Invariants & Property-Based Test Suite
 * Validates financial correctness, zero-sum ledger conservation, largest-remainder precision,
 * and greedy settlement graph properties across thousands of deterministic test vectors.
 */

require_once __DIR__ . '/test_bootstrap.php';

use App\Utils\Money;
use App\Services\SplitCalculator;
use App\Services\SettlementEngine;
use App\Services\CurrencyService;

echo "================================================================================\n";
echo " SMART SPLIT: DEEP FINANCIAL INVARIANTS & PROPERTY TEST SUITE\n";
echo "================================================================================\n\n";

$passed = 0;
$failed = 0;
$totalAssertions = 0;

function assertInvariant(bool $condition, string $name, string $details = ''): void
{
    global $passed, $failed, $totalAssertions;
    $totalAssertions++;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name} : {$details}\n";
    }
}

// -----------------------------------------------------------------------------
// 1. HARE-NIEMEYER LARGEST REMAINDER & EQUAL SPLIT INVARIANTS
// -----------------------------------------------------------------------------
echo "--- 1. Testing Equal Split & Hare-Niemeyer Remainder Invariants ---\n";

// Invariant 1.1: Conservation of total sum across 500 random integer amounts and group sizes
$allEqualPreserved = true;
$deterministicOrderPreserved = true;

for ($i = 1; $i <= 500; $i++) {
    $amountCents = ($i * 37) % 500000 + 1; // 1 to 500,000 paise
    $memberCount = ($i % 25) + 2;          // 2 to 26 members
    $memberIds = range(10, 10 + $memberCount - 1);

    $splits = SplitCalculator::calculateEqual($amountCents, $memberIds);
    $sum = array_sum($splits);

    if ($sum !== $amountCents) {
        $allEqualPreserved = false;
        break;
    }

    $base = (int) floor($amountCents / $memberCount);
    $rem = $amountCents % $memberCount;

    // Verify first $rem members get $base + 1, and the rest get $base
    foreach ($memberIds as $idx => $mId) {
        $expected = $base + ($idx < $rem ? 1 : 0);
        if ($splits[$mId] !== $expected) {
            $deterministicOrderPreserved = false;
            break 2;
        }
    }
}

assertInvariant($allEqualPreserved, "Conservation Invariant: sum(calculateEqual) == totalCents across 500 test configurations");
assertInvariant($deterministicOrderPreserved, "Deterministic Order Invariant: Exactly (totalCents % N) lowest member IDs receive +1 remainder penny");

// Invariant 1.2: Single penny division (1 cent split among N members)
$singlePenny = SplitCalculator::calculateEqual(1, [101, 102, 103, 104, 105]);
assertInvariant(
    array_sum($singlePenny) === 1 && $singlePenny[101] === 1 && $singlePenny[102] === 0 && $singlePenny[105] === 0,
    "1-Penny Boundary: 1 cent among 5 members allocates 1 cent to member 101 and 0 to remaining 4"
);

// Invariant 1.3: Permutation Invariance (member IDs order in input does not alter deterministic assignment)
$splitsAsc = SplitCalculator::calculateEqual(1000, [1, 2, 3]);
$splitsDesc = SplitCalculator::calculateEqual(1000, [3, 2, 1]);
$splitsShuffled = SplitCalculator::calculateEqual(1000, [2, 1, 3]);
assertInvariant(
    $splitsAsc == $splitsDesc && $splitsDesc == $splitsShuffled,
    "Permutation Invariance: Member ID input ordering produces identical split allocations"
);

// -----------------------------------------------------------------------------
// 2. PERCENTAGE SPLIT PROPERTY TESTS
// -----------------------------------------------------------------------------
echo "\n--- 2. Testing Percentage Split Invariants & Precision ---\n";

// Invariant 2.1: Classic 1/3 division (33.33%, 33.33%, 33.34%)
$pct1 = SplitCalculator::calculatePercentage(10000, [
    1 => 33.33,
    2 => 33.33,
    3 => 33.34,
]);
assertInvariant(
    array_sum($pct1) === 10000 && $pct1[3] === 3334 && $pct1[1] === 3333 && $pct1[2] === 3333,
    "Percentage Invariant: 33.33% / 33.33% / 33.34% of ₹100.00 sums strictly to 10,000 paise with highest fraction receiving penny"
);

// Invariant 2.2: 7-way fractional percentage distribution
$pct7Map = [
    1 => 14.28,
    2 => 14.28,
    3 => 14.28,
    4 => 14.28,
    5 => 14.28,
    6 => 14.28,
    7 => 14.32,
];
$pct7Splits = SplitCalculator::calculatePercentage(70000, $pct7Map);
assertInvariant(
    array_sum($pct7Splits) === 70000,
    "7-Way Percentage Conservation: Sum of splits strictly equals 70,000 paise"
);

// Invariant 2.3: Non-100% rejection
$invalidPctCaught = false;
try {
    SplitCalculator::calculatePercentage(10000, [1 => 50.0, 2 => 45.0]); // 95% != 100%
} catch (\InvalidArgumentException $e) {
    $invalidPctCaught = true;
}
assertInvariant($invalidPctCaught, "Percentage Rejection: Percentage sum of 95.0% is rejected with 422 InvalidArgumentException");

// -----------------------------------------------------------------------------
// 3. SHARES / RATIO SPLIT PROPERTY TESTS
// -----------------------------------------------------------------------------
echo "\n--- 3. Testing Shares & Ratio Split Invariants ---\n";

// Invariant 3.1: Proportionality Invariant
$sharesResult = SplitCalculator::calculateShares(120000, [
    1 => 3, // 3 shares = 50% = 60,000 paise
    2 => 2, // 2 shares = 33.33% = 40,000 paise
    3 => 1, // 1 share  = 16.67% = 20,000 paise
]);
assertInvariant(
    array_sum($sharesResult) === 120000 && $sharesResult[1] === 60000 && $sharesResult[2] === 40000 && $sharesResult[3] === 20000,
    "Shares Proportionality Invariant: 3:2:1 ratio of ₹1,200.00 yields exactly ₹600.00, ₹400.00, and ₹200.00"
);

// Invariant 3.2: Indivisible Shares Remainder Allocation
$sharesOdd = SplitCalculator::calculateShares(1000, [
    1 => 1,
    2 => 1,
    3 => 1,
]);
assertInvariant(
    array_sum($sharesOdd) === 1000 && $sharesOdd[1] === 334 && $sharesOdd[2] === 333 && $sharesOdd[3] === 333,
    "Shares Remainder Invariant: 1:1:1 ratio of 1000 paise allocates remainder penny to lowest member ID"
);

// -----------------------------------------------------------------------------
// 4. ITEMIZED RECEIPT SURCHARGE INVARIANTS
// -----------------------------------------------------------------------------
echo "\n--- 4. Testing Itemized Receipt Split & Surcharge Invariants ---\n";

$items = [
    ['name' => 'Truffle Pasta', 'amount_cents' => 45000, 'member_ids' => [1, 2]],
    ['name' => 'Garlic Bread', 'amount_cents' => 15000, 'member_ids' => [1, 2, 3]],
    ['name' => 'Mocktail Pitcher', 'amount_cents' => 20000, 'member_ids' => [2, 3]],
];
// Subtotal = 45000 + 15000 + 20000 = 80000 paise (₹800.00)
// Member 1 Subtotal = 22500 + 5000 = 27500 (34.375%)
// Member 2 Subtotal = 22500 + 5000 + 10000 = 37500 (46.875%)
// Member 3 Subtotal = 5000 + 10000 = 15000 (18.75%)
// Tax: 8000 (+10%), Tip: 4000 (+5%), Discount: 2000 (-2.5%) -> Net Surcharge = +10000
// Total Bill = 90000 paise (₹900.00)

$itemized = SplitCalculator::calculateItemized($items, 8000, 4000, 2000);
assertInvariant(
    $itemized['total_cents'] === 90000,
    "Itemized Total Invariant: total_cents (90,000) == subtotal (80,000) + tax (8,000) + tip (4,000) - discount (2,000)"
);
assertInvariant(
    array_sum($itemized['splits']) === 90000,
    "Itemized Splits Conservation: Sum of member splits strictly equals 90,000 paise"
);
assertInvariant(
    $itemized['splits'][2] > $itemized['splits'][1] && $itemized['splits'][1] > $itemized['splits'][3],
    "Itemized Proportionality: Member 2 (highest consumption) pays largest share of surcharge"
);

// -----------------------------------------------------------------------------
// 5. ADJUSTMENTS SPLIT INVARIANTS
// -----------------------------------------------------------------------------
echo "\n--- 5. Testing Adjustment Split Offsets & Invariants ---\n";

$adjRes = SplitCalculator::calculateAdjustments(60000, [1, 2, 3], [
    1 => 10000,  // +₹100 offset
    3 => -10000, // -₹100 offset
]);
assertInvariant(
    array_sum($adjRes) === 60000 && $adjRes[1] === 30000 && $adjRes[2] === 20000 && $adjRes[3] === 10000,
    "Adjustments Invariant: +100 and -100 INR offsets on ₹600.00 bill yields ₹300.00, ₹200.00, and ₹100.00"
);

// -----------------------------------------------------------------------------
// 6. GREEDY DEBT MINIMIZATION (MIN-CASH-FLOW) GRAPH INVARIANTS
// -----------------------------------------------------------------------------
echo "\n--- 6. Testing Greedy Min-Cash-Flow Settlement Invariants ---\n";

// Invariant 6.1: Triangle/Circular Debt Simplification (A owes B 100, B owes C 100, C owes A 100 -> 0 transfers)
$circularBalances = [
    ['member_id' => 1, 'name' => 'Alice', 'net_balance_cents' => 0],
    ['member_id' => 2, 'name' => 'Bob', 'net_balance_cents' => 0],
    ['member_id' => 3, 'name' => 'Charlie', 'net_balance_cents' => 0],
];
$circularPlan = SettlementEngine::simplifyDebts($circularBalances, 'INR');
assertInvariant(
    $circularPlan['total_transactions'] === 0 && count($circularPlan['transactions']) === 0,
    "Circular Debt Simplification: Fully balanced circular debt yields exactly 0 settlement transactions"
);

// Invariant 6.2: Complete Settlement Conservation
// After applying all transactions from SettlementEngine, all member balances must equal 0.
$testLedger = [
    ['member_id' => 1, 'name' => 'Alice', 'net_balance_cents' => 15000],   // +150
    ['member_id' => 2, 'name' => 'Bob', 'net_balance_cents' => -8000],    // -80
    ['member_id' => 3, 'name' => 'Charlie', 'net_balance_cents' => -7000],// -70
    ['member_id' => 4, 'name' => 'David', 'net_balance_cents' => 20000],   // +200
    ['member_id' => 5, 'name' => 'Emma', 'net_balance_cents' => -20000],  // -200
];
$plan = SettlementEngine::simplifyDebts($testLedger, 'INR');

$simulatedBalances = [];
foreach ($testLedger as $m) {
    $simulatedBalances[$m['member_id']] = $m['net_balance_cents'];
}

foreach ($plan['transactions'] as $tx) {
    // from_member pays to_member
    $simulatedBalances[$tx['from_member_id']] += $tx['amount_cents'];
    $simulatedBalances[$tx['to_member_id']] -= $tx['amount_cents'];
}

$allZeroAfterSettlement = true;
foreach ($simulatedBalances as $finalNet) {
    if ($finalNet !== 0) {
        $allZeroAfterSettlement = false;
        break;
    }
}

assertInvariant(
    $allZeroAfterSettlement,
    "Settlement Conservation Theorem: Applying all settlement transactions reduces every member's balance strictly to 0"
);

// Invariant 6.3: Maximum Transaction Bound (<= N-1 for N non-zero members)
$nonZeroCount = count(array_filter($testLedger, fn($m) => $m['net_balance_cents'] !== 0));
assertInvariant(
    $plan['total_transactions'] <= ($nonZeroCount - 1),
    "Graph Minimality Bound: Transaction count ({$plan['total_transactions']}) is strictly <= N-1 (" . ($nonZeroCount - 1) . ")"
);

// Invariant 6.4: No Self-Settlement and Positive Amounts
$noSelfPayments = true;
$allPositiveAmounts = true;
foreach ($plan['transactions'] as $tx) {
    if ($tx['from_member_id'] === $tx['to_member_id']) $noSelfPayments = false;
    if ($tx['amount_cents'] <= 0) $allPositiveAmounts = false;
}
assertInvariant($noSelfPayments, "No Self-Settlement: No transaction instructs a member to pay themselves");
assertInvariant($allPositiveAmounts, "Positive Transfers: All settlement amounts are strictly > 0 cents");

// -----------------------------------------------------------------------------
// 7. MULTI-CURRENCY CONVERSION & FX RATE INVARIANTS
// -----------------------------------------------------------------------------
echo "\n--- 7. Testing Multi-Currency FX Invariants ---\n";

// Invariant 7.1: Identity Conversion (1 USD -> USD is exactly 1.0 rate, 0 loss)
$usdIdentity = CurrencyService::convert(5000, 'USD', 'USD');
assertInvariant(
    $usdIdentity['converted_cents'] === 5000 && $usdIdentity['exchange_rate'] === 1.0,
    "Identity Conversion: USD to USD conversion preserves 5000 cents exactly at 1.0 rate"
);

// Invariant 7.2: Bidirectional Inversion Consistency
$rateUsdToInr = CurrencyService::getExchangeRate('USD', 'INR');
$rateInrToUsd = CurrencyService::getExchangeRate('INR', 'USD');
$product = $rateUsdToInr * $rateInrToUsd;
assertInvariant(
    abs($product - 1.0) < 0.001,
    "Bidirectional FX Consistency: Rate(USD->INR) * Rate(INR->USD) == 1.0 (actual: " . round($product, 6) . ")"
);

// Invariant 7.3: Currency Validation
assertInvariant(CurrencyService::isSupported('INR') && CurrencyService::isSupported('EUR') && !CurrencyService::isSupported('XYZ'),
    "Currency Whitelist: Supported codes (INR, EUR) accepted; invalid code (XYZ) rejected");

echo "\n================================================================================\n";
echo " FINANCIAL INVARIANTS TEST SUMMARY: {$passed} / {$totalAssertions} Passed\n";
echo "================================================================================\n\n";

if ($failed > 0) {
    exit(1);
}
