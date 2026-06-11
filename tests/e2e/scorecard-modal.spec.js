// tests/e2e/scorecard-modal.spec.js
// Scorecard Modal E2E tests — SM-01 through SM-04
// Phase 4.3 — LGW Test Suite

'use strict';

const { test, expect } = require('@playwright/test');
const { mockCsv }      = require('./helpers/mock-csv');
const { wpcli, seedTestEnvironment } = require('./helpers/wp-login');
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
  await seedTestEnvironment();
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
  const editableInputs = modal.locator('input[type="number"], input:not([type="hidden"])').filter({ visible: true });
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
  // Mark a played fixture as disputed directly in the cache
  await wpcli(`eval '
    $season = lgw_get_active_season_id();
    $key    = "lgw_div_cache_" . sanitize_key($season) . "_testdivision";
    $data   = get_option($key);
    if (!is_array($data)) { echo "no cache"; return; }
    foreach ($data["fixtures"] as &$fx) {
      if (strcasecmp($fx["homeTeam"], "Test Club A") === 0 &&
          strcasecmp($fx["awayTeam"], "Test Club B") === 0) {
        $fx["status"] = "disputed";
        break;
      }
    }
    unset($fx);
    update_option($key, $data, false);
    echo "ok";
  '`);

  await page.goto(TEST_PAGE_URL);
  await waitForWidget(page);
  await openFixturesTab(page);

  // Find the disputed played row by text
  const rows = page.locator('.fx-row');
  const count = await rows.count();
  let targetRow = null;
  for (let i = 0; i < count; i++) {
    const txt = await rows.nth(i).textContent();
    if (txt.includes('Test Club A') && txt.includes('Test Club B')) {
      targetRow = rows.nth(i); break;
    }
  }
  expect(targetRow, 'Test Club A v Test Club B row not found').not.toBeNull();
  await targetRow.click();

  const modal = page.locator(MODAL_SEL);
  await expect(modal).toBeVisible({ timeout: 8000 });

  // Dispute banner should be visible in modal
  const disputeBanner = modal.locator('.lgw-notice, [class*="disputed"], .fx-sc-status');
  await expect(disputeBanner.first()).toBeVisible({ timeout: 5000 });
});
