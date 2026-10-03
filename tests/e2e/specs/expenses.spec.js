import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Expense Logging & Split Methodologies', () => {
  test('Logs Exact split expense with live validation', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    ledgerPage,
  }) => {
    // 1. Create test workspace with Alice, Bob, Charlie
    await createTestWorkspace();

    // 2. Open Expense Modal
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Groceries Exact',
      amount: '1500',
      payerName: 'Alice',
      categoryName: 'Groceries',
    });

    // 3. Select EXACT Split
    await expenseModal.selectSplitType('EXACT');

    // Fill exact amounts: Alice ₹500, Bob ₹600, Charlie ₹400 = ₹1500
    const customInputs = expenseModal.page.locator('.split-custom-input');
    await customInputs.nth(0).fill('500');
    await customInputs.nth(1).fill('600');
    await customInputs.nth(2).fill('400');

    // 4. Verify Validation Bar shows valid
    await expect(expenseModal.validationBar).toHaveClass(/valid/);
    await expect(expenseModal.whatIfContainer).toBeVisible();

    // 5. Submit Expense
    await expenseModal.submit();

    // 6. Verify Ledger entry
    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Groceries Exact');
    await expect(ledgerPage.tableRows.first()).toContainText('₹1,500.00');
  });

  test('Logs Percentage split expense (50% / 30% / 20%)', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    ledgerPage,
  }) => {
    await createTestWorkspace();

    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Conference Pass',
      amount: '10000',
      payerName: 'Bob',
      categoryName: 'General',
    });

    await expenseModal.selectSplitType('PERCENTAGE');

    // Fill percentages summing to 100
    const customInputs = expenseModal.page.locator('.split-custom-input');
    await customInputs.nth(0).fill('50');
    await customInputs.nth(1).fill('30');
    await customInputs.nth(2).fill('20');

    await expect(expenseModal.validationBar).toHaveClass(/valid/);
    await expenseModal.submit();

    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Conference Pass');
  });

  test('Logs Shares split expense (1 share vs 2 shares vs 3 shares)', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    ledgerPage,
  }) => {
    await createTestWorkspace();

    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Server Hosting',
      amount: '6000',
      payerName: 'Charlie',
      categoryName: 'Utilities & Bills',
    });

    await expenseModal.selectSplitType('SHARES');

    const customInputs = expenseModal.page.locator('.split-custom-input');
    await customInputs.nth(0).fill('1');
    await customInputs.nth(1).fill('2');
    await customInputs.nth(2).fill('3');

    await expect(expenseModal.validationBar).toHaveClass(/valid/);
    await expenseModal.submit();

    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Server Hosting');
  });

  test('Logs Multi-Payer expense split across 2 contributors', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    ledgerPage,
  }) => {
    await createTestWorkspace();

    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Group Flight Tickets',
      amount: '12000',
      categoryName: 'Travel & Transport',
    });

    // Toggle multi-payer mode
    await expenseModal.toggleMultiPayer();
    const payerInputs = expenseModal.page.locator('.multi-payer-input');
    // Alice pays 7000, Bob pays 5000, Charlie pays 0
    await payerInputs.nth(0).fill('7000');
    await payerInputs.nth(1).fill('5000');
    await payerInputs.nth(2).fill('0');

    await expenseModal.selectSplitType('EQUAL');
    await expect(expenseModal.validationBar).toHaveClass(/valid/);
    await expenseModal.submit();

    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Group Flight Tickets');
    await expect(ledgerPage.tableRows.first()).toContainText('2 Payers');
  });
});
