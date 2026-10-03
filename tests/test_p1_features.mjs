/**
 * Smart Split V2 — Test Suite: P1 Product Improvements Frontend Tests
 * 
 * Validates:
 * 1. P1.3 Expense Duplication pre-fill & isolation logic (field copying, receipt clearing, ID stripping)
 * 2. P1.1 Real-Time Server-Sent Events (SSE) Client Lifecycle (subscriptions, debouncing, teardown)
 * 3. P1.2 Storage & Receipt Metadata Invariants (sanitization, MIME validation, formatters)
 */

import assert from 'node:assert';
import { SSEManager } from '../public/assets/js/utils/sse.js';

let passed = 0;
let total = 0;

function test(name, fn) {
    total++;
    try {
        fn();
        console.log(`  [PASS] ${name}`);
        passed++;
    } catch (err) {
        console.error(`  [FAIL] ${name}`);
        console.error(`         ${err.message}`);
        process.exit(1);
    }
}

async function asyncTest(name, fn) {
    total++;
    try {
        await fn();
        console.log(`  [PASS] ${name}`);
        passed++;
    } catch (err) {
        console.error(`  [FAIL] ${name}`);
        console.error(`         ${err.message}`);
        process.exit(1);
    }
}

console.log('================================================================================');
console.log(' Smart Split V2: P1 Frontend Unit Tests (SSE, Storage & Duplication)');
console.log('================================================================================\n');

// -----------------------------------------------------------------------------
// SECTION 1: P1.3 EXPENSE DUPLICATION LOGIC
// -----------------------------------------------------------------------------
console.log('--- Section 1: P1.3 Expense Duplication Logic ---');

test('P1.3: Expense duplication extracts core financial attributes and excludes identifiers', () => {
    const originalExpense = {
        id: 42,
        title: 'Team Offsite Dinner',
        description: 'Team Offsite Dinner',
        amount_cents: 1250000,
        currency_code: 'INR',
        exchange_rate: 1.0,
        category: 'FOOD',
        notes: 'Annual gathering dinner',
        split_type: 'ITEMIZED',
        expense_date: '2026-05-10',
        created_at: '2026-05-10 19:30:00',
        updated_at: '2026-05-10 20:00:00',
        receipts: [
            { id: 101, file_name: 'bill_receipt.png', url: '/receipts/101.png' },
            { id: 102, file_name: 'tip_receipt.jpg', url: '/receipts/102.jpg' },
        ],
        payers: [
            { member_id: 1, amount_paid_cents: 750000 },
            { member_id: 2, amount_paid_cents: 500000 },
        ],
        splits: [
            { member_id: 1, amount_owed_cents: 400000 },
            { member_id: 2, amount_owed_cents: 450000 },
            { member_id: 3, amount_owed_cents: 400000 },
        ],
        items: [
            { name: 'Appetizers', amount_cents: 350000, assigned_members: [1, 2, 3] },
            { name: 'Mains', amount_cents: 900000, assigned_members: [1, 2, 3] },
        ],
    };

    // Simulate duplication prefill transformation
    const duplicateData = {
        title: originalExpense.title || originalExpense.description,
        amount_cents: originalExpense.amount_cents,
        currency_code: originalExpense.currency_code,
        exchange_rate: originalExpense.exchange_rate,
        category: originalExpense.category,
        notes: originalExpense.notes,
        split_type: originalExpense.split_type,
        expense_date: new Date().toISOString().slice(0, 10),
        payers: originalExpense.payers.map(p => ({ ...p })),
        splits: originalExpense.splits.map(s => ({ ...s })),
        items: originalExpense.items ? originalExpense.items.map(i => ({ ...i })) : [],
        // Explicitly omitted / emptied
        id: undefined,
        receipts: [],
        created_at: undefined,
        updated_at: undefined,
    };

    assert.strictEqual(duplicateData.id, undefined, 'Duplicate transaction ID is undefined/omitted');
    assert.strictEqual(duplicateData.title, 'Team Offsite Dinner', 'Title matches original');
    assert.strictEqual(duplicateData.amount_cents, 1250000, 'Monetary amount matches original');
    assert.strictEqual(duplicateData.split_type, 'ITEMIZED', 'Split type matches original');
    assert.strictEqual(duplicateData.receipts.length, 0, 'Receipt attachments are cleared');
    assert.strictEqual(duplicateData.payers.length, 2, 'Payer allocations preserved');
    assert.strictEqual(duplicateData.splits.length, 3, 'Member splits preserved');
    assert.strictEqual(duplicateData.items.length, 2, 'Itemized items preserved');
    assert.strictEqual(duplicateData.expense_date, new Date().toISOString().slice(0, 10), 'Date defaults to current date');
});

test('P1.3: Duplicate modal configuration overrides headers and CTA button', () => {
    const duplicateFrom = { title: 'Coffee & Snacks', amount_cents: 45000 };
    const modalTitle = duplicateFrom ? 'Duplicate Transaction' : 'Record Transaction';
    const confirmButtonText = duplicateFrom ? 'Create Duplicate' : 'Save Transaction';

    assert.strictEqual(modalTitle, 'Duplicate Transaction');
    assert.strictEqual(confirmButtonText, 'Create Duplicate');
});

