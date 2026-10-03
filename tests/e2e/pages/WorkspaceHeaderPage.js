import { expect } from '@playwright/test';
import { BasePage } from './BasePage.js';

/**
 * Page Object for Workspace Header, Top Bar, Metrics & Budget Target.
 */
export class WorkspaceHeaderPage extends BasePage {
  /**
   * @param {import('@playwright/test').Page} page
   */
  constructor(page) {
    super(page);
    this.title = page.locator('.exec-title');
    this.currencyBadge = page.locator('.exec-title-row .badge-settled');
    this.metaInfo = page.locator('.exec-header-meta');
    this.totalSpendValue = page.locator('.exec-stat-box').nth(0).locator('.exec-stat-value');
    this.settlementStatusValue = page.locator('.exec-stat-box').nth(2).locator('.exec-stat-value');
    this.logExpenseHeaderBtn = page.locator('#btn-header-add-expense');
    this.shareMenuToggleBtn = page.locator('#btn-share-menu-toggle');
    this.copyInviteBtn = page.locator('#btn-copy-invite');
    this.whatsappInviteBtn = page.locator('#btn-whatsapp-invite');
    this.setBudgetBtn = page.locator('#btn-set-budget');
    this.editBudgetBtn = page.locator('#btn-edit-budget');
    this.budgetProgressTrack = page.locator('.budget-progress-track');
    this.claimingBanner = page.locator('#workspace-claiming-banner');
    this.claimMemberSelect = page.locator('#select-claim-member');
    this.claimMemberBtn = page.locator('#btn-claim-member');
    this.claimedIdentityPill = page.locator('#claimed-identity-pill');
    this.unlinkProfileBtn = page.locator('#btn-unlink-my-profile');
  }

  async getTitleText() {
    return await this.title.innerText();
  }

  async getTotalSpendText() {
    return await this.totalSpendValue.innerText();
  }

  async getSettlementStatusText() {
    return await this.settlementStatusValue.innerText();
  }

  async openLogExpenseModal() {
    await this.logExpenseHeaderBtn.click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Transaction/i);
  }

  async setBudget(amount) {
    if (await this.setBudgetBtn.isVisible()) {
      await this.setBudgetBtn.click();
    } else if (await this.editBudgetBtn.isVisible()) {
      await this.editBudgetBtn.click();
    }
    await expect(this.modalDialog).toBeVisible();
    const input = this.page.locator('#input-budget-limit');
    await input.fill(String(amount));
    await this.confirmModal();
  }
}
