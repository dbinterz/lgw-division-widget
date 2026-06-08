// tests/playwright.config.js
// LGW Division Widget — Playwright E2E test configuration
// Phase 4.3 — Test Suite (v7.6.0)

const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './e2e',
  timeout: 30000,          // 30s per test
  retries: 1,              // one retry on CI to absorb transient WP startup delays
  workers: 1,              // MANDATORY: serial — WP DB state must not interleave

  use: {
    baseURL:            process.env.LGW_BASE_URL || 'http://localhost:8080',
    headless:           true,
    screenshot:         'only-on-failure',
    video:              'retain-on-failure',
    trace:              'retain-on-failure',
    actionTimeout:      10000,
    navigationTimeout:  20000,
  },

  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile',   use: { ...devices['Pixel 5'] } },
  ],

  reporter: [
    ['list'],
    ['html', { outputFolder: './playwright-report', open: 'never' }],
  ],

  outputDir: './test-results',
});
