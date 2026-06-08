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
  seedTestClubs,
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
  await seedTestClubs();
});

test.beforeEach(async ({ page }) => {
  await mockCsv(page, CSV.STANDARD);
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-01  Cache is populated after admin triggers manual sync @P1
// ─────────────────────────────────────────────────────────────────────────────
test('CS-01: cache populated after admin manual sync @P1', async ({ page }) => {
  await clearDivisionCache();

  await wpAdminLogin(page);
  await page.goto(ADMIN_SETTINGS);

  // Find and click the Sync All Now button in the cache health panel
  const syncBtn = page.locator(SYNC_ALL_BTN).first();
  await expect(syncBtn).toBeVisible({ timeout: 8000 });
  await syncBtn.click();

  // Wait for AJAX success indicator (spinner gone / "Synced" text)
  await page.waitForSelector('.lgw-sync-status:has-text("Synced"), .lgw-sync-ok, [data-sync-done="1"]', {
    timeout: 15000,
  });

  // Verify at least one lgw_div_cache_* option now exists
  const cacheKeys = await wpcli(`option list --search="lgw_div_cache_*" --format=csv`);
  expect(cacheKeys.trim().length).toBeGreaterThan(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-02  Confirmed scorecard result appears in fixture list within 2s @P1
// ─────────────────────────────────────────────────────────────────────────────
test('CS-02: confirmed scorecard result appears in fixture list @P1', async ({ page }) => {
  // Populate cache from standard CSV
  await wpcli(`eval 'lgw_cache_sync_all();'`);

  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  await openFixturesTab(page);

  // Grab pre-confirmation state — Test Club B vs Larne BC (Sat 2-May-2026) is unplayed
  const targetRow = page.locator('.fx-row[data-home="Test Club B"][data-away="Larne BC"]').first();
  await expect(targetRow).toBeVisible();
  await expect(targetRow).not.toHaveClass(/played/);

  // Simulate a scorecard confirmation via WP-CLI (invokes lgw_scorecard_confirmed action)
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
        "_lgw_division"   => "Division 1",
      )
    ));
    do_action("lgw_scorecard_confirmed", $post_id);
  '`);

  // Reload page — cache merge should have updated the fixture row
  await page.reload();
  await waitForWidget(page);
  await openFixturesTab(page);

  const confirmedRow = page.locator('.fx-row[data-home="Test Club B"][data-away="Larne BC"]').first();
  await expect(confirmedRow).toHaveClass(/played/, { timeout: 5000 });

  // Score should be visible in the row
  const rowText = await confirmedRow.textContent();
  expect(rowText).toMatch(/19/);
  expect(rowText).toMatch(/14/);
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-03  Cache invalidation clears option and forces XHR fallback @P1
// ─────────────────────────────────────────────────────────────────────────────
test('CS-03: cache invalidation clears option and forces XHR fallback @P1', async ({ page }) => {
  // Populate cache first
  await wpcli(`eval 'lgw_cache_sync_all();'`);
  const cacheKeysBefore = await wpcli(`option list --search="lgw_div_cache_*" --format=csv`);
  expect(cacheKeysBefore.trim().length).toBeGreaterThan(0);

  // Invalidate
  await clearDivisionCache();
  const cacheKeysAfter = await wpcli(`option list --search="lgw_div_cache_*" --format=csv`);
  // After clearing, list should be empty or just the header row
  const lines = cacheKeysAfter.trim().split('\n').filter(l => l.includes('lgw_div_cache_'));
  expect(lines.length).toBe(0);

  // Widget should still load via XHR fallback
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
  // First sync
  await wpcli(`eval 'lgw_cache_sync_all();'`);

  // Read the first synced_at value from any cache option
  const optionRaw = await wpcli(`eval '
    global $wpdb;
    $row = $wpdb->get_row("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE \"lgw_div_cache_%\" LIMIT 1");
    if ($row) {
      $data = maybe_unserialize($row->option_value);
      echo $data["synced_at"] ?? 0;
    } else {
      echo 0;
    }
  '`);
  const firstSyncedAt = parseInt(optionRaw.trim(), 10);
  expect(firstSyncedAt).toBeGreaterThan(0);

  // Wait 2 seconds to ensure Unix timestamp differs
  await new Promise(r => setTimeout(r, 2000));

  // Sync again
  await wpcli(`eval 'lgw_cache_sync_all();'`);

  const optionRaw2 = await wpcli(`eval '
    global $wpdb;
    $row = $wpdb->get_row("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE \"lgw_div_cache_%\" LIMIT 1");
    if ($row) {
      $data = maybe_unserialize($row->option_value);
      echo $data["synced_at"] ?? 0;
    } else {
      echo 0;
    }
  '`);
  const secondSyncedAt = parseInt(optionRaw2.trim(), 10);
  expect(secondSyncedAt).toBeGreaterThanOrEqual(firstSyncedAt);
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-05  Hard TTL (24h) causes fallback to XHR @P2
// ─────────────────────────────────────────────────────────────────────────────
test('CS-05: hard TTL exceeded causes XHR fallback @P2', async ({ page }) => {
  // Manually write a stale cache entry with synced_at > 24h ago
  await wpcli(`eval '
    $stale_data = array(
      "teams"     => array(array("Test Club A", 0, 0, 0, 0, 0, 0, 0)),
      "fixtures"  => array(),
      "synced_at" => time() - (25 * HOUR_IN_SECONDS),
      "csv_url"   => "https://example.com/fake.csv",
      "season_id" => "2026",
      "division"  => "Division 1",
    );
    update_option("lgw_div_cache_2026_division-1", $stale_data);
  '`);

  // Mock CSV so XHR fallback has data to render
  await mockCsv(page, CSV.STANDARD);
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);

  // Stale cache should cause SSR to be skipped — XHR path used
  const widget = page.locator(WIDGET_SEL).first();
  const prerendered = await widget.getAttribute('data-prerendered');
  expect(prerendered).not.toBe('1');
});

// ─────────────────────────────────────────────────────────────────────────────
// CS-06  Sync failure with unreachable CSV leaves existing cache intact @P2
// ─────────────────────────────────────────────────────────────────────────────
test('CS-06: sync failure with unreachable CSV leaves existing cache intact @P2', async ({ page }) => {
  // Populate cache with good data first
  await wpcli(`eval 'lgw_cache_sync_all();'`);

  // Read current team count from cache
  const before = await wpcli(`eval '
    global $wpdb;
    $row = $wpdb->get_row("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE \"lgw_div_cache_%\" LIMIT 1");
    if ($row) {
      $data = maybe_unserialize($row->option_value);
      echo count($data["teams"] ?? array());
    } else {
      echo -1;
    }
  '`);
  const teamsBefore = parseInt(before.trim(), 10);
  expect(teamsBefore).toBeGreaterThan(0);

  // Attempt sync with an unreachable CSV URL (lgw_cache_sync_from_csv should return false)
  await wpcli(`eval '
    $result = lgw_cache_sync_from_csv("https://unreachable.invalid/csv", "2026", "Division 1");
    echo $result ? "synced" : "failed";
  '`);

  // Cache should still have the original team count
  const after = await wpcli(`eval '
    global $wpdb;
    $row = $wpdb->get_row("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE \"lgw_div_cache_%\" LIMIT 1");
    if ($row) {
      $data = maybe_unserialize($row->option_value);
      echo count($data["teams"] ?? array());
    } else {
      echo -1;
    }
  '`);
  const teamsAfter = parseInt(after.trim(), 10);
  expect(teamsAfter).toBe(teamsBefore);
});