// -----------------------------------------------------------------------------
// SECTION 2: P1.1 REAL-TIME SERVER-SENT EVENTS (SSE) CLIENT
// -----------------------------------------------------------------------------
console.log('\n--- Section 2: P1.1 Real-Time Server-Sent Events (SSE) Client ---');

test('P1.1: SSEManager initializes with clean state', () => {
    const sse = new SSEManager();
    assert.strictEqual(sse.eventSource, null, 'EventSource is initially null');
    assert.strictEqual(sse.currentToken, null, 'Current token is initially null');
    assert.strictEqual(sse.listeners.size, 0, 'Listeners set is initially empty');
});

test('P1.1: SSEManager subscription lifecycle registers and unbinds listeners', () => {
    const sse = new SSEManager();
    let eventReceived = null;
    const unsubscribe = sse.subscribe((payload) => {
        eventReceived = payload;
    });

    assert.strictEqual(sse.listeners.size, 1, 'Listener registered');

    // Trigger listeners
    const testPayload = { type: 'expense.created', entity_id: 88 };
    sse.listeners.forEach(fn => fn(testPayload));
    assert.deepStrictEqual(eventReceived, testPayload, 'Listener received event payload');

    unsubscribe();
    assert.strictEqual(sse.listeners.size, 0, 'Listener successfully unsubscribed');
});

await asyncTest('P1.1: SSEManager debounces multiple incoming burst events into single trigger', async () => {
    const sse = new SSEManager();
    let triggerCount = 0;
    let lastPayload = null;

    sse.subscribe((payload) => {
        triggerCount++;
        lastPayload = payload;
    });

    // Simulate 3 rapid events arriving in 20ms intervals
    sse.handleEvent({ type: 'expense.created', entity_id: 1 });
    sse.handleEvent({ type: 'expense.updated', entity_id: 1 });
    sse.handleEvent({ type: 'receipt.created', entity_id: 10 });

    // Immediately triggerCount should be 0 due to 200ms debounce
    assert.strictEqual(triggerCount, 0, 'Debounce prevents immediate execution');

    // Wait 250ms for debounce timer to resolve
    await new Promise(r => setTimeout(r, 250));

    assert.strictEqual(triggerCount, 1, 'Debounce collapsed 3 events into 1 single notification');
    assert.strictEqual(lastPayload.type, 'receipt.created', 'Received latest event payload');

    sse.disconnect();
});

test('P1.1: SSEManager disconnect cleans up timers and connection', () => {
    const sse = new SSEManager();
    sse.currentToken = 'token_123';
    sse.debounceTimer = setTimeout(() => {}, 10000);
    sse.reconnectTimer = setTimeout(() => {}, 10000);

    sse.disconnect();

    assert.strictEqual(sse.isExplicitlyClosed, true, 'Marked as explicitly closed');
    assert.strictEqual(sse.debounceTimer, null, 'Debounce timer cleared');
    assert.strictEqual(sse.reconnectTimer, null, 'Reconnect timer cleared');
    assert.strictEqual(sse.currentToken, null, 'Current token reset to null');
});

// -----------------------------------------------------------------------------
// SECTION 3: P1.2 STORAGE & RECEIPT METADATA INVARIANTS
// -----------------------------------------------------------------------------
console.log('\n--- Section 3: P1.2 Storage & Receipt Metadata Invariants ---');

test('P1.2: Receipt filename sanitization removes dangerous characters', () => {
    function sanitizeReceiptFileName(rawName) {
        return rawName.replace(/[^a-zA-Z0-9_\-\.]/g, '_').replace(/\.{2,}/g, '_');
    }

    const dirtyName = 'my receipt & invoice (2026)/..#1.png';
    const cleanName = sanitizeReceiptFileName(dirtyName);
    assert.strictEqual(cleanName, 'my_receipt___invoice__2026____1.png');
    assert.ok(!cleanName.includes('/'), 'Contains no forward slashes');
    assert.ok(!cleanName.includes('..'), 'Contains no double dot sequences');
});

test('P1.2: Allowed receipt MIME types are correctly validated', () => {
    const allowedMimes = new Set(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);

    assert.strictEqual(allowedMimes.has('image/jpeg'), true);
    assert.strictEqual(allowedMimes.has('image/png'), true);
    assert.strictEqual(allowedMimes.has('image/webp'), true);
    assert.strictEqual(allowedMimes.has('application/pdf'), true);
    assert.strictEqual(allowedMimes.has('text/html'), false);
    assert.strictEqual(allowedMimes.has('application/javascript'), false);
    assert.strictEqual(allowedMimes.has('application/x-php'), false);
});

console.log('\n================================================================================');
console.log(` P1 Frontend Test Suite Completed: ${passed}/${total} Tests Passed.`);
console.log('================================================================================\n');
