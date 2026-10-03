import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Responsive Mobile Viewport & Usability', () => {
  test('Renders responsive mobile navigation and modal workflows', async ({
    landingPage,
    headerPage,
    memberSection,
    expenseModal,
  }) => {
    // 1. Visit landing in mobile viewport
    await landingPage.goto('/');

    // 2. Mobile Logo is visible
    const mobileLogo = landingPage.page.locator('.ss-logo-mobile');
    await expect(mobileLogo).toBeVisible();

    // 3. Create Workspace on Mobile
    const wsName = `Mobile Trip ${Date.now()}`;
    await landingPage.createWorkspace(wsName, 'Manoj', 'INR');
    await expect(headerPage.title).toBeVisible();

    // 4. Add Member on Mobile
    await memberSection.addMember('Deepa');
    await expect(memberSection.memberChips.filter({ hasText: 'Deepa' })).toBeVisible();

    // 5. Open and verify Expense Modal fits viewport without horizontal overflow
    await headerPage.openLogExpenseModal();
    const modalDialog = expenseModal.modalDialog;
    await expect(modalDialog).toBeVisible();

    const boundingBox = await modalDialog.boundingBox();
    const viewportSize = landingPage.page.viewportSize();
    if (boundingBox && viewportSize) {
      expect(boundingBox.width).toBeLessThanOrEqual(viewportSize.width);
    }

    await expenseModal.closeModal();
  });
});
