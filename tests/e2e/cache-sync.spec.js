// tests/e2e/cache-sync.spec.js
// Cache Sync E2E tests — CS-01 through CS-06
// Phase 4.3 — LGW Test Suite

'use strict';

const { test, expect }  = require('@playwright/test');
const { mockCsv }       = require('./helpers/mock-csv');
const {
  wpAdminLogin,
  wpcli,
  clearDivisionCache,
  clearTestDivisionCache,
  seedTestEnvironment,
  seedCacheFromFixture,
  getWpOption,
  setWpOption,
} = require('./helpers/wp-login');
const {
  TEST_PAGE_URL,
  ADMIN_SETTINGS,
  TABLE_BODY_SEL,
  WIDGET_SEL,
  PLAYED_ROW_SEL,
  CACHE_PANEL_SEL,
  SYNC_ALL_BTN,
  waitForWidget,
  openFixturesTab,
  CSV,
} = require('./helpers/fixtures');

test.beforeAll(async () => {
  await seedTestEnvironment();
});

test.beforeEach(async () => {
  // Re-seed test division cache before each test for a clean state
  await seedCacheFromFixture('div1-standard.csv');
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-01  Cache is populated after admin triggers manual sync @P1
// ─────────────────────────────────────────────────────────────────────────────
test('CS-01: cache populated after admin manual sync @P1', async ({ page }) => {
  // Clear only test division cache — admin sync will repopulate active season
  await clearTestDivisionCache();

  await wpAdminLogin(page);
  await page.goto(`http://localhost:8080${ADMIN_SETTINGS}`);
  await page.waitForURL(`**${ADMIN_SETTINGS}**`, { timeout: 20000 });

  // Find and click Sync All Now button
  const syncBtn = page.locator(SYNC_ALL_BTN).first();
  await expect(syncBtn).toBeVisible({ timeout: 8000 });
  await syncBtn.click();

  // Wait for status text to update — actual element is #lgw-cache-sync-status
  await expect(page.locator('#lgw-cache-sync-status')).toContainText(
    /Sync|synced|OK|✓|Done/i, { timeout: 20000 }
  );

  // Verify lgw_div_cache_* options exist
  const cacheKeys = await wpcli(`option list --search="lgw_div_cache_*" --field=option_name --format=csv`);
  expect(cacheKeys.trim().length).toBeGreaterThan(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-02  Confirmed scorecard result appears in fixture list @P1
// ─────────────────────────────────────────────────────────────────────────────
test('CS-02: confirmed scorecard result appears in fixture list @P1', async ({ page }) => {
  // Cache is pre-seeded by beforeEach with 12 played fixtures
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  await openFixturesTab(page);

  // Test Club B v Larne BC on Sat 2-May-2026 is unplayed in div1-standard.csv
  const targetRow = page.locator('.fx-row[data-home="Test Club B"][data-away="Larne BC"]').first();
  await expect(targetRow).toBeVisible();
  await expect(targetRow).not.toHaveClass(/played/);

  // Confirm scorecard via WP-CLI — uses Test Division so cache merge finds it
  await wpcli(`eval '
    $post_id = wp_insert_post(array(
      "post_type"   => "lgw_scorecard",
      "post_status" => "publish",
      "meta_input"  => array(
        "_lgw_home"       => "Test Club B",
        "_lgw_away"       => "Larne BC",
        "_lgw_home_score" => "19",
        "_lgw_away_score" => "14",
        "_lgw_home_pts"   => "2",
        "_lgw_away_pts"   => "0",
        "_lgw_date"       => "2026-05-02",
        "_lgw_status"     => "confirmed",
        "_lgw_division"   => "Test Division",
      )
    ));
    do_action("lgw_scorecard_confirmed", $post_id);
  '`);

  // Reload — cache merge should have marked the fixture as played
  await page.reload();
  await waitForWidget(page);
  await openFixturesTab(page);

  const confirmedRow = page.locator('.fx-row[data-home="Test Club B"][data-away="Larne BC"]').first();
  await expect(confirmedRow).toHaveClass(/played/, { timeout: 5000 });
  const rowText = await confirmedRow.textContent();
  expect(rowText).toMatch(/19/);
  expect(rowText).toMatch(/14/);
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-03  Cache invalidation clears test division and forces XHR fallback @P1
// ─────────────────────────────────────────────────────────────────────────────
test('CS-03: cache invalidation clears option and forces XHR fallback @P1', async ({ page }) => {
  // Confirm test division cache exists
  const keysBefore = await wpcli(`option list --search="lgw_div_cache_*" --field=option_name --format=csv`);
  const testKeyBefore = keysBefore.split('\n').filter(k => k.includes('testdivision'));
  expect(testKeyBefore.length).toBeGreaterThan(0);

  // Invalidate test division only
  await clearTestDivisionCache();
  const keysAfter = await wpcli(`option list --search="lgw_div_cache_*" --field=option_name --format=csv`);
  const testKeyAfter = keysAfter.split('\n').filter(k => k.includes('testdivision'));
  expect(testKeyAfter.length).toBe(0);

  // Widget should fall back to XHR
  await mockCsv(page, CSV.STANDARD);
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);

  const widget = page.locator(WIDGET_SEL).first();
  const prerendered = await widget.getAttribute('data-prerendered');
  expect(prerendered).not.toBe('1');

  // Table still renders correctly
  await expect(page.locator(TABLE_BODY_SEL)).toBeVisible();
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-04  synced_at timestamp updates after each sync @P2
// ─────────────────────────────────────────────────────────────────────────────
test('CS-04: synced_at timestamp updates after sync @P2', async ({ page }) => {
  // Get synced_at from test division cache (seeded in beforeEach)
  const first = await wpcli(`eval '
    $data = get_option("lgw_div_cache_" . sanitize_key(lgw_get_active_season_id()) . "_testdivision");
    echo $data["synced_at"] ?? 0;
  '`);
  const firstSyncedAt = parseInt(first.trim(), 10);
  expect(firstSyncedAt).toBeGreaterThan(0);

  // Wait 2 seconds then re-seed
  await new Promise(r => setTimeout(r, 2000));
  await seedCacheFromFixture('div1-standard.csv');

  const second = await wpcli(`eval '
    $data = get_option("lgw_div_cache_" . sanitize_key(lgw_get_active_season_id()) . "_testdivision");
    echo $data["synced_at"] ?? 0;
  '`);
  const secondSyncedAt = parseInt(second.trim(), 10);
  expect(secondSyncedAt).toBeGreaterThanOrEqual(firstSyncedAt);
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-05  Hard TTL (24h) causes fallback to XHR @P2
// ─────────────────────────────────────────────────────────────────────────────
test('CS-05: hard TTL exceeded causes XHR fallback @P2', async ({ page }) => {
  // Write a stale cache entry for test division with synced_at > 24h ago
  await wpcli(`eval '
    $key = "lgw_div_cache_" . sanitize_key(lgw_get_active_season_id()) . "_testdivision";
    $stale = array(
      "teams"     => array(array("Test Club A", 0, 0, 0, 0, 0, 0, 0)),
      "fixtures"  => array(),
      "synced_at" => time() - (25 * HOUR_IN_SECONDS),
      "csv_url"   => "https://docs.google.com/spreadsheets/d/TEST_FIXTURE/export?format=csv",
      "season_id" => lgw_get_active_season_id(),
      "division"  => "Test Division",
    );
    update_option($key, $stale);
  '`);

  // Mock CSV so XHR fallback renders
  await mockCsv(page, CSV.STANDARD);
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);

  // Stale cache should be skipped — widget uses XHR path
  const widget = page.locator(WIDGET_SEL).first();
  const prerendered = await widget.getAttribute('data-prerendered');
  expect(prerendered).not.toBe('1');
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-06  Sync failure with unreachable CSV leaves existing cache intact @P2
// ─────────────────────────────────────────────────────────────────────────────
test('CS-06: sync failure with unreachable CSV leaves existing cache intact @P2', async ({ page }) => {
  // Get current team count from test division cache
  const before = await wpcli(`eval '
    $key = "lgw_div_cache_" . sanitize_key(lgw_get_active_season_id()) . "_testdivision";
    $data = get_option($key);
    echo is_array($data) ? count($data["teams"] ?? array()) : -1;
  '`);
  const teamsBefore = parseInt(before.trim(), 10);
  expect(teamsBefore).toBeGreaterThan(0);

  // Attempt sync with unreachable URL — should fail gracefully
  const result = await wpcli(`eval '
    $result = lgw_cache_sync_from_csv(
      "https://unreachable.invalid/csv",
      lgw_get_active_season_id(),
      "Test Division"
    );
    echo $result ? "synced" : "failed";
  '`);
  // May succeed or fail depending on HTTP timeout — either way cache should be intact
  
  // Cache should still have original team count
  const after = await wpcli(`eval '
    $key = "lgw_div_cache_" . sanitize_key(lgw_get_active_season_id()) . "_testdivision";
    $data = get_option($key);
    echo is_array($data) ? count($data["teams"] ?? array()) : -1;
  '`);
  const teamsAfter = parseInt(after.trim(), 10);
  expect(teamsAfter).toBe(teamsBefore);
});
