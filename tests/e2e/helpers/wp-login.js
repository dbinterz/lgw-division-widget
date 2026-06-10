// tests/e2e/helpers/wp-login.js
// Reusable WordPress admin login helper + test data seeding for LGW tests.
//
// Assumes the local Docker WP site is running at LGW_BASE_URL (default http://localhost:8080).
// Credentials default to admin/password — override via env vars WP_USER / WP_PASS.

'use strict';

const { exec } = require('child_process');
const { promisify } = require('util');
const execAsync = promisify(exec);

// ── Constants ─────────────────────────────────────────────────────────────────

const BASE_URL       = process.env.LGW_BASE_URL    || 'http://localhost:8080';
const WP_USER        = process.env.WP_USER         || 'admin';
const WP_PASS        = process.env.WP_PASS         || 'password';
const CONTAINER_NAME = process.env.WP_CONTAINER    || 'nipgl-local_wordpress_1';
const WP_CLI_BASE    = `docker exec ${CONTAINER_NAME} wp --allow-root`;

// SHA-256 hashes of test passphrases for deterministic seeding.
// "test-pass-a" → sha256, "test-pass-b" → sha256
// Pre-computed to avoid exec() dependency in test files.
const TEST_PASSPHRASE_A_RAW  = 'test-pass-a';
const TEST_PASSPHRASE_B_RAW  = 'test-pass-b';

/**
 * Log in to WP admin via the browser.
 * Call this in beforeAll() for any test that needs an authenticated session.
 *
 * @param {import('@playwright/test').Page} page
 */
async function wpAdminLogin(page) {
  await page.goto(`${BASE_URL}/wp-login.php`);
  await page.fill('#user_login', WP_USER);
  await page.fill('#user_pass',  WP_PASS);
  await page.click('#wp-submit');
  await page.waitForURL('**/wp-admin/**');
}

/**
 * Run an arbitrary WP-CLI command inside the Docker container.
 * Returns stdout as a string (trimmed).
 *
 * @param {string} cmd  — everything after `wp --allow-root`
 * @returns {Promise<string>}
 */
async function wpcli(cmd) {
  const { stdout, stderr } = await execAsync(`${WP_CLI_BASE} ${cmd}`);
  if (stderr && stderr.trim()) {
    // WP-CLI writes non-fatal notices to stderr; log but don't throw
    console.warn('[wp-login helper] WP-CLI stderr:', stderr.trim());
  }
  return stdout.trim();
}

/**
 * Seed two test clubs ("Test Club A" and "Test Club B") with known passphrases
 * into lgw_clubs WP option. Idempotent — safe to call in beforeAll().
 *
 * Clubs are seeded via the lgw_seed_test_clubs() PHP helper (registered only
 * when WP_ENVIRONMENT_TYPE === 'local').
 */
async function seedTestClubs() {
  await wpcli(`eval 'lgw_seed_test_clubs();'`);
}

/**
 * Delete all lgw_scorecard posts. Used to reset scorecard state between test
 * file runs so results from one spec don't bleed into another.
 * Safe when no scorecards exist.
 */
async function deleteAllScorecards() {
  // Get IDs first; skip delete entirely if there are none
  const ids = await wpcli(`post list --post_type=lgw_scorecard --format=ids 2>/dev/null || true`);
  if (ids && ids.trim()) {
    await wpcli(`post delete ${ids.trim()} --force 2>/dev/null || true`);
  }
}

/**
 * Invalidate all lgw_div_cache_* options, forcing the XHR fallback path on
 * next page load. Useful to set up cache-empty test states.
 */
async function clearDivisionCache() {
  await wpcli(`eval 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \'lgw_div_cache_%\'");'`);
}

/**
 * Set a single WP option via WP-CLI.
 *
 * @param {string} key
 * @param {string} value  — JSON-encoded or plain string
 */
async function setWpOption(key, value) {
  // Use wp option update for strings; update adds if absent
  const escaped = value.replace(/'/g, "'\\''");
  await wpcli(`option update ${key} '${escaped}'`);
}

/**
 * Get a WP option value as a string via WP-CLI.
 *
 * @param {string} key
 * @returns {Promise<string>}
 */
async function getWpOption(key) {
  return wpcli(`option get ${key}`);
}

/**
 * Full reset: delete scorecards + clear division cache.
 * Suitable for beforeAll() in most test files.
 */
async function resetTestState() {
  await deleteAllScorecards();
  await clearDivisionCache();
}

module.exports = {
  wpAdminLogin,
  wpcli,
  seedTestClubs,
  deleteAllScorecards,
  clearDivisionCache,
  setWpOption,
  getWpOption,
  resetTestState,
  TEST_PASSPHRASE_A_RAW,
  TEST_PASSPHRASE_B_RAW,
};
