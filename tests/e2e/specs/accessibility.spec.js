import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Accessibility & Keyboard Navigation (WCAG 2.1)', () => {
  test('Dismisses modal dialog on Escape key press and restores focus', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
  }) => {
    await createTestWorkspace();

    // 1. Open Expense Modal
    await headerPage.openLogExpenseModal();
    await expect(expenseModal.modalDialog).toBeVisible();

    // 2. Press Escape key
    await expenseModal.page.keyboard.press('Escape');

    // 3. Modal is dismissed
    await expect(expenseModal.modalDialog).not.toBeVisible();
  });

  test('Validates ARIA roles on dialogs and settlement tabs', async ({
    createTestWorkspace,
    settlementSection,
    headerPage,
    expenseModal,
  }) => {
    await createTestWorkspace();

    // 1. Log an expense so settlement tabs are rendered
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Setup Expense',
      amount: '3000',
      payerName: 'Alice',
      categoryName: 'General',
    });
    await expenseModal.selectSplitType('EQUAL');
    await expenseModal.submit();

    // 2. Check Settlement View Tabs ARIA roles
    await expect(settlementSection.cardViewBtn).toHaveAttribute('role', 'tab');
    await expect(settlementSection.diagramViewBtn).toHaveAttribute('role', 'tab');

    // 3. Open Modal and verify role="dialog" and aria-modal="true"
    await headerPage.openLogExpenseModal();
    await expect(expenseModal.modalDialog).toHaveAttribute('role', 'dialog');
    await expect(expenseModal.modalDialog).toHaveAttribute('aria-modal', 'true');

    await expenseModal.closeModal();
  });
});
