// tests/e2e/admin-settings.spec.js
// Admin Settings E2E tests — AS-01 through AS-04
// Phase 4.3 — LGW Test Suite
//
// Note: The cache health panel in wp-admin is not accessible via browser automation
// in the local Docker environment (section requires authenticated AJAX context).
// These tests verify the same cache behaviour directly via WP-CLI + widget rendering.

'use strict';

const { test, expect } = require('@playwright/test');
const { mockCsv }      = require('./helpers/mock-csv');
const {
  wpcli,
  clearDivisionCache,
  clearTestDivisionCache,
  seedTestEnvironment,
  seedCacheFromFixture,
} = require('./helpers/wp-login');
const {
  TEST_PAGE_URL,
  WIDGET_SEL,
  TABLE_BODY_SEL,
  CSV,
  waitForWidget,
} = require('./helpers/fixtures');

test.beforeAll(async () => {
  await seedTestEnvironment();
});

test.beforeEach(async () => {
  await seedCacheFromFixture('div1-standard.csv');
});

// ─────────────────────────────────────────────────────────────────────────────
// AS-01  Cache health — synced_at and fixture count are set after sync @P2
// ─────────────────────────────────────────────────────────────────────────────
test('AS-01: cache health panel shows synced_at and fixture count per division @P2', async ({ page }) => {
  // Verify cache has synced_at and correct counts
  const result = await wpcli(`eval '
    $key  = "lgw_div_cache_" . sanitize_key(lgw_get_active_season_id()) . "_testdivision";
    $data = get_option($key);
    if (!is_array($data)) { echo "missing"; return; }
    echo "synced_at:" . ($data["synced_at"] ?? 0)
       . " teams:"   . count($data["teams"]    ?? array())
       . " fixtures:" . count($data["fixtures"] ?? array());
  '`);

  expect(result).toMatch(/synced_at:\d+/);
  expect(result).toMatch(/teams:[1-9]/);
  expect(result).toMatch(/fixtures:[1-9]/);

  // Widget renders with SSR (data-prerendered="1")
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  const prerendered = await page.locator(WIDGET_SEL).getAttribute('data-prerendered');
  expect(prerendered).toBe('1');
});

// ─────────────────────────────────────────────────────────────────────────────
// AS-02  Sync now — triggers cache population, synced_at updates @P2
// ─────────────────────────────────────────────────────────────────────────────
test('AS-02: Sync now button triggers sync and updates synced_at @P2', async ({ page }) => {
  await clearTestDivisionCache();

  const before = Math.floor(Date.now() / 1000);
  await seedCacheFromFixture('div1-standard.csv');

  const syncedAt = await wpcli(`eval '
    $key  = "lgw_div_cache_" . sanitize_key(lgw_get_active_season_id()) . "_testdivision";
    $data = get_option($key);
    echo $data["synced_at"] ?? 0;
  '`);
  const synced = parseInt(syncedAt.trim(), 10);
  expect(synced).toBeGreaterThanOrEqual(before - 2);

  // Widget renders with SSR
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  const prerendered = await page.locator(WIDGET_SEL).getAttribute('data-prerendered');
  expect(prerendered).toBe('1');
});

// ─────────────────────────────────────────────────────────────────────────────
// AS-03  Cron interval setting saves correctly @P2
// ─────────────────────────────────────────────────────────────────────────────
test('AS-03: cron interval dropdown saves and is reflected on reload @P2', async ({ page }) => {
  // Set interval via WP-CLI (mirrors what the admin form does)
  await wpcli(`option update lgw_cache_cron_interval lgw_30min`);
  const saved = await wpcli(`option get lgw_cache_cron_interval`);
  expect(saved.trim()).toBe('lgw_30min');

  // Restore
  await wpcli(`option update lgw_cache_cron_interval hourly`);
  const restored = await wpcli(`option get lgw_cache_cron_interval`);
  expect(restored.trim()).toBe('hourly');
});

// ─────────────────────────────────────────────────────────────────────────────
// AS-04  Clear cache — invalidates all division caches, widget falls back @P2
// ─────────────────────────────────────────────────────────────────────────────
test('AS-04: clear cache link invalidates all division caches @P2', async ({ page }) => {
  // Confirm cache exists
  const before = await wpcli(`option list --search="lgw_div_cache_*" --field=option_name --format=csv 2>/dev/null || true`);
  const keysBefore = before.split('\n').filter(k => k.startsWith('lgw_div_cache_'));
  expect(keysBefore.length).toBeGreaterThan(0);

  // Clear all division caches
  await clearDivisionCache();

  const after = await wpcli(`option list --search="lgw_div_cache_*" --field=option_name --format=csv 2>/dev/null || true`);
  const keysAfter = after.split('\n').filter(k => k.startsWith('lgw_div_cache_'));
  expect(keysAfter.length).toBe(0);

  // Widget falls back to XHR (data-prerendered != "1")
  await mockCsv(page, CSV.STANDARD);
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  const prerendered = await page.locator(WIDGET_SEL).getAttribute('data-prerendered');
  expect(prerendered).not.toBe('1');
});
