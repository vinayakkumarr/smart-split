import { expect } from '@playwright/test';
import { BasePage } from './BasePage.js';

/**
 * Page Object for the Landing Page & Workspaces Hub.
 */
export class LandingPage extends BasePage {
  /**
   * @param {import('@playwright/test').Page} page
   */
  constructor(page) {
    super(page);
    this.groupNameInput = page.locator('#group-name-input');
    this.creatorNameInput = page.locator('#creator-name-input');
    this.currencySelect = page.locator('#currency-select');
    this.createGroupBtn = page.locator('#btn-create-group');
    this.recentWorkspacesSection = page.locator('.landing-recents-directory');
    this.recentWorkspaceRows = page.locator('.landing-recents-row');
  }

  async createWorkspace(groupName, creatorName, currency = 'INR') {
    await this.groupNameInput.fill(groupName);
    await this.creatorNameInput.fill(creatorName);
    if (currency) {
      await this.currencySelect.selectOption(currency);
    }
    await this.createGroupBtn.click();
    await this.page.waitForURL(/#\/g\/[a-zA-Z0-9_-]+/);
    await expect(this.page.locator('.exec-title')).toBeVisible();
  }

  async getRecentWorkspaceNames() {
    if (await this.recentWorkspacesSection.isVisible()) {
      return await this.recentWorkspaceRows.locator('span').first().allTextContents();
    }
    return [];
  }

  async openRecentWorkspace(name) {
    const row = this.recentWorkspaceRows.filter({ hasText: name });
    await row.click();
    await this.page.waitForURL(/#\/g\/[a-zA-Z0-9_-]+/);
  }
}
