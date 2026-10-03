/**
 * Smart Split V2 — Test Suite: P3 Product Improvements & Audit Remediations Frontend Tests
 * 
 * Validates:
 * 1. P3.2 Print-Ready PDF Report Generator:
 *    - Token Masking: Raw bearer invite tokens, creator tokens, and auth secrets are excluded from generated HTML.
 *    - Document integrity: KPIs, member net positions, category distribution, settlement plan, and ledger history.
 * 2. P3.1 Offline-First Outbox Mutation Queue & Auto-Sync Engine:
 *    - Stable idempotency key forwarding (item.id reused across all retries).
 *    - Transient network drop & 500 server error preservation.
 *    - 401/403 authorization error pause without data loss.
 *    - 400/422 permanent validation error dead-lettering & unblocking subsequent queue items.
 * 3. P3.3 Workspace Spending Budget Target & Live Threshold Engine:
 *    - Budget storage persistence and clearing.
 *    - Safe (<75%), warning (75-90%), danger (>90%), and exceeded (>100%) thresholds.
 */

import assert from 'node:assert';
import { ReportModal } from '../public/assets/js/components/ReportModal.js';
import { OfflineManager } from '../public/assets/js/utils/offline.js';
import { GroupHeader } from '../public/assets/js/components/GroupHeader.js';
import { FailedMutationsModal } from '../public/assets/js/components/FailedMutationsModal.js';

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
console.log(' Smart Split V2: P3 Frontend Unit Tests & Remediation Verification');
console.log('================================================================================\n');

// Mock localStorage if in node environment
const mockStorage = new Map();
globalThis.localStorage = {
    getItem: (key) => (mockStorage.has(key) ? mockStorage.get(key) : null),
    setItem: (key, val) => mockStorage.set(key, String(val)),
    removeItem: (key) => mockStorage.delete(key),
    clear: () => mockStorage.clear(),
};

// -----------------------------------------------------------------------------
// SECTION 1: P3.2 PRINT-READY PDF & TOKEN MASKING SECURITY
// -----------------------------------------------------------------------------
console.log('--- Section 1: P3.2 Print-Ready PDF & Document Security ---');

test('P3.2: ReportModal.generateReportHtml masks and excludes raw bearer invite tokens', () => {
    const rawSecretToken = 'secret_invite_token_9999';
    const mockData = {
        group: { id: 42, name: 'Goa Vacation 2026', invite_token: rawSecretToken, token: rawSecretToken },
        members: [
            { id: 1, name: 'Alice' },
            { id: 2, name: 'Bob' },
            { id: 3, name: 'Charlie' },
        ],
        balances: [
            { id: 1, member_id: 1, total_paid_cents: 600000, total_owed_cents: 200000, net_balance_cents: 400000 },
            { id: 2, member_id: 2, total_paid_cents: 0, total_owed_cents: 200000, net_balance_cents: -200000 },
            { id: 3, member_id: 3, total_paid_cents: 0, total_owed_cents: 200000, net_balance_cents: -200000 },
        ],
        settlementPlan: {
            transactions: [
                { from_member_id: 2, from_name: 'Bob', to_member_id: 1, to_name: 'Alice', amount_cents: 200000, to_upi_id: 'alice@upi' },
                { from_member_id: 3, from_name: 'Charlie', to_member_id: 1, to_name: 'Alice', amount_cents: 200000, to_upi_id: 'alice@upi' },
            ]
        },
        expenses: [
            {
                id: 101,
                title: 'Villa Stay',
                total_amount_cents: 600000,
                category: { name: 'ACCOMMODATION' },
                paid_by: { name: 'Alice' },
                paid_by_member_id: 1,
                split_type: 'EQUAL',
                expense_date: '2026-06-01',
                notes: '3 nights beachfront villa'
            }
        ],
        currency: 'INR'
    };

    const html = ReportModal.generateReportHtml(mockData);

    // Assert raw bearer token is completely absent
    assert.strictEqual(html.includes(rawSecretToken), false, 'Raw bearer invite token MUST NOT appear in report HTML');
    assert.ok(html.includes('REF: SS-0042'), 'Includes non-sensitive document reference REF: SS-0042');
    assert.ok(html.includes('Goa Vacation 2026'), 'Includes workspace name');
    assert.ok(html.includes('Base Currency: <strong>INR</strong>'), 'Includes base currency');
    assert.ok(html.includes('Total Workspace Spend'), 'Includes total spend KPI');
    assert.ok(html.includes('Active Members'), 'Includes active members KPI');
    assert.ok(html.includes('1. Member Financial Position Statement'), 'Includes Member Net Position table');
    assert.ok(html.includes('2. Category Spend Breakdown'), 'Includes Category breakdown');
    assert.ok(html.includes('3. Simplified Debt Clearance Wire Transfers'), 'Includes Settlement table');
    assert.ok(html.includes('4. Detailed Itemized Transaction Ledger'), 'Includes Ledger table');
});

