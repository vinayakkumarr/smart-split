/**
 * Automated Verification Script for UTR / Bank Reference Tracking in Settlements
 */

import assert from 'node:assert/strict';

// Mock Browser Environment
global.document = {
    getElementById: () => null,
    querySelector: () => null,
    querySelectorAll: () => [],
    createElement: () => ({
        className: '',
        style: {},
        appendChild: () => {},
        remove: () => {},
        querySelector: () => ({ addEventListener: () => {} }),
        querySelectorAll: () => []
    }),
    body: { appendChild: () => {} }
};
global.window = {
    location: { href: 'http://localhost:8000/#/groups/test_utr_token' }
};

let lastCreatedSettlement = null;
global.fetch = async (url, options = {}) => {
    if (url.includes('/settlements') && options.method === 'POST') {
        const body = typeof options.body === 'string' ? JSON.parse(options.body) : options.body;
        lastCreatedSettlement = body;
        return {
            ok: true,
            json: async () => ({
                success: true,
                data: {
                    settlement: { id: 99, ...body }
                }
            })
        };
    }
    return {
        ok: true,
        json: async () => ({ success: true, data: {} })
    };
};

console.log('--- TEST 1: Testing SettlementPlan.extractUtr Helper ---');
const { SettlementPlan } = await import('../public/assets/js/components/SettlementPlan.js');
assert(typeof SettlementPlan.extractUtr === 'function', 'SettlementPlan.extractUtr must be a static function');

// Case A: Null / Empty / Standard Notes without UTR
assert.equal(SettlementPlan.extractUtr(null), null);
assert.equal(SettlementPlan.extractUtr(''), null);
assert.equal(SettlementPlan.extractUtr('Settled via Smart Split (INR)'), null);

// Case B: Standard UTR Appended
assert.equal(
    SettlementPlan.extractUtr('Settled via Smart Split (INR) • UTR: 409281928391'),
    '409281928391'
);

// Case C: Custom text reference (e.g. IMPS / Cash / UPI Ref)
assert.equal(
    SettlementPlan.extractUtr('Settled via Smart Split (INR) • UTR: UPI Ref 99887766'),
    'UPI Ref 99887766'
);
assert.equal(
    SettlementPlan.extractUtr('Paid in Cash • UTR: CASH_HANDOVER'),
    'CASH_HANDOVER'
);

console.log('✔ SettlementPlan.extractUtr parsing logic verified across all variations');

console.log('\n--- TEST 2: Testing Settle Modal Content & UTR Input Field ---');
let capturedModal = null;
const { Modal } = await import('../public/assets/js/components/Modal.js');
const origOpen = Modal.open;
Modal.open = (options) => {
    capturedModal = options;
};

SettlementPlan.openSettleModal({
    token: 'test_token',
    tx: {
        from_member_id: 1,
        from_name: 'Rahul',
        to_member_id: 2,
        to_name: 'Priya',
        amount_cents: 125000
    },
    currency: 'INR'
});

assert(capturedModal !== null, 'Modal.open must be invoked');
assert(capturedModal.content.includes('id="settle-utr-input"'), 'Modal markup must contain #settle-utr-input');
assert(capturedModal.content.includes('Payment Reference / UTR Number (Optional)'), 'Modal markup must contain UTR field label');
console.log('✔ Settle modal includes optional UTR input field');

console.log('\n--- TEST 3: Testing Settlement Submission Payload with UTR ---');
// Mock Modal mount point
const mockMount = {
    querySelector: (sel) => {
        if (sel === '#settle-amount-input') return { value: '1250.00' };
        if (sel === '#settle-utr-input') return { value: 'UPI Ref 409281928391' };
        return null;
    }
};
Modal.getMountPoint = () => mockMount;

await capturedModal.onConfirm();
assert(lastCreatedSettlement !== null, 'Settlement must be posted');
assert.equal(lastCreatedSettlement.amount_cents, 125000);
assert.equal(lastCreatedSettlement.notes, 'Settled via Smart Split (INR) • UTR: UPI Ref 409281928391');
console.log('✔ Settlement with UTR appends UTR reference cleanly:', lastCreatedSettlement.notes);

console.log('\n--- TEST 4: Testing Settlement Submission Payload WITHOUT UTR ---');
const mockMountEmptyUtr = {
    querySelector: (sel) => {
        if (sel === '#settle-amount-input') return { value: '500.00' };
        if (sel === '#settle-utr-input') return { value: '   ' };
        return null;
    }
};
Modal.getMountPoint = () => mockMountEmptyUtr;

await capturedModal.onConfirm();
assert.equal(lastCreatedSettlement.amount_cents, 50000);
assert.equal(lastCreatedSettlement.notes, 'Settled via Smart Split (INR)');
console.log('✔ Settlement without UTR keeps clean base notes without empty UTR prefix:', lastCreatedSettlement.notes);

console.log('\n--- TEST 5: Testing Payment Audit History UTR Badge Rendering ---');
const container = {
    innerHTML: '',
    querySelector: () => null,
    querySelectorAll: () => []
};

const sampleSettlements = [
    {
        id: 1,
        payer_name: 'Rahul',
        payee_name: 'Priya',
        amount_cents: 125000,
        settlement_date: '2026-09-22',
        notes: 'Settled via Smart Split (INR) • UTR: UPI-88492019'
    },
    {
        id: 2,
        payer_name: 'Amit',
        payee_name: 'Rahul',
        amount_cents: 60000,
        settlement_date: '2026-09-23',
        notes: 'Settled via Smart Split (INR)'
    }
];

SettlementPlan.render(container, {
    token: 'tok_abc',
    plan: { transactions: [] },
    settlements: sampleSettlements,
    currency: 'INR'
});

assert(container.innerHTML.includes('UTR: UPI-88492019'), 'Must render UTR badge for settlement with UTR');
assert(container.innerHTML.includes('badge-settled'), 'Must use badge-settled badge class');
console.log('✔ Payment Audit History renders UTR badge for settlements containing UTR');

console.log('\n=========================================');
console.log('🎉 ALL UTR TRACKING TESTS PASSED! 🎉');
console.log('=========================================');
