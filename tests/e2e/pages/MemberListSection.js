import { expect } from '@playwright/test';
import { BasePage } from './BasePage.js';

/**
 * Page Object for Member Roster section, adding members, and customizing member avatars.
 */
export class MemberListSection extends BasePage {
  /**
   * @param {import('@playwright/test').Page} page
   */
  constructor(page) {
    super(page);
    this.section = page.locator('.member-chip-list');
    this.addMemberBtn = page.locator('#btn-add-member');
    this.memberChips = page.locator('.member-chip');
    this.newMemberNameInput = page.locator('#new-member-name-input');
    this.emojiButtons = page.locator('#add-member-emoji-grid .avatar-emoji-btn');
  }

  async getMemberNames() {
    return await this.memberChips.locator('.member-name-text').allInnerTexts();
  }

  async addMember(name, emoji = null) {
    await this.addMemberBtn.click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Add Member/i);
    await this.newMemberNameInput.fill(name);

    if (emoji) {
      const emojiBtn = this.page.locator(`.avatar-emoji-btn[data-emoji="${emoji}"]`);
      if (await emojiBtn.isVisible()) {
        await emojiBtn.click();
      }
    }

    await this.confirmModal();
    await expect(this.modalDialog).not.toBeVisible();
    await expect(this.memberChips.filter({ hasText: name })).toBeVisible();
  }

  async openAvatarModal(memberName) {
    const chip = this.memberChips.filter({ hasText: memberName });
    await chip.click();
    await expect(this.modalDialog).toBeVisible();
    await expect(this.modalTitle).toContainText(/Member Settings|Avatar/i);
  }

  async customizeAvatar(memberName, emoji, paletteId = 'ocean') {
    await this.openAvatarModal(memberName);
    const emojiBtn = this.page.locator(`.avatar-emoji-btn[data-emoji="${emoji}"]`);
    if (await emojiBtn.isVisible()) {
      await emojiBtn.click();
    }
    const paletteBtn = this.page.locator(`.avatar-palette-swatch[data-palette-id="${paletteId}"]`);
    if (await paletteBtn.isVisible()) {
      await paletteBtn.click();
    }
    await this.confirmModal();
    await expect(this.modalDialog).not.toBeVisible();
  }
}