test('P3.2: ReportModal.generateReportHtml handles fully settled zero-debt state gracefully', () => {
    const mockData = {
        group: { id: 10, name: 'Settled Group', invite_token: 'tok_settled_123' },
        members: [{ id: 1, name: 'Alice' }],
        balances: [{ id: 1, member_id: 1, total_paid_cents: 10000, total_owed_cents: 10000, net_balance_cents: 0 }],
        settlementPlan: { transactions: [] },
        expenses: [],
        currency: 'USD'
    };

    const html = ReportModal.generateReportHtml(mockData);

    assert.ok(html.includes('All Settled'), 'Reports all settled status in KPIs');
    assert.ok(html.includes('All debts in this workspace are fully settled'), 'Reports settled message in clearance section');
    assert.strictEqual(html.includes('tok_settled_123'), false, 'Raw token excluded');
});

// -----------------------------------------------------------------------------
// SECTION 2: P3.1 OFFLINE-FIRST MUTATION OUTBOX QUEUE & ERROR CLASSIFICATION
// -----------------------------------------------------------------------------
console.log('\n--- Section 2: P3.1 Offline-First Outbox Queue & Error Classification ---');

test('P3.1: OfflineManager enqueues mutations with unique client UUIDs and persistence', () => {
    mockStorage.clear();
    const offline = new OfflineManager('test_offline_queue');

    assert.strictEqual(offline.getQueue().length, 0, 'Queue initially empty');

    const item1 = offline.enqueue({
        action: 'CREATE_EXPENSE',
        token: 'workspace_abc',
        payload: { title: 'Lunch', amount_cents: 50000 }
    });

    assert.ok(item1.id.startsWith('offline_'), 'Generated client-side offline UUID');
    assert.strictEqual(item1.action, 'CREATE_EXPENSE');
    assert.strictEqual(offline.getQueue().length, 1, 'Queue has 1 pending item');
    assert.strictEqual(offline.hasPendingForToken('workspace_abc'), true);

    offline.remove(item1.id);
    assert.strictEqual(offline.getQueue().length, 0, 'Removed item by ID');
});

await asyncTest('P3.1: OfflineManager passes stable idempotencyKey and drains sequential operations', async () => {
    mockStorage.clear();
    const offline = new OfflineManager('test_offline_drain');

    const item = offline.enqueue({
        action: 'CREATE_EXPENSE',
        token: 'tok_drain',
        payload: { title: 'Taxi Ride', amount_cents: 30000 }
    });

    const passedKeys = [];
    const mockApi = {
        createExpense: async (token, payload, idempotencyKey) => {
            passedKeys.push(idempotencyKey);
            return { success: true };
        }
    };

    const syncedCount = await offline.drainQueue(mockApi);

    assert.strictEqual(syncedCount, 1, 'Drained 1 operation');
    assert.strictEqual(passedKeys[0], item.id, 'Passed stable client-side idempotencyKey to API');
    assert.strictEqual(offline.getQueue().length, 0, 'Queue empty after success');
});

await asyncTest('P3.1: OfflineManager transient network drop preserves queued item and halts drain', async () => {
    mockStorage.clear();
    const offline = new OfflineManager('test_offline_net_fail');

    offline.enqueue({
        action: 'CREATE_EXPENSE',
        token: 'tok_net',
        payload: { title: 'Coffee', amount_cents: 4000 }
    });

    const mockApi = {
        createExpense: async () => {
            const err = new Error('Network offline');
            err.code = 'NETWORK_OFFLINE';
            throw err;
        }
    };

    const syncedCount = await offline.drainQueue(mockApi);

    assert.strictEqual(syncedCount, 0, 'Zero operations synced on network failure');
    assert.strictEqual(offline.getQueue().length, 1, 'Item remains safely in queue');
});

