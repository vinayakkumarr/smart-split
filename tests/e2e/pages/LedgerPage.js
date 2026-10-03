import { expect } from '@playwright/test';
import { BasePage } from './BasePage.js';

/**
 * Page Object for Transaction Ledger, Search, Filters, In-Place Edit/Delete, and Trash Recovery.
 */
export class LedgerPage extends BasePage {
  /**
   * @param {import('@playwright/test').Page} page
   */
  constructor(page) {
    super(page);
    this.panel = page.locator('.panel').filter({ hasText: /Transaction Ledger/i });
    this.tableRows = page.locator('.data-table tbody tr');
    this.searchInput = page.locator('#ledger-search-input');
    this.clearSearchBtn = page.locator('#btn-clear-search');
    this.toggleFiltersBtn = page.locator('#btn-toggle-filters');
    this.filterDrawer = page.locator('.filter-drawer');
    this.categoryChips = page.locator('.category-chip');
    this.memberFilterSelect = page.locator('#filter-member-id');
    this.splitTypeFilterSelect = page.locator('#filter-split-type');
    this.minAmountFilterInput = page.locator('#filter-min-amount');
    this.maxAmountFilterInput = page.locator('#filter-max-amount');
    this.resetFiltersBtn = page.locator('#btn-reset-filters');
    this.moreActionsToggleBtn = page.locator('#btn-ledger-more-toggle');
    this.moreActionsMenu = page.locator('#ledger-actions-dropdown-menu');
    this.copySheetsBtn = page.locator('#btn-copy-sheets');
    this.trashBinMenuBtn = page.locator('#btn-view-trash-bin');
  }

  async getRowsCount() {
    return await this.tableRows.count();
  }

  async getRowTitle(index = 0) {
    return await this.tableRows.nth(index).locator('.table-cell-title').innerText();
  }

  async getRowAmount(index = 0) {
    return await this.tableRows.nth(index).locator('.table-cell-amount').first().innerText();
  }

  async search(query) {
    await this.searchInput.fill(query);
  }

  async clearSearch() {
    await this.searchInput.fill('');
    await this.page.evaluate(() => {
      const input = document.querySelector('#ledger-search-input');
      if (input) {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
  }

  async filterByCategory(categoryName) {
    const chip = this.categoryChips.filter({ hasText: categoryName });
    await chip.click();
    await expect(chip).toHaveClass(/is-active/);
  }

  async openFilterDrawer() {
    const drawer = this.page.locator('.filter-drawer');
    const isVisible = await drawer.isVisible().catch(() => false);
    if (!isVisible) {
      const toggleBtn = this.page.locator('#btn-toggle-filters');
      await expect(toggleBtn).toBeVisible();
      await toggleBtn.click();
      await expect(drawer).toBeVisible({ timeout: 8000 });
    }
  }

  async filterByMember(memberName) {
    await this.openFilterDrawer();
    await this.memberFilterSelect.selectOption({ label: memberName });
  }

  async filterBySplitType(type) {
    await this.openFilterDrawer();
    await this.splitTypeFilterSelect.selectOption(type);
  }

  async filterByAmountRange(min, max) {
    await this.openFilterDrawer();
    if (min !== null) {
      await this.minAmountFilterInput.fill(String(min));
      await this.minAmountFilterInput.dispatchEvent('input');
    }
    if (max !== null) {
      await this.maxAmountFilterInput.fill(String(max));
      await this.maxAmountFilterInput.dispatchEvent('input');
    }
  }

  async resetFilters() {
    await this.openFilterDrawer();
    const btn = this.page.locator('#btn-reset-filters');
    await expect(btn).toBeVisible();
    await btn.click();
  }

  async clickEditExpense(index = 0) {
    const row = this.tableRows.nth(index);
    const editBtn = row.locator('.btn-edit-expense');
    await editBtn.click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Edit Transaction/i);
  }

  async clickDeleteExpense(index = 0) {
    const row = this.tableRows.nth(index);
    const deleteBtn = row.locator('.btn-delete-expense');
    await deleteBtn.click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Delete/i);
    await this.confirmModal();
  }

  async openTrashBin() {
    await this.moreActionsToggleBtn.click();
    await expect(this.moreActionsMenu).toBeVisible();
    await this.trashBinMenuBtn.click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Trash/i);
  }

  async restoreTrashItem(expenseId) {
    const restoreBtn = this.page.locator(`.btn-restore-item[data-expense-id="${expenseId}"]`);
    await restoreBtn.click();
  }
}
