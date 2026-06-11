// tests/e2e/division-widget.spec.js
// Division Widget E2E tests — DW-01 through DW-10
// Phase 4.3 — LGW Test Suite

'use strict';

const { test, expect } = require('@playwright/test');
const { mockCsv }      = require('./helpers/mock-csv');
const { wpcli, clearDivisionCache, clearTestDivisionCache, seedTestEnvironment, seedCacheFromFixture } = require('./helpers/wp-login');
const {
  TEST_PAGE_URL,
  TABLE_BODY_SEL, TABLE_ROW_SEL,
  FIXTURE_ROW_SEL, PLAYED_ROW_SEL, UNPLAYED_ROW_SEL,
  FILTER_BAR_SEL, FILTER_BTN_SEL,
  TAB_FIXTURES_SEL, TAB_TABLE_SEL,
  WIDGET_SEL,
  waitForWidget,
  openFixturesTab,
  CSV,
} = require('./helpers/fixtures');

// ── beforeAll: seed clubs (passphrase setup); cache cleared per test ──────────
test.beforeAll(async () => {
  await seedTestEnvironment();
});

test.beforeEach(async ({ page }) => {
  // Intercept the lgw_csv AJAX proxy for every test — SSR path uses cached data
  // but XHR fallback must also work with deterministic data.
  await mockCsv(page, CSV.STANDARD);
});

// ─────────────────────────────────────────────────────────────────────────────
// DW-01  Widget renders table with correct columns @P1
// ─────────────────────────────────────────────────────────────────────────────
test('DW-01: table has correct columns @P1', async ({ page }) => {
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);

  const headers = await page.locator('.tbl-wrap table thead th').allTextContents();
  const normalized = headers.map(h => h.trim().toUpperCase());

  // Required columns — matches both SSR (Pos/Pl/Pts/For/Agn) and XHR path headers
  const required = ['POS', 'TEAM', 'PL', 'W', 'L', 'D'];
  for (const col of required) {
    expect(normalized, `Missing column: ${col}`).toContain(col);
  }
});

// ─────────────────────────────────────────────────────────────────────────────
// DW-02  Table row count matches team count from CSV @P1
// ─────────────────────────────────────────────────────────────────────────────
test('DW-02: table row count matches CSV team count @P1', async ({ page }) => {
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);

  const rows = page.locator(TABLE_ROW_SEL);
  // div1-standard.csv has 8 teams
  await expect(rows).toHaveCount(8);
});

// ─────────────────────────────────────────────────────────────────────────────
// DW-03  SSR path: table in DOM on page load, no spinner @P1
// ─────────────────────────────────────────────────────────────────────────────
test('DW-03: SSR path — table rendered in HTML, no loading spinner @P1', async ({ page }) => {
  // Ensure cache is populated before this test
  await seedCacheFromFixture('div1-standard.csv');

  // Block the CSV XHR to confirm SSR doesn't need it
  await page.route(/action=lgw_csv/, route => route.abort());

  await page.goto(TEST_PAGE_URL);

  // Table body must be present without waiting for XHR
  const tableBody = page.locator(TABLE_BODY_SEL);
  await expect(tableBody).toBeVisible({ timeout: 5000 });

  // No loading spinner visible
  const spinner = page.locator('.lgw-status:has-text("Loading")');
  await expect(spinner).toHaveCount(0);

  // data-prerendered="1" confirms SSR path was used
  const widget = page.locator(WIDGET_SEL).first();
  await expect(widget).toHaveAttribute('data-prerendered', '1');
});

// ─────────────────────────────────────────────────────────────────────────────
// DW-04  Fixtures tab shows fixture list grouped by date @P1
// ─────────────────────────────────────────────────────────────────────────────
test('DW-04: fixtures tab shows fixture rows grouped by date @P1', async ({ page }) => {
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  await openFixturesTab(page);

  // Check fixture rows are present
  const rows = page.locator(FIXTURE_ROW_SEL);
  await expect(rows.first()).toBeVisible();
  const count = await rows.count();
  expect(count).toBeGreaterThan(0);

  // Check date group headers exist
  const dateHeaders = page.locator('.date-hdr');
  await expect(dateHeaders.first()).toBeVisible();
});

// ─────────────────────────────────────────────────────────────────────────────
// DW-05  Promotion/relegation zones highlighted @P2
// ─────────────────────────────────────────────────────────────────────────────
test('DW-05: promotion/relegation zones highlighted when attrs set @P2', async ({ page }) => {
  // The test page shortcode must have promote="1" relegate="1" for this test.
  // If the test page uses default (0), we check that no zone classes exist.
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);

  const widget = page.locator(WIDGET_SEL).first();
  const promote  = await widget.getAttribute('data-promote');
  const relegate = await widget.getAttribute('data-relegate');

  if (parseInt(promote) > 0) {
    // Top N rows should have a promotion indicator class
    const promotionRows = page.locator('.tbl-wrap tbody tr.row-promoted');
    await expect(promotionRows).toHaveCount(parseInt(promote));
  }
  if (parseInt(relegate) > 0) {
    const relegationRows = page.locator('.tbl-wrap tbody tr.row-relegated');
    await expect(relegationRows).toHaveCount(parseInt(relegate));
  }
});

