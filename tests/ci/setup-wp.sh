#!/bin/bash
# tests/ci/setup-wp.sh
# Bootstrap the CI WordPress environment for LGW Playwright E2E tests.
#
# Run once after WordPress and MySQL containers are healthy.
# Called from the GitHub Actions test.yml workflow before running Playwright.
#
# Prerequisites (provided by docker-compose services in test.yml):
#   - WordPress container reachable at http://localhost:8080
#   - WP-CLI available inside the wordpress container
#   - LGW plugin files copied to /var/www/html/wp-content/plugins/lgw-division-widget/

set -euo pipefail

WP_CLI="docker exec wordpress wp --allow-root"
PLUGIN_DIR="/var/www/html/wp-content/plugins/lgw-division-widget"

echo "⏳ Waiting for WordPress to be ready..."
until curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/wp-login.php | grep -q "200"; do
  sleep 2
done
echo "✅ WordPress is up"

# ── 1. Install WordPress core ─────────────────────────────────────────────────
echo "🔧 Installing WordPress core..."
$WP_CLI core install \
  --url="http://localhost:8080" \
  --title="LGW Test Site" \
  --admin_user="admin" \
  --admin_password="password" \
  --admin_email="test@example.com" \
  --skip-email || echo "Already installed"

# ── 2. Copy plugin into container ─────────────────────────────────────────────
echo "📦 Copying LGW plugin..."
docker cp . wordpress:${PLUGIN_DIR}

# ── 3. Activate plugin ────────────────────────────────────────────────────────
echo "🔌 Activating plugin..."
$WP_CLI plugin activate lgw-division-widget

# ── 4. Set up a test page with the [lgw_division] shortcode ──────────────────
echo "📄 Creating test division page..."

# Check if the test page already exists
PAGE_EXISTS=$($WP_CLI post list --post_type=page --post_name="test-division" --format=ids 2>/dev/null || echo "")
if [ -z "$PAGE_EXISTS" ]; then
  $WP_CLI post create \
    --post_type=page \
    --post_title="Test Division" \
    --post_name="test-division" \
    --post_status=publish \
    --post_content='[lgw_division csv="https://docs.google.com/spreadsheets/d/TEST_SHEET_ID/export?format=csv" title="Division 1" promote="1" relegate="1" max_points="7"]'
  echo "✅ Test page created at /test-division/"
else
  echo "✅ Test page already exists"
fi

# ── 5. Set active season ──────────────────────────────────────────────────────
echo "📅 Setting up test season..."
$WP_CLI eval '
  $season = array(
    "id"        => "2026",
    "label"     => "2026",
    "active"    => true,
    "divisions" => array(
      array(
        "division" => "Division 1",
        "csv_url"  => "https://docs.google.com/spreadsheets/d/TEST_SHEET_ID/export?format=csv",
      )
    ),
  );
  $seasons = get_option("lgw_seasons", array());
  // Remove any existing 2026 entry
  $seasons = array_values(array_filter($seasons, function($s){ return ($s["id"] ?? "") !== "2026"; }));
  $seasons[] = $season;
  update_option("lgw_seasons", $seasons);
  update_option("lgw_active_season", "2026");
  echo "Season 2026 configured";
'

# ── 6. Seed test clubs ────────────────────────────────────────────────────────
echo "🏆 Seeding test clubs..."
$WP_CLI eval 'lgw_seed_test_clubs();'

# ── 7. Configure scorecard submission mode ────────────────────────────────────
$WP_CLI eval '
  $settings = get_option("lgw_general", array());
  $settings["submission_mode"] = "open";
  update_option("lgw_general", $settings);
  echo "Submission mode: open";
'

echo ""
echo "✅ LGW CI environment ready"
echo "   Site URL:    http://localhost:8080"
echo "   Test page:   http://localhost:8080/test-division/"
echo "   Admin:       http://localhost:8080/wp-admin/"
echo "   Credentials: admin / password"
