import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Ledger Actions, In-Place Editing & Trash Bin Recovery', () => {
  test('Edits an existing expense in-place and updates ledger', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    ledgerPage,
  }) => {
    // 1. Setup workspace and create an expense
    await createTestWorkspace();
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Original Expense',
      amount: '500',
      payerName: 'Alice',
      categoryName: 'General',
    });
    await expenseModal.selectSplitType('EQUAL');
    await expenseModal.submit();

    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Original Expense');

    // 2. Click In-Place Edit on first row
    await ledgerPage.clickEditExpense(0);
    await expect(expenseModal.modalTitle).toContainText(/Edit Transaction/i);

    // 3. Update Title to "Updated Expense" and Amount to 750
    await expenseModal.titleInput.fill('Updated Expense');
    await expenseModal.amountInput.fill('750');
    await expenseModal.submit();

    // 4. Verify Ledger row reflects updated data
    await expect(ledgerPage.tableRows.first()).toContainText('Updated Expense');
    await expect(ledgerPage.tableRows.first()).toContainText('₹750.00');
  });

  test('Soft-deletes an expense and restores it via Trash Bin', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    ledgerPage,
  }) => {
    await createTestWorkspace();
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Deletable Coffee',
      amount: '300',
      payerName: 'Bob',
      categoryName: 'Food & Dining',
    });
    await expenseModal.selectSplitType('EQUAL');
    await expenseModal.submit();

    await expect(ledgerPage.tableRows).toHaveCount(1);

    // 2. Soft-delete the expense
    await ledgerPage.clickDeleteExpense(0);
    await expect(ledgerPage.tableRows).toHaveCount(0);

    // 3. Open Trash Bin Modal
    await ledgerPage.openTrashBin();
    const trashModal = ledgerPage.modalDialog;
    await expect(trashModal).toContainText('Deletable Coffee');

    // 4. Click Restore button
    const restoreBtn = trashModal.locator('.btn-restore-item').first();
    await restoreBtn.click();
    await ledgerPage.closeModal();

    // 5. Verify expense restored in active ledger
    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Deletable Coffee');
  });
});
