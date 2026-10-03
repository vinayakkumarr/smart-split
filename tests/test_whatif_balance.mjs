import assert from 'node:assert';
import { ExpenseModal } from '../public/assets/js/components/ExpenseModal.js';
import * as MathUtils from '../public/assets/js/utils/math.js';
import * as Formatters from '../public/assets/js/utils/formatters.js';

console.log('🧪 Testing ExpenseModal What-If Balance Impact Feature...\n');

// -------------------------------------------------------------
// Test 1: ExpenseModal.calculateProjectedBalances - New Expense (Single Payer)
// -------------------------------------------------------------
{
    const members = [
        { id: 1, name: 'Alice' },
        { id: 2, name: 'Bob' },
        { id: 3, name: 'Charlie' },
        { id: 4, name: 'David' }
    ];

    const currentBalances = [
        { member_id: 1, net_balance_cents: 20000 },  // +₹200.00
        { member_id: 2, net_balance_cents: -10000 }, // -₹100.00
        { member_id: 3, net_balance_cents: 0 },      // ₹0.00
        { member_id: 4, net_balance_cents: 5000 }    // +₹50.00
    ];

    // Alice pays ₹300.00 (30000 cents), split equally among Alice, Bob, Charlie (10000 cents each)
    const paidByMap = { 1: 30000 };
    const calculatedSplits = { 1: 10000, 2: 10000, 3: 10000 };

    const projections = ExpenseModal.calculateProjectedBalances({
        members,
        currentBalances,
        calculatedSplits,
        paidByMap,
        expenseToEdit: null
    });

    assert.strictEqual(projections.length, 4);

    const alice = projections.find(p => p.memberId === 1);
    assert.strictEqual(alice.currentNetCents, 20000);
    assert.strictEqual(alice.deltaCents, 20000); // paid 30000 - owed 10000 = +20000
    assert.strictEqual(alice.projectedNetCents, 40000); // 20000 + 20000 = +40000
    assert.strictEqual(alice.isParticipating, true);

    const bob = projections.find(p => p.memberId === 2);
    assert.strictEqual(bob.currentNetCents, -10000);
    assert.strictEqual(bob.deltaCents, -10000); // paid 0 - owed 10000 = -10000
    assert.strictEqual(bob.projectedNetCents, -20000); // -10000 + (-10000) = -20000
    assert.strictEqual(bob.isParticipating, true);

    const charlie = projections.find(p => p.memberId === 3);
    assert.strictEqual(charlie.currentNetCents, 0);
    assert.strictEqual(charlie.deltaCents, -10000); // paid 0 - owed 10000 = -10000
    assert.strictEqual(charlie.projectedNetCents, -10000);
    assert.strictEqual(charlie.isParticipating, true);

    const david = projections.find(p => p.memberId === 4);
    assert.strictEqual(david.currentNetCents, 5000);
    assert.strictEqual(david.deltaCents, 0); // neither paid nor owes
    assert.strictEqual(david.projectedNetCents, 5000);
    assert.strictEqual(david.isParticipating, false);

    console.log('✅ Test 1 Passed: Single Payer What-If calculations correct.');
}

// -------------------------------------------------------------
// Test 2: ExpenseModal.calculateProjectedBalances - Multi-Payer Split
// -------------------------------------------------------------
{
    const members = [
        { id: 1, name: 'Alice' },
        { id: 2, name: 'Bob' },
        { id: 3, name: 'Charlie' }
    ];

    const currentBalances = [
        { member_id: 1, net_balance_cents: -5000 },
        { member_id: 2, net_balance_cents: 10000 },
        { member_id: 3, net_balance_cents: -5000 }
    ];

    // Total expense ₹1,000.00 (100000 cents). Alice pays 60000, Bob pays 40000.
    // Splits: Alice: 30000, Bob: 30000, Charlie: 40000.
    const paidByMap = { 1: 60000, 2: 40000 };
    const calculatedSplits = { 1: 30000, 2: 30000, 3: 40000 };

    const projections = ExpenseModal.calculateProjectedBalances({
        members,
        currentBalances,
        calculatedSplits,
        paidByMap,
        expenseToEdit: null
    });

    const alice = projections.find(p => p.memberId === 1);
    assert.strictEqual(alice.deltaCents, 30000); // 60000 - 30000
    assert.strictEqual(alice.projectedNetCents, 25000); // -5000 + 30000
    assert.strictEqual(alice.isParticipating, true);

    const bob = projections.find(p => p.memberId === 2);
    assert.strictEqual(bob.deltaCents, 10000); // 40000 - 30000
    assert.strictEqual(bob.projectedNetCents, 20000); // 10000 + 10000
    assert.strictEqual(bob.isParticipating, true);

    const charlie = projections.find(p => p.memberId === 3);
    assert.strictEqual(charlie.deltaCents, -40000); // 0 - 40000
    assert.strictEqual(charlie.projectedNetCents, -45000); // -5000 + (-40000)
    assert.strictEqual(charlie.isParticipating, true);

    console.log('✅ Test 2 Passed: Multi-Payer What-If calculations correct.');
}

