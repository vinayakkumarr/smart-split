/**
 * Smart Split – Frontend Financial Invariants & Client-Side Logic Test Suite
 */

import { toCents, toDecimal, calculateEqual, calculateExact, calculatePercentage, calculateShares, calculateItemized, calculateAdjustments } from '../public/assets/js/utils/math.js';
import { formatCurrency, getCurrencySymbol, formatDate, formatRelativeTime, getInitials, escapeHtml, renderWithHashtags, CURATED_MEMBER_EMOJIS, AVATAR_PALETTES, getDeterministicPalette, renderMemberAvatar } from '../public/assets/js/utils/formatters.js';

console.log('================================================================================');
console.log(' SMART SPLIT: FRONTEND FINANCIAL INVARIANTS & UTILITIES SUITE');
console.log('================================================================================\n');

let passed = 0;
let failed = 0;

function assertTest(condition, name, details = '') {
    if (condition) {
        passed++;
        console.log(`  [PASS] ${name}`);
    } else {
        failed++;
        console.log(`  [FAIL] ${name} : ${details}`);
    }
}

// 1. Math Converter Precision
console.log('--- 1. Client Math Conversion & Rounding Precision ---');
assertTest(toCents('10.50') === 1050, 'toCents("10.50") === 1050');
assertTest(toCents('₹1,499.99') === 149999, 'toCents("₹1,499.99") === 149999');
assertTest(toCents(0.01) === 1, 'toCents(0.01) === 1');
assertTest(toCents('0.00') === 0, 'toCents("0.00") === 0');
assertTest(toCents(-50) === 0, 'toCents(-50) falls back to 0 safely');
assertTest(toDecimal(1050) === '10.50', 'toDecimal(1050) === "10.50"');
assertTest(toDecimal(5) === '0.05', 'toDecimal(5) === "0.05"');

// 2. Equal Split Conservation
console.log('\n--- 2. Client Equal Split & Hare-Niemeyer Remainder Allocation ---');
let allEqualPreserved = true;
for (let i = 1; i <= 300; i++) {
    const totalCents = (i * 43) % 200000 + 1;
    const memberCount = (i % 20) + 2;
    const memberIds = Array.from({ length: memberCount }, (_, k) => k + 1);

    const splits = calculateEqual(totalCents, memberIds);
    const sum = Object.values(splits).reduce((a, b) => a + b, 0);
    if (sum !== totalCents) {
        allEqualPreserved = false;
        break;
    }
}
assertTest(allEqualPreserved, 'Client Equal Split Conservation: sum(splits) == totalCents across 300 randomized cases');

const oddSplit = calculateEqual(1000, [1, 2, 3]);
assertTest(oddSplit[1] === 334 && oddSplit[2] === 333 && oddSplit[3] === 333, 'Client Equal Split deterministic 1st penny allocation (334, 333, 333)');

// 3. Exact Split Validation
console.log('\n--- 3. Client Exact Split Validation ---');
const exactValid = calculateExact(5000, { 1: 2500, 2: 1500, 3: 1000 });
assertTest(exactValid.isValid && exactValid.allocatedSum === 5000 && exactValid.diffCents === 0, 'calculateExact detects perfectly balanced exact split');

const exactShortfall = calculateExact(5000, { 1: 2500, 2: 1500, 3: 900 });
assertTest(!exactShortfall.isValid && exactShortfall.diffCents === 100, 'calculateExact detects 100-cent shortfall');

// 4. Percentage Split Largest Remainder
console.log('\n--- 4. Client Percentage Split Largest Remainder Engine ---');
const pctSplits = calculatePercentage(10000, { 1: 33.33, 2: 33.33, 3: 33.34 });
const pctSum = Object.values(pctSplits).reduce((a, b) => a + b, 0);
assertTest(pctSum === 10000 && pctSplits[3] === 3334, 'calculatePercentage: Largest percentage 33.34% receives remainder penny (3334 cents)');

// 5. Shares Split
console.log('\n--- 5. Client Shares / Ratio Split ---');
const sharesSplits = calculateShares(120000, { 1: 3, 2: 2, 3: 1 });
const sharesSum = Object.values(sharesSplits).reduce((a, b) => a + b, 0);
assertTest(sharesSum === 120000 && sharesSplits[1] === 60000 && sharesSplits[2] === 40000 && sharesSplits[3] === 20000, 'calculateShares: 3:2:1 ratio of ₹1,200.00 yields ₹600.00, ₹400.00, ₹200.00');

// 6. Itemized Bill with Taxes and Discounts
console.log('\n--- 6. Client Itemized Receipt Calculations ---');
const itemizedResult = calculateItemized([
    { name: 'Pizza', amount_cents: 60000, member_ids: [1, 2] },
    { name: 'Risotto', amount_cents: 40000, member_ids: [2, 3] },
], 10000, 5000, 2000); // Subtotal: 100000, Net Surcharge: +13000, Total: 113000
const itemizedSum = Object.values(itemizedResult.splits).reduce((a, b) => a + b, 0);
assertTest(itemizedResult.totalCents === 113000 && itemizedSum === 113000, 'calculateItemized: Total bill 113,000 paise conserved strictly across member splits');

// 7. Adjustments Split
console.log('\n--- 7. Client Adjustment Offset Split ---');
const adjResult = calculateAdjustments(30000, [1, 2, 3], { 1: 5000, 3: -5000 });
const adjSum = Object.values(adjResult).reduce((a, b) => a + b, 0);
assertTest(adjSum === 30000 && adjResult[1] === 15000 && adjResult[2] === 10000 && adjResult[3] === 5000, 'calculateAdjustments: Offset math (+50, 0, -50 INR) conserved strictly');

// 8. Formatters & XSS Sanitization
console.log('\n--- 8. Client Formatters & XSS Sanitization ---');
assertTest(formatCurrency(1050, 'INR') === '₹10.50', 'formatCurrency(1050, "INR") === "₹10.50"');
assertTest(formatCurrency(500000, 'USD') === '$5,000.00', 'formatCurrency(500000, "USD") === "$5,000.00"');
assertTest(getCurrencySymbol('EUR') === '€', 'getCurrencySymbol("EUR") === "€"');
assertTest(getInitials('Aarav Sharma') === 'AS', 'getInitials("Aarav Sharma") === "AS"');
assertTest(getInitials('Diya') === 'DI', 'getInitials("Diya") === "DI"');
assertTest(escapeHtml('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;', 'escapeHtml strips script tags safely');
assertTest(renderWithHashtags('Lunch at #cafe-delight') === 'Lunch at <span class="badge badge-tag" data-tag="cafe-delight">#cafe-delight</span>', 'renderWithHashtags transforms tags into badges safely');

// 9. Member Avatars & Deterministic Palettes
console.log('\n--- 9. Avatar Palette & Monogram Rendering ---');
const palette = getDeterministicPalette('Aarav Sharma');
assertTest(palette && palette.bg && palette.border && palette.text, 'getDeterministicPalette returns structured color palette');
const renderedAvatar = renderMemberAvatar('Aarav Sharma', 'token123', { size: 28 });
assertTest(renderedAvatar.includes('default-initials') && renderedAvatar.includes('AS'), 'renderMemberAvatar renders default initials monogram safely');

console.log('\n================================================================================');
console.log(` FRONTEND FINANCIAL INVARIANTS SUMMARY: ${passed} Passed, ${failed} Failed`);
console.log('================================================================================\n');

if (failed > 0) {
    process.exit(1);
}
