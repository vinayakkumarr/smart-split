<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Utils/Money.php';
require dirname(__DIR__) . '/src/Services/SplitCalculator.php';

use App\Utils\Money;
use App\Services\SplitCalculator;

echo "=====================================================\n";
echo " Smart Split – Financial Math & Split Engine Tests\n";
echo "=====================================================\n";

$testsPassed = 0;
$totalTests = 0;

function assertTest(bool $condition, string $testName): void
{
    global $testsPassed, $totalTests;
    $totalTests++;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $testsPassed++;
    } else {
        echo "  [FAIL] {$testName}\n";
        exit(1);
    }
}

// 1. Money Converter Tests
echo "\n--- 1. Testing Money Utilities ---\n";
assertTest(Money::toCents("10.50") === 1050, "Money::toCents('10.50') === 1050");
assertTest(Money::toCents("₹99.99") === 9999, "Money::toCents('₹99.99') === 9999");
assertTest(Money::toCents(0.01) === 1, "Money::toCents(0.01) === 1");
assertTest(Money::toCents("0.00") === 0, "Money::toCents('0.00') === 0");
assertTest(Money::toDecimal(1050) === "10.50", "Money::toDecimal(1050) === '10.50'");
assertTest(Money::format(1050) === "₹10.50", "Money::format(1050) === '₹10.50' (Default INR)");
assertTest(Money::format(1050, 'INR') === "₹10.50", "Money::format(1050, 'INR') === '₹10.50'");
assertTest(Money::format(500, 'EUR') === "€5.00", "Money::format(500, 'EUR') === '€5.00'");

$negativeCaught = false;
try {
    Money::toCents(-10.5);
} catch (InvalidArgumentException $e) {
    $negativeCaught = true;
}
assertTest($negativeCaught, "Money::toCents rejects negative amounts");

// 2. Equal Split Tests
echo "\n--- 2. Testing Equal Split Distribution ---\n";

// 1000 cents split 3 ways: [334, 333, 333]
$equal3 = SplitCalculator::calculateEqual(1000, [10, 20, 30]);
assertTest(array_sum($equal3) === 1000, "Equal split (1000 cents / 3) total sum === 1000");
assertTest($equal3[10] === 334 && $equal3[20] === 333 && $equal3[30] === 333, "Equal split allocates remainder penny to lowest member_id");

// 10000 cents split 7 ways: sum must be exactly 10000
$equal7 = SplitCalculator::calculateEqual(10000, [1, 2, 3, 4, 5, 6, 7]);
assertTest(array_sum($equal7) === 10000, "Equal split (10000 cents / 7) total sum === 10000");
// 10000 / 7 = 1428 remainder 4. First 4 get 1429, last 3 get 1428.
assertTest($equal7[1] === 1429 && $equal7[4] === 1429 && $equal7[5] === 1428, "Equal split remainder distributed across first 4 members");

// 3. Exact Split Tests
echo "\n--- 3. Testing Exact Split Validation ---\n";
$exactValid = SplitCalculator::calculateExact(5000, [
    1 => 2500,
    2 => 1500,
    3 => 1000,
]);
assertTest(array_sum($exactValid) === 5000, "Exact split (2500 + 1500 + 1000) === 5000");

$exactMismatchCaught = false;
try {
    SplitCalculator::calculateExact(5000, [
        1 => 2500,
        2 => 1500,
        3 => 999, // 1 cent shortfall
    ]);
} catch (InvalidArgumentException $e) {
    $exactMismatchCaught = true;
}
assertTest($exactMismatchCaught, "Exact split detects 1 cent shortfall and rejects transaction");

// 4. Percentage Split Tests
echo "\n--- 4. Testing Percentage Split Distribution ---\n";
// $100 split 33.33%, 33.33%, 33.34% -> sum must be exactly 10000
$pctSplits = SplitCalculator::calculatePercentage(10000, [
    1 => 33.33,
    2 => 33.33,
    3 => 33.34,
]);
assertTest(array_sum($pctSplits) === 10000, "Percentage split (33.33% / 33.33% / 33.34%) sums to exactly 10000 cents");
assertTest($pctSplits[3] === 3334, "Highest percentage receives remainder allocation");

