import { defineConfig, devices } from '@playwright/test';

/**
 * Smart Split V2 – Production-Grade Playwright E2E & Browser Test Configuration
 */
export default defineConfig({
  testDir: './tests/e2e/specs',
  timeout: 35000,
  expect: {
    timeout: 6000,
  },
  fullyParallel: false,
  workers: 1, // Single worker to avoid MySQL state collision during live workspace operations
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: [
    ['list'],
    ['html', { outputFolder: 'tests/e2e/reports/html', open: 'never' }],
  ],
  use: {
    baseURL: 'http://127.0.0.1:8000',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 8000,
    navigationTimeout: 15000,
  },
  projects: [
    {
      name: 'Desktop Chrome',
      use: {
        ...devices['Desktop Chrome'],
        channel: 'chrome',
        viewport: { width: 1440, height: 900 },
      },
      testIgnore: /responsive-mobile\.spec\.js/,
    },
    {
      name: 'Mobile Chrome',
      use: {
        ...devices['Pixel 5'],
        channel: 'chrome',
        viewport: { width: 390, height: 844 },
      },
      testMatch: /responsive-mobile\.spec\.js/,
    },
  ],
  webServer: {
    command: 'php -S 127.0.0.1:8000 -t public public/index.php',
    url: 'http://127.0.0.1:8000/api/health',
    reuseExistingServer: true,
    timeout: 10000,
  },
});
