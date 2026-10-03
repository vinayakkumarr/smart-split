/**
 * Node.js Unit Verification for Client-Side ES6 Core Modules
 */

import { Store } from '../public/assets/js/state.js';
import * as MathUtils from '../public/assets/js/utils/math.js';
import * as Formatters from '../public/assets/js/utils/formatters.js';
import { Router } from '../public/assets/js/router.js';

console.log("=====================================================");
console.log(" Smart Split – Client-Side Core JS Tests (ES6)");
console.log("=====================================================");

let passed = 0;
let total = 0;

function assert(condition, name) {
    total++;
    if (condition) {
        console.log(`  [PASS] ${name}`);
        passed++;
    } else {
        console.error(`  [FAIL] ${name}`);
        process.exit(1);
    }
}

// 1. Math Utils Tests
console.log("\n--- 1. Testing Math Utilities ---");
assert(MathUtils.toCents("10.50") === 1050, "toCents('10.50') === 1050");
assert(MathUtils.toCents("₹99.99") === 9999, "toCents('₹99.99') === 9999");
assert(MathUtils.toCents(10.5) === 1050, "toCents(10.5) === 1050");
assert(MathUtils.toDecimal(1050) === "10.50", "toDecimal(1050) === '10.50'");

// Equal split: 1000 paise / 3 -> [334, 333, 333]
const equal3 = MathUtils.calculateEqual(1000, [1, 2, 3]);
assert(equal3[1] === 334 && equal3[2] === 333 && equal3[3] === 333, "calculateEqual(1000, [1,2,3]) distributes remainder penny");

// Percentage split: 10000 paise split 33.33%, 33.33%, 33.34%
const pctSplit = MathUtils.calculatePercentage(10000, { 1: 33.33, 2: 33.33, 3: 33.34 });
const pctSum = Object.values(pctSplit).reduce((a, b) => a + b, 0);
assert(pctSum === 10000, "calculatePercentage sums precisely to 10000 paise");

// Shares split: 10000 paise split 2:1:1
const shares = MathUtils.calculateShares(10000, { 1: 2, 2: 1, 3: 1 });
assert(shares[1] === 5000 && shares[2] === 2500 && shares[3] === 2500, "calculateShares(10000, {1:2, 2:1, 3:1}) === 5000, 2500, 2500");

// Itemized split with tax/tip
const itemized = MathUtils.calculateItemized([
    { name: 'Entree', amount_cents: 6000, member_ids: [1, 2] },
    { name: 'Dessert', amount_cents: 4000, member_ids: [2, 3] }
], 1000, 500, 0); // Total 11500 paise
assert(itemized.totalCents === 11500, "calculateItemized totalCents === 11500");
const itemizedSum = Object.values(itemized.splits).reduce((a, b) => a + b, 0);
assert(itemizedSum === 11500, "calculateItemized splits sum equals totalCents (11500)");

// Adjusted split
const adj = MathUtils.calculateAdjustments(30000, [1, 2, 3], { 1: 5000, 3: -5000 });
assert(adj[1] === 15000 && adj[2] === 10000 && adj[3] === 5000, "calculateAdjustments offsets correctly applied");

// 2. Formatters Tests
console.log("\n--- 2. Testing Formatters ---");
assert(Formatters.formatCurrency(1050, 'INR').includes('10.50'), "formatCurrency formats INR decimal");
assert(Formatters.getInitials("Alice Smith") === "AS", "getInitials('Alice Smith') === 'AS'");
assert(Formatters.getInitials("Bob") === "BO", "getInitials('Bob') === 'BO'");
assert(Formatters.escapeHtml("<script>alert('xss')</script>").includes('&lt;script&gt;'), "escapeHtml neutralizes tags");

// 3. State Store Tests
console.log("\n--- 3. Testing Reactive State Store ---");
const testStore = new Store({ currentGroup: null });
let listenerFired = false;
let observedState = null;

const unsubscribe = testStore.subscribe((state) => {
    listenerFired = true;
    observedState = state;
});

testStore.setState({ currentGroup: { id: 1, name: "Test Group" } });
assert(listenerFired === true, "Store notifies subscribers on setState");
assert(observedState.currentGroup.name === "Test Group", "Subscriber receives updated state");

unsubscribe();
listenerFired = false;
testStore.setState({ activeView: "dashboard" });
assert(listenerFired === false, "Unsubscribed listener does not fire");

// 4. Router Pattern Tests
console.log("\n--- 4. Testing Hash Router Matching ---");
const testRouter = new Router();
let matchedParams = null;

testRouter.on('/g/:token/ledger/:memberId', (params) => {
    matchedParams = params;
});

// Mock hash and test resolution
const route = testRouter.routes[0];
const match = '/g/abc123token/ledger/42'.match(route.regex);
assert(match !== null, "Router regex matches dynamic path with multiple parameters");
assert(match[1] === 'abc123token' && match[2] === '42', "Router extracts named tokens accurately");

console.log("\n=====================================================");
console.log(` All ${passed} / ${total} Client Core Tests PASSED!`);
console.log("=====================================================");