// $50 split 50%, 25%, 25%
$pctSplits2 = SplitCalculator::calculatePercentage(5000, [
    1 => 50.0,
    2 => 25.0,
    3 => 25.0,
]);
assertTest($pctSplits2[1] === 2500 && $pctSplits2[2] === 1250 && $pctSplits2[3] === 1250, "Percentage split (50/25/25) exact calculation");

$invalidPctCaught = false;
try {
    SplitCalculator::calculatePercentage(10000, [1 => 50.0, 2 => 40.0]); // 90% != 100%
} catch (InvalidArgumentException $e) {
    $invalidPctCaught = true;
}
assertTest($invalidPctCaught, "Percentage split rejects non-100% totals");

// 5. Shares Split Tests
echo "\n--- 5. Testing Shares / Ratios Split ---\n";
// $100 split 2 shares vs 1 share vs 1 share (total 4 shares)
$shares = SplitCalculator::calculateShares(10000, [
    1 => 2,
    2 => 1,
    3 => 1,
]);
assertTest(array_sum($shares) === 10000, "Shares split (2:1:1 of $100) sums to 10000 cents");
assertTest($shares[1] === 5000 && $shares[2] === 2500 && $shares[3] === 2500, "Shares split allocations match 50% / 25% / 25%");

// $10 split 1:1:1 shares
$shares3 = SplitCalculator::calculateShares(1000, [
    1 => 1,
    2 => 1,
    3 => 1,
]);
assertTest(array_sum($shares3) === 1000, "Shares split (1:1:1 of $10) sums to 1000 cents");
// 6. Itemized Split Tests
echo "\n--- 6. Testing Itemized Receipt Split & Proportional Surcharge Math ---\n";
// Item 1: Burger ₹400 (Alice, Bob = ₹200 each)
// Item 2: Salad ₹200 (Bob, Charlie = ₹100 each)
// Item 3: Drink ₹100 (Alice = ₹100)
// Subtotal = ₹700 (Alice: ₹300, Bob: ₹300, Charlie: ₹100)
// Tax = ₹70, Tip = ₹35, Discount = ₹15 -> Net surcharge = ₹90 (Total = ₹790)
$itemizedRes = SplitCalculator::calculateItemized([
    ['name' => 'Burger', 'amount_cents' => 40000, 'member_ids' => [1, 2]],
    ['name' => 'Salad', 'amount_cents' => 20000, 'member_ids' => [2, 3]],
    ['name' => 'Drink', 'amount_cents' => 10000, 'member_ids' => [1]],
], 7000, 3500, 1500);

assertTest($itemizedRes['total_cents'] === 79000, "Itemized total matches subtotal + tax + tip - discount (79000 paise)");
assertTest(array_sum($itemizedRes['splits']) === 79000, "Sum of member itemized allocations exactly equals total bill (79000 paise)");
assertTest($itemizedRes['splits'][1] > 30000 && $itemizedRes['splits'][2] > 30000, "Proportional tax/tip added to Alice and Bob based on higher subtotal");

// 7. Adjustments Split Tests
echo "\n--- 7. Testing Adjustment Offset Math ---\n";
// Total ₹300 split among 3 members, Member 1 has +₹50 offset, Member 3 has -₹50 offset
$adjSplits = SplitCalculator::calculateAdjustments(30000, [1, 2, 3], [1 => 5000, 3 => -5000]);
assertTest(array_sum($adjSplits) === 30000, "Adjusted split sums to exactly 30000 paise");
assertTest($adjSplits[1] === 15000 && $adjSplits[2] === 10000 && $adjSplits[3] === 5000, "Adjusted shares correctly reflect +50 and -50 INR offsets (150, 100, 50 INR)");

echo "\n=====================================================\n";
echo " All {$testsPassed} / {$totalTests} Math & Split Engine Tests PASSED!\n";
echo "=====================================================\n";
