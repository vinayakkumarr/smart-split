/**
 * Test Suite for Step 13: Dynamic Split Calculations & Live Math Previews
 */

import * as MathUtils from '../public/assets/js/utils/math.js';
import * as Formatters from '../public/assets/js/utils/formatters.js';

let passed = 0;
let total = 0;

function assertTest(condition, name) {
    total++;
    if (condition) {
        console.log(`  [PASS] ${name}`);
        passed++;
    } else {
        console.error(`  [FAIL] ${name}`);
        process.exit(1);
    }
}

console.log('=====================================================');
console.log(' Step 13 – Live Split Calculation & Preview Tests (INR)');
console.log('=====================================================\n');

// 1. Equal Split Test: ₹100 for 3 equal members
console.log('--- 1. Equal Split Mode ---');
const total100 = MathUtils.toCents('100.00'); // 10000 paise
const equalSplits = MathUtils.calculateEqual(total100, [1, 2, 3]);

assertTest(equalSplits[1] === 3334, 'Member 1 gets 3334 paise (₹33.34)');
assertTest(equalSplits[2] === 3333, 'Member 2 gets 3333 paise (₹33.33)');
assertTest(equalSplits[3] === 3333, 'Member 3 gets 3333 paise (₹33.33)');
assertTest(equalSplits[1] + equalSplits[2] + equalSplits[3] === 10000, 'Sum of shares equals exactly 10000 paise');

// 2. Exact Split Test: Incomplete vs Complete amounts
console.log('\n--- 2. Exact Split Mode ---');
const incompleteExact = MathUtils.calculateExact(10000, {
    1: '40.00', // 4000
    2: '50.00', // 5000
});
assertTest(incompleteExact.isValid === false, 'Incomplete exact splits correctly flagged as invalid');
assertTest(incompleteExact.diffCents === 1000, 'Shortfall of 1000 paise (₹10.00) calculated accurately');

const completeExact = MathUtils.calculateExact(10000, {
    1: '40.00', // 4000
    2: '60.00', // 6000
});
assertTest(completeExact.isValid === true, 'Complete exact splits verified as valid');
assertTest(completeExact.allocatedSum === 10000, 'Allocated sum equals exactly 10000 paise');
assertTest(completeExact.allocatedSumCents === 10000, 'allocatedSumCents property equals 10000 paise');
assertTest(completeExact.totalAllocatedCents === 10000, 'totalAllocatedCents property equals 10000 paise');
assertTest(completeExact.diffCents === 0, 'Zero difference cents on valid exact split');

const overAllocatedExact = MathUtils.calculateExact(156600, {
    1: '25.00',   // 2500
    2: '1596.00', // 159600
});
assertTest(overAllocatedExact.isValid === false, 'Over-allocated exact split correctly flagged as invalid');
assertTest(overAllocatedExact.allocatedSum === 162100, 'Over-allocated sum is ₹1621.00 (162100 paise)');
assertTest(overAllocatedExact.allocatedSumCents === 162100, 'Over-allocated allocatedSumCents is 162100 paise');
assertTest(overAllocatedExact.diffCents === -5500, 'Over-allocated diffCents is -5500 paise (-₹55.00)');

// 3. Percentage Split Test: 50% / 25% / 25% & 33.33% 3-way
console.log('\n--- 3. Percentage Split Mode ---');
const pctSplits = MathUtils.calculatePercentage(10000, {
    1: 50.0,
    2: 25.0,
    3: 25.0,
});
assertTest(pctSplits[1] === 5000, 'Member 1 (50%) gets ₹50.00 (5000 paise)');
assertTest(pctSplits[2] === 2500, 'Member 2 (25%) gets ₹25.00 (2500 paise)');
assertTest(pctSplits[3] === 2500, 'Member 3 (25%) gets ₹25.00 (2500 paise)');

const pct3Way = MathUtils.calculatePercentage(10000, {
    1: 33.33,
    2: 33.33,
    3: 33.34,
});
const sumPct3Way = Object.values(pct3Way).reduce((a, b) => a + b, 0);
assertTest(sumPct3Way === 10000, '3-way percentage split with penny reconciliation sums to 10000 paise');

// 4. Shares / Ratio Split Test: 2 : 1 : 1
console.log('\n--- 4. Shares Split Mode ---');
const sharesSplits = MathUtils.calculateShares(10000, {
    1: 2,
    2: 1,
    3: 1,
});
assertTest(sharesSplits[1] === 5000, 'Member 1 (2 shares) gets ₹50.00 (5000 paise)');
assertTest(sharesSplits[2] === 2500, 'Member 2 (1 share) gets ₹25.00 (2500 paise)');
assertTest(sharesSplits[3] === 2500, 'Member 3 (1 share) gets ₹25.00 (2500 paise)');

// 5. Currency & Display Formatting
console.log('\n--- 5. Currency & Symbol Formatting (INR) ---');
assertTest(Formatters.formatCurrency(3334, 'INR') === '₹33.34', '3334 paise formats as ₹33.34');
assertTest(Formatters.formatCurrency(100000, 'INR') === '₹1,000.00', '100000 paise formats as ₹1,000.00');

console.log('\n=====================================================');
console.log(` All ${passed} / ${total} Step 13 Split Math & Preview Tests PASSED!`);
console.log('=====================================================');
