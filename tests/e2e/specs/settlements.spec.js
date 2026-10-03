import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Debt Settlement & Visual Flow Diagram', () => {
  test('Toggles between Card View and SVG Flow Diagram View', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    settlementSection,
  }) => {
    // 1. Setup workspace with expenses generating debt
    await createTestWorkspace();
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Resort Stay',
      amount: '9000',
      payerName: 'Alice',
      categoryName: 'Housing & Rent',
    });
    await expenseModal.selectSplitType('EQUAL');
    await expenseModal.submit();

    // 2. Default Card View is visible
    await expect(settlementSection.cardsContainer).toBeVisible();
    await expect(settlementSection.settlementCards).toHaveCount(2);

    // 3. Toggle to SVG Flow Diagram View
    await settlementSection.switchToDiagramView();
    await expect(settlementSection.diagramContainer).toBeVisible();
    await expect(settlementSection.diagramContainer.locator('svg.debt-flow-svg')).toBeVisible();

    // 4. Toggle back to Card View
    await settlementSection.switchToCardView();
    await expect(settlementSection.cardsContainer).toBeVisible();
  });

  test('Records payment with dynamic QR code and tests settlement undo', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    settlementSection,
  }) => {
    await createTestWorkspace();
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Buffet Lunch',
      amount: '3000',
      payerName: 'Bob',
      categoryName: 'Food & Dining',
    });
    await expenseModal.selectSplitType('EQUAL');
    await expenseModal.submit();

    // 1. Open UPI Settlement Modal
    await settlementSection.openSettleModal(0);
    await settlementSection.assertUpiUnset();
    await settlementSection.enterPayeeUpi('bob.dining@okaxis');
    await settlementSection.assertUpiConfigured();

    // 2. Record payment with custom UTR reference
    await settlementSection.recordPayment({ utr: 'UPI-REF-998877' });

    // 3. Verify Payment Audit History shows recorded settlement
    const auditSection = settlementSection.panel.locator('text=Payment Audit History');
    await expect(auditSection).toBeVisible();
    await expect(settlementSection.panel).toContainText('UPI-REF-998877');

    // 4. Undo the recorded settlement
    await settlementSection.undoSettlement(0);

    // 5. Debt returns to transfer list
    await expect(settlementSection.settlementCards).toHaveCount(2);
  });
});
