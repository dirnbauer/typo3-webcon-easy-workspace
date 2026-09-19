import { defineConfig, devices } from '@playwright/test';

/**
 * Browser scenario for the toolbar badge. It drives a running TYPO3
 * installation with the extension installed (see Documentation/Testing.rst
 * for the environment variables). CI does not run it: it needs an instance.
 */
const browser = process.env.WEW_E2E_BROWSER || 'chromium';
const projects = {
  chromium: { name: 'chromium', use: { ...devices['Desktop Chrome'], channel: process.env.WEW_E2E_CHANNEL || undefined } },
  webkit: { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  firefox: { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
};

export default defineConfig({
  testDir: './Tests/E2E',
  outputDir: './.Build/playwright/results',
  reporter: [['list'], ['html', { outputFolder: './.Build/playwright/report', open: 'never' }]],
  fullyParallel: false,
  workers: 1,
  retries: Number(process.env.WEW_E2E_RETRIES ?? 1),
  timeout: 180_000,
  expect: { timeout: 15_000 },
  use: {
    baseURL: process.env.WEW_E2E_BASE_URL || 'https://localhost',
    ignoreHTTPSErrors: true,
    actionTimeout: 30_000,
    navigationTimeout: 90_000,
    viewport: { width: 1440, height: 900 },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [projects[browser] || projects.chromium],
});