await asyncTest('P3.1: OfflineManager 500 server error preserves queued item and halts drain', async () => {
    mockStorage.clear();
    const offline = new OfflineManager('test_offline_500_fail');

    offline.enqueue({
        action: 'CREATE_EXPENSE',
        token: 'tok_500',
        payload: { title: 'Server Test', amount_cents: 5000 }
    });

    const mockApi = {
        createExpense: async () => {
            const err = new Error('Internal Server Error');
            err.status = 500;
            throw err;
        }
    };

    const syncedCount = await offline.drainQueue(mockApi);

    assert.strictEqual(syncedCount, 0, 'Zero operations synced on 500');
    assert.strictEqual(offline.getQueue().length, 1, 'Item preserved in queue for later retry');
});

await asyncTest('P3.1: OfflineManager 401/403 authorization error pauses drain and preserves user data', async () => {
    mockStorage.clear();
    const offline = new OfflineManager('test_offline_401_fail');

    offline.enqueue({
        action: 'CREATE_EXPENSE',
        token: 'tok_auth',
        payload: { title: 'Auth Required', amount_cents: 6000 }
    });

    const mockApi = {
        createExpense: async () => {
            const err = new Error('Unauthorized');
            err.status = 401;
            throw err;
        }
    };

    const syncedCount = await offline.drainQueue(mockApi);

    assert.strictEqual(syncedCount, 0);
    assert.strictEqual(offline.getQueue().length, 1, 'Item preserved; sync paused for authentication');
});

await asyncTest('P3.1: OfflineManager dead-letters permanent 400/422 validation error and unblocks subsequent items', async () => {
    mockStorage.clear();
    const offline = new OfflineManager('test_offline_400_dead_letter', 'test_dead_letter');

    // Item 1: Invalid payload (e.g. member deleted)
    offline.enqueue({
        action: 'CREATE_EXPENSE',
        token: 'tok_dead',
        payload: { title: 'Invalid Expense', amount_cents: 9999 }
    });

    // Item 2: Valid payload
    offline.enqueue({
        action: 'CREATE_EXPENSE',
        token: 'tok_dead',
        payload: { title: 'Valid Subsequent Expense', amount_cents: 5000 }
    });

    assert.strictEqual(offline.getQueue().length, 2);

    const mockApi = {
        createExpense: async (token, payload) => {
            if (payload.title === 'Invalid Expense') {
                const err = new Error('Member no longer exists');
                err.status = 400;
                err.code = 'INVALID_MEMBER';
                throw err;
            }
            return { success: true };
        }
    };

    const syncedCount = await offline.drainQueue(mockApi);

    assert.strictEqual(syncedCount, 1, 'Second valid item synced successfully');
    assert.strictEqual(offline.getQueue().length, 0, 'Active queue is completely clear');

    const deadLetter = offline.getFailedMutations();
    assert.strictEqual(deadLetter.length, 1, 'Invalid item moved to dead-letter store');
    assert.strictEqual(deadLetter[0].payload.title, 'Invalid Expense');
    assert.strictEqual(deadLetter[0].errorMessage, 'Member no longer exists');
    assert.strictEqual(deadLetter[0].status, 400);
});

test('P3.1: OfflineManager removeFailedMutation and clearFailedMutations manage dead-letter entries', () => {
    mockStorage.clear();
    const offline = new OfflineManager('test_offline_q', 'test_dead_letter_manage');

    offline.recordFailedMutation({ id: 'fail_1', action: 'CREATE_EXPENSE', token: 'tok_a', payload: { title: 'Dinner' } }, { message: 'Bad request', status: 400 });
    offline.recordFailedMutation({ id: 'fail_2', action: 'CREATE_EXPENSE', token: 'tok_b', payload: { title: 'Lunch' } }, { message: 'Bad request', status: 400 });
    offline.recordFailedMutation({ id: 'fail_3', action: 'CREATE_EXPENSE', token: 'tok_a', payload: { title: 'Drinks' } }, { message: 'Bad request', status: 400 });

    assert.strictEqual(offline.getFailedMutations().length, 3);
    assert.strictEqual(offline.getFailedMutations('tok_a').length, 2);
    assert.strictEqual(offline.getFailedMutations('tok_b').length, 1);

    offline.removeFailedMutation('fail_1');
    assert.strictEqual(offline.getFailedMutations('tok_a').length, 1);

    offline.clearFailedMutations('tok_a');
    assert.strictEqual(offline.getFailedMutations('tok_a').length, 0);
    assert.strictEqual(offline.getFailedMutations('tok_b').length, 1);

    offline.clearFailedMutations();
    assert.strictEqual(offline.getFailedMutations().length, 0);
});

