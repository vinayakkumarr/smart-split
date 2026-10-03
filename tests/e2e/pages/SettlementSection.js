import { expect } from '@playwright/test';
import { BasePage } from './BasePage.js';

/**
 * Page Object for Settlement Router, Min-Cash-Flow Transfers, SVG Flow Diagram, and Payment Recording.
 */
export class SettlementSection extends BasePage {
  /**
   * @param {import('@playwright/test').Page} page
   */
  constructor(page) {
    super(page);
    this.panel = page.locator('.panel').filter({ hasText: /Settlement Router/i });
    this.statusBadge = this.panel.locator('.badge');
    this.cardViewBtn = page.locator('#tab-settlement-cards');
    this.diagramViewBtn = page.locator('#tab-settlement-diagram');
    this.cardsContainer = page.locator('#settlement-cards-view');
    this.diagramContainer = page.locator('#settlement-diagram-view');
    this.settlementCards = page.locator('.settlement-card');
    this.settlePayBtns = page.locator('.mark-paid-btn');
    this.shareWhatsAppBtns = page.locator('.btn-share-settlement');
    this.undoBtns = page.locator('.btn-undo-settlement');
    this.qrBox = page.locator('#settle-qr-code-mount');
    this.settleAmountInput = page.locator('#settle-amount-input');
    this.settleUtrInput = page.locator('#settle-utr-input');
  }

  async getTransfersCount() {
    if (await this.cardsContainer.isVisible()) {
      return await this.settlementCards.count();
    }
    return 0;
  }

  async isAllSettled() {
    const text = await this.statusBadge.innerText();
    return /Zero Debt|All Settled/i.test(text);
  }

  async switchToDiagramView() {
    await this.diagramViewBtn.click();
    await expect(this.diagramContainer).toBeVisible();
    await expect(this.cardsContainer).not.toBeVisible();
  }

  async switchToCardView() {
    await this.cardViewBtn.click();
    await expect(this.cardsContainer).toBeVisible();
    await expect(this.diagramContainer).not.toBeVisible();
  }

  async openSettleModal(index = 0) {
    await this.settlePayBtns.nth(index).click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Record Transfer|Settle Debt/i);
  }

  async assertUpiUnset() {
    await expect(this.page.locator('#settle-upi-unset-notice')).toBeVisible();
    await expect(this.page.locator('#settle-upi-deeplink')).toHaveClass(/disabled/);
  }

  async assertUpiConfigured() {
    await expect(this.qrBox).toBeVisible();
    await expect(this.page.locator('#settle-upi-unset-notice')).not.toBeVisible();
    await expect(this.page.locator('#settle-upi-deeplink')).not.toHaveClass(/disabled/);
  }

  async enterPayeeUpi(upiId) {
    const upiInput = this.page.locator('#settle-upi-input');
    await upiInput.fill(upiId);
    await expect(this.qrBox).toBeVisible();
  }

  async recordPayment({ amount = null, utr = null, recordedByName = null } = {}) {
    if (amount !== null) {
      await this.settleAmountInput.fill(String(amount));
    }
    if (utr !== null && (await this.settleUtrInput.isVisible())) {
      await this.settleUtrInput.fill(utr);
    }
    if (recordedByName !== null) {
      const recorderSelect = this.page.locator('#settle-recorder-select');
      if (await recorderSelect.isVisible()) {
        await recorderSelect.selectOption({ label: recordedByName });
      }
    }
    await this.confirmModal();
    await expect(this.modalDialog).not.toBeVisible();
  }

  async confirmReceipt(index = 0) {
    const confirmBtn = this.page.locator('.btn-confirm-receipt').nth(index);
    await confirmBtn.click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Confirm Payment Receipt|Confirm/i);
    await this.confirmModal();
    await expect(this.modalDialog).not.toBeVisible();
  }

  async undoSettlement(index = 0) {
    const actionBtn = this.page.locator('.btn-undo-settlement, .btn-dispute-payment').nth(index);
    await actionBtn.click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Undo Settlement|Reverse Settlement|Dispute|Cancel/i);
    await this.confirmModal();
  }
}

