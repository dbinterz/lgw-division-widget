// tests/e2e/helpers/fixtures.js
// Test data factory for LGW E2E tests.
//
// Provides helpers to build scorecard POST payloads, fixture row selectors,
// and shared constants used across spec files.

'use strict';

// ── Selectors ─────────────────────────────────────────────────────────────────

/** LGW widget root selector — covers all [lgw_division] instances on a page */
const WIDGET_SEL        = '.lgw-w';
const TABLE_BODY_SEL    = '.lgw-table-body';
const TABLE_ROW_SEL     = '.lgw-table-body tr';
const FIXTURES_PANEL    = '[data-panel="fixtures"]';
const TABLE_PANEL       = '[data-panel="table"]';
const FIXTURE_ROW_SEL   = '.fx-row';
const PLAYED_ROW_SEL    = '.fx-row.played';
const UNPLAYED_ROW_SEL  = '.fx-row:not(.played)';
const FILTER_BAR_SEL    = '.lgw-filter-bar';
const FILTER_BTN_SEL    = '.lgw-filter-btn';
const TAB_FIXTURES_SEL  = '.lgw-tab[data-tab="fixtures"]';
const TAB_TABLE_SEL     = '.lgw-tab[data-tab="table"]';

// Scorecard modal / submission selectors
const MODAL_SEL         = '.lgw-modal, #lgw-sc-modal';
const LOGIN_FORM_SEL    = '.lgw-login-form, #lgw-login-wrap';
const PASSPHRASE_INPUT  = 'input[name="lgw_passphrase"], input[type="password"]';
const SC_SUBMIT_BTN     = '#lgw-sc-submit-btn, button[name="lgw_submit_sc"]';

// Cache health panel (admin settings)
const CACHE_PANEL_SEL   = '#lgw-cache-panel, .lgw-cache-health';
const SYNC_ALL_BTN      = '#lgw-sync-all-btn, .lgw-sync-all';

// ── URLs ──────────────────────────────────────────────────────────────────────

/** Default test page URL — must exist on the local WP site with [lgw_division] */
const TEST_PAGE_URL     = process.env.LGW_TEST_PAGE || '/test-division/';
const ADMIN_SETTINGS    = '/wp-admin/admin.php?page=lgw-settings';
const ADMIN_SCORECARDS  = '/wp-admin/admin.php?page=lgw-scorecards';

// ── Test club data ─────────────────────────────────────────────────────────────

const CLUB_A = { name: 'Test Club A', passphrase: 'test-pass-a' };
const CLUB_B = { name: 'Test Club B', passphrase: 'test-pass-b' };

// ── CSV fixture filenames (in tests/e2e/fixtures/) ────────────────────────────

const CSV = {
  STANDARD:       'div1-standard.csv',
  ALL_UNPLAYED:   'div1-all-unplayed.csv',
  SINGLE_TEAM:    'div1-single-team.csv',
  EMPTY:          'div1-empty.csv',
};

// ── Helper: wait for LGW widget to be ready ───────────────────────────────────

/**
 * Wait for the LGW widget to finish loading — either SSR path (table body already
 * present on page load) or XHR path (table body injected after AJAX response).
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} [timeout=10000]
 */
async function waitForWidget(page, timeout = 10000) {
  await page.waitForSelector(TABLE_BODY_SEL, { timeout });
}

/**
 * Click the "Fixtures & Results" tab and wait for fixture rows.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openFixturesTab(page) {
  const tab = page.locator(TAB_FIXTURES_SEL);
  if (await tab.isVisible()) await tab.click();
  await page.waitForSelector(FIXTURE_ROW_SEL, { timeout: 8000 });
}

/**
 * Enter club passphrase in the login gate and submit.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} passphrase
 */
async function submitPassphrase(page, passphrase) {
  await page.waitForSelector(PASSPHRASE_INPUT, { timeout: 8000 });
  await page.fill(PASSPHRASE_INPUT, passphrase);
  const submitBtn = page.locator('button[type="submit"], input[type="submit"]').first();
  await submitBtn.click();
}

module.exports = {
  // Selectors
  WIDGET_SEL,
  TABLE_BODY_SEL,
  TABLE_ROW_SEL,
  FIXTURES_PANEL,
  TABLE_PANEL,
  FIXTURE_ROW_SEL,
  PLAYED_ROW_SEL,
  UNPLAYED_ROW_SEL,
  FILTER_BAR_SEL,
  FILTER_BTN_SEL,
  TAB_FIXTURES_SEL,
  TAB_TABLE_SEL,
  MODAL_SEL,
  LOGIN_FORM_SEL,
  PASSPHRASE_INPUT,
  SC_SUBMIT_BTN,
  CACHE_PANEL_SEL,
  SYNC_ALL_BTN,
  // URLs
  TEST_PAGE_URL,
  ADMIN_SETTINGS,
  ADMIN_SCORECARDS,
  // Data
  CLUB_A,
  CLUB_B,
  CSV,
  // Helpers
  waitForWidget,
  openFixturesTab,
  submitPassphrase,
};
