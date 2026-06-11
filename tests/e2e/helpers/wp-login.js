// tests/e2e/helpers/wp-login.js
'use strict';

const { exec, execSync } = require('child_process');
const { promisify } = require('util');
const path = require('path');
const fs   = require('fs');
const execAsync = promisify(exec);

const BASE_URL       = process.env.LGW_BASE_URL    || 'http://localhost:8080';
const WP_USER        = process.env.WP_USER         || 'admin';
const WP_PASS        = process.env.WP_PASS         || 'password';
const CONTAINER_NAME = process.env.WP_CONTAINER    || 'lgw-local_wordpress_1';
const WP_CLI_BASE    = `docker exec ${CONTAINER_NAME} wp --allow-root`;

const TEST_PASSPHRASE_A_RAW = 'test-pass-a';
const TEST_PASSPHRASE_B_RAW = 'test-pass-b';

// Division name used in the test page shortcode and cache seed.
// Seeded under the ACTIVE season so lgw_cache_render_division finds it.
const TEST_DIVISION  = 'Test Division';
// Dummy CSV URL — never fetched; mock-csv.js intercepts action=lgw_csv regardless of url param.
const TEST_CSV_URL   = 'https://docs.google.com/spreadsheets/d/TEST_FIXTURE/export?format=csv';

async function wpAdminLogin(page) {
  await page.goto(`${BASE_URL}/wp-login.php`);
  await page.fill('#user_login', WP_USER);
  await page.fill('#user_pass',  WP_PASS);
  await page.click('#wp-submit');
  await page.waitForURL('**/wp-admin/**');
}

async function wpcli(cmd) {
  const { stdout, stderr } = await execAsync(`${WP_CLI_BASE} ${cmd}`);
  if (stderr && stderr.trim()) {
    console.warn('[wp-login helper] WP-CLI stderr:', stderr.trim());
  }
  return stdout.trim();
}

async function seedTestClubs() {
  await wpcli(`eval 'lgw_seed_test_clubs();'`);
}

async function deleteAllScorecards() {
  const ids = await wpcli(`post list --post_type=lgw_scorecard --format=ids 2>/dev/null || true`);
  if (ids && ids.trim()) {
    await wpcli(`post delete ${ids.trim()} --force 2>/dev/null || true`);
  }
}

/**
 * Copy a fixture CSV into the container and seed the "Test Division" cache
 * under the ACTIVE season. Uses lgw_cache_parse_teams + lgw_cache_parse_fixtures
 * directly — no HTTP fetch needed.
 *
 * @param {string} fixtureFile — filename in tests/e2e/fixtures/
 */
async function seedCacheFromFixture(fixtureFile = 'div1-standard.csv') {
  const csvPath = path.join(__dirname, '..', 'fixtures', fixtureFile);
  execSync(`docker cp ${csvPath} ${CONTAINER_NAME}:/tmp/lgw-test-fixture.csv`);

  // Get the active season ID at runtime
  const seasonId = await wpcli(`eval 'echo lgw_get_active_season_id();'`);
  if (!seasonId || !seasonId.trim()) {
    throw new Error('seedCacheFromFixture: no active season ID found');
  }

  const result = await wpcli(`eval '
    $csv = file_get_contents("/tmp/lgw-test-fixture.csv");
    if (!$csv) { echo "ERR:read"; return; }
    $teams    = lgw_cache_parse_teams($csv);
    $fixtures = lgw_cache_parse_fixtures($csv);
    $data = array(
      "teams"    => $teams,
      "fixtures" => $fixtures,
      "csv_url"  => "${TEST_CSV_URL}",
    );
    lgw_cache_set_division("${seasonId.trim()}", "Test Division", $data);
    echo "ok:" . count($teams) . "t," . count($fixtures) . "f";
  '`);

  if (!result || result.startsWith('ERR')) {
    throw new Error(`seedCacheFromFixture failed: ${result}`);
  }
  console.log(`[seedCacheFromFixture] ${result} (season: ${seasonId.trim()})`);
}

/**
 * Seed the complete test environment. Idempotent — safe in beforeAll().
 *  1. Test clubs with known passphrases
 *  2. "Test Division" entry added to the active season's divisions list
 *  3. Test page shortcode updated to [lgw_division csv="TEST_CSV_URL" title="Test Division" ...]
 *  4. Cache primed from div1-standard.csv under the active season
 */
async function seedTestEnvironment() {
  // 1. Test clubs
  await seedTestClubs();

  // 2. Add "Test Division" to the active season's division list (idempotent)
  await wpcli(`eval '
    $seasons  = get_option("lgw_seasons", array());
    $active   = lgw_get_active_season_id();
    foreach ($seasons as &$s) {
      if (($s["id"] ?? "") !== $active) continue;
      $divs = $s["divisions"] ?? array();
      // Remove any existing Test Division entry
      $divs = array_values(array_filter($divs, function($d){
        return trim($d["division"] ?? "") !== "Test Division";
      }));
      $divs[] = array("division" => "Test Division", "csv_url" => "${TEST_CSV_URL}");
      $s["divisions"] = $divs;
      break;
    }
    unset($s);
    update_option("lgw_seasons", $seasons);
    echo "ok";
  '`);

  // 3. Ensure test page has shortcode with csv attr
  const pageId = await wpcli(`post list --post_type=page --post_name=test-division --format=ids`);
  const id = pageId ? pageId.trim().split(/\s+/)[0] : '';
  const shortcode = `[lgw_division csv="${TEST_CSV_URL}" title="Test Division" promote="1" relegate="1" max_points="7"]`;
  if (id) {
    await wpcli(`post update ${id} --post_content='${shortcode}'`);
  } else {
    await wpcli(`post create --post_type=page --post_title="Test Division" --post_name=test-division --post_status=publish --post_content='${shortcode}'`);
    await wpcli(`rewrite flush`);
  }

  // 4. Prime cache from standard fixture
  await seedCacheFromFixture('div1-standard.csv');
}

async function clearDivisionCache() {
  const keys = await wpcli(`option list --search="lgw_div_cache_*" --field=option_name --format=csv 2>/dev/null || true`);
  if (!keys || !keys.trim()) return;
  const names = keys.trim().split('\n').filter(k => k.startsWith('lgw_div_cache_'));
  if (names.length === 0) return;
  await wpcli(`option delete ${names.join(' ')}`);
}

// Clear only the test division cache entry (leaves live division caches intact)
async function clearTestDivisionCache() {
  const seasonId = await wpcli(`eval 'echo lgw_get_active_season_id();'`);
  if (!seasonId || !seasonId.trim()) return;
  const key = `lgw_div_cache_${seasonId.trim()}_testdivision`;
  await wpcli(`option delete ${key} 2>/dev/null || true`);
}

async function setWpOption(key, value) {
  const escaped = value.replace(/'/g, "'\\''");
  await wpcli(`option update ${key} '${escaped}'`);
}

async function getWpOption(key) {
  return wpcli(`option get ${key}`);
}

async function resetTestState() {
  await deleteAllScorecards();
  await seedCacheFromFixture('div1-standard.csv');
}

module.exports = {
  wpAdminLogin,
  wpcli,
  seedTestClubs,
  seedTestEnvironment,
  seedCacheFromFixture,
  clearTestDivisionCache,
  deleteAllScorecards,
  clearDivisionCache,
  setWpOption,
  getWpOption,
  resetTestState,
  TEST_PASSPHRASE_A_RAW,
  TEST_PASSPHRASE_B_RAW,
  TEST_DIVISION,
  TEST_CSV_URL,
};
