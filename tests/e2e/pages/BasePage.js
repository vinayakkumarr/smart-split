import { expect } from '@playwright/test';

/**
 * Base Page Object encapsulating universal layout elements, navigation, theme, toasts, and modals.
 */
export class BasePage {
  /**
   * @param {import('@playwright/test').Page} page
   */
  constructor(page) {
    this.page = page;
    this.navbarLogo = page.locator('.ss-logo-desktop, .ss-logo-mobile');
    this.themeToggleBtn = page.locator('#btn-navbar-theme');
    this.workspacesBtn = page.locator('#btn-navbar-workspaces');
    this.currencyBadge = page.locator('#navbar-currency-badge');
    this.modalOverlay = page.locator('#modal-overlay.active');
    this.modalDialog = page.locator('.modal-dialog');
    this.modalContainer = page.locator('.modal-container');
    this.modalTitle = page.locator('.modal-title');
    this.modalConfirmBtn = page.locator('.modal-btn-confirm');
    this.modalCancelBtn = page.locator('.modal-btn-cancel');
    this.modalCloseBtn = page.locator('.modal-close');
    this.toastContainer = page.locator('#toast-container');
  }

  async goto(path = '/') {
    await this.page.goto(path);
    await this.page.waitForLoadState('domcontentloaded');
  }

  async getTheme() {
    return await this.page.evaluate(() => document.documentElement.getAttribute('data-theme'));
  }

  async toggleTheme() {
    await this.themeToggleBtn.click();
  }

  async openWorkspacesHub() {
    await this.workspacesBtn.click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Workspaces/i);
  }

  async waitForToast(textPattern, options = { timeout: 7000 }) {
    const toast = this.page.locator('.toast', { hasText: textPattern });
    await expect(toast).toBeVisible(options);
    return toast;
  }

  async closeModal() {
    if (await this.modalCloseBtn.isVisible()) {
      await this.modalCloseBtn.click();
    } else if (await this.modalCancelBtn.isVisible()) {
      await this.modalCancelBtn.click();
    } else {
      await this.page.keyboard.press('Escape');
    }
    await expect(this.modalDialog).not.toBeVisible();
  }

  async confirmModal() {
    await this.modalConfirmBtn.click();
  }
}