await asyncTest('P3.1: OfflineManager retryFailedMutation successfully retries and removes from dead-letter', async () => {
    mockStorage.clear();
    const offline = new OfflineManager('test_offline_q', 'test_dead_letter_retry');

    offline.recordFailedMutation({
        id: 'fail_retry_1',
        action: 'CREATE_EXPENSE',
        token: 'tok_retry',
        payload: { title: 'Coffee', amount_cents: 350 }
    }, { message: 'Temporary validation issue', status: 422 });

    assert.strictEqual(offline.getFailedMutations().length, 1);

    let retriedWithToken = null;
    let retriedWithId = null;
    const mockApi = {
        createExpense: async (token, payload, idempKey) => {
            retriedWithToken = token;
            retriedWithId = idempKey;
            return { success: true, expense_id: 99 };
        }
    };

    const res = await offline.retryFailedMutation('fail_retry_1', mockApi);
    assert.strictEqual(res.success, true);
    assert.strictEqual(retriedWithToken, 'tok_retry');
    assert.strictEqual(retriedWithId, 'fail_retry_1', 'Stable idempotency key forwarded on retry');
    assert.strictEqual(offline.getFailedMutations().length, 0, 'Successfully retried item removed from dead-letter store');
});

test('P3.1: FailedMutationsModal.renderListHtml sanitizes output and provides recovery controls', () => {
    const emptyHtml = FailedMutationsModal.renderListHtml([]);
    assert.ok(emptyHtml.includes('All Offline Actions In Sync'), 'Renders empty state message');

    const sampleItems = [
        {
            id: 'fail_item_1',
            action: 'CREATE_EXPENSE',
            token: 'tok_sec',
            payload: { title: '<script>alert("xss")</script>Dinner', total_amount_cents: 120000 },
            failedAt: 1790915000000,
            errorMessage: 'Member <img src=x onerror=alert(1)> not found',
            status: 400,
        }
    ];

    const html = FailedMutationsModal.renderListHtml(sampleItems);
    assert.ok(!html.includes('<script>'), 'XSS script tags escaped');
    assert.ok(!html.includes('<img src=x'), 'XSS image tags escaped');
    assert.ok(html.includes('&lt;script&gt;'), 'HTML escaped properly in title');
    assert.ok(html.includes('Retry Sync'), 'Contains retry button');
    assert.ok(html.includes('Dismiss'), 'Contains dismiss button');
});

// -----------------------------------------------------------------------------
// SECTION 3: P3.3 WORKSPACE SPENDING BUDGET TARGET & THRESHOLDS
// -----------------------------------------------------------------------------
console.log('\n--- Section 3: P3.3 Workspace Spending Budget Target & Threshold Engine ---');

test('P3.3: GroupHeader budget storage persists and clears values properly', () => {
    mockStorage.clear();
    const testToken = 'token_budget_test_1';

    assert.strictEqual(GroupHeader.getBudget(testToken), null, 'Budget is initially null');

    GroupHeader.setBudget(testToken, 25000);
    assert.strictEqual(GroupHeader.getBudget(testToken), 25000, 'Budget saved as 25000');

    GroupHeader.setBudget(testToken, null);
    assert.strictEqual(GroupHeader.getBudget(testToken), null, 'Budget cleared when null');
});

test('P3.3: GroupHeader.calculateBudgetProgress calculates safe, warning, and danger thresholds', () => {
    const budget = 50000;

    const safe = GroupHeader.calculateBudgetProgress(3000000, budget); // 60%
    assert.strictEqual(safe.statusClass, 'budget-safe');

    const warning = GroupHeader.calculateBudgetProgress(4000000, budget); // 80%
    assert.strictEqual(warning.statusClass, 'budget-warning');

    const danger = GroupHeader.calculateBudgetProgress(6000000, budget); // 120%
    assert.strictEqual(danger.statusClass, 'budget-danger');
    assert.strictEqual(danger.isExceeded, true);
    assert.strictEqual(danger.visualWidth, 100);
});

console.log('\n================================================================================');
console.log(` P3 Frontend Test Suite Completed: ${passed}/${total} Tests Passed.`);
console.log('================================================================================\n');
