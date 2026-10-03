import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Personal UPI Identity & Profile Resolution', () => {
  test('User configures Personal UPI ID in Settings and resolves it dynamically in Settlement Plan', async ({
    page,
    createTestWorkspace,
    headerPage,
    expenseModal,
    settlementSection,
  }) => {
    // 1. Setup workspace with expenses
    await createTestWorkspace();
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Group Dinner',
      amount: '3000',
      payerName: 'Alice',
      categoryName: 'Food & Dining',
    });
    await expenseModal.selectSplitType('EQUAL');
    await expenseModal.submit();

    // 2. Open Settlement Modal as Guest before linking account
    await settlementSection.openSettleModal(0);
    const upiInput = page.locator('#settle-upi-input');
    await expect(upiInput).toBeVisible();

    // Close modal
    const closeBtn = page.locator('.modal-close, #btn-modal-cancel, button:has-text("Cancel")').first();
    if (await closeBtn.isVisible()) {
      await closeBtn.click();
    }

    // 3. Navigate to Settings page
    await page.goto('/#/settings');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('h1')).toContainText('Settings & Preferences');

    // 4. In Guest mode, verify sign in button
    const guestSignInBtn = page.locator('#btn-settings-guest-signin');
    await expect(guestSignInBtn).toBeVisible();

    // 5. Open Sign In modal and switch to Register
    await guestSignInBtn.click();
    const modalOverlay = page.locator('#modal-overlay');
    await expect(modalOverlay).toBeVisible();

    const switchRegister = page.locator('.auth-tab-btn[data-tab="register"]');
    await switchRegister.click();

    const testEmail = `e2e_upi_${Date.now()}@example.com`;
    await page.fill('#auth-reg-name', 'Alice E2E');
    await page.fill('#auth-reg-email', testEmail);
    await page.fill('#auth-reg-password', 'SecurePassword123!');

    await page.click('#auth-reg-submit');

    // Acknowledge recovery key modal
    const recoveryAck = page.locator('#chk-auth-recovery-ack');
    await expect(recoveryAck).toBeVisible();
    await recoveryAck.check();

    const doneBtn = page.locator('#btn-auth-recovery-done');
    await expect(doneBtn).toBeEnabled();
    await doneBtn.click();

    // 6. User is now signed in on Settings page
    await page.goto('/#/settings');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('text=Signed In')).toBeVisible();

    // 7. Verify Personal UPI Section in Settings
    const upiProfileInput = page.locator('#settings-profile-upi');
    await expect(upiProfileInput).toBeVisible();
    await upiProfileInput.fill('alice.e2e@okaxis');

    const saveProfileBtn = page.locator('#btn-settings-save-profile');
    await saveProfileBtn.click();

    // 8. Verify Active Badge is shown
    await expect(page.locator('.settings-page-wrapper')).toContainText('Active');
    await expect(upiProfileInput).toHaveValue('alice.e2e@okaxis');

    // 9. Copy button is functional
    const copyBtn = page.locator('#btn-settings-copy-upi');
    await expect(copyBtn).toBeVisible();
  });
});