// -------------------------------------------------------------
// Test 3: ExpenseModal.calculateProjectedBalances - Edit Expense Delta
// -------------------------------------------------------------
{
    const members = [
        { id: 1, name: 'Alice' },
        { id: 2, name: 'Bob' }
    ];

    // Current balances reflect the old transaction already recorded
    const currentBalances = [
        { member_id: 1, net_balance_cents: 5000 },  // Alice is +50
        { member_id: 2, net_balance_cents: -5000 }  // Bob is -50
    ];

    // Previously: Alice paid 10000 (100.00), split 5000 / 5000. Old delta: Alice +5000, Bob -5000.
    const expenseToEdit = {
        id: 99,
        payers: [{ member_id: 1, amount_paid_cents: 10000 }],
        splits: [
            { member_id: 1, amount_owed_cents: 5000 },
            { member_id: 2, amount_owed_cents: 5000 }
        ]
    };

    // User is updating: Amount increases to 20000 (200.00). Alice pays 20000, split 10000 / 10000.
    // New delta before edit diff: Alice +10000, Bob -10000.
    // Net adjustment delta: Alice +10000 - (+5000) = +5000; Bob -10000 - (-5000) = -5000.
    // Projected net: Alice 5000 + 5000 = +10000; Bob -5000 + (-5000) = -10000.
    const paidByMap = { 1: 20000 };
    const calculatedSplits = { 1: 10000, 2: 10000 };

    const projections = ExpenseModal.calculateProjectedBalances({
        members,
        currentBalances,
        calculatedSplits,
        paidByMap,
        expenseToEdit
    });

    const alice = projections.find(p => p.memberId === 1);
    assert.strictEqual(alice.deltaCents, 5000);
    assert.strictEqual(alice.projectedNetCents, 10000);

    const bob = projections.find(p => p.memberId === 2);
    assert.strictEqual(bob.deltaCents, -5000);
    assert.strictEqual(bob.projectedNetCents, -10000);

    console.log('✅ Test 3 Passed: Edit Mode What-If delta adjustments correct.');
}

// -------------------------------------------------------------
// Test 4: ExpenseModal.renderProjectedChips - HTML & Color Tokens
// -------------------------------------------------------------
{
    const projections = [
        {
            memberId: 1,
            name: 'Sneha',
            currentNetCents: -40000,
            deltaCents: -75000,
            projectedNetCents: -115000,
            isParticipating: true
        },
        {
            memberId: 2,
            name: 'Vikram',
            currentNetCents: 50000,
            deltaCents: 75000,
            projectedNetCents: 125000,
            isParticipating: true
        },
        {
            memberId: 3,
            name: 'Rahul',
            currentNetCents: 0,
            deltaCents: 0,
            projectedNetCents: 0,
            isParticipating: false
        }
    ];

    const html = ExpenseModal.renderProjectedChips(projections, 'INR');

    // Should contain chips for Sneha and Vikram, but NOT Rahul (not participating)
    assert.ok(html.includes('Sneha:'));
    assert.ok(html.includes('Vikram:'));
    assert.ok(!html.includes('Rahul:'));

    // Should contain arrow symbol
    assert.ok(html.includes('➔'));

    // Check color variable usages
    assert.ok(html.includes('var(--financial-debt)'));
    assert.ok(html.includes('var(--financial-credit)'));

    // Check formatted values
    assert.ok(html.includes('-₹400.00'));
    assert.ok(html.includes('-₹1,150.00'));
    assert.ok(html.includes('+₹500.00'));
    assert.ok(html.includes('+₹1,250.00'));

    console.log('✅ Test 4 Passed: Chips rendering and design system color tokens correct.');
}

console.log('\n🎉 All ExpenseModal What-If Balance tests passed successfully!');