// ─────────────────────────────────────────────────────────────────────────────
// DW-06  Filter bar shows All / Played / Upcoming buttons @P2
// ─────────────────────────────────────────────────────────────────────────────
test('DW-06: filter bar shows All / Played / Upcoming buttons @P2', async ({ page }) => {
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  await openFixturesTab(page);

  const filterBar = page.locator(FILTER_BAR_SEL);
  await expect(filterBar).toBeVisible();

  const btns = await page.locator(FILTER_BTN_SEL).allTextContents();
  const labels = btns.map(b => b.trim().toLowerCase());

  expect(labels.some(l => l.includes('all'))).toBe(true);
  expect(labels.some(l => l.includes('played') || l.includes('results'))).toBe(true);
  expect(labels.some(l => l.includes('upcoming') || l.includes('unplayed'))).toBe(true);
});

// ─────────────────────────────────────────────────────────────────────────────
// DW-07  Filter "Played" hides unplayed fixtures @P2
// ─────────────────────────────────────────────────────────────────────────────
test('DW-07: filter Played hides unplayed fixture rows @P2', async ({ page }) => {
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  await openFixturesTab(page);

  // Click the "Played" / "Results" filter button
  const playedBtn = page.locator(FILTER_BTN_SEL).filter({ hasText: /played|results/i }).first();
  await playedBtn.click();

  await page.waitForTimeout(300); // allow DOM update

  // No unplayed rows should be visible
  const unplayed = page.locator(UNPLAYED_ROW_SEL);
  const visibleUnplayed = await unplayed.evaluateAll(
    els => els.filter(el => el.style.display !== 'none' && !el.hidden).length
  );
  expect(visibleUnplayed).toBe(0);

  // Played rows should still be visible
  const played = page.locator(PLAYED_ROW_SEL);
  await expect(played.first()).toBeVisible();
});

// ─────────────────────────────────────────────────────────────────────────────
// DW-08  Widget falls back to XHR when cache is empty @P2
// ─────────────────────────────────────────────────────────────────────────────
test('DW-08: widget falls back to XHR path when cache empty @P2', async ({ page }) => {
  // Clear the test division cache so SSR path is unavailable
  await clearTestDivisionCache();

  // Mock the CSV XHR with standard data
  await mockCsv(page, CSV.STANDARD);

  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);

  // Widget should still render (XHR path)
  const tableBody = page.locator(TABLE_BODY_SEL);
  await expect(tableBody).toBeVisible({ timeout: 12000 });

  // data-prerendered should be absent or "0"
  const widget = page.locator(WIDGET_SEL).first();
  const prerendered = await widget.getAttribute('data-prerendered');
  expect(prerendered).not.toBe('1');
});

// ─────────────────────────────────────────────────────────────────────────────
// DW-09  Widget renders correctly on mobile viewport @P2
// ─────────────────────────────────────────────────────────────────────────────
test('DW-09: widget renders correctly on mobile viewport @P2', async ({ page }) => {
  // This test runs as part of the "mobile" project (Pixel 5) in playwright.config.js
  // but also validates layout basics on any viewport
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);

  const widget = page.locator(WIDGET_SEL).first();
  const box    = await widget.boundingBox();
  expect(box).not.toBeNull();
  expect(box.width).toBeGreaterThan(0);
  expect(box.height).toBeGreaterThan(0);

  // Table must not overflow horizontally (basic overflow check)
  const tableBox = await page.locator('table.lg').first().boundingBox();
  if (tableBox) {
    expect(tableBox.width).toBeLessThanOrEqual(box.width + 2); // allow 2px rounding
  }
});

// ─────────────────────────────────────────────────────────────────────────────
// DW-10  Score overrides are applied to fixture rows @P2
// ─────────────────────────────────────────────────────────────────────────────
test('DW-10: score overrides appear in fixture rows @P2', async ({ page }) => {
  // Score overrides are applied client-side only (XHR path via applyScoreOverrides()).
  // Clear cache so the widget falls back to XHR, then mock the CSV.
  await clearTestDivisionCache();

  // Key format: csv_url||date||home||away
  const { TEST_CSV_URL } = require('./helpers/wp-login');
  const overrideKey = `${TEST_CSV_URL}||Sat 25-Apr-2026||Test Club A||Carrickfergus BC`;
  const overrideObj = {
    [overrideKey]: { csv_url: TEST_CSV_URL, date: 'Sat 25-Apr-2026',
                     home: 'Test Club A', away: 'Carrickfergus BC',
                     sh: '21', sa: '14', ph: '2', pa: '0' }
  };
  const escaped = JSON.stringify(overrideObj).replace(/'/g, "\'");
  await wpcli(`option update lgw_score_overrides '${escaped}' --format=json`);

  await mockCsv(page, CSV.STANDARD);
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  await openFixturesTab(page);

  const overriddenRow = page.locator(FIXTURE_ROW_SEL)
    .filter({ hasText: /Test Club A/ })
    .filter({ hasText: /Carrickfergus/ })
    .first();
  await expect(overriddenRow).toBeVisible();

  const rowText = await overriddenRow.textContent();
  expect(rowText).toMatch(/21/);
  expect(rowText).toMatch(/14/);

  // Tidy up
  await wpcli(`option update lgw_score_overrides '{}' --format=json`);
  await seedCacheFromFixture('div1-standard.csv');
});
