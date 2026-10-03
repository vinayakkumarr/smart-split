import { test, expect } from '../fixtures/test-fixtures.js';
import path from 'path';
import fs from 'fs';

test.describe('Smart Split V2 – Receipt Attachment & Lightbox Viewer', () => {
  test('Attaches PNG receipt to expense and views in Lightbox', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    ledgerPage,
    lightboxPage,
  }) => {
    const uploadDir = path.resolve('storage/receipts');
    const beforeFiles = new Set(fs.existsSync(uploadDir) ? fs.readdirSync(uploadDir) : []);

    try {
      await createTestWorkspace();

      // 1. Open Expense Modal and fill details
      await headerPage.openLogExpenseModal();
      await expenseModal.fillBasicDetails({
        title: 'Restaurant with Bill',
        amount: '2500',
        payerName: 'Alice',
        categoryName: 'Food & Dining',
      });
      await expenseModal.selectSplitType('EQUAL');

      // 2. Attach Receipt File
      const sampleReceiptPath = path.resolve('tests/e2e/data/receipt-sample.png');
      await expenseModal.attachReceipt(sampleReceiptPath);

      // 3. Submit Expense
      await expenseModal.submit();

      // 4. Verify Ledger row has Receipt button
      await expect(ledgerPage.tableRows).toHaveCount(1);
      const receiptBtn = ledgerPage.tableRows.first().locator('.btn-view-receipts');
      await expect(receiptBtn).toBeVisible();
      await expect(receiptBtn).toHaveAttribute('title', /View Attached Receipts \(1\)/);

      // 5. Open Lightbox Viewer
      await lightboxPage.openFromLedger(0);
      await expect(lightboxPage.stage).toBeVisible();
      await expect(lightboxPage.image).toBeVisible();

      // 6. Close Lightbox
      await lightboxPage.closeModal();
    } finally {
      // Remove files created during test run
      if (fs.existsSync(uploadDir)) {
        const afterFiles = fs.readdirSync(uploadDir);
        for (const file of afterFiles) {
          if (!beforeFiles.has(file)) {
            try {
              fs.unlinkSync(path.join(uploadDir, file));
            } catch {
              // Ignore cleanup error if already removed
            }
          }
        }
      }
    }
  });
});

