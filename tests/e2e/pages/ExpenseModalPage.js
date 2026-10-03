import { expect } from '@playwright/test';
import { BasePage } from './BasePage.js';

/**
 * Page Object for Transaction Modal (Log / Edit Expense with 5 split models & itemization).
 */
export class ExpenseModalPage extends BasePage {
  /**
   * @param {import('@playwright/test').Page} page
   */
  constructor(page) {
    super(page);
    this.titleInput = page.locator('#modal-expense-title');
    this.categorySelect = page.locator('#modal-expense-category');
    this.amountInput = page.locator('#modal-expense-amount');
    this.dateInput = page.locator('#modal-expense-date');
    this.currencySelect = page.locator('#modal-expense-currency');
    this.fxRateInput = page.locator('#modal-fx-rate');
    this.notesInput = page.locator('#modal-expense-notes');
    this.payerSelect = page.locator('#modal-payer-select');
    this.toggleMultiPayerBtn = page.locator('#toggle-multi-payer-btn');
    this.multiPayerContainer = page.locator('#multi-payer-container');
    this.splitTabs = page.locator('.split-tab-btn');
    this.validationBar = page.locator('#split-validation-bar');
    this.validationBadge = page.locator('#validation-remaining-badge');
    this.whatIfContainer = page.locator('#whatif-balance-container');
    this.whatIfChips = page.locator('.whatif-balance-chip');
    this.receiptDropzone = page.locator('#receipt-upload-dropzone');
    this.receiptFileInput = page.locator('#modal-receipt-file-input');
    this.receiptPreviewContainer = page.locator('#receipt-preview-container');
    this.receiptFileLabel = page.locator('#receipt-file-label');
    this.addItemBtn = page.locator('#add-item-btn');
    this.itemizedTaxInput = page.locator('#itemized-tax-input');
    this.itemizedTipInput = page.locator('#itemized-tip-input');
    this.itemizedDiscountInput = page.locator('#itemized-discount-input');
    this.itemizedSubtotal = page.locator('#itemized-subtotal-val');
    this.itemizedNetTotal = page.locator('#itemized-net-val');
  }

  async selectSplitType(type) {
    const isAdvancedVisible = await this.page.locator('#advanced-split-section').isVisible();
    if (!isAdvancedVisible) {
      const toggleBtn = this.page.locator('#btn-toggle-advanced-split');
      if (await toggleBtn.isVisible()) {
        await toggleBtn.click();
      }
    }
    const tab = this.page.locator(`.split-tab-btn[data-type="${type}"]`);
    await tab.click();
    await expect(tab).toHaveClass(/active/);
  }

  async fillBasicDetails({ title, amount, payerName = null, categoryName = null, notes = null, currency = null, fxRate = null }) {
    if (title !== undefined) await this.titleInput.fill(title);
    if (amount !== undefined) await this.amountInput.fill(String(amount));
    if (categoryName || notes || currency || (fxRate !== null && fxRate !== undefined)) {
      const isMoreVisible = await this.page.locator('#more-options-section').isVisible();
      if (!isMoreVisible) {
        const toggleBtn = this.page.locator('#btn-toggle-more-options');
        if (await toggleBtn.isVisible()) {
          await toggleBtn.click();
        }
      }
    }
    if (categoryName) {
      try {
        await this.categorySelect.selectOption({ label: categoryName });
      } catch {
        // Match partial text or select first option
        const options = await this.categorySelect.locator('option').allInnerTexts();
        const matched = options.find(o => o.toLowerCase().includes(categoryName.toLowerCase()));
        if (matched) {
          await this.categorySelect.selectOption({ label: matched });
        }
      }
    }
    if (payerName) {
      await this.payerSelect.selectOption({ label: payerName });
    }
    if (notes) await this.notesInput.fill(notes);
    if (currency) {
      await this.currencySelect.selectOption(currency);
    }
    if (fxRate !== null && fxRate !== undefined) {
      await this.fxRateInput.fill(String(fxRate));
    }
  }

  async toggleMultiPayer() {
    const isMoreVisible = await this.page.locator('#more-options-section').isVisible();
    if (!isMoreVisible) {
      const toggleBtn = this.page.locator('#btn-toggle-more-options');
      if (await toggleBtn.isVisible()) {
        await toggleBtn.click();
      }
    }
    await this.toggleMultiPayerBtn.click();
  }

  async fillMultiPayerAmounts(memberAmountMap) {
    for (const [memberIdOrName, amt] of Object.entries(memberAmountMap)) {
      const input = this.page.locator(`.multi-payer-input[data-member-id="${memberIdOrName}"]`);
      if (await input.isVisible()) {
        await input.fill(String(amt));
      }
    }
  }

  async fillCustomSplitValues(customMap) {
    const isAdvancedVisible = await this.page.locator('#advanced-split-section').isVisible();
    if (!isAdvancedVisible) {
      const toggleBtn = this.page.locator('#btn-toggle-advanced-split');
      if (await toggleBtn.isVisible()) {
        await toggleBtn.click();
      }
    }
    for (const [memberId, val] of Object.entries(customMap)) {
      const input = this.page.locator(`.split-custom-input[data-member-id="${memberId}"]`);
      if (await input.isVisible()) {
        await input.fill(String(val));
      }
    }
  }

  async setParticipantChecked(memberId, checked) {
    const isAdvancedVisible = await this.page.locator('#advanced-split-section').isVisible();
    if (!isAdvancedVisible) {
      const toggleBtn = this.page.locator('#btn-toggle-advanced-split');
      if (await toggleBtn.isVisible()) {
        await toggleBtn.click();
      }
    }
    const checkbox = this.page.locator(`.participant-check[data-member-id="${memberId}"]`);
    const isChecked = await checkbox.isChecked();
    if (isChecked !== checked) {
      await checkbox.setChecked(checked);
    }
  }

  async addItemizedLineItem({ name, amount, selectMemberIndices = [] }) {
    await this.addItemBtn.click();
    const rows = this.page.locator('#itemized-items-list > div');
    const lastRow = rows.last();
    const nameInput = lastRow.locator('input[type="text"]').first();
    const amtInput = lastRow.locator('input[type="text"]').nth(1);
    await nameInput.fill(name);
    await amtInput.fill(String(amount));

    if (selectMemberIndices.length > 0) {
      const memberChips = lastRow.locator('.badge');
      for (let i = 0; i < await memberChips.count(); i++) {
        const shouldSelect = selectMemberIndices.includes(i);
        const chip = memberChips.nth(i);
        const hasPrimary = await chip.evaluate(el => el.classList.contains('badge-primary'));
        if (hasPrimary !== shouldSelect) {
          await chip.click();
        }
      }
    }
  }

  async attachReceipt(absoluteFilePath) {
    const isMoreVisible = await this.page.locator('#more-options-section').isVisible();
    if (!isMoreVisible) {
      const toggleBtn = this.page.locator('#btn-toggle-more-options');
      if (await toggleBtn.isVisible()) {
        await toggleBtn.click();
      }
    }
    await this.receiptFileInput.setInputFiles(absoluteFilePath);
    await expect(this.receiptPreviewContainer).toBeVisible();
  }

  async isSaveEnabled() {
    return await this.modalConfirmBtn.isEnabled();
  }

  async submit() {
    await expect(this.modalConfirmBtn).toBeEnabled();
    await this.modalConfirmBtn.click();
    await expect(this.modalDialog).not.toBeVisible();
  }
}
