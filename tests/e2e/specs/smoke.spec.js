import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Core E2E Smoke Journey', () => {
  test('Complete user lifecycle from landing to settled debt', async ({
    landingPage,
    headerPage,
    memberSection,
    expenseModal,
    ledgerPage,
    settlementSection,
  }) => {
    // 1. Visit Landing Page
    await landingPage.goto('/');
    await expect(landingPage.groupNameInput).toBeVisible();

    // 2. Create Workspace "Goa Retreat" with Organizer "Rahul"
    const workspaceName = `Goa Retreat ${Date.now()}`;
    await landingPage.createWorkspace(workspaceName, 'Rahul', 'INR');

    // 3. Verify Workspace Header
    await expect(headerPage.title).toHaveText(workspaceName);
    await expect(headerPage.currencyBadge).toHaveText('INR');

    // 4. Add Member "Priya" and Member "Amit"
    await memberSection.addMember('Priya', '🌸');
    await memberSection.addMember('Amit', '⚡');

    const memberNames = await memberSection.getMemberNames();
    expect(memberNames).toContain('Rahul');
    expect(memberNames).toContain('Priya');
    expect(memberNames).toContain('Amit');

    // 5. Log Equal Expense "Villa Stay" for ₹3000 paid by Rahul
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Villa Stay',
      amount: '3000',
      payerName: 'Rahul',
      categoryName: 'Housing & Rent',
      notes: 'Beachside Villa booking',
    });
    await expenseModal.selectSplitType('EQUAL');
    await expect(expenseModal.validationBar).toHaveClass(/valid/);
    await expenseModal.submit();

    // 6. Verify Ledger Row
    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Villa Stay');
    await expect(ledgerPage.tableRows.first()).toContainText('₹3,000.00');

    // 7. Verify Settlement Router generates 2 transfers (Priya owes Rahul ₹1000, Amit owes Rahul ₹1000)
    await expect(settlementSection.cardsContainer).toBeVisible();
    const transfersCount = await settlementSection.getTransfersCount();
    expect(transfersCount).toBe(2);

    // 8. Settle first transfer to Rahul (₹1000)
    await settlementSection.openSettleModal(0);
    // Verify initial unset UPI state
    await settlementSection.assertUpiUnset();
    // Configure Rahul's UPI ID dynamically in settlement modal
    await settlementSection.enterPayeeUpi('rahul.retreat@okaxis');
    // Verify live QR generation and configured state
    await settlementSection.assertUpiConfigured();
    await settlementSection.recordPayment({ recordedByName: 'Rahul' });

    // 9. Verify Transfer count reduces to 1
    await expect(settlementSection.settlementCards).toHaveCount(1);

    // 10. Settle remaining debt to Rahul
    await settlementSection.openSettleModal(0);
    await settlementSection.recordPayment({ recordedByName: 'Rahul' });

    // 11. Verify Workspace is All Settled (Zero Debt)
    await expect(settlementSection.panel).toContainText(/All accounts settled|Zero Debt/i);
    await expect(headerPage.settlementStatusValue).toContainText(/All Settled/i);
  });
});
