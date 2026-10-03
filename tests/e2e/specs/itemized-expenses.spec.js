import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Itemized Receipt Splitting', () => {
  test('Creates itemized expense with tax & tip proportional surcharges', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    ledgerPage,
  }) => {
    // 1. Setup workspace with Alice, Bob, Charlie
    await createTestWorkspace();

    // 2. Open Expense Modal
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Italian Bistro Dinner',
      payerName: 'Alice',
      categoryName: 'Food & Dining',
    });

    // 3. Select ITEMIZED tab
    await expenseModal.selectSplitType('ITEMIZED');

    // 4. Fill Item 1: Truffle Pizza for ₹800 (Assigned to Alice & Bob)
    const firstRow = expenseModal.page.locator('#itemized-items-list > div').first();
    await firstRow.locator('input[type="text"]').first().fill('Truffle Pizza');
    await firstRow.locator('input[type="text"]').nth(1).fill('800');

    // Add Item 2: Pasta for ₹400 (Assigned to Charlie)
    await expenseModal.addItemBtn.click();
    const secondRow = expenseModal.page.locator('#itemized-items-list > div').nth(1);
    await secondRow.locator('input[type="text"]').first().fill('Penne Arrabbiata');
    await secondRow.locator('input[type="text"]').nth(1).fill('400');

    // Unselect Alice and Bob for Item 2, leave only Charlie
    const aliceBtnItem2 = secondRow.locator('.item-member-toggle', { hasText: 'Alice' });
    const bobBtnItem2 = secondRow.locator('.item-member-toggle', { hasText: 'Bob' });
    await aliceBtnItem2.click(); // toggle off Alice
    await bobBtnItem2.click();   // toggle off Bob

    // 5. Add Tax ₹120 and Tip ₹80
    await expenseModal.itemizedTaxInput.fill('120');
    await expenseModal.itemizedTipInput.fill('80');

    // 6. Verify Subtotal ₹1,200, Net Total ₹1,400
    await expect(expenseModal.itemizedSubtotal).toHaveText(/₹1,200\.00/);
    await expect(expenseModal.itemizedNetTotal).toHaveText(/₹1,400\.00/);

    // 7. Submit Expense
    await expenseModal.submit();

    // 8. Verify Ledger Row
    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Italian Bistro Dinner');
    await expect(ledgerPage.tableRows.first()).toContainText('₹1,400.00');
  });
});
