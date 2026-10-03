import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Dedicated Settings View & Preferences', () => {
  test('Direct navigation to #/settings loads guest view, allows theme switching, density toggle, and regional preferences', async ({ page }) => {
    await page.goto('/#/settings');
    await page.waitForLoadState('domcontentloaded');

    // 1. Verify Header & Guest Badge
    const heading = page.locator('h1');
    await expect(heading).toContainText('Settings & Preferences');
    await expect(page.locator('text=Guest Mode')).toBeVisible();

    // 2. Verify Theme Selector in Settings
    const lightThemeCard = page.locator('button[data-theme-target="light"]');
    const darkThemeCard = page.locator('button[data-theme-target="dark"]');
    await expect(lightThemeCard).toBeVisible();
    await expect(darkThemeCard).toBeVisible();

    // 3. Switch to dark mode via Settings
    await darkThemeCard.click();
    const docTheme = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
    expect(docTheme).toBe('dark');

    // 4. Verify LocalStorage theme persistence
    const storedTheme = await page.evaluate(() => localStorage.getItem('smartsplit_theme'));
    expect(storedTheme).toBe('dark');

    // 5. Test Display Density (Compact Mode)
    const compactCard = page.locator('button[data-density-target="compact"]');
    await expect(compactCard).toBeVisible();
    await compactCard.click();
    const isCompact = await page.evaluate(() => document.body.classList.contains('density-compact'));
    expect(isCompact).toBe(true);

    // 6. Test Default Currency Preference Selection
    const currencySelect = page.locator('#settings-pref-currency');
    await expect(currencySelect).toBeVisible();
    await currencySelect.selectOption('USD');
    const storedCurr = await page.evaluate(() => localStorage.getItem('smartsplit_pref_currency'));
    expect(storedCurr).toBe('USD');

    // 7. Test Date Format Preference Selection
    const dateFormatSelect = page.locator('#settings-pref-date-format');
    await expect(dateFormatSelect).toBeVisible();
    await dateFormatSelect.selectOption('YYYY-MM-DD');
    const storedDateFmt = await page.evaluate(() => localStorage.getItem('smartsplit_pref_date_format'));
    expect(storedDateFmt).toBe('YYYY-MM-DD');

    // 8. Verify Navigation Back to Workspaces
    const backBtn = page.locator('#btn-settings-back');
    await backBtn.click();
    await expect(page).toHaveURL(/.*#\//);
  });
});
