import { expect } from '@playwright/test';
import { BasePage } from './BasePage.js';

/**
 * Page Object for High-Resolution Receipt Attachment Lightbox & Document Viewer.
 */
export class ReceiptLightboxPage extends BasePage {
  /**
   * @param {import('@playwright/test').Page} page
   */
  constructor(page) {
    super(page);
    this.stage = page.locator('#lightbox-viewer-stage');
    this.image = page.locator('#lightbox-image');
    this.prevBtn = page.locator('#lightbox-prev-btn');
    this.nextBtn = page.locator('#lightbox-next-btn');
    this.deleteBtn = page.locator('#lightbox-delete-btn');
  }

  async openFromLedger(expenseRowIndex = 0) {
    const receiptBtn = this.page.locator('.btn-view-receipts').nth(expenseRowIndex);
    await receiptBtn.click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Receipt/i);
  }

  async isImageVisible() {
    return await this.image.isVisible();
  }

  async getImageSrc() {
    return await this.image.getAttribute('src');
  }
}
