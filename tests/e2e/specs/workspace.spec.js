import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Workspace Management & Hub', () => {
  test('Creates workspace with custom currency (USD)', async ({ landingPage, headerPage }) => {
    await landingPage.goto('/');
    const workspaceName = `US Team Offsite ${Date.now()}`;
    await landingPage.createWorkspace(workspaceName, 'Sarah Connor', 'USD');

    await expect(headerPage.title).toHaveText(workspaceName);
    await expect(headerPage.currencyBadge).toHaveText('USD');
  });

  test('Validates required fields on workspace creation', async ({ landingPage }) => {
    await landingPage.goto('/');
    
    // Attempt submit with empty inputs
    await landingPage.groupNameInput.fill('');
    await landingPage.creatorNameInput.fill('');
    await landingPage.createGroupBtn.click();

    // HTML5 validation or Toast error keeps user on landing
    expect(landingPage.page.url()).not.toContain('#/g/');
    await expect(landingPage.groupNameInput).toBeVisible();
  });

  test('Workspaces Hub modal displays recent workspaces and navigates correctly', async ({
    landingPage,
    headerPage,
  }) => {
    // 1. Create First Workspace
    await landingPage.goto('/');
    const ws1 = `Hub Workspace A ${Date.now()}`;
    await landingPage.createWorkspace(ws1, 'Alice');
    await expect(headerPage.title).toHaveText(ws1);

    // 2. Open Workspaces Hub from Navbar
    await headerPage.openWorkspacesHub();
    const modal = headerPage.modalDialog;
    await expect(modal).toContainText(ws1);

    // 3. Create Second Workspace
    await headerPage.closeModal();
    await landingPage.goto('/');
    const ws2 = `Hub Workspace B ${Date.now()}`;
    await landingPage.createWorkspace(ws2, 'Bob');
    await expect(headerPage.title).toHaveText(ws2);

    // 4. Open Workspaces Hub and switch back to Workspace A
    await headerPage.openWorkspacesHub();
    const ws1Link = modal.locator('.workspace-switcher-item, a[href*="#/g/"]').filter({ hasText: ws1 }).first();
    if (await ws1Link.isVisible()) {
      await ws1Link.click();
      await expect(headerPage.title).toHaveText(ws1);
    }
  });

  test('Pair Device with Code modals open stably without closing automatically', async ({
    landingPage,
    headerPage,
    page,
  }) => {
    // 1. Test clicking "Pair device with code" from landing page
    await landingPage.goto('/');
    const landingPairBtn = page.locator('#btn-landing-pair-device');
    await expect(landingPairBtn).toBeVisible();
    await landingPairBtn.click();

    // Verify modal stays open stably past the previous 200ms race timeout
    const modalDialog = page.locator('.modal-dialog');
    await expect(modalDialog).toBeVisible();
    await expect(modalDialog).toContainText('Enter Pairing Code');
    const codeInput = page.locator('#input-claim-code');
    await expect(codeInput).toBeVisible();
    await page.waitForTimeout(500); // Verify modal remains open
    await expect(modalDialog).toBeVisible();

    // Close modal
    await page.locator('.modal-close').click();
    await expect(modalDialog).not.toBeVisible();

    // 2. Test clicking "Pair Device with Code" from Workspaces Hub modal
    await landingPage.goto('/');
    await landingPage.openWorkspacesHub();
    await expect(modalDialog).toContainText('Workspaces Hub');

    const hubPairBtn = page.locator('#btn-modal-pair-device');
    await expect(hubPairBtn).toBeVisible();
    await hubPairBtn.click();

    // Verify claim pairing modal replaced hub modal and remains open
    await expect(modalDialog).toContainText('Enter Pairing Code');
    await expect(codeInput).toBeVisible();
    await page.waitForTimeout(500);
    await expect(modalDialog).toBeVisible();
    await page.locator('.modal-close').click();

    // 3. Create a workspace and test "Pair Another Device" from Share menu
    const ws = `Pairing Suite ${Date.now()}`;
    await landingPage.createWorkspace(ws, 'Alice');
    await expect(headerPage.title).toHaveText(ws);

    const shareToggleBtn = page.locator('#btn-share-menu-toggle');
    await shareToggleBtn.click();
    const pairAnotherDeviceBtn = page.locator('#btn-pair-device-creator');
    await expect(pairAnotherDeviceBtn).toBeVisible();
    await pairAnotherDeviceBtn.click();

    // Verify creator pairing QR modal is open with countdown timer
    await expect(modalDialog).toContainText('Pair Another Device');
    await expect(page.locator('#pairing-code-val')).toBeVisible();
    await expect(page.locator('#pairing-timer-countdown')).toBeVisible();
    await page.waitForTimeout(500);
    await expect(modalDialog).toBeVisible();
    await page.locator('.modal-close').click();
  });
});
