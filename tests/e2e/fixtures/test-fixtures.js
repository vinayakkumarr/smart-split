import { test as baseTest, expect } from '@playwright/test';
import { LandingPage } from '../pages/LandingPage.js';
import { WorkspaceHeaderPage } from '../pages/WorkspaceHeaderPage.js';
import { MemberListSection } from '../pages/MemberListSection.js';
import { ExpenseModalPage } from '../pages/ExpenseModalPage.js';
import { LedgerPage } from '../pages/LedgerPage.js';
import { SettlementSection } from '../pages/SettlementSection.js';
import { ReceiptLightboxPage } from '../pages/ReceiptLightboxPage.js';

/**
 * Custom Playwright test fixture bundling all Smart Split page objects.
 */
export const test = baseTest.extend({
  landingPage: async ({ page }, use) => {
    await use(new LandingPage(page));
  },
  headerPage: async ({ page }, use) => {
    await use(new WorkspaceHeaderPage(page));
  },
  memberSection: async ({ page }, use) => {
    await use(new MemberListSection(page));
  },
  expenseModal: async ({ page }, use) => {
    await use(new ExpenseModalPage(page));
  },
  ledgerPage: async ({ page }, use) => {
    await use(new LedgerPage(page));
  },
  settlementSection: async ({ page }, use) => {
    await use(new SettlementSection(page));
  },
  lightboxPage: async ({ page }, use) => {
    await use(new ReceiptLightboxPage(page));
  },

  /**
   * Helper fixture to quickly bootstrap a fresh isolated workspace with given members.
   */
  createTestWorkspace: async ({ page, landingPage, memberSection }, use) => {
    const helper = async ({
      name = `Test Workspace ${Date.now()}`,
      creator = 'Alice',
      currency = 'INR',
      additionalMembers = ['Bob', 'Charlie'],
    } = {}) => {
      await landingPage.goto('/');
      await landingPage.createWorkspace(name, creator, currency);
      for (const m of additionalMembers) {
        await memberSection.addMember(m);
      }
      return { name, creator, currency, members: [creator, ...additionalMembers] };
    };
    await use(helper);
  },
});

export { expect };
