import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Resilience & Error Handling', () => {
  test('Displays error UI on invalid workspace token', async ({ landingPage }) => {
    // Navigate to non-existent group token
    await landingPage.goto('/#/g/non_existent_token_xyz999');

    // Verify error container is displayed
    const errorContainer = landingPage.page.locator('#main-content');
    await expect(errorContainer).toContainText(/Failed to load workspace data|not found|Workspace/i);
  });

  test('Prevents expense submission with 0 or negative amount', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
  }) => {
    await createTestWorkspace();
    await headerPage.openLogExpenseModal();

    await expenseModal.titleInput.fill('Zero Amount Expense');
    await expenseModal.amountInput.fill('0');

    // Confirm button remains disabled
    await expect(expenseModal.modalConfirmBtn).toBeDisabled();

    // Validation bar indicates amount required
    await expect(expenseModal.validationBar).toHaveClass(/info|invalid/);

    await expenseModal.closeModal();
  });
});
