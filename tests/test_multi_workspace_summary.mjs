import assert from 'node:assert';
import { LandingView } from '../public/assets/js/components/LandingView.js';
import * as Formatters from '../public/assets/js/utils/formatters.js';

console.log('🧪 Testing Multi-Workspace Consolidated Net Financial Summary...\n');

// -------------------------------------------------------------
// Test 1: LandingView.calculateConsolidatedSummary - Multi-Workspace Aggregations
// -------------------------------------------------------------
{
    const results = [
        {
            workspace: { token: 'ws-a', name: 'Goa Trip', currency: 'INR' },
            status: 'fulfilled',
            data: {
                group: { id: 1, name: 'Goa Trip', currency_code: 'INR' },
                members: [
                    { member_id: 101, name: 'Rahul', net_balance_cents: 140000, status: 'CREDITOR' },
                    { member_id: 102, name: 'Priya', net_balance_cents: -140000, status: 'DEBTOR' },
                ]
            }
        },
        {
            workspace: { token: 'ws-b', name: 'Apartment 402', currency: 'INR' },
            status: 'fulfilled',
            data: {
                group: { id: 2, name: 'Apartment 402', currency_code: 'INR' },
                members: [
                    { member_id: 201, name: 'Rahul', net_balance_cents: -50000, status: 'DEBTOR' },
                    { member_id: 202, name: 'Amit', net_balance_cents: 50000, status: 'CREDITOR' },
                ]
            }
        },
        {
            workspace: { token: 'ws-c', name: 'Weekend Dinner', currency: 'INR' },
            status: 'fulfilled',
            data: {
                group: { id: 3, name: 'Weekend Dinner', currency_code: 'INR' },
                members: [
                    { member_id: 301, name: 'Rahul', net_balance_cents: 60000, status: 'CREDITOR' },
                    { member_id: 302, name: 'Sneha', net_balance_cents: -60000, status: 'DEBTOR' },
                ]
            }
        },
        {
            workspace: { token: 'ws-d', name: 'Old Settled Trip', currency: 'INR' },
            status: 'fulfilled',
            data: {
                group: { id: 4, name: 'Old Settled Trip', currency_code: 'INR' },
                members: [
                    { member_id: 401, name: 'Rahul', net_balance_cents: 0, status: 'SETTLED' },
                    { member_id: 402, name: 'Sneha', net_balance_cents: 0, status: 'SETTLED' },
                ]
            }
        }
    ];

    const summary = LandingView.calculateConsolidatedSummary(results);

    // Total: +140000 - 50000 + 60000 + 0 = +150000 (+₹1,500.00)
    assert.strictEqual(summary.totalNetCents, 150000, 'Consolidated net total should be +150000 paise (+₹1,500.00)');
    assert.strictEqual(summary.activeCreditCount, 2, '2 workspaces with active credit (ws-a, ws-c)');
    assert.strictEqual(summary.activeDebtCount, 1, '1 workspace with active debt (ws-b)');
    assert.strictEqual(summary.settledCount, 1, '1 workspace settled (ws-d)');
    assert.strictEqual(summary.successfulCount, 4, '4 successful responses');

    // Individual Badges
    assert.strictEqual(summary.workspaceSummaries[0].badgeType, 'credit');
    assert.strictEqual(summary.workspaceSummaries[0].badgeText, '+₹1,400.00');

    assert.strictEqual(summary.workspaceSummaries[1].badgeType, 'debt');
    assert.strictEqual(summary.workspaceSummaries[1].badgeText, '-₹500.00');

    assert.strictEqual(summary.workspaceSummaries[2].badgeType, 'credit');
    assert.strictEqual(summary.workspaceSummaries[2].badgeText, '+₹600.00');

    assert.strictEqual(summary.workspaceSummaries[3].badgeType, 'settled');
    assert.strictEqual(summary.workspaceSummaries[3].badgeText, 'Settled');

    console.log('✅ Test 1 Passed: Multi-workspace algebraic sum, credit/debt counts, and badge formats verified.');
}

// -------------------------------------------------------------
// Test 2: LandingView.calculateConsolidatedSummary - Graceful Partial & Full Failure Handling
// -------------------------------------------------------------
{
    // Partial Failure: 1 succeeded, 1 failed
    const partialResults = [
        {
            workspace: { token: 'ws-ok', name: 'Active Group', currency: 'INR' },
            status: 'fulfilled',
            data: {
                members: [{ member_id: 1, name: 'Alice', net_balance_cents: 20000 }]
            }
        },
        {
            workspace: { token: 'ws-fail', name: 'Deleted Group', currency: 'INR' },
            status: 'rejected',
            error: new Error('Group not found')
        }
    ];

    const partialSummary = LandingView.calculateConsolidatedSummary(partialResults);
    assert.strictEqual(partialSummary.successfulCount, 1);
    assert.strictEqual(partialSummary.totalNetCents, 20000);
    assert.strictEqual(partialSummary.activeCreditCount, 1);
    assert.strictEqual(partialSummary.activeDebtCount, 0);
    assert.strictEqual(partialSummary.workspaceSummaries[0].isAvailable, true);
    assert.strictEqual(partialSummary.workspaceSummaries[1].isAvailable, false);
    assert.strictEqual(partialSummary.workspaceSummaries[1].badgeText, 'Unavailable');

    // Full Failure: all failed
    const fullFailResults = [
        {
            workspace: { token: 'ws-1', name: 'Offline Group 1', currency: 'INR' },
            status: 'rejected',
            error: new Error('Network offline')
        },
        {
            workspace: { token: 'ws-2', name: 'Offline Group 2', currency: 'INR' },
            status: 'rejected',
            error: new Error('Network offline')
        }
    ];

    const fullFailSummary = LandingView.calculateConsolidatedSummary(fullFailResults);
    assert.strictEqual(fullFailSummary.successfulCount, 0);
    assert.strictEqual(fullFailSummary.activeCreditCount, 0);
    assert.strictEqual(fullFailSummary.activeDebtCount, 0);
    assert.strictEqual(fullFailSummary.workspaceSummaries[0].badgeText, 'Unavailable');
    assert.strictEqual(fullFailSummary.workspaceSummaries[1].badgeText, 'Unavailable');

    console.log('✅ Test 2 Passed: Partial and full network failures handled gracefully without poisoning totals.');
}

console.log('\n🎉 All Multi-Workspace Consolidated Summary tests passed successfully!');
