/**
 * Automated Verification Script for Group Spending Budget Target Feature
 */

import assert from 'node:assert/strict';

// Mock Browser Environment
const localStorageMock = (function() {
    let store = {};
    return {
        getItem: (key) => store[key] || null,
        setItem: (key, value) => { store[key] = String(value); },
        removeItem: (key) => { delete store[key]; },
        clear: () => { store = {}; }
    };
})();

global.localStorage = localStorageMock;
global.window = {
    location: { href: 'http://localhost:8000/#/groups/test_budget_token' }
};
global.document = {
    body: {
        appendChild: () => {},
        style: {}
    },
    getElementById: () => null,
    createElement: (tag) => ({
        tagName: tag,
        className: '',
        id: '',
        style: {},
        innerHTML: '',
        dataset: {},
        addEventListener: () => {},
        querySelector: () => null,
        querySelectorAll: () => [],
        appendChild: () => {},
        remove: () => {},
        classList: { add: () => {}, remove: () => {}, contains: () => false },
        focus: () => {},
        select: () => {}
    })
};

console.log('--- TEST 1: Importing GroupHeader Module ---');
const { GroupHeader } = await import('../public/assets/js/components/GroupHeader.js');
assert(typeof GroupHeader === 'function', 'GroupHeader should be exported as a class');
console.log('✔ GroupHeader loaded successfully');

console.log('\n--- TEST 2: Budget Progress Calculations & Thresholds ---');
// Case A: Safe (< 75%)
const safeProgress = GroupHeader.calculateBudgetProgress(2500000, 50000); // Spent ₹25,000 out of ₹50,000 (50%)
assert.equal(safeProgress.percentage, 50);
assert.equal(safeProgress.percentageFormatted, '50.0%');
assert.equal(safeProgress.visualWidth, 50);
assert.equal(safeProgress.statusClass, 'budget-safe');
assert(safeProgress.statusColor.includes('--financial-credit'), 'Color should be green for < 75%');
assert.equal(safeProgress.isExceeded, false);
console.log('✔ Safe threshold (<75%) verified:', safeProgress.percentageFormatted, safeProgress.statusColor);

// Case B: Warning (75% - 90%)
const warningProgress = GroupHeader.calculateBudgetProgress(3840000, 50000); // Spent ₹38,400 out of ₹50,000 (76.8%)
assert.equal(warningProgress.percentage, 76.8);
assert.equal(warningProgress.percentageFormatted, '76.8%');
assert.equal(warningProgress.visualWidth, 76.8);
assert.equal(warningProgress.statusClass, 'budget-warning');
assert(warningProgress.statusColor.includes('--financial-warning'), 'Color should be amber/orange for 75-90%');
assert.equal(warningProgress.isExceeded, false);
console.log('✔ Warning threshold (75-90%) verified:', warningProgress.percentageFormatted, warningProgress.statusColor);

// Case C: Alert (> 90% and < 100%)
const alertProgress = GroupHeader.calculateBudgetProgress(4700000, 50000); // Spent ₹47,000 out of ₹50,000 (94.0%)
assert.equal(alertProgress.percentage, 94);
assert.equal(alertProgress.percentageFormatted, '94.0%');
assert.equal(alertProgress.visualWidth, 94);
assert.equal(alertProgress.statusClass, 'budget-danger');
assert(alertProgress.statusColor.includes('--financial-debt'), 'Color should be red for >90%');
assert.equal(alertProgress.isExceeded, false);
console.log('✔ Alert threshold (>90%) verified:', alertProgress.percentageFormatted, alertProgress.statusColor);

