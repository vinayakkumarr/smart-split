import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Multi-Dimensional Ledger Search & Filtering', () => {
  test('Filters ledger by instant search keyword, category, and advanced drawer', async ({
    createTestWorkspace,
    headerPage,
    expenseModal,
    ledgerPage,
  }) => {
    // 1. Setup workspace with Alice, Bob, Charlie and 3 distinct expenses
    await createTestWorkspace();

    // Expense 1: Groceries #weekly
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Weekly Market #groceries',
      amount: '1200',
      payerName: 'Alice',
      categoryName: 'Groceries',
    });
    await expenseModal.selectSplitType('EQUAL');
    await expenseModal.submit();

    // Expense 2: Flight #vacation
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Flight Ticket #vacation',
      amount: '8500',
      payerName: 'Bob',
      categoryName: 'Travel & Transport',
    });
    await expenseModal.selectSplitType('EQUAL');
    await expenseModal.submit();

    // Expense 3: Movie #fun
    await headerPage.openLogExpenseModal();
    await expenseModal.fillBasicDetails({
      title: 'Cinema IMAX #entertainment',
      amount: '900',
      payerName: 'Charlie',
      categoryName: 'Entertainment',
    });
    await expenseModal.selectSplitType('EQUAL');
    await expenseModal.submit();

    await expect(ledgerPage.tableRows).toHaveCount(3);

    // 2. Search by keyword "Flight"
    await ledgerPage.search('Flight');
    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Flight Ticket');

    // Clear search
    await ledgerPage.clearSearch();
    await expect(ledgerPage.tableRows).toHaveCount(3);

    // 3. Search by hashtag "#entertainment"
    await ledgerPage.search('#entertainment');
    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Cinema IMAX');
    await ledgerPage.clearSearch();

    // 4. Filter by Category Chip "Groceries"
    await ledgerPage.filterByCategory('Groceries');
    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Weekly Market');

    // Reset to All Categories
    await ledgerPage.filterByCategory('All Categories');
    await expect(ledgerPage.tableRows).toHaveCount(3);

    // 5. Filter by Min/Max Amount Bounds (e.g. Min 5000)
    await ledgerPage.filterByAmountRange(5000, null);
    await expect(ledgerPage.tableRows).toHaveCount(1);
    await expect(ledgerPage.tableRows.first()).toContainText('Flight Ticket');

    // Reset All Filters
    await ledgerPage.resetFilters();
    await expect(ledgerPage.tableRows).toHaveCount(3);
  });
});
