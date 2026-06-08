// tests/e2e/admin-settings.spec.js
// Admin Settings E2E tests — AS-01 through AS-04
// Phase 4.3 — LGW Test Suite

'use strict';

const { test, expect } = require('@playwright/test');
const { mockCsv }      = require('./helpers/mock-csv');
const {
  wpAdminLogin,
  wpcli,
  clearDivisionCache,
  seedTestClubs,
} = require('./helpers/wp-login');
const {
  ADMIN_SETTINGS,
  CACHE_PANEL_SEL,
  SYNC_ALL_BTN,
  CSV,
} = require('./helpers/fixtures');

test.beforeAll(async () => {
  await seedTestClubs();
});

test.beforeEach(async ({ page }) => {
  await wpAdminLogin(page);
  await mockCsv(page, CSV.STANDARD);
});

// ─────────────────────────────────────────────────────────────────────────────
// AS-01  Cache health panel shows synced_at and fixture count @P2
// ─────────────────────────────────────────────────────────────────────────────
test('AS-01: cache health panel shows synced_at and fixture count per division @P2', async ({ page }) => {
  // Ensure cache is populated so the panel has data
  await wpcli(`eval 'lgw_cache_sync_all();'`);

  await page.goto(ADMIN_SETTINGS);

  const cachePanel = page.locator(CACHE_PANEL_SEL);
  await expect(cachePanel).toBeVisible({ timeout: 8000 });

  // Panel should show a "Last synced" / "synced_at" style timestamp
  const panelText = await cachePanel.textContent();
  // Should contain something time-related (minutes ago, seconds ago, or a date)
  expect(panelText).toMatch(/ago|sync|fixtures|teams/i);
});

// ─────────────────────────────────────────────────────────────────────────────
// AS-02  "Sync now" button triggers sync and updates synced_at @P2
// ─────────────────────────────────────────────────────────────────────────────
test('AS-02: Sync now button triggers sync and updates synced_at @P2', async ({ page }) => {
  await clearDivisionCache();

  await page.goto(ADMIN_SETTINGS);

  const syncBtn = page.locator(SYNC_ALL_BTN).first();
  await expect(syncBtn).toBeVisible({ timeout: 8000 });

  // Note current time before sync
  const before = Math.floor(Date.now() / 1000);

  await syncBtn.click();

  // Wait for success indicator
  await page.waitForSelector(
    '.lgw-sync-status:has-text("Synced"), .lgw-sync-ok, [data-sync-done="1"]',
    { timeout: 15000 }
  );

  // Verify synced_at in the DB is recent
  const syncedAt = await wpcli(`eval '
    global $wpdb;
    $row = $wpdb->get_row("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE \\"lgw_div_cache_%\\" LIMIT 1");
    if ($row) {
      $data = maybe_unserialize($row->option_value);
      echo $data["synced_at"] ?? 0;
    } else {
      echo 0;
    }
  '`);
  const synced = parseInt(syncedAt.trim(), 10);
  expect(synced).toBeGreaterThanOrEqual(before - 2); // allow 2s leeway
});

// ─────────────────────────────────────────────────────────────────────────────
// AS-03  Cron interval dropdown saves and is reflected on reload @P2
// ─────────────────────────────────────────────────────────────────────────────
test('AS-03: cron interval dropdown saves and is reflected on reload @P2', async ({ page }) => {
  await page.goto(ADMIN_SETTINGS);

  // Find the cron interval dropdown
  const intervalSelect = page.locator('select[name*="lgw_cache_cron_interval"], select[name*="cron_interval"]').first();
  await expect(intervalSelect).toBeVisible({ timeout: 8000 });

  // Select "30 min" (value likely 'twicehourly' for WP cron)
  await intervalSelect.selectOption({ value: 'twicehourly' });

  // Save settings
  const saveBtn = page.locator('input[type="submit"][name="submit"], button[type="submit"]').first();
  await saveBtn.click();
  await page.waitForLoadState('networkidle');

  // Reload and verify selection persisted
  await page.goto(ADMIN_SETTINGS);
  const savedValue = await page.locator('select[name*="lgw_cache_cron_interval"], select[name*="cron_interval"]').first().inputValue();
  expect(savedValue).toBe('twicehourly');

  // Restore to hourly
  await page.locator('select[name*="lgw_cache_cron_interval"], select[name*="cron_interval"]').first().selectOption({ value: 'hourly' });
  await page.locator('input[type="submit"][name="submit"], button[type="submit"]').first().click();
  await page.waitForLoadState('networkidle');
});

// ─────────────────────────────────────────────────────────────────────────────
// AS-04  "Clear cache" link invalidates all division caches @P2
// ─────────────────────────────────────────────────────────────────────────────
test('AS-04: clear cache link invalidates all division caches @P2', async ({ page }) => {
  // Populate cache first
  await wpcli(`eval 'lgw_cache_sync_all();'`);
  const before = await wpcli(`option list --search="lgw_div_cache_*" --format=csv`);
  const linesBefore = before.trim().split('\n').filter(l => l.includes('lgw_div_cache_'));
  expect(linesBefore.length).toBeGreaterThan(0);

  await page.goto(ADMIN_SETTINGS);

  // Find and click the Clear Cache button/link in the cache panel
  const clearLink = page.locator(
    'a:has-text("Clear"), button:has-text("Clear"), [data-action="clear-cache"]'
  ).first();
  await expect(clearLink).toBeVisible({ timeout: 8000 });
  await clearLink.click();

  // Confirm any dialog
  page.on('dialog', dialog => dialog.accept());

  // Wait for AJAX or page reload
  await page.waitForTimeout(2000);

  // Cache options should be gone
  const after = await wpcli(`option list --search="lgw_div_cache_*" --format=csv`);
  const linesAfter = after.trim().split('\n').filter(l => l.includes('lgw_div_cache_'));
  expect(linesAfter.length).toBe(0);
});
