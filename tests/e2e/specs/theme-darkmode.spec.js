import { test, expect } from '../fixtures/test-fixtures.js';

test.describe('Smart Split V2 – Dark Mode & Theme Persistence', () => {
  test('Toggles light/dark theme and persists across page reloads', async ({ landingPage }) => {
    await landingPage.goto('/');

    // 1. Initial Theme check
    const initialTheme = await landingPage.getTheme();
    expect(['light', 'dark']).toContain(initialTheme);

    // 2. Toggle Theme
    await landingPage.toggleTheme();
    const toggledTheme = await landingPage.getTheme();
    expect(toggledTheme).not.toBe(initialTheme);

    // 3. Verify LocalStorage
    const storedTheme = await landingPage.page.evaluate(() => localStorage.getItem('smartsplit_theme'));
    expect(storedTheme).toBe(toggledTheme);

    // 4. Reload page and assert persisted theme
    await landingPage.page.reload();
    await landingPage.page.waitForLoadState('domcontentloaded');
    const persistedTheme = await landingPage.getTheme();
    expect(persistedTheme).toBe(toggledTheme);

    // 5. Toggle back to original theme
    await landingPage.toggleTheme();
    const finalTheme = await landingPage.getTheme();
    expect(finalTheme).toBe(initialTheme);
  });
});
