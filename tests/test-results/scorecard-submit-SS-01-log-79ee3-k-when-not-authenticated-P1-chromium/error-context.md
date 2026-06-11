# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: scorecard-submit.spec.js >> SS-01: login gate appears on fixture click when not authenticated @P1
- Location: e2e/scorecard-submit.spec.js:56:1

# Error details

```
"beforeAll" hook timeout of 30000ms exceeded.
```

# Test source

```ts
  1   | // tests/e2e/scorecard-submit.spec.js
  2   | // Scorecard Submission E2E tests — SS-01 through SS-09
  3   | // Phase 4.3 — LGW Test Suite
  4   | 
  5   | 'use strict';
  6   | 
  7   | const { test, expect } = require('@playwright/test');
  8   | const { mockCsv }      = require('./helpers/mock-csv');
  9   | const {
  10  |   wpAdminLogin,
  11  |   wpcli,
  12  |   seedTestEnvironment,
  13  |   resetTestState,
  14  |   deleteAllScorecards,
  15  |   clearDivisionCache,
  16  |   seedCacheFromFixture,
  17  | } = require('./helpers/wp-login');
  18  | const {
  19  |   TEST_PAGE_URL,
  20  |   ADMIN_SCORECARDS,
  21  |   MODAL_SEL,
  22  |   LOGIN_FORM_SEL,
  23  |   PASSPHRASE_INPUT,
  24  |   FIXTURE_ROW_SEL,
  25  |   UNPLAYED_ROW_SEL,
  26  |   PLAYED_ROW_SEL,
  27  |   WIDGET_SEL,
  28  |   CLUB_A,
  29  |   CLUB_B,
  30  |   CSV,
  31  |   waitForWidget,
  32  |   openFixturesTab,
  33  |   submitPassphrase,
  34  | } = require('./helpers/fixtures');
  35  | 
> 36  | test.beforeAll(async () => {
      |      ^ "beforeAll" hook timeout of 30000ms exceeded.
  37  |   await seedTestEnvironment();
  38  | });
  39  | 
  40  | test.beforeEach(async ({ page }) => {
  41  |   await resetTestState();
  42  |   await mockCsv(page, CSV.STANDARD);
  43  | });
  44  | 
  45  | // Helper: click the first unplayed fixture row involving CLUB_A or CLUB_B
  46  | async function clickUnplayedFixture(page) {
  47  |   await openFixturesTab(page);
  48  |   // div1-standard.csv: Test Club A vs Carrickfergus BC (Sat 25-Apr-2026) is unplayed
  49  |   const row = page.locator(`.fx-row:not(.played)[data-home="${CLUB_A.name}"]`).first();
  50  |   await row.click();
  51  | }
  52  | 
  53  | // ─────────────────────────────────────────────────────────────────────────────
  54  | // SS-01  Login gate appears on fixture click when not authenticated @P1
  55  | // ─────────────────────────────────────────────────────────────────────────────
  56  | test('SS-01: login gate appears on fixture click when not authenticated @P1', async ({ page }) => {
  57  |   await page.goto(TEST_PAGE_URL);
  58  |   await waitForWidget(page);
  59  |   await clickUnplayedFixture(page);
  60  | 
  61  |   // Debug: check what appeared after click
  62  |   const modal = page.locator('.lgw-modal');
  63  |   const modalVis = await modal.isVisible().catch(() => false);
  64  |   const pinGate  = await page.locator('#lgw-pin-gate').isVisible().catch(() => false);
  65  |   const submitForm = await page.locator('#lgw-submit-form').isVisible().catch(() => false);
  66  |   console.log('modal:', modalVis, 'pin-gate:', pinGate, 'submit-form:', submitForm);
  67  |   await page.waitForTimeout(2000); // wait for AJAX
  68  |   const modalFull = await page.locator('.lgw-modal').innerHTML().catch(() => '');
  69  |   console.log('modal-full:', modalFull.substring(0, 600));
  70  |   // Login form / passphrase gate must appear
  71  |   const loginForm = page.locator(LOGIN_FORM_SEL);
  72  |   await expect(loginForm).toBeVisible({ timeout: 5000 });
  73  |   await expect(page.locator(PASSPHRASE_INPUT)).toBeVisible();
  74  | });
  75  | 
  76  | // ─────────────────────────────────────────────────────────────────────────────
  77  | // SS-02  Wrong passphrase shows error @P1
  78  | // ─────────────────────────────────────────────────────────────────────────────
  79  | test('SS-02: wrong passphrase shows error and stays on login screen @P1', async ({ page }) => {
  80  |   await page.goto(TEST_PAGE_URL);
  81  |   await waitForWidget(page);
  82  |   await clickUnplayedFixture(page);
  83  | 
  84  |   await submitPassphrase(page, 'definitely-wrong-passphrase-xyz');
  85  | 
  86  |   // Error message should appear
  87  |   const error = page.locator('#lgw-pin-error').first();
  88  |   await expect(error).toBeVisible({ timeout: 8000 });
  89  | 
  90  |   // Login form should still be visible (not advanced to submission)
  91  |   await expect(page.locator(LOGIN_FORM_SEL)).toBeVisible();
  92  | });
  93  | 
  94  | // ─────────────────────────────────────────────────────────────────────────────
  95  | // SS-03  Correct passphrase unlocks submission for matching fixtures only @P1
  96  | // ─────────────────────────────────────────────────────────────────────────────
  97  | test('SS-03: correct passphrase unlocks submission form for matching fixtures @P1', async ({ page }) => {
  98  |   await page.goto(TEST_PAGE_URL);
  99  |   await waitForWidget(page);
  100 |   await clickUnplayedFixture(page);
  101 | 
  102 |   await submitPassphrase(page, CLUB_A.passphrase);
  103 | 
  104 |   // Submission form should appear (score input fields)
  105 |   const scoreInput = page.locator('#lgw-modal-home').first();
  106 |   await expect(scoreInput).toBeVisible({ timeout: 8000 });
  107 | });
  108 | 
  109 | // ─────────────────────────────────────────────────────────────────────────────
  110 | // SS-04  Scorecard submit creates pending scorecard @P1
  111 | // ─────────────────────────────────────────────────────────────────────────────
  112 | test('SS-04: scorecard submit creates pending scorecard @P1', async ({ page }) => {
  113 |   await page.goto(TEST_PAGE_URL);
  114 |   await waitForWidget(page);
  115 |   await clickUnplayedFixture(page);
  116 |   await submitPassphrase(page, CLUB_A.passphrase);
  117 | 
  118 |   // Fill in scores
  119 |   await page.fill('#lgw-modal-home', '21');
  120 |   await page.fill('#lgw-modal-away', '14');
  121 | 
  122 |   // Fill points if visible (may be auto-calculated)
  123 |   const homePtsInput = page.locator('#lgw-modal-home-pts');
  124 |   if (await homePtsInput.count() > 0) {
  125 |     await homePtsInput.fill('2');
  126 |     await page.fill('#lgw-modal-away-pts', '0');
  127 |   }
  128 | 
  129 |   // Submit
  130 |   const submitBtn = page.locator('#lgw-save-scorecard');
  131 |   await submitBtn.click();
  132 | 
  133 |   // Confirmation message should appear
  134 |   const confirmation = page.locator('#lgw-save-status').first();
  135 |   await expect(confirmation).toBeVisible({ timeout: 10000 });
  136 | 
```