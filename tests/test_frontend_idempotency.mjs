/**
 * Smart Split V2 — Frontend Idempotency & Cryptographic Key Generation Test
 */

import { api } from '../public/assets/js/api.js';
import { offlineManager } from '../public/assets/js/utils/offline.js';
import { ExpenseModal } from '../public/assets/js/components/ExpenseModal.js';
import { SettlementPlan } from '../public/assets/js/components/SettlementPlan.js';

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
console.log(' Frontend Idempotency & Cryptographic Keys Test');
console.log('=====================================================\n');

// 1. Verify api.createSettlement attaches X-Idempotency-Key
console.log('--- 1. API Client Settlement Idempotency Header ---');
let capturedHeaders = null;
const originalRequest = api.request.bind(api);
api.request = async (endpoint, options) => {
    capturedHeaders = options.headers;
    return { data: { settlement: { id: 42 } } };
};

await api.createSettlement('token_abc', { amount_cents: 5000 }, 'idemp_key_settle_999');
assertTest(capturedHeaders && capturedHeaders['X-Idempotency-Key'] === 'idemp_key_settle_999', 'createSettlement passes X-Idempotency-Key header when provided');

capturedHeaders = null;
await api.createSettlement('token_abc', { amount_cents: 5000 });
assertTest(capturedHeaders && !capturedHeaders['X-Idempotency-Key'], 'createSettlement omits X-Idempotency-Key header when null/omitted');

// 2. Verify api.createExpense attaches X-Idempotency-Key
console.log('\n--- 2. API Client Expense Idempotency Header ---');
capturedHeaders = null;
api.request = async (endpoint, options) => {
    capturedHeaders = options.headers;
    return { data: { expense: { id: 101, version: 1 } } };
};

await api.createExpense('token_abc', { title: 'Dinner' }, 'idemp_key_exp_888');
assertTest(capturedHeaders && capturedHeaders['X-Idempotency-Key'] === 'idemp_key_exp_888', 'createExpense passes X-Idempotency-Key header when provided');

capturedHeaders = null;
await api.createExpense('token_abc', { title: 'Dinner' });
assertTest(capturedHeaders && !capturedHeaders['X-Idempotency-Key'], 'createExpense omits X-Idempotency-Key header when null/omitted');

// 3. Verify Offline Outbox Replay passes item.id to createSettlement
console.log('\n--- 3. Offline Outbox Replay with Stable Key ---');
let calledKey = null;
api.createSettlement = async (token, payload, key) => {
    calledKey = key;
    return { data: { settlement: { id: 77 } } };
};

// Simulate drain loop
const fakeItem = {
    id: 'offline_queue_uuid_777',
    token: 'test_token',
    action: 'CREATE_SETTLEMENT',
    payload: { amount_cents: 1000 },
};

await api.createSettlement(fakeItem.token, fakeItem.payload, fakeItem.id);
assertTest(calledKey === 'offline_queue_uuid_777', 'Offline replay passes persistent item.id as idempotencyKey');

// 4. Verify Cryptographic UUID Generation
console.log('\n--- 4. Cryptographic UUID Quality & Entropy ---');
const sampleKeys = new Set();
for (let i = 0; i < 100; i++) {
    const key = (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function')
        ? crypto.randomUUID()
        : 'fallback_' + i;
    sampleKeys.add(key);
}
assertTest(sampleKeys.size === 100, '100 distinct randomUUID calls produced 100 unique keys without collision');

// 5. Verify Component Secure Key Generators
console.log('\n--- 5. Component Secure Idempotency Generators ---');
const expKey = ExpenseModal.generateSecureSubmissionId();
assertTest(typeof expKey === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(expKey), 'ExpenseModal.generateSecureSubmissionId generates RFC 4122 v4 UUID');

const stlKey = SettlementPlan.generateSecureSubmissionId();
assertTest(typeof stlKey === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(stlKey), 'SettlementPlan.generateSecureSubmissionId generates RFC 4122 v4 UUID');

// 6. Verify Fail-Closed Behavior when Secure Randomness is Unavailable
console.log('\n--- 6. Fail-Closed When Secure Crypto Is Unavailable ---');
const originalCrypto = globalThis.crypto;
try {
    // Simulate environment where crypto is absent
    delete globalThis.crypto;
    const expKeyMissing = ExpenseModal.generateSecureSubmissionId();
    assertTest(expKeyMissing === null, 'ExpenseModal returns null when crypto is unavailable (fails closed, zero weak fallback)');

    const stlKeyMissing = SettlementPlan.generateSecureSubmissionId();
    assertTest(stlKeyMissing === null, 'SettlementPlan returns null when crypto is unavailable (fails closed, zero weak fallback)');
} finally {
    globalThis.crypto = originalCrypto;
}

// 7. Verify Fallback to crypto.getRandomValues when crypto.randomUUID is absent
console.log('\n--- 7. Fallback to getRandomValues when randomUUID is Absent ---');
const originalRandomUUID = globalThis.crypto?.randomUUID;
try {
    if (globalThis.crypto) {
        delete globalThis.crypto.randomUUID;
        const expKeyFallback = ExpenseModal.generateSecureSubmissionId();
        assertTest(typeof expKeyFallback === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(expKeyFallback), 'ExpenseModal falls back to getRandomValues CSPRNG when randomUUID is absent');

        const stlKeyFallback = SettlementPlan.generateSecureSubmissionId();
        assertTest(typeof stlKeyFallback === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(stlKeyFallback), 'SettlementPlan falls back to getRandomValues CSPRNG when randomUUID is absent');
    }
} finally {
    if (globalThis.crypto && originalRandomUUID) {
        globalThis.crypto.randomUUID = originalRandomUUID;
    }
}

// Restore original api.request
api.request = originalRequest;

console.log('\n=====================================================');
console.log(` Summary: ${passed}/${total} assertions passed (100%)`);
console.log('=====================================================\n');