// Case D: Exceeded (> 100%)
const exceededProgress = GroupHeader.calculateBudgetProgress(6000000, 50000); // Spent ₹60,000 out of ₹50,000 (120.0%)
assert.equal(exceededProgress.percentage, 120);
assert.equal(exceededProgress.percentageFormatted, '120.0%');
assert.equal(exceededProgress.visualWidth, 100, 'Visual width must cap at 100% to prevent CSS overflow');
assert.equal(exceededProgress.statusClass, 'budget-danger');
assert(exceededProgress.statusColor.includes('--financial-debt'), 'Color should be red when exceeded');
assert.equal(exceededProgress.isExceeded, true);
console.log('✔ Exceeded threshold (>100%) verified:', exceededProgress.percentageFormatted, 'visualWidth capped at', exceededProgress.visualWidth);

console.log('\n--- TEST 3: localStorage Persistence (getBudget / setBudget) ---');
const testToken = 'wksp_alpha_987';

// Initially null
assert.equal(GroupHeader.getBudget(testToken), null);

// Set budget
GroupHeader.setBudget(testToken, 50000);
assert.equal(GroupHeader.getBudget(testToken), 50000);
assert.equal(localStorage.getItem(`smartsplit_budget_${testToken}`), '50000');

// Update budget
GroupHeader.setBudget(testToken, 75000.50);
assert.equal(GroupHeader.getBudget(testToken), 75000.50);

// Clear budget
GroupHeader.setBudget(testToken, null);
assert.equal(GroupHeader.getBudget(testToken), null);
assert.equal(localStorage.getItem(`smartsplit_budget_${testToken}`), null);
console.log('✔ localStorage persistence, retrieval, updating, and clearing verified');

console.log('\n--- TEST 4: DOM Rendering without Budget ---');
const containerNoBudget = {
    innerHTML: '',
    querySelector: function(sel) {
        if (sel === '#btn-set-budget') return { addEventListener: () => {} };
        return null;
    }
};

GroupHeader.render(containerNoBudget, {
    invite_token: 'no_budget_tok',
    name: 'Trip to Goa',
    currency_code: 'INR',
    created_at: '2026-09-20'
}, {
    totalSpendCents: 1500000,
    memberCount: 4,
    pendingTransfers: 2
});

assert(containerNoBudget.innerHTML.includes('Budget Target'), 'Must include Budget Target label');
assert(containerNoBudget.innerHTML.includes('+ Set Budget'), 'Must render "+ Set Budget" link when no budget configured');
assert(containerNoBudget.innerHTML.includes('Total Expenditure'), 'Must include Total Expenditure');
console.log('✔ Unset budget header rendering verified');

console.log('\n--- TEST 5: DOM Rendering with Active Budget ---');
const activeToken = 'tok_with_budget';
GroupHeader.setBudget(activeToken, 50000);

const containerWithBudget = {
    innerHTML: '',
    querySelector: function(sel) {
        if (sel === '#btn-edit-budget') return { addEventListener: () => {} };
        return null;
    }
};

GroupHeader.render(containerWithBudget, {
    invite_token: activeToken,
    name: 'Tokyo Expedition',
    currency_code: 'INR',
    created_at: '2026-09-20'
}, {
    totalSpendCents: 3840000, // ₹38,400.00
    memberCount: 5,
    pendingTransfers: 0
});

assert(containerWithBudget.innerHTML.includes('Budget Target'), 'Must render Budget Target');
assert(containerWithBudget.innerHTML.includes('Edit'), 'Must render Edit button');
assert(containerWithBudget.innerHTML.includes('38,400.00'), 'Must display current spent');
assert(containerWithBudget.innerHTML.includes('50,000.00'), 'Must display target ceiling');
assert(containerWithBudget.innerHTML.includes('76.8%'), 'Must display percentage progress');
assert(containerWithBudget.innerHTML.includes('budget-progress-track'), 'Must include progress track element');
assert(containerWithBudget.innerHTML.includes('budget-progress-bar'), 'Must include progress bar fill element');
console.log('✔ Active budget header rendering with progress metrics & bar verified');

console.log('\n=========================================');
console.log('🎉 ALL BUDGET TARGET TESTS PASSED! 🎉');
console.log('=========================================');
