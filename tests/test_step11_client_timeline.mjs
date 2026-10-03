/**
 * Step 11: Client-Side Activity Timeline & Relative Time Utilities Test
 */

import * as Formatters from '../public/assets/js/utils/formatters.js';

console.log("\n====================================================================");
console.log(" STEP 11: CLIENT-SIDE ACTIVITY TIMELINE & TIME FORMATTERS");
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

// 1. Test formatRelativeTime
const now = new Date();
assert(Formatters.formatRelativeTime(now.toISOString()) === 'Just now', "Relative time 'Just now' for current timestamp");

const tenMinsAgo = new Date(Date.now() - 10 * 60 * 1000);
assert(Formatters.formatRelativeTime(tenMinsAgo.toISOString()) === '10m ago', "Relative time '10m ago' for 10-minute old timestamp");

const twoHoursAgo = new Date(Date.now() - 2 * 3600 * 1000);
assert(Formatters.formatRelativeTime(twoHoursAgo.toISOString()) === '2h ago', "Relative time '2h ago' for 2-hour old timestamp");

const yesterday = new Date(Date.now() - 26 * 3600 * 1000);
assert(Formatters.formatRelativeTime(yesterday.toISOString()) === 'Yesterday', "Relative time 'Yesterday' for 26-hour old timestamp");

const threeDaysAgo = new Date(Date.now() - 3 * 86400 * 1000);
assert(Formatters.formatRelativeTime(threeDaysAgo.toISOString()) === '3d ago', "Relative time '3d ago' for 3-day old timestamp");

// 2. Test Formatters currency with commas
assert(Formatters.formatCurrency(150000, 'INR') === '₹1,500.00', "formatCurrency formats ₹1,500.00 with comma grouping");
assert(Formatters.formatCurrency(1000000, 'INR') === '₹10,000.00', "formatCurrency formats ₹10,000.00 with comma grouping");

console.log("\n====================================================================");
console.log(` CLIENT TIMELINE RESULTS: ${passed} PASSED | ${failed} FAILED`);
console.log("====================================================================\n");

process.exit(failed > 0 ? 1 : 0);
