const { defineConfig, devices } = require('@playwright/test');
const baseURL = process.env.PAYROLL_QA_URL || 'http://127.0.0.1:8000';
module.exports = defineConfig({
  testDir: './tests',
  timeout: 30000,
  expect: { timeout: 8000 },
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  outputDir: 'test-results',
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    { name: 'desktop-chromium', use: { ...devices['Desktop Chrome'], reducedMotion: 'reduce' } },
    { name: 'mobile-chromium', use: { ...devices['Pixel 7'], reducedMotion: 'reduce' } },
  ],
});
