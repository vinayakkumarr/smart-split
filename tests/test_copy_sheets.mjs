/**
 * Smart Split – Copy for Sheets/Excel (TSV) Unit & Integration Test Suite
 */
import assert from 'assert';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, '..');

import { ExpenseList } from '../public/assets/js/components/ExpenseList.js';

console.log('=====================================================');
console.log(' Smart Split – Copy for Sheets (TSV) Test Suite');
console.log('=====================================================\n');

// Test Data
const mockExpenses = [
    {
        id: 1,
        expense_date: '2026-09-20',
        title: 'Dinner at Beach Shack',
        category: { id: 2, name: 'Food & Dining' },
        split_type: 'EQUAL',
        total_amount_cents: 350000,
        notes: 'Included mocktails and seafood platter',
        payers: [
            { member_name: 'Rahul Sharma', amount_paid_cents: 350000 }
        ],
        splits: [
            { member_name: 'Rahul Sharma', amount_owed_cents: 116667 },
            { member_name: 'Priya Patel', amount_owed_cents: 116667 },
            { member_name: 'Amit Verma', amount_owed_cents: 116666 }
        ]
    },
    {
        id: 2,
        expense_date: '2026-09-21',
        title: 'Scooter Rental\t(2 days)', // has tab in title to test sanitization
        category: { id: 3, name: 'Travel & Transport' },
        split_type: 'EXACT',
        total_amount_cents: 180000,
        notes: 'Helmet deposits\nincluded', // has newline in notes to test sanitization
        payers: [
            { member_name: 'Priya Patel', amount_paid_cents: 100000 },
            { member_name: 'Amit Verma', amount_paid_cents: 80000 }
        ],
        splits: [
            { member_name: 'Rahul Sharma', amount_owed_cents: 60000 },
            { member_name: 'Priya Patel', amount_owed_cents: 60000 },
            { member_name: 'Amit Verma', amount_owed_cents: 60000 }
        ]
    }
];

// -----------------------------------------------------------------------------
// 1. TSV Generation Testing
// -----------------------------------------------------------------------------
console.log('--- 1. Testing TSV Formatting Output ---');

const tsv = ExpenseList.formatExpensesToTsv(mockExpenses);
console.log('Generated TSV:\n' + tsv + '\n');

const lines = tsv.split('\n');
assert.strictEqual(lines.length, 3, 'Must have 1 header line + 2 data lines');

// Check Header
const expectedHeader = ['Date', 'Description', 'Category', 'Paid By', 'Split Detail', 'Amount', 'Notes'].join('\t');
assert.strictEqual(lines[0], expectedHeader, 'Header row must match requirement exactly');
console.log('  [PASS] Header row matches Date\\tDescription\\tCategory\\tPaid By\\tSplit Detail\\tAmount\\tNotes');

// Check Row 1 (Single Payer)
const row1Cols = lines[1].split('\t');
assert.strictEqual(row1Cols.length, 7, 'Row 1 must have 7 columns');
assert.strictEqual(row1Cols[0], '2026-09-20', 'Row 1 Date');
assert.strictEqual(row1Cols[1], 'Dinner at Beach Shack', 'Row 1 Description');
assert.strictEqual(row1Cols[2], 'Food & Dining', 'Row 1 Category');
assert.strictEqual(row1Cols[3], 'Rahul Sharma', 'Row 1 Paid By');
assert(row1Cols[4].includes('Rahul Sharma (1166.67)'), 'Row 1 Split Detail');
assert.strictEqual(row1Cols[5], '3500.00', 'Row 1 Amount formatted to 2 decimals');
assert.strictEqual(row1Cols[6], 'Included mocktails and seafood platter', 'Row 1 Notes');
console.log('  [PASS] Single payer row formatted accurately.');

// Check Row 2 (Multi Payer & Tab/Newline Sanitization)
const row2Cols = lines[2].split('\t');
assert.strictEqual(row2Cols.length, 7, 'Row 2 must have 7 columns');
assert.strictEqual(row2Cols[0], '2026-09-21', 'Row 2 Date');
assert.strictEqual(row2Cols[1], 'Scooter Rental (2 days)', 'Row 2 Description has tab sanitized');
assert.strictEqual(row2Cols[2], 'Travel & Transport', 'Row 2 Category');
assert(row2Cols[3].includes('Priya Patel (1000.00)') && row2Cols[3].includes('Amit Verma (800.00)'), 'Row 2 Multi Payer breakdown');
assert.strictEqual(row2Cols[5], '1800.00', 'Row 2 Amount formatted to 2 decimals');
assert.strictEqual(row2Cols[6], 'Helmet deposits included', 'Row 2 Notes has newline sanitized');
console.log('  [PASS] Multi-payer breakdown and tab/newline sanitization verified.');

