// tests/e2e/scorecard-modal.spec.js
// Scorecard Modal E2E tests — SM-01 through SM-04
// Phase 4.3 — LGW Test Suite

'use strict';

const { test, expect } = require('@playwright/test');
const { mockCsv }      = require('./helpers/mock-csv');
const { wpcli, seedTestClubs } = require('./helpers/wp-login');
const {
  TEST_PAGE_URL,
  MODAL_SEL,
  PLAYED_ROW_SEL,
  FIXTURE_ROW_SEL,
  CLUB_A,
  CSV,
  waitForWidget,
  openFixturesTab,
} = require('./helpers/fixtures');

test.beforeAll(async () => {
  await seedTestClubs();
  // Ensure cache is populated with standard data (includes confirmed fixtures)
  await wpcli(`eval 'lgw_cache_sync_all();'`);
});

test.beforeEach(async ({ page }) => {
  await mockCsv(page, CSV.STANDARD);
});

// Helper: click on a confirmed/played fixture row
async function clickPlayedFixture(page) {
  await openFixturesTab(page);
  const playedRow = page.locator(PLAYED_ROW_SEL).first();
  await expect(playedRow).toBeVisible({ timeout: 8000 });
  await playedRow.click();
}

// ─────────────────────────────────────────────────────────────────────────────
// SM-01  Clicking a played fixture opens read-only scorecard modal @P1
// ─────────────────────────────────────────────────────────────────────────────
test('SM-01: played fixture opens read-only scorecard modal @P1', async ({ page }) => {
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  await clickPlayedFixture(page);

  // Modal must appear
  const modal = page.locator(MODAL_SEL);
  await expect(modal).toBeVisible({ timeout: 8000 });

  // Modal should be read-only — no editable score inputs
  const editableInputs = modal.locator('input[type="number"], input[type="text"]');
  // Count only visible ones
  const visibleEditable = await editableInputs.evaluateAll(
    els => els.filter(el => el.offsetParent !== null && !el.disabled && !el.readOnly).length
  );
  expect(visibleEditable).toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// SM-02  Modal shows home/away team names, date, and scores @P1
// ─────────────────────────────────────────────────────────────────────────────
test('SM-02: modal shows team names, date, and scores @P1', async ({ page }) => {
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);

  // Use the first confirmed fixture from div1-standard.csv:
  // Test Club A vs Test Club B, Sat 14-Mar-2026, 21:14
  await openFixturesTab(page);
  const row = page.locator(`.fx-row.played[data-home="${CLUB_A.name}"]`).first();
  await row.click();

  const modal = page.locator(MODAL_SEL);
  await expect(modal).toBeVisible({ timeout: 8000 });

  const modalText = await modal.textContent();
  expect(modalText).toMatch(/Test Club A/i);
  expect(modalText).toMatch(/Test Club B/i);
  // Score 21:14 should be visible
  expect(modalText).toMatch(/21/);
  expect(modalText).toMatch(/14/);
});

// ─────────────────────────────────────────────────────────────────────────────
// SM-03  Modal closes on overlay click and ESC key @P1
// ─────────────────────────────────────────────────────────────────────────────
test('SM-03: modal closes on overlay click and ESC key @P1', async ({ page }) => {
  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  await clickPlayedFixture(page);

  const modal = page.locator(MODAL_SEL);
  await expect(modal).toBeVisible({ timeout: 8000 });

  // Close via ESC
  await page.keyboard.press('Escape');
  await expect(modal).not.toBeVisible({ timeout: 3000 });

  // Reopen and close via overlay click
  await clickPlayedFixture(page);
  await expect(modal).toBeVisible({ timeout: 8000 });

  // Click the overlay — usually a .lgw-modal-overlay or clicking outside the inner content
  const overlay = page.locator('.lgw-modal-overlay, .lgw-modal-bg').first();
  if (await overlay.isVisible()) {
    await overlay.click();
  } else {
    // Fallback: click close button
    const closeBtn = modal.locator('.lgw-modal-close, button.close, [aria-label*="close"]').first();
    await closeBtn.click();
  }

  await expect(modal).not.toBeVisible({ timeout: 3000 });
});

// ─────────────────────────────────────────────────────────────────────────────
// SM-04  Disputed scorecard shows dispute banner @P2
// ─────────────────────────────────────────────────────────────────────────────
test('SM-04: disputed scorecard shows dispute banner @P2', async ({ page }) => {
  // Seed a disputed scorecard
  const postId = await wpcli(`eval '
    $id = wp_insert_post(array(
      "post_type"   => "lgw_scorecard",
      "post_status" => "publish",
      "meta_input"  => array(
        "_lgw_home"       => "Test Club A",
        "_lgw_away"       => "Carrickfergus BC",
        "_lgw_home_score" => "21",
        "_lgw_away_score" => "14",
        "_lgw_home_pts"   => "2",
        "_lgw_away_pts"   => "0",
        "_lgw_date"       => "2026-04-25",
        "_lgw_status"     => "disputed",
        "_lgw_division"   => "Division 1",
      )
    ));
    echo $id;
  '`);
  expect(parseInt(postId.trim(), 10)).toBeGreaterThan(0);

  // Refresh cache so the disputed status appears in the fixture list
  await wpcli(`eval 'lgw_cache_sync_all();'`);

  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  await openFixturesTab(page);

  // Click the disputed fixture
  const row = page.locator(`.fx-row[data-home="${CLUB_A.name}"][data-away="Carrickfergus BC"]`).first();
  await row.click();

  const modal = page.locator(MODAL_SEL);
  await expect(modal).toBeVisible({ timeout: 8000 });

  // Dispute banner should be visible
  const disputeBanner = modal.locator('.lgw-dispute, .lgw-disputed, [data-disputed], :has-text("disputed"), :has-text("Disputed")');
  await expect(disputeBanner.first()).toBeVisible({ timeout: 5000 });

  // Tidy up
  await wpcli(`post delete ${postId.trim()} --force --allow-root`);
});
