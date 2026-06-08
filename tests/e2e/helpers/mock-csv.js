// tests/e2e/helpers/mock-csv.js
// CSV interception helper for LGW E2E tests.
//
// Uses Playwright's page.route() to intercept outbound XHR requests to the
// lgw_csv AJAX proxy and serve a local fixture CSV file instead.
//
// This avoids real Google Sheets requests in CI and makes tests deterministic.
//
// Usage:
//   const { mockCsv, unmockCsv } = require('./mock-csv');
//   await mockCsv(page, 'div1-standard.csv');
//   // ... navigate and test
//   await unmockCsv(page);

'use strict';

const fs   = require('fs');
const path = require('path');

const FIXTURES_DIR = path.join(__dirname, '..', 'fixtures');

// The AJAX proxy URL pattern used by lgw-widget.js
// Matches ?action=lgw_csv regardless of the ?url= value
const LGW_CSV_PATTERN = /action=lgw_csv/;

/**
 * Intercept all lgw_csv AJAX requests and respond with the contents of
 * `fixtureFile` from tests/e2e/fixtures/.
 *
 * Call BEFORE page.goto(). The route persists for the lifetime of the page
 * unless unmockCsv() is called.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}  fixtureFile  — filename in tests/e2e/fixtures/ e.g. 'div1-standard.csv'
 * @param {object}  [opts]
 * @param {number}  [opts.status=200]   — HTTP status to return
 * @param {boolean} [opts.abort=false]  — abort request instead of fulfilling (simulate network error)
 */
async function mockCsv(page, fixtureFile, opts = {}) {
  const { status = 200, abort = false } = opts;

  let body = '';
  if (!abort) {
    const filePath = path.join(FIXTURES_DIR, fixtureFile);
    if (!fs.existsSync(filePath)) {
      throw new Error(`[mock-csv] Fixture file not found: ${filePath}`);
    }
    body = fs.readFileSync(filePath, 'utf8');
  }

  await page.route(LGW_CSV_PATTERN, route => {
    if (abort) {
      route.abort('failed');
      return;
    }
    route.fulfill({
      status,
      contentType: 'text/plain; charset=utf-8',
      body,
    });
  });
}

/**
 * Remove the CSV mock route from the page, restoring real network behaviour.
 *
 * @param {import('@playwright/test').Page} page
 */
async function unmockCsv(page) {
  await page.unroute(LGW_CSV_PATTERN);
}

module.exports = { mockCsv, unmockCsv, FIXTURES_DIR };