// Check Row 3 (Formula Injection & Foreign FX Enrichment)
const mockAdversarial = [
    {
        id: 3,
        expense_date: '2026-09-22',
        title: '=SUM(A1:B10)', // formula injection attempt
        category: 'Entertainment', // category as string fallback
        split_type: 'EQUAL',
        total_amount_cents: 820000,
        original_currency_code: 'EUR',
        original_amount_cents: 9000,
        exchange_rate: 91.11,
        notes: '+Bonus note', // leading + formula injection attempt
        payers: [{ name: 'Vikram', amount_paid_cents: 820000 }], // name instead of member_name
        splits: [{ name: 'Vikram', amount_owed_cents: 820000 }]
    }
];

const advTsv = ExpenseList.formatExpensesToTsv(mockAdversarial);
const advLines = advTsv.split('\n');
const advRowCols = advLines[1].split('\t');

assert.strictEqual(advRowCols[1], "'=SUM(A1:B10)", 'Leading = must be escaped with single quote');
assert.strictEqual(advRowCols[2], 'Entertainment', 'String category fallback correctly handled');
assert.strictEqual(advRowCols[3], 'Vikram', 'Payer fallback name correctly resolved');
assert(advRowCols[6].includes("'+Bonus note"), 'Leading + note must be escaped with single quote');
assert(advRowCols[6].includes('[FX: EUR 90.00 @ 91.11]'), 'Foreign FX metadata enriched in notes');
console.log('  [PASS] Formula injection neutralization, string category, and FX note enrichment verified.');

// -----------------------------------------------------------------------------
// 3. Testing ExpenseList.render and Click Event Lifecycle
// -----------------------------------------------------------------------------
console.log('\n--- 3. Testing ExpenseList.render and Click Event Lifecycle ---');

// Mock browser DOM environment
global.window = { isSecureContext: true, open: () => ({}) };
try {
    Object.defineProperty(globalThis, 'navigator', {
        value: {
            clipboard: {
                writeText: async (t) => true
            }
        },
        configurable: true,
        writable: true
    });
} catch (e) {
    if (globalThis.navigator) {
        globalThis.navigator.clipboard = {
            writeText: async (t) => true
        };
    }
}

const domStore = {};
function createMockEl(tag = 'div') {
    return {
        tagName: tag.toUpperCase(),
        innerHTML: '',
        listeners: {},
        dataset: {},
        style: {},
        classList: {
            toggle: () => false,
            remove: () => {},
            add: () => {}
        },
        addEventListener(evt, fn) {
            this.listeners[evt] = fn;
        },
        appendChild(child) {},
        removeChild(child) {},
        remove() {},
        setAttribute() {},
        querySelector(s) {
            if (!domStore[s]) domStore[s] = createMockEl('div');
            return domStore[s];
        },
        querySelectorAll(s) {
            return [];
        }
    };
}

global.document = {
    addEventListener: () => {},
    getElementById: (id) => createMockEl('div'),
    createElement: (tag) => createMockEl(tag),
    body: createMockEl('body')
};

const containerMock = createMockEl('div');
ExpenseList.render(containerMock, {
    token: 'test-token',
    expenses: mockExpenses,
    members: [{ id: 1, name: 'Rahul Sharma' }, { id: 2, name: 'Priya Patel' }, { id: 3, name: 'Amit Verma' }],
    currency: 'INR'
});

const copyBtn = domStore['#btn-copy-sheets'];
assert(copyBtn, '#btn-copy-sheets must be queryable in container');
assert(typeof copyBtn.listeners['click'] === 'function', '#btn-copy-sheets must have click listener attached');

// Fire click event simulation
let clickError = null;
try {
    await copyBtn.listeners['click']({ stopPropagation: () => {}, preventDefault: () => {} });
} catch (err) {
    clickError = err;
}
assert.strictEqual(clickError, null, 'Clicking #btn-copy-sheets must NOT throw any ReferenceError or Exception');
console.log('  [PASS] ExpenseList.render click handler executed cleanly without any scoping or runtime errors.');

// Fire open in sheets click event simulation
const openSheetsBtn = domStore['#btn-open-sheets'];
assert(openSheetsBtn, '#btn-open-sheets must be queryable in container');
assert(typeof openSheetsBtn.listeners['click'] === 'function', '#btn-open-sheets must have click listener attached');

let openClickError = null;
try {
    await openSheetsBtn.listeners['click']({ stopPropagation: () => {}, preventDefault: () => {} });
} catch (err) {
    openClickError = err;
}
assert.strictEqual(openClickError, null, 'Clicking #btn-open-sheets must NOT throw any ReferenceError or Exception');
console.log('  [PASS] Open in Google Sheets click handler executed cleanly without any scoping or runtime errors.');

console.log('\n=====================================================');
console.log(' ALL COPY FOR SHEETS TESTS PASSED (100%)');
console.log('=====================================================');

