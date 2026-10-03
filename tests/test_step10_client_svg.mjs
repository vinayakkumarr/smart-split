/**
 * Step 10: Client-side SVG Analytics and Charts Verification
 */

import { BalanceSummary } from '../public/assets/js/components/BalanceSummary.js';
import * as Formatters from '../public/assets/js/utils/formatters.js';

console.log("\n====================================================================");
console.log(" STEP 10: CLIENT-SIDE SVG & VISUAL ANALYTICS VALIDATION");
console.log("====================================================================\n");

let passed = 0;
let failed = 0;

function assert(condition, msg) {
    if (condition) {
        console.log(`  [PASS] ${msg}`);
        passed++;
    } else {
        console.error(`  [FAIL] ${msg}`);
        failed++;
    }
}

// 1. Test Donut SVG with Empty/Zero Data
const emptyDonut = BalanceSummary.generateDonutSvg([], 0, 'INR', 160);
assert(emptyDonut.includes('<svg width="160" height="160"'), "Empty donut renders SVG element with specified dimensions");
assert(emptyDonut.includes('₹0.00'), "Empty donut renders zero balance label");

// 2. Test Donut SVG with Populated Categories
const mockCategories = [
    { name: 'Food & Dining', icon: '🍽️', color: '#d97706', spent_cents: 1000000, percentage: 50 },
    { name: 'Scuba Diving', icon: '🤿', color: '#0284c7', spent_cents: 1000000, percentage: 50 },
];
const populatedDonut = BalanceSummary.generateDonutSvg(mockCategories, 2000000, 'INR', 160);
assert(populatedDonut.includes('stroke="#d97706"'), "Donut SVG contains category 1 stroke color");
assert(populatedDonut.includes('stroke="#0284c7"'), "Donut SVG contains category 2 stroke color");
assert(populatedDonut.includes('class="chart-donut-slice"'), "Donut SVG renders interactive chart slices");
assert(populatedDonut.includes('₹20,000.00'), "Donut SVG center text contains total formatted currency");

// 3. Test Daily Histogram SVG with Empty Data
const emptyHisto = BalanceSummary.generateHistogramSvg([], 0, 'INR', 480, 160);
assert(emptyHisto.includes('No daily transaction trends recorded yet'), "Empty histogram shows placeholder message");

// 4. Test Daily Histogram SVG with Daily Trends
const mockTrends = [
    { date: '2026-09-10', spent_cents: 1000000, count: 1 },
    { date: '2026-09-11', spent_cents: 600000, count: 2 },
    { date: '2026-09-12', spent_cents: 600000, count: 1 },
];
const populatedHisto = BalanceSummary.generateHistogramSvg(mockTrends, 733333, 'INR', 480, 160);
assert(populatedHisto.includes('<svg width="100%" height="160" viewBox="0 0 480 160"'), "Histogram renders responsive SVG with viewBox");
assert(populatedHisto.includes('class="histogram-bar"'), "Histogram renders bar rect elements");
assert(populatedHisto.includes('Avg: ₹7,333.33/day'), "Histogram renders average daily velocity reference line");
assert(populatedHisto.includes('09/10') && populatedHisto.includes('09/11') && populatedHisto.includes('09/12'), "Histogram renders formatted date labels on X-axis");

// 5. Test Member Outlay Comparison HTML
const mockOutlay = [
    { member_id: 1, name: 'Alice Organizer', paid_cents: 1000000, owed_cents: 550000, net_balance_cents: 450000, status: 'CREDITOR' },
    { member_id: 2, name: 'Bob Builder', paid_cents: 400000, owed_cents: 550000, net_balance_cents: -150000, status: 'DEBTOR' },
    { member_id: 3, name: 'Charlie Chef', paid_cents: 600000, owed_cents: 550000, net_balance_cents: 50000, status: 'CREDITOR' },
    { member_id: 4, name: 'Dana Driver', paid_cents: 200000, owed_cents: 550000, net_balance_cents: -350000, status: 'DEBTOR' },
];
const outlayHtml = BalanceSummary.generateMemberOutlayHtml(mockOutlay, 'INR');
assert(outlayHtml.includes('Alice Organizer') && outlayHtml.includes('+₹4,500.00'), "Member outlay renders Alice creditor position (+₹4,500.00)");
assert(outlayHtml.includes('Bob Builder') && outlayHtml.includes('-₹1,500.00'), "Member outlay renders Bob debtor position (-₹1,500.00)");
assert(outlayHtml.includes('outlay-bar-paid') && outlayHtml.includes('outlay-bar-owed'), "Member outlay renders dual comparison bars for paid vs owed");

console.log("\n====================================================================");
console.log(` CLIENT SVG RESULTS: ${passed} PASSED | ${failed} FAILED`);
console.log("====================================================================\n");

process.exit(failed > 0 ? 1 : 0);
