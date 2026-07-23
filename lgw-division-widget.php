<?php
/**
 * Plugin Name: League Game Widget
 * Description: Mobile-friendly league tables, fixtures, and scorecard submission for bowls leagues. Fetches live data from Google Sheets CSV. Supports per-club passphrase authentication, two-party scorecard confirmation, photo/Excel parsing via AI, player appearance tracking, sponsor branding, and animated cup bracket draws.
 * Version: 2026.30.12
 * Author: dbinterz
 * Plugin URI: https://github.com/dbinterz/lgw-division-widget
 * GitHub Plugin URI: https://github.com/dbinterz/lgw-division-widget
 * Primary Branch: main
 * Release Asset: true
 */

define('LGW_PLUGIN_FILE', __FILE__);
define('LGW_VERSION', '2026.30.12');
define('LGW_SETUP_PAGE', 'lgw-league-setup'); // page slug for League Setup admin page


// ── Admin page logo header helper ────────────────────────────────────────────
function lgw_page_header($title) {
    $logo = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="36" height="36" style="display:block;flex-shrink:0"><polygon points="10,0 20,5 20,15 10,20 0,15 0,5" fill="#072a82"/><polygon points="10,2 18,6 18,14 10,18 2,14 2,6" fill="none" stroke="#138211" stroke-width="1.2"/><text x="10" y="11" font-family="system-ui,sans-serif" font-size="5.5" font-weight="800" fill="#fcfcfc" text-anchor="middle" dominant-baseline="central" letter-spacing="0.3">LGW</text></svg>';
    echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">'
       . $logo
       . '<div>'
       . '<h1 style="margin:0;padding:0;line-height:1.2">' . esc_html($title) . '</h1>'
       . '<p style="margin:0;padding:0;font-size:12px;color:#888">League Game Widget v' . LGW_VERSION . '</p>'
       . '</div>'
       . '</div>';
}

// ── One-time migration: nipgl_* options → lgw_* (originals kept for rollback) ─
function lgw_migrate_options() {
    if ( get_option('lgw_migration_done') ) return;
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'nipgl_%'",
        ARRAY_A
    );
    foreach ( $rows as $row ) {
        $old_key = $row['option_name'];
        $new_key = 'lgw_' . substr( $old_key, strlen('nipgl_') );
        // Copy to lgw_ key — original nipgl_ key intentionally left intact for rollback
        if ( get_option( $new_key ) === false ) {
            $value = get_option( $old_key );
            update_option( $new_key, $value );
        }
    }
    // Copy post meta keys (add lgw_ versions alongside existing nipgl_ ones)
    $wpdb->query(
        "INSERT IGNORE INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
         SELECT post_id, CONCAT('lgw_', SUBSTRING(meta_key, 7)), meta_value
         FROM {$wpdb->postmeta}
         WHERE meta_key LIKE 'nipgl_%'"
    );
    update_option('lgw_migration_done', '7.0.0');
}
add_action('init', 'lgw_migrate_options');

// ── Custom cron intervals for division cache sync ─────────────────────────────
add_filter('cron_schedules', 'lgw_add_cron_intervals');
function lgw_add_cron_intervals($schedules) {
    $schedules['lgw_15min'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes');
    $schedules['lgw_30min'] = array('interval' => 30 * MINUTE_IN_SECONDS, 'display' => 'Every 30 minutes');
    $schedules['lgw_4hour'] = array('interval' =>  4 * HOUR_IN_SECONDS,   'display' => 'Every 4 hours');
    return $schedules;
}

// Include plugin modules — guarded so a missing/broken file cannot bring the whole site down
$lgw_modules = array(
    'lgw-draw.php',
    'lgw-scorecards.php',
    'lgw-cup.php',
    'lgw-champ.php',
    'lgw-gchamp.php',
    'lgw-multichamp.php',
    'lgw-export.php',
    'lgw-players.php',
    'lgw-sc-admin.php',
    'lgw-drive.php',
    'lgw-sheets.php',
    'lgw-calendar.php',
    'lgw-finals.php',
    'lgw-seasons.php',
    'lgw-div-cache.php',
    'lgw-clubs.php',
    'lgw-club-access.php',
);
// Optional modules — in-flight/experimental. Loaded if present, but a missing
// or broken file is silently skipped (no admin notice) so it can't nag users.
$lgw_optional_modules = array(
    'lgw-setup-wizard.php',
);
$lgw_missing = array();
foreach ($lgw_modules as $lgw_module) {
    $lgw_path = plugin_dir_path(__FILE__) . $lgw_module;
    if (!file_exists($lgw_path)) {
        $lgw_missing[] = $lgw_module;
        continue;
    }
    try {
        require_once $lgw_path;
    } catch (\Throwable $e) {
        $lgw_missing[] = $lgw_module . ' (' . $e->getMessage() . ')';
    }
}
foreach ($lgw_optional_modules as $lgw_module) {
    $lgw_path = plugin_dir_path(__FILE__) . $lgw_module;
    if (!file_exists($lgw_path)) {
        continue;
    }
    try {
        require_once $lgw_path;
    } catch (\Throwable $e) {
        // Optional module — swallow load failure, keep core plugin running.
    }
}
if (!empty($lgw_missing)) {
    add_action('admin_notices', function() use ($lgw_missing) {
        echo '<div class="notice notice-error"><p><strong>League Game Widget:</strong> The following modules could not be loaded — some features may be unavailable: <code>'
            . esc_html(implode(', ', $lgw_missing))
            . '</code></p></div>';
    });
}

// ── Auto-updater (checks GitHub releases) ────────────────────────────────────
// ── GitHub API helper — adds auth header if a PAT is configured ──────────────
function lgw_github_request_args() {
    $token = get_option('lgw_github_token', '');
    $headers = array(
        'Accept'     => 'application/vnd.github.v3+json',
        'User-Agent' => 'WordPress/' . get_bloginfo('version'),
    );
    if ($token) $headers['Authorization'] = 'token ' . $token;
    return array('headers' => $headers, 'timeout' => 10);
}

// ── Installed plugin slug ────────────────────────────────────────────────────
// WordPress derives the "View details" slug from the plugin's installed folder
// name, which may differ from the repo name (e.g. the folder is renamed on
// install). Match against the real slug so the info popup resolves instead of
// falling through to wordpress.org ("Plugin not found").
function lgw_plugin_slug() {
    $base = plugin_basename(__FILE__);
    $dir  = dirname($base);
    return ($dir && $dir !== '.') ? $dir : basename($base, '.php');
}

// ── Inject PAT when WP downloads the release zip from GitHub ─────────────────
add_filter('http_request_args', 'lgw_inject_github_auth', 10, 2);
function lgw_inject_github_auth($args, $url) {
    $token = get_option('lgw_github_token', '');
    if (!$token) return $args;
    // Only inject auth for GitHub API and release URLs — NOT CDN redirects
    // Sending Authorization to S3/CDN causes 400/403/404
    $is_github_api = strpos($url, 'api.github.com') !== false
                  || strpos($url, 'github.com/dbinterz/lgw-division-widget') !== false;
    if (!$is_github_api) return $args;
    if (!isset($args['headers'])) $args['headers'] = array();
    $args['headers']['Authorization'] = 'token ' . $token;
    // For asset downloads, tell GitHub to return binary content
    if (strpos($url, '/releases/assets/') !== false) {
        $args['headers']['Accept'] = 'application/octet-stream';
    }
    return $args;
}

add_action('upgrader_process_complete', 'lgw_bust_update_transient', 10, 2);
function lgw_bust_update_transient($upgrader, $options) {
    if ($options['action'] === 'update' && $options['type'] === 'plugin') {
        delete_transient('lgw_github_update');
    }
}

add_filter('pre_set_site_transient_update_plugins', 'lgw_check_for_update');
function lgw_check_for_update($transient) {
    if (empty($transient->checked)) return $transient;

    // Bust cache if the stored release is older than or equal to installed version
    $cached = get_transient('lgw_github_update');
    if ($cached && !empty($cached->tag_name)) {
        $cached_ver = ltrim($cached->tag_name, 'v');
        if (version_compare($cached_ver, LGW_VERSION, '<=')) {
            delete_transient('lgw_github_update');
        }
    }

    $plugin_slug = plugin_basename(__FILE__);
    $current_version = LGW_VERSION;
    $github_user = 'dbinterz';
    $github_repo = 'lgw-division-widget';

    $cache_key = 'lgw_github_update';
    $release = get_transient($cache_key);

    if ($release === false) {
        $response = wp_remote_get(
            "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest",
            lgw_github_request_args()
        );
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return $transient;
        }
        $release = json_decode(wp_remote_retrieve_body($response));
        set_transient($cache_key, $release, HOUR_IN_SECONDS);
    }

    if (empty($release->tag_name)) return $transient;

    $latest_version = ltrim($release->tag_name, 'v');

    if (version_compare($latest_version, $current_version, '>')) {
        // Use GitHub API asset URL which handles auth + redirect correctly for private repos
        $tag        = $release->tag_name;
        $asset_url  = '';
        // Find the zip asset in the release assets list
        if (!empty($release->assets)) {
            foreach ($release->assets as $asset) {
                if (isset($asset->name) && strpos($asset->name, '.zip') !== false) {
                    $asset_url = $asset->url; // API URL: api.github.com/repos/.../releases/assets/{id}
                    break;
                }
            }
        }
        // Fall back to direct URL if no asset found
        if (!$asset_url) {
            $asset_url = "https://github.com/{$github_user}/{$github_repo}/releases/download/{$tag}/{$github_repo}-{$tag}.zip";
        }
        $transient->response[$plugin_slug] = (object) array(
            'slug'        => lgw_plugin_slug(),
            'plugin'      => $plugin_slug,
            'new_version' => $latest_version,
            'url'         => "https://github.com/{$github_user}/{$github_repo}",
            'package'     => $asset_url,
        );
    }

    return $transient;
}

// Show plugin info popup from GitHub release notes
add_filter('plugins_api', 'lgw_plugin_info', 10, 3);
function lgw_plugin_info($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== lgw_plugin_slug()) return $result;

    $github_user = 'dbinterz';
    $github_repo = 'lgw-division-widget';

    $response = wp_remote_get(
        "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest",
        lgw_github_request_args()
    );
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return $result;

    $release = json_decode(wp_remote_retrieve_body($response));
    if (empty($release->tag_name)) return $result;

    $tag       = $release->tag_name;
    $asset_url = '';
    if (!empty($release->assets)) {
        foreach ($release->assets as $asset) {
            if (isset($asset->name) && strpos($asset->name, '.zip') !== false) {
                $asset_url = $asset->url; // API URL: api.github.com/.../releases/assets/{id}
                break;
            }
        }
    }
    if (!$asset_url) {
        $asset_url = "https://github.com/{$github_user}/{$github_repo}/releases/download/{$tag}/{$github_repo}-{$tag}.zip";
    }

    return (object) array(
        'name'          => 'LGW Division Widget',
        'slug'          => lgw_plugin_slug(),
        'version'       => ltrim($release->tag_name, 'v'),
        'author'        => 'LGW',
        'homepage'      => "https://github.com/{$github_user}/{$github_repo}",
        'last_updated'  => isset($release->published_at) ? $release->published_at : '',
        'sections'      => array(
            'description' => 'Mobile-friendly league tables, fixtures, and scorecard submission for bowls leagues.',
            'changelog'   => lgw_format_changelog(isset($release->body) ? $release->body : ''),
        ),
        'download_link' => $asset_url,
    );
}

// Render a GitHub release body as safe HTML for the "View details" popup.
// Bullet lines (•, *, -) become a <ul>; bare URLs are linkified; the first
// non-bullet line is treated as a heading; everything is escaped.
function lgw_format_changelog($body) {
    $body = trim((string) $body);
    if ($body === '') return 'See GitHub releases for changelog.';

    $lines   = preg_split('/\r\n|\r|\n/', $body);
    $html    = '';
    $in_list = false;
    $seen_heading = false;

    $linkify = function ($text) {
        // Escape first, then turn escaped URLs into links.
        $esc = esc_html($text);
        return preg_replace_callback('#https?://[^\s<]+#', function ($m) {
            $url = html_entity_decode($m[0], ENT_QUOTES);
            return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($url) . '</a>';
        }, $esc);
    };

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') { if ($in_list) { $html .= '</ul>'; $in_list = false; } continue; }

        if (preg_match('/^[•*\-]\s+(.*)$/u', $line, $m)) {
            if (!$in_list) { $html .= '<ul style="list-style:disc;margin-left:20px">'; $in_list = true; }
            $html .= '<li>' . $linkify($m[1]) . '</li>';
            continue;
        }

        if ($in_list) { $html .= '</ul>'; $in_list = false; }
        if (!$seen_heading) { $html .= '<h4>' . $linkify($line) . '</h4>'; $seen_heading = true; }
        else               { $html .= '<p>' . $linkify($line) . '</p>'; }
    }
    if ($in_list) $html .= '</ul>';

    return $html;
}

// Check for updates now action
add_action('admin_post_lgw_test_download', 'lgw_test_download');
function lgw_test_download() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('lgw_test_download_nonce');

    $github_user = 'dbinterz';
    $github_repo = 'lgw-division-widget';
    $token       = get_option('lgw_github_token', '');

    // Get latest tag
    $api_response = wp_remote_get(
        "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest",
        lgw_github_request_args()
    );
    $release = !is_wp_error($api_response) ? json_decode(wp_remote_retrieve_body($api_response)) : null;
    $tag     = $release->tag_name ?? 'unknown';
    $zip_url = "https://github.com/{$github_user}/{$github_repo}/releases/download/{$tag}/{$github_repo}-{$tag}.zip";

    $lines = array();
    $lines[] = '<strong>Token set:</strong> ' . ($token ? 'Yes (' . strlen($token) . ' chars)' : '<span style="color:red">NO</span>');
    $lines[] = '<strong>Latest tag:</strong> ' . esc_html($tag);
    $lines[] = '<strong>Direct URL:</strong> ' . esc_html($zip_url);

    // Find asset URL from release
    $asset_url = '';
    $asset_id  = '';
    if (!empty($release->assets)) {
        foreach ($release->assets as $asset) {
            if (isset($asset->name) && strpos($asset->name, '.zip') !== false) {
                $asset_url = $asset->url;
                $asset_id  = $asset->id ?? '';
                break;
            }
        }
    }
    $lines[] = '<strong>API asset URL:</strong> ' . ($asset_url ? esc_html($asset_url) : '<span style="color:red">Not found in release assets</span>');

    // Test 1: Direct URL with auth
    $head = wp_remote_head($zip_url, array(
        'headers'     => $token ? array('Authorization' => 'token ' . $token) : array(),
        'redirection' => 0,
        'sslverify'   => true,
    ));
    $head_code     = is_wp_error($head) ? 'ERROR: ' . $head->get_error_message() : wp_remote_retrieve_response_code($head);
    $head_location = is_wp_error($head) ? '' : wp_remote_retrieve_header($head, 'location');
    $lines[] = '<strong>Direct URL HEAD (with auth):</strong> HTTP ' . esc_html($head_code);
    if ($head_location) $lines[] = '&nbsp;&nbsp;↳ Redirect to: ' . esc_html(substr($head_location, 0, 80)) . '…';

    // Test 2: API asset URL with auth + Accept header
    if ($asset_url) {
        $head2 = wp_remote_head($asset_url, array(
            'headers'     => array(
                'Authorization' => 'token ' . $token,
                'Accept'        => 'application/octet-stream',
            ),
            'redirection' => 5,
            'sslverify'   => true,
        ));
        $head2_code = is_wp_error($head2) ? 'ERROR: ' . $head2->get_error_message() : wp_remote_retrieve_response_code($head2);
        $lines[] = '<strong>API asset URL HEAD (with auth + Accept):</strong> HTTP ' . esc_html($head2_code);
    }

    $output = implode('<br>', $lines);
    $encoded = base64_encode($output);
    wp_redirect(admin_url('admin.php?page=lgw-settings&dl_test=' . urlencode($encoded)));
    exit;
}

add_action('admin_post_lgw_check_updates', 'lgw_check_updates_now');
function lgw_check_updates_now() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('lgw_check_updates_nonce');
    // Clear the cached GitHub release so next check hits the API fresh
    delete_transient('lgw_github_update');
    // Clear WordPress's own plugin update transient so it re-checks immediately
    delete_site_transient('update_plugins');
    // Force WP to re-check right now
    wp_update_plugins();
    wp_redirect(admin_url('admin.php?page=lgw-settings&updated=1'));
    exit;
}
add_action('upgrader_process_complete', 'lgw_clear_update_cache', 10, 2);
function lgw_clear_update_cache($upgrader, $options) {
    if ($options['action'] === 'update' && $options['type'] === 'plugin') {
        delete_transient('lgw_github_update');
    }
}

// ── 1. CSV Proxy ─────────────────────────────────────────────────────────────
add_action('wp_ajax_lgw_csv', 'lgw_csv_proxy');
add_action('wp_ajax_nopriv_lgw_csv', 'lgw_csv_proxy');

function lgw_csv_proxy() {
    $allowed_host = 'docs.google.com';
    $url = isset($_GET['url']) ? esc_url_raw($_GET['url']) : '';
    if (!$url) { wp_die('Missing url', '', array('response' => 400)); }
    $parsed = parse_url($url);
    if (!isset($parsed['host']) || $parsed['host'] !== $allowed_host) {
        wp_die('Forbidden', '', array('response' => 403));
    }

    // Cache key based on the URL
    $cache_key = 'lgw_csv_' . md5($url);
    $cached = get_transient($cache_key);

    if ($cached !== false) {
        // Serve from cache
        header('Content-Type: text/plain; charset=utf-8');
        header('X-LGW-Cache: HIT');
        echo $cached;
        exit;
    }

    $fetch_args = array('timeout' => 10, 'user-agent' => 'Mozilla/5.0');
    $response   = wp_remote_get($url, $fetch_args);

    // Retry once on failure after a short delay
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        sleep(1);
        $response = wp_remote_get($url, $fetch_args);
    }

    if (is_wp_error($response)) {
        // Serve stale cache as fallback if available
        $stale = get_transient('lgw_csv_stale_' . md5($url));
        if ($stale !== false) {
            header('Content-Type: text/plain; charset=utf-8');
            header('X-LGW-Cache: STALE');
            echo $stale;
            exit;
        }
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(array('error' => 'Could not reach Google Sheets. Please try again shortly.'));
        exit;
    }
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        // Serve stale cache as fallback if available
        $stale = get_transient('lgw_csv_stale_' . md5($url));
        if ($stale !== false) {
            header('Content-Type: text/plain; charset=utf-8');
            header('X-LGW-Cache: STALE');
            echo $stale;
            exit;
        }
        header('Content-Type: application/json');
        http_response_code(502);
        echo json_encode(array('error' => 'Google Sheets returned an unexpected response. Please check the sheet is published and the URL is correct.'));
        exit;
    }

    $body = wp_remote_retrieve_body($response);

    // Cache for configured duration (default 5 minutes)
    $cache_mins = intval(get_option('lgw_cache_mins', 5));
    set_transient($cache_key, $body, $cache_mins * MINUTE_IN_SECONDS);
    // Store a longer-lived stale copy as fallback for transient fetch failures (24 hours)
    set_transient('lgw_csv_stale_' . md5($url), $body, DAY_IN_SECONDS);

    header('Content-Type: text/plain; charset=utf-8');
    header('X-LGW-Cache: MISS');
    echo $body;
    exit;
}

// ── Played-dates map: fixture date → actual played date (where different) ────
/**
 * Returns an array keyed by "home||away||fixtureDate" (lowercased home/away)
 * whose value is the actual played date — only when it differs from fixture date.
 * Used by widget JS to annotate fixture rows where the game was rescheduled.
 */
/**
 * Parse a date string in any of the formats used by the plugin and return
 * a Unix timestamp at midnight, or null on failure.
 *
 * Handles:
 *   dd/mm/yyyy          — scorecard submission form
 *   Sat dd-Mon-yyyy     — CSV fixture date (e.g. 'Sat 25-Apr-2026')
 *   dd-Mon-yyyy         — same without day-of-week prefix
 */
function lgw_parse_any_date($str) {
    $str = trim($str);
    if (!$str) return null;

    // dd/mm/yyyy
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $str, $m)) {
        $ts = mktime(0,0,0,(int)$m[2],(int)$m[1],(int)$m[3]);
        return $ts ?: null;
    }

    // Sat 25-Apr-2026  or  25-Apr-2026
    if (preg_match('#(?:^|\s)(\d{1,2})-([A-Za-z]{3})-(\d{4})$#', $str, $m)) {
        $ts = strtotime($m[1] . ' ' . $m[2] . ' ' . $m[3]);
        return ($ts !== false) ? $ts : null;
    }

    // Fallback — let PHP try
    $ts = strtotime($str);
    return ($ts !== false) ? $ts : null;
}

function lgw_build_played_dates_map() {
    try {
        $active_id = function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '';
        $meta_query = array(
            'relation' => 'AND',
            array('key' => 'lgw_sc_context', 'value' => 'league', 'compare' => '='),
        );
        if ($active_id) {
            $meta_query[] = array('key' => 'lgw_sc_season', 'value' => $active_id, 'compare' => '=');
        }
        $posts = get_posts(array(
            'post_type'      => 'lgw_scorecard',
            'posts_per_page' => 500,
            'post_status'    => 'publish',
            'meta_query'     => $meta_query,
        ));
        $map = array();
        foreach ($posts as $p) {
            $sc           = get_post_meta($p->ID, 'lgw_scorecard_data', true);
            $fixture_date = get_post_meta($p->ID, 'lgw_fixture_date',   true);
            if (!$sc || empty($sc['date'])) continue;
            $played_date = $sc['date'];
            $fix_date    = $fixture_date ?: $played_date; // fall back if not stored
            // Normalise both to a timestamp for comparison — handles mixed formats
            // e.g. '25/4/2026' (scorecard) vs 'Sat 25-Apr-2026' (CSV fixture date)
            $played_ts  = lgw_parse_any_date($played_date);
            $fix_ts     = lgw_parse_any_date($fix_date);
            if ($played_ts && $fix_ts && $played_ts === $fix_ts) continue; // same calendar day
            $key = strtolower($sc['home_team'] ?? '') . '||' . strtolower($sc['away_team'] ?? '') . '||' . strtolower($fix_date);
            $map[$key] = $played_date;
        }
        return $map;
    } catch (Exception $e) {
        return array();
    }
}


// ── Option array helper ───────────────────────────────────────────────────────
/**
 * Like get_option() but always returns an array.
 * Handles options stored as a PHP serialised array (normal WP behaviour),
 * a JSON string (legacy/migration artefact), or already an array.
 */
function lgw_get_option_array( $key, $default = array() ) {
    $val = get_option( $key, $default );
    if ( is_array( $val ) ) return $val;
    if ( is_string( $val ) && $val !== '' ) {
        $decoded = json_decode( $val, true );
        if ( is_array( $decoded ) ) return $decoded;
    }
    return $default;
}

// ── Postponements ────────────────────────────────────────────────────────────
/**
 * Returns the postponements map: fixture_key → { rescheduled_for: 'dd/mm/yyyy'|'' }
 * Key format: home||away||fixture_date (lowercase).
 */
function lgw_get_postponements() {
    return lgw_get_option_array('lgw_postponements');
}

function lgw_build_postponements_map() {
    return lgw_get_postponements();
}

// Save or clear a postponement via AJAX
add_action('wp_ajax_lgw_save_postponement', 'lgw_ajax_save_postponement');
function lgw_ajax_save_postponement() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not authorised');
    }
    $home   = strtolower(sanitize_text_field($_POST['home'] ?? ''));
    $away   = strtolower(sanitize_text_field($_POST['away'] ?? ''));
    $date   = strtolower(sanitize_text_field($_POST['date'] ?? ''));
    $action = sanitize_text_field($_POST['postpone_action'] ?? 'set');
    $reschedule = sanitize_text_field($_POST['rescheduled_for'] ?? '');

    if (!$home || !$away || !$date) {
        wp_send_json_error('Missing fixture details');
    }

    $key  = $home . '||' . $away . '||' . $date;
    $map  = lgw_get_postponements();

    if ($action === 'clear') {
        unset($map[$key]);
    } else {
        $map[$key] = array('rescheduled_for' => $reschedule);
    }

    update_option('lgw_postponements', $map);
    wp_send_json_success(array('key' => $key, 'action' => $action));
}

// ── Concessions ──────────────────────────────────────────────────────────────
/**
 * Returns the concessions map: fixture_key → { conceding_team: 'home'|'away', conceded_on: 'YYYY-MM-DD' }
 * fixture_key = strtolower("home||away||date")
 */
function lgw_get_concessions() {
    return lgw_get_option_array('lgw_concessions');
}

// Save or clear a concession via AJAX (admin only)
add_action('wp_ajax_lgw_save_concession', 'lgw_ajax_save_concession');
function lgw_ajax_save_concession() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not authorised');
    }

    // Raw team names (preserve case for scorecard / Sheets lookup)
    $home_raw = sanitize_text_field(wp_unslash($_POST['home'] ?? ''));
    $away_raw = sanitize_text_field(wp_unslash($_POST['away'] ?? ''));
    $date_raw = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
    $action   = sanitize_text_field($_POST['concession_action'] ?? 'set');
    $division = sanitize_text_field(wp_unslash($_POST['division'] ?? ''));
    $conceding_team = sanitize_text_field($_POST['conceding_team'] ?? 'away'); // 'home' or 'away'

    if (!$home_raw || !$away_raw || !$date_raw) {
        wp_send_json_error('Missing fixture details');
    }
    if (!in_array($conceding_team, array('home', 'away'), true)) {
        wp_send_json_error('Invalid conceding_team value');
    }

    // Lowercase key for the overlay map (backwards-compat)
    $key = strtolower($home_raw) . '||' . strtolower($away_raw) . '||' . strtolower($date_raw);
    $map = lgw_get_concessions();

    if ($action === 'clear') {
        // Remove overlay entry
        unset($map[$key]);
        update_option('lgw_concessions', $map);

        // Trash the auto-created concession scorecard if one exists
        $existing = get_posts(array(
            'post_type'  => 'lgw_scorecard',
            'meta_query' => array(
                array('key' => 'lgw_sc_concession', 'value' => '1'),
                array('key' => 'lgw_match_key',     'value' => $key),
            ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ));
        if (!empty($existing)) {
            wp_update_post(array('ID' => $existing[0], 'post_status' => 'trash'));
        }

        // Remove any score overrides for this home/away pair (any date/csv_url)
        $overrides = lgw_get_option_array('lgw_score_overrides');
        $changed   = false;
        foreach ( $overrides as $ok => $ov ) {
            if ( strcasecmp( $ov['home'] ?? '', $home_raw ) === 0
              && strcasecmp( $ov['away'] ?? '', $away_raw ) === 0 ) {
                unset( $overrides[$ok] );
                $changed = true;
            }
        }
        if ( $changed ) update_option( 'lgw_score_overrides', $overrides );

        // Wipe the cached fixture back to unplayed
        if ( function_exists('lgw_cache_wipe_fixture_result') ) {
            $season_id = function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '';
            if ( $season_id && $division ) {
                lgw_cache_wipe_fixture_result( $home_raw, $away_raw, $season_id, $division );
            }
        }

        wp_send_json_success(array('key' => $key, 'action' => 'clear'));
    }

    // ── SET: create/update a confirmed scorecard reflecting the concession ────
    $home_concedes = ($conceding_team === 'home');
    // Max points is per-division (6 for the 12-player division, 7 otherwise). The
    // client sends the division's value; fall back to the global option if absent.
    $posted_max = isset($_POST['max_points']) ? intval($_POST['max_points']) : 0;
    $max_pts    = $posted_max > 0
        ? max(6, min(7, $posted_max))
        : max(6, min(7, intval(get_option('lgw_max_points', 7))));

    $home_shots  = $home_concedes ? 0  : 50;
    $away_shots  = $home_concedes ? 50 : 0;
    $home_points = $home_concedes ? -$max_pts : $max_pts;
    $away_points = $home_concedes ? $max_pts  : -$max_pts;

    // Build a minimal scorecard data array (no individual rinks — concession has none)
    $season_id = function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '';

    $sc = array(
        'home_team'    => $home_raw,
        'away_team'    => $away_raw,
        'date'         => $date_raw,
        'division'     => $division,
        'home_total'   => $home_shots,
        'away_total'   => $away_shots,
        'home_points'  => $home_points,
        'away_points'  => $away_points,
        'rinks'        => array(),
        'concession'   => true,
        'conceding_team' => $conceding_team,
    );

    // Check for an existing concession scorecard for this fixture
    $existing = get_posts(array(
        'post_type'      => 'lgw_scorecard',
        'meta_query'     => array(
            array('key' => 'lgw_sc_concession', 'value' => '1'),
            array('key' => 'lgw_match_key',     'value' => $key),
        ),
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ));

    if (!empty($existing)) {
        $post_id = $existing[0];
        wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
        update_post_meta($post_id, 'lgw_scorecard_data', $sc);
        update_post_meta($post_id, 'lgw_sc_status', 'confirmed');
    } else {
        $title   = $home_raw . ' v ' . $away_raw . ' (' . $date_raw . ') [Concession]';
        $post_id = wp_insert_post(array(
            'post_type'   => 'lgw_scorecard',
            'post_title'  => $title,
            'post_status' => 'publish',
        ));
        if (is_wp_error($post_id)) {
            wp_send_json_error('Could not create concession scorecard: ' . $post_id->get_error_message());
            return;
        }
        update_post_meta($post_id, 'lgw_match_key',     $key);
        update_post_meta($post_id, 'lgw_scorecard_data', $sc);
        update_post_meta($post_id, 'lgw_sc_status',     'confirmed');
        update_post_meta($post_id, 'lgw_sc_context',    'league');
        update_post_meta($post_id, 'lgw_sc_concession', '1'); // flag so we can find/void it
        if ($division) update_post_meta($post_id, 'lgw_sc_division', $division);
        if ($season_id) update_post_meta($post_id, 'lgw_sc_season', $season_id);
        if (!empty($date_raw)) update_post_meta($post_id, 'lgw_fixture_date', $date_raw);
    }

    // Fire the confirmed hook — triggers Sheets writeback + cache merge.
    // Wrapped in try/catch so a Sheets/Drive failure doesn't kill the AJAX response.
    try {
        do_action('lgw_scorecard_confirmed', $post_id);
    } catch (\Throwable $e) {
        error_log('[LGW] lgw_ajax_save_concession: hook failed — ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }

    // Also write the overlay entry (backwards-compat for cache render + pill display)
    $map[$key] = array(
        'conceding_team' => $conceding_team,
        'conceded_on'    => current_time('Y-m-d'),
        'scorecard_id'   => $post_id,
    );
    update_option('lgw_concessions', $map);

    wp_send_json_success(array(
        'key'            => $key,
        'action'         => 'set',
        'conceding_team' => $conceding_team,
        'scorecard_id'   => $post_id,
    ));
}

// ── Null & Void ──────────────────────────────────────────────────────────────
function lgw_get_null_voids() {
    return lgw_get_option_array('lgw_null_voids');
}

add_action('wp_ajax_lgw_save_null_void', 'lgw_ajax_save_null_void');
function lgw_ajax_save_null_void() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not authorised');
    }

    $home_raw = sanitize_text_field(wp_unslash($_POST['home'] ?? ''));
    $away_raw = sanitize_text_field(wp_unslash($_POST['away'] ?? ''));
    $date_raw = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
    $action   = sanitize_text_field($_POST['null_void_action'] ?? 'set');
    $division = sanitize_text_field(wp_unslash($_POST['division'] ?? ''));

    if (!$home_raw || !$away_raw || !$date_raw) {
        wp_send_json_error('Missing fixture details');
    }

    $key = strtolower($home_raw) . '||' . strtolower($away_raw) . '||' . strtolower($date_raw);
    $map = lgw_get_null_voids();

    if ($action === 'clear') {
        unset($map[$key]);
        update_option('lgw_null_voids', $map);
        $existing = get_posts(array(
            'post_type'      => 'lgw_scorecard',
            'meta_query'     => array(
                array('key' => 'lgw_sc_null_void', 'value' => '1'),
                array('key' => 'lgw_match_key',    'value' => $key),
            ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ));
        if (!empty($existing)) {
            wp_update_post(array('ID' => $existing[0], 'post_status' => 'trash'));
        }
        wp_send_json_success(array('key' => $key, 'action' => 'clear'));
        return;
    }

    // SET: create/update a confirmed scorecard with 0–0 result
    $season_id = function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '';
    $sc = array(
        'home_team'   => $home_raw,
        'away_team'   => $away_raw,
        'date'        => $date_raw,
        'division'    => $division,
        'home_total'  => 0,
        'away_total'  => 0,
        'home_points' => 0,
        'away_points' => 0,
        'rinks'       => array(),
        'null_void'   => true,
    );

    $existing = get_posts(array(
        'post_type'      => 'lgw_scorecard',
        'meta_query'     => array(
            array('key' => 'lgw_sc_null_void', 'value' => '1'),
            array('key' => 'lgw_match_key',    'value' => $key),
        ),
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ));

    if (!empty($existing)) {
        $post_id = $existing[0];
        wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
        update_post_meta($post_id, 'lgw_scorecard_data', $sc);
        update_post_meta($post_id, 'lgw_sc_status', 'confirmed');
    } else {
        $title   = $home_raw . ' v ' . $away_raw . ' (' . $date_raw . ') [Null & Void]';
        $post_id = wp_insert_post(array(
            'post_type'   => 'lgw_scorecard',
            'post_title'  => $title,
            'post_status' => 'publish',
        ));
        if (is_wp_error($post_id)) {
            wp_send_json_error('Could not create null & void scorecard: ' . $post_id->get_error_message());
            return;
        }
        update_post_meta($post_id, 'lgw_match_key',      $key);
        update_post_meta($post_id, 'lgw_scorecard_data', $sc);
        update_post_meta($post_id, 'lgw_sc_status',      'confirmed');
        update_post_meta($post_id, 'lgw_sc_context',     'league');
        update_post_meta($post_id, 'lgw_sc_null_void',   '1');
        if ($division)  update_post_meta($post_id, 'lgw_sc_division',    $division);
        if ($season_id) update_post_meta($post_id, 'lgw_sc_season',      $season_id);
        if (!empty($date_raw)) update_post_meta($post_id, 'lgw_fixture_date', $date_raw);
    }

    try {
        do_action('lgw_scorecard_confirmed', $post_id);
    } catch (\Throwable $e) {
        error_log('[LGW] lgw_ajax_save_null_void: hook failed — ' . $e->getMessage()
            . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }

    $map[$key] = array('voided_on' => current_time('Y-m-d'), 'scorecard_id' => $post_id);
    update_option('lgw_null_voids', $map);

    wp_send_json_success(array('key' => $key, 'action' => 'set', 'scorecard_id' => $post_id));
}

// ── Fixture editing (WordPress-authoritative mode only) ──────────────────────
/**
 * Does a fixture have any result or admin overlay tied to its identity key?
 * If so, changing its teams/date would orphan that record, so identity edits
 * are blocked until the result is cleared.
 */
/**
 * Fuzzy date equality for fixture identity: empty on either side is treated as a
 * match (legacy/date-less records), otherwise exact (case-insensitive) or parsed
 * within a day.
 */
function lgw_fixture_dates_match($a, $b) {
    $a = trim((string) $a); $b = trim((string) $b);
    if ($a === '' || $b === '') return true;
    if (strcasecmp($a, $b) === 0) return true;
    if (function_exists('lgw_parse_any_date')) {
        $ta = lgw_parse_any_date($a); $tb = lgw_parse_any_date($b);
        if ($ta && $tb && abs($ta - $tb) <= DAY_IN_SECONDS) return true;
    }
    return false;
}

function lgw_fixture_has_result($home, $away, $date, $division) {
    $key = strtolower($home) . '||' . strtolower($away) . '||' . strtolower($date);

    $concessions = lgw_get_concessions();
    if (isset($concessions[$key])) return true;

    $postponements = lgw_get_option_array('lgw_postponements');
    if (isset($postponements[$key])) return true;

    $null_voids = lgw_get_null_voids();
    if (isset($null_voids[$key])) return true;

    $overrides = lgw_get_option_array('lgw_score_overrides');
    foreach ($overrides as $ov) {
        if (strcasecmp($ov['home'] ?? '', $home) === 0
         && strcasecmp($ov['away'] ?? '', $away) === 0
         && lgw_fixture_dates_match($ov['date'] ?? '', $date)) return true;
    }

    // Confirmed/pending scorecard for THIS fixture (date-scoped so the reverse
    // leg of the same pairing on another date doesn't false-trigger the guard).
    if (function_exists('lgw_get_scorecard')) {
        $sc = lgw_get_scorecard($home, $away, $date, 'league', $division);
        if ($sc) return true;
    }
    return false;
}

add_action('wp_ajax_lgw_edit_fixture', 'lgw_ajax_edit_fixture');
function lgw_ajax_edit_fixture() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Not authorised');
    if (!function_exists('lgw_source_is_wordpress') || !lgw_source_is_wordpress()) {
        wp_send_json_error('Fixture editing is only available in WordPress data-source mode.');
    }

    $season_id = sanitize_text_field(wp_unslash($_POST['season_id'] ?? ''));
    $division  = sanitize_text_field(wp_unslash($_POST['division']  ?? ''));
    if (!$season_id && function_exists('lgw_get_active_season_id')) $season_id = lgw_get_active_season_id();
    $old_home  = trim(sanitize_text_field(wp_unslash($_POST['old_home'] ?? '')));
    $old_away  = trim(sanitize_text_field(wp_unslash($_POST['old_away'] ?? '')));
    $old_date  = trim(sanitize_text_field(wp_unslash($_POST['old_date'] ?? '')));
    $new_home  = trim(sanitize_text_field(wp_unslash($_POST['home'] ?? '')));
    $new_away  = trim(sanitize_text_field(wp_unslash($_POST['away'] ?? '')));
    $new_date  = trim(sanitize_text_field(wp_unslash($_POST['date'] ?? '')));
    $new_time  = trim(sanitize_text_field(wp_unslash($_POST['time'] ?? '')));

    if (!$season_id || !$division) wp_send_json_error('Missing season/division');
    if (!$old_home || !$old_away)  wp_send_json_error('Missing fixture identity');
    if (!$new_home || !$new_away)  wp_send_json_error('Home and away teams are required');

    $data = lgw_cache_get_division($season_id, $division);
    if (!$data || empty($data['fixtures'])) wp_send_json_error('Division fixtures not found in WordPress cache');

    $idx = lgw_find_fixture_index($data['fixtures'], $old_home, $old_away, $old_date);
    if ($idx === -1) wp_send_json_error('Fixture not found — it may have already changed. Reload and try again.');

    // Block identity changes when a result/overlay is attached to the old key.
    $identity_changed = (strcasecmp($new_home, $old_home) !== 0
                      || strcasecmp($new_away, $old_away) !== 0
                      || ($new_date !== '' && strcasecmp($new_date, $old_date) !== 0));
    if ($identity_changed && lgw_fixture_has_result($old_home, $old_away, $old_date, $division)) {
        wp_send_json_error('This fixture has a confirmed result or an admin overlay (concession/postponement/null-void/override). Clear it first, then change the teams or date.');
    }

    // Lazy baseline capture — snapshot the pristine fixtures before this first edit.
    lgw_fixture_snapshot_baseline($season_id, $division, $data['fixtures']);

    $before = $data['fixtures'][$idx];
    $fx     = $before;

    // A pure swap (teams transposed) also transposes the scores/points.
    $is_swap = (strcasecmp($new_home, $old_away) === 0 && strcasecmp($new_away, $old_home) === 0);
    if ($is_swap) {
        $fx['homeTeam'] = $new_home;
        $fx['awayTeam'] = $new_away;
        $t = $fx['shotsHome']; $fx['shotsHome'] = $fx['shotsAway']; $fx['shotsAway'] = $t;
        $t = $fx['ptsHome'];   $fx['ptsHome']   = $fx['ptsAway'];   $fx['ptsAway']   = $t;
    } else {
        $fx['homeTeam'] = $new_home;
        $fx['awayTeam'] = $new_away;
    }
    if ($new_date !== '') $fx['date'] = $new_date;
    $fx['timeNote'] = $new_time;

    $data['fixtures'][$idx] = $fx;
    lgw_cache_set_division($season_id, $division, $data);
    lgw_fixture_log($is_swap ? 'swap' : 'edit', $season_id, $division, $before, $fx);

    wp_send_json_success(array('fixture' => $fx, 'index' => $idx));
}

add_action('wp_ajax_lgw_revert_fixture', 'lgw_ajax_revert_fixture');
function lgw_ajax_revert_fixture() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Not authorised');
    if (!function_exists('lgw_source_is_wordpress') || !lgw_source_is_wordpress()) {
        wp_send_json_error('Fixture editing is only available in WordPress data-source mode.');
    }

    $season_id = sanitize_text_field(wp_unslash($_POST['season_id'] ?? ''));
    $division  = sanitize_text_field(wp_unslash($_POST['division']  ?? ''));
    if (!$season_id && function_exists('lgw_get_active_season_id')) $season_id = lgw_get_active_season_id();
    $home      = trim(sanitize_text_field(wp_unslash($_POST['home'] ?? '')));
    $away      = trim(sanitize_text_field(wp_unslash($_POST['away'] ?? '')));
    $date      = trim(sanitize_text_field(wp_unslash($_POST['date'] ?? '')));

    if (!$season_id || !$division || !$home || !$away) wp_send_json_error('Missing fixture identity');

    $data = lgw_cache_get_division($season_id, $division);
    if (!$data || empty($data['fixtures'])) wp_send_json_error('Division fixtures not found');

    $idx = lgw_find_fixture_index($data['fixtures'], $home, $away, $date);
    if ($idx === -1) wp_send_json_error('Fixture not found');

    // Newest log entry whose "after" identity matches the current fixture.
    $log = get_option('lgw_fixture_edit_log', array());
    $found = null;
    if (is_array($log)) {
        foreach ($log as $entry) {
            if (($entry['season'] ?? '') !== $season_id || ($entry['division'] ?? '') !== $division) continue;
            $a = $entry['after'] ?? array();
            if (strcasecmp($a['home'] ?? '', $home) === 0
             && strcasecmp($a['away'] ?? '', $away) === 0
             && strcasecmp($a['date'] ?? '', $date) === 0) { $found = $entry; break; }
        }
    }
    if (!$found) wp_send_json_error('No edit history for this fixture to undo.');

    $b = $found['before'];
    // Reverting to a different identity is subject to the same result guard.
    $identity_changed = (strcasecmp($b['home'], $home) !== 0
                      || strcasecmp($b['away'], $away) !== 0
                      || strcasecmp($b['date'], $date) !== 0);
    if ($identity_changed && lgw_fixture_has_result($home, $away, $date, $division)) {
        wp_send_json_error('This fixture now has a result/overlay — clear it before undoing an identity change.');
    }

    $current = $data['fixtures'][$idx];
    $fx = $current;
    $fx['homeTeam']  = $b['home'];
    $fx['awayTeam']  = $b['away'];
    $fx['date']      = $b['date'];
    $fx['timeNote']  = $b['time'];
    $fx['shotsHome'] = $b['shotsHome'];
    $fx['shotsAway'] = $b['shotsAway'];
    $fx['ptsHome']   = $b['ptsHome'];
    $fx['ptsAway']   = $b['ptsAway'];

    $data['fixtures'][$idx] = $fx;
    lgw_cache_set_division($season_id, $division, $data);
    lgw_fixture_log('revert', $season_id, $division, $current, $fx);

    wp_send_json_success(array('fixture' => $fx, 'index' => $idx));
}

// ── Clear a scorecard attached to a fixture (admin) ──────────────────────────
// Trashes the scorecard post for a specific fixture, removes any score override
// for the pairing, and wipes the cached result back to unplayed — so an admin
// can then re-edit the fixture (the edit guard blocks while a result exists).
add_action('wp_ajax_lgw_delete_scorecard', 'lgw_ajax_delete_scorecard');
function lgw_ajax_delete_scorecard() {
    check_ajax_referer('lgw_submit_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Not authorised');

    $home     = trim(sanitize_text_field(wp_unslash($_POST['home'] ?? '')));
    $away     = trim(sanitize_text_field(wp_unslash($_POST['away'] ?? '')));
    $date     = trim(sanitize_text_field(wp_unslash($_POST['date'] ?? '')));
    $division = sanitize_text_field(wp_unslash($_POST['division'] ?? ''));
    $context  = sanitize_key($_POST['context'] ?? 'league');
    if (!$home || !$away) wp_send_json_error('Missing fixture identity');

    if (!function_exists('lgw_get_scorecard')) wp_send_json_error('Scorecard system unavailable');
    $sc_post = lgw_get_scorecard($home, $away, $date, $context, $division);
    if (!$sc_post) wp_send_json_error('No scorecard found for this fixture');

    // Trash (recoverable) rather than hard-delete
    wp_update_post(array('ID' => $sc_post->ID, 'post_status' => 'trash'));

    // Remove score overrides for THIS fixture only (match pairing + date so the
    // reverse leg on another date keeps its override).
    $overrides = lgw_get_option_array('lgw_score_overrides');
    $changed   = false;
    foreach ($overrides as $ok => $ov) {
        if (strcasecmp($ov['home'] ?? '', $home) === 0
         && strcasecmp($ov['away'] ?? '', $away) === 0
         && lgw_fixture_dates_match($ov['date'] ?? '', $date)) {
            unset($overrides[$ok]);
            $changed = true;
        }
    }
    if ($changed) update_option('lgw_score_overrides', $overrides);

    // Wipe the cached fixture result back to unplayed
    if (function_exists('lgw_cache_wipe_fixture_result')) {
        $season_id = function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '';
        if ($season_id && $division) {
            lgw_cache_wipe_fixture_result($home, $away, $season_id, $division);
        }
    }

    wp_send_json_success(array('scorecard_id' => $sc_post->ID));
}

// ── Scorecard submission status map ──────────────────────────────────────────
/**
 * Returns a map of fixture keys → submission status ('pending'|'confirmed'|'disputed')
 * for all league scorecards in the active season.
 * Key format: home||away||fixture_date (lowercase).
 */
function lgw_build_scorecard_status_map() {
    try {
        $active_id = function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '';
        $meta_query = array(
            'relation' => 'AND',
            array('key' => 'lgw_sc_context', 'value' => 'league', 'compare' => '='),
        );
        if ($active_id) {
            $meta_query[] = array('key' => 'lgw_sc_season', 'value' => $active_id, 'compare' => '=');
        }
        $posts = get_posts(array(
            'post_type'      => 'lgw_scorecard',
            'posts_per_page' => 500,
            'post_status'    => array('publish', 'draft'),
            'meta_query'     => $meta_query,
        ));
        $map = array();
        foreach ($posts as $p) {
            $sc             = get_post_meta($p->ID, 'lgw_scorecard_data',  true);
            $fixture_date   = get_post_meta($p->ID, 'lgw_fixture_date',    true);
            $status         = get_post_meta($p->ID, 'lgw_sc_status',       true) ?: 'pending';
            $submitted_by   = get_post_meta($p->ID, 'lgw_submitted_by',    true) ?: '';
            if (!$sc) continue;
            $home_team = $sc['home_team'] ?? '';
            $away_team = $sc['away_team'] ?? '';
            $home = strtolower($home_team);
            $away = strtolower($away_team);
            $date = $fixture_date ?: ($sc['date'] ?? '');
            if (!$home || !$away || !$date) continue;
            $key = $home . '||' . $away . '||' . strtolower($date);
            // Upgrade status: disputed > confirmed > pending
            $existing = $map[$key] ?? '';
            if ($status === 'disputed' || $existing === 'disputed') {
                $map[$key] = 'disputed';
            } elseif ($status === 'confirmed' || $existing === 'confirmed') {
                $map[$key] = 'confirmed';
            } else {
                // Determine which side submitted using lgw_submitted_by (the actual club name).
                // Encode as "pending:home" or "pending:away" so the widget can show
                // the NON-submitting team, e.g. "Pending (Hilden)" means Hilden hasn't submitted.
                $submitted_side = 'home'; // fallback
                if ($submitted_by) {
                    if (function_exists('lgw_club_matches_team') && lgw_club_matches_team($away_team, $submitted_by)) {
                        $submitted_side = 'away';
                    } elseif (stripos($away_team, $submitted_by) !== false || stripos($submitted_by, $away_team) !== false) {
                        $submitted_side = 'away';
                    }
                }
                $new_val = 'pending:' . $submitted_side;
                // If already pending from the other side, both have submitted
                if (strpos($existing, 'pending:') === 0) {
                    $existing_side = substr($existing, 8);
                    if ($existing_side !== $submitted_side) {
                        $new_val = 'pending:both';
                    }
                }
                $map[$key] = $new_val;
            }
        }
        return $map;
    } catch (Exception $e) {
        return array();
    }
}

// ── Recent results: confirmed scorecards sorted by date played ────────────────
/**
 * Returns up to $limit confirmed league scorecards for the active season,
 * sorted by actual played date descending. Provides data for the results ticker.
 */
function lgw_get_recent_results($limit = 30) {
    try {
        $active_id = function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '';
        $meta_query = array(
            'relation' => 'AND',
            array('key' => 'lgw_sc_context', 'value' => 'league',    'compare' => '='),
            array('key' => 'lgw_sc_status',  'value' => 'confirmed', 'compare' => '='),
        );
        if ($active_id) {
            $meta_query[] = array('key' => 'lgw_sc_season', 'value' => $active_id, 'compare' => '=');
        }
        $posts = get_posts(array(
            'post_type'      => 'lgw_scorecard',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => $meta_query,
        ));
        $all = array();
        foreach ($posts as $p) {
            $sc = get_post_meta($p->ID, 'lgw_scorecard_data', true);
            if (!$sc || empty($sc['home_team']) || empty($sc['away_team'])) continue;
            $all[] = array(
                'home_team'   => $sc['home_team'],
                'away_team'   => $sc['away_team'],
                'home_total'  => isset($sc['home_total'])  ? $sc['home_total']  : null,
                'away_total'  => isset($sc['away_total'])  ? $sc['away_total']  : null,
                'home_points' => isset($sc['home_points']) ? $sc['home_points'] : null,
                'away_points' => isset($sc['away_points']) ? $sc['away_points'] : null,
                'date'        => $sc['date']     ?? '',
                'division'    => $sc['division'] ?? '',
            );
        }

        // Deduplicate by home+away+date (two-party submission creates two posts per fixture)
        $seen = array();
        $all = array_values(array_filter($all, function($r) use (&$seen) {
            $key = strtolower(trim($r['home_team'])) . '||' . strtolower(trim($r['away_team'])) . '||' . $r['date'];
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));

        $date_sort = function($a, $b) {
            $da = $a['date'] ? DateTime::createFromFormat('d/m/Y', $a['date']) : false;
            $db = $b['date'] ? DateTime::createFromFormat('d/m/Y', $b['date']) : false;
            if (!$da && !$db) return 0;
            if (!$da) return  1;
            if (!$db) return -1;
            return $db <=> $da;
        };
        usort($all, $date_sort);

        // Take the $limit most recent per division so no division is starved by others
        $by_div = array();
        foreach ($all as $r) {
            $div = $r['division'] ?: '__none__';
            if (!isset($by_div[$div])) $by_div[$div] = array();
            if (count($by_div[$div]) < $limit) $by_div[$div][] = $r;
        }
        $results = array();
        foreach ($by_div as $rows) foreach ($rows as $r) $results[] = $r;
        usort($results, $date_sort);
        return $results;
    } catch (Exception $e) {
        return array();
    }
}

// ── 2. Enqueue CSS + JS ───────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', 'lgw_enqueue');
function lgw_enqueue() {
    global $post;
    if (!is_a($post, 'WP_Post')) return;
    $has_lgw = has_shortcode($post->post_content, 'lgw_division')
            || has_shortcode($post->post_content, 'lgw_player_stats');
    if (!$has_lgw) return;

    $lgw_theme_opt = get_option('lgw_theme', array());
    $lgw_font_key  = ! empty( $lgw_theme_opt['font_family'] ) ? $lgw_theme_opt['font_family'] : 'Saira';
    $lgw_fonts     = lgw_font_options();
    $lgw_weights   = $lgw_fonts[ $lgw_font_key ]['weights'] ?? '400;600;700';
    $lgw_font_url  = 'https://fonts.googleapis.com/css2?family=' . urlencode( str_replace('+', ' ', $lgw_font_key) ) . ':wght@' . $lgw_weights . '&display=swap';
    wp_enqueue_style( 'lgw-font', $lgw_font_url, array(), null );
    wp_enqueue_style( 'lgw-widget', plugin_dir_url(__FILE__) . 'lgw-widget.css', array('lgw-font'), LGW_VERSION );
    // Inline CSS var so .lgw-w picks up the chosen font
    $lgw_font_css_name = str_replace('+', ' ', $lgw_font_key);
    wp_add_inline_style( 'lgw-widget', ".lgw-w { --lgw-font: '{$lgw_font_css_name}', Arial, sans-serif; font-family: var(--lgw-font); }" );

    // Register scorecard script here so lgw-widget can declare it as a dependency.
    // This guarantees lgw-scorecard.js loads before lgw-widget.js on any page
    // with [lgw_division], even if [lgw_submit] is on a different page.
    if (!wp_script_is('lgw-scorecard', 'registered')) {
        wp_register_script('lgw-scorecard', plugin_dir_url(__FILE__) . 'lgw-scorecard.js', array(), LGW_VERSION, true);
        wp_register_style('lgw-scorecard',  plugin_dir_url(__FILE__) . 'lgw-scorecard.css', array(), LGW_VERSION);
    }
    if (!wp_script_is('lgw-scorecard', 'enqueued')) {
        wp_enqueue_script('lgw-scorecard');
        wp_enqueue_style('lgw-scorecard');
    }
    // Always localise — safe to call multiple times, ensures lgwSubmit is defined
    // even if script was registered (not enqueued) by a prior call.
    wp_localize_script('lgw-scorecard', 'lgwSubmit', array(
        'ajaxUrl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('lgw_submit_nonce'),
        'authClub'       => lgw_get_auth_club(),
        'clubs'          => array_map(function($c){ return $c['name']; }, get_option('lgw_clubs', array())),
        'pointsPerRink'  => floatval(get_option('lgw_points_per_rink',  1)),
        'pointsOverall'  => floatval(get_option('lgw_points_overall',   3)),
        'authMode'       => function_exists('lgw_auth_mode') ? lgw_auth_mode() : 'both',
        'isLoggedIn'     => is_user_logged_in() ? '1' : '0',
        'loginUrl'       => wp_login_url(),
        'requestAccessUrl' => function_exists('lgw_request_access_url') ? lgw_request_access_url() : '',
        'userClubs'      => ( function_exists('lgw_user_submit_clubs') && is_user_logged_in() && ! current_user_can('manage_options') ) ? array_values( lgw_user_submit_clubs() ) : array(),
    ));

    wp_enqueue_script('lgw-widget', plugin_dir_url(__FILE__) . 'lgw-widget.js', array('lgw-scorecard'), LGW_VERSION, true);

    $badges       = get_option('lgw_badges',       array());
    $club_badges  = get_option('lgw_club_badges',  array());
    $sponsors     = get_option('lgw_sponsors',     array());
    $club_colors  = array();
    foreach ( get_option('lgw_clubs', array()) as $c ) {
        if ( empty( $c['name'] ) ) continue;
        if ( ! empty( $c['colors'] ) ) {
            $club_colors[ $c['name'] ] = array_values( $c['colors'] );
        } elseif ( ! empty( $c['color'] ) ) {
            $club_colors[ $c['name'] ] = array( $c['color'] );
        }
    }
    wp_localize_script('lgw-widget', 'lgwData', array(
        'ajaxUrl'        => admin_url('admin-ajax.php'),
        'scNonce'        => wp_create_nonce('lgw_submit_nonce'),
        'badges'         => $badges,
        'clubBadges'     => $club_badges,
        'clubColors'     => $club_colors,
        'sponsors'       => $sponsors,
        'scoreOverrides' => lgw_get_option_array('lgw_score_overrides'),
        'seasons'        => function_exists('lgw_seasons_for_js') ? lgw_seasons_for_js() : array(),
        'activeSeasonId' => function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '',
        'submissionMode' => get_option('lgw_submission_mode', 'open'),
        'dataSource'     => get_option('lgw_data_source', 'google_sheets'),
        'isAdmin'        => current_user_can('manage_options') ? '1' : '0',
        'authClub'       => lgw_get_auth_club(),
        'clubCanSubmit'  => function_exists('lgw_club_can_submit') && lgw_club_can_submit() ? '1' : '0',
        'playedDates'    => lgw_build_played_dates_map(),
        'scorecardStatus'  => lgw_build_scorecard_status_map(),
        'postponements'    => lgw_build_postponements_map(),
        'concessions'      => lgw_get_concessions(),
        'null_voids'       => lgw_get_null_voids(),
        'recentResults'    => lgw_get_recent_results(30),
        'progressNonce'    => wp_create_nonce('lgw_season_progress_nonce'),
    ));
}

// ── 3. Shortcode ─────────────────────────────────────────────────────────────
add_shortcode('lgw_division', 'lgw_division_shortcode');
function lgw_division_shortcode($atts) {
    $atts = shortcode_atts(array(
        'csv'          => '',
        'title'        => '',
        'promote'      => '0',
        'relegate'     => '0',
        'sponsor_img'  => '',
        'sponsor_url'  => '',
        'sponsor_name' => '',
        'color_primary'   => '',
        'color_secondary' => '',
        'color_bg'        => '',
        'seasons'      => '',  // comma-separated season IDs, or "all"
        'max_points'   => '7', // max points per match (6 for 12-player division)
    ), $atts);

    if (!$atts['csv']) return '<p>No CSV URL provided.</p>';

    $id          = 'lgw-' . substr(md5($atts['csv']), 0, 8);
    $csv_escaped = esc_attr($atts['csv']);

    // ── Build season switcher data ─────────────────────────────────────────────
    // seasons_data: array of {id, label, csv_url} for this division
    $seasons_data = array();
    if (!empty($atts['seasons']) && function_exists('lgw_get_seasons')) {
        $all_seasons     = lgw_get_seasons();
        $active_id       = function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '';
        $division_title  = trim($atts['title']);

        // Add the active / current season first (uses the shortcode csv directly)
        if ($active_id) {
            // Find label for active season
            $active_label = '';
            foreach ($all_seasons as $s) {
                if (!empty($s['active'])) { $active_label = $s['label']; break; }
            }
            $seasons_data[] = array(
                'id'      => $active_id,
                'label'   => $active_label ?: $active_id,
                'csv_url' => $atts['csv'],
                'active'  => true,
            );
        }

        // Determine which archived season IDs to include
        $archived_seasons = array_values(array_filter($all_seasons, function($s){ return empty($s['active']); }));
        usort($archived_seasons, function($a, $b){ return strcmp($b['id'], $a['id']); });

        if ($atts['seasons'] === 'all') {
            $wanted_ids = array_map(function($s){ return $s['id']; }, $archived_seasons);
        } else {
            $wanted_ids = array_map('trim', explode(',', $atts['seasons']));
        }

        foreach ($archived_seasons as $s) {
            if (!in_array($s['id'], $wanted_ids)) continue;
            // Find CSV URL for this division in the archived season
            $csv_url = '';
            // Normalise both sides by stripping a trailing 4-digit year so that
            // "Division 1 2026" matches archived entries stored as "Division 1"
            // or "Division 1 2025".
            $norm_title = $division_title ? trim(preg_replace('/\s+\d{4}$/', '', $division_title)) : '';
            foreach (($s['divisions'] ?? array()) as $d) {
                // Match by division title if set, else take first division
                $norm_div = trim(preg_replace('/\s+\d{4}$/', '', $d['division']));
                if ($division_title && strcasecmp($norm_div, $norm_title) === 0) {
                    $csv_url = $d['csv_url']; break;
                } elseif (!$division_title && !empty($d['csv_url'])) {
                    $csv_url = $d['csv_url']; break;
                }
            }
            if ($csv_url) {
                $seasons_data[] = array(
                    'id'      => $s['id'],
                    'label'   => $s['label'],
                    'csv_url' => $csv_url,
                    'active'  => false,
                );
            }
        }
    }
    $seasons_json = esc_attr(json_encode($seasons_data));

    // Resolve theme: shortcode override > global setting > CSS defaults
    $global_theme = get_option('lgw_theme', array());
    $primary   = sanitize_hex_color($atts['color_primary'])   ?: ($global_theme['color_primary']   ?? '');
    $secondary = sanitize_hex_color($atts['color_secondary']) ?: ($global_theme['color_secondary'] ?? '');
    $bg        = sanitize_hex_color($atts['color_bg'])        ?: ($global_theme['color_bg']        ?? '');

    // Build scoped CSS variable overrides if any theme colour is set
    $theme_style = '';
    if ($primary || $secondary || $bg) {
        $vars = '';
        if ($primary) {
            $vars .= '--lgw-navy:' . $primary . ';';
            $vars .= '--lgw-navy-mid:' . lgw_theme_lighten($primary, 20) . ';';
            $vars .= '--lgw-tab-bg:' . lgw_theme_mix($primary, '#ffffff', 85) . ';';
            $vars .= '--lgw-pts:' . $primary . ';';
        }
        if ($secondary) {
            $vars .= '--lgw-gold:' . $secondary . ';';
        }
        if ($bg) {
            $vars .= '--lgw-bg:' . $bg . ';';
            $vars .= '--lgw-bg-alt:' . lgw_theme_darken($bg, 5) . ';';
            $vars .= '--lgw-bg-hover:' . lgw_theme_darken($bg, 10) . ';';
        }
        $theme_style = '<style>#' . $id . '-wrap{' . $vars . '}</style>';
    }

    // Resolve sponsors: shortcode override takes priority over global setting
    $global_sponsors = get_option('lgw_sponsors', array());
    if (!empty($atts['sponsor_img'])) {
        $primary_sponsor = array(
            'image' => esc_url($atts['sponsor_img']),
            'url'   => esc_url($atts['sponsor_url']),
            'name'  => esc_attr($atts['sponsor_name']),
        );
        $extra_sponsors = array_slice($global_sponsors, 1); // keep globals beyond first as extras
    } else {
        $primary_sponsor = !empty($global_sponsors[0]) ? $global_sponsors[0] : null;
        $extra_sponsors  = array_slice($global_sponsors, 1);
    }

    // Primary sponsor bar (above title)
    $primary_html = '';
    if ($primary_sponsor && !empty($primary_sponsor['image'])) {
        $img = '<img src="' . esc_url($primary_sponsor['image']) . '" alt="' . esc_attr($primary_sponsor['name'] ?: 'Sponsor') . '" class="lgw-sponsor-img">';
        $primary_html = '<div class="lgw-sponsor-bar lgw-sponsor-primary">'
            . (!empty($primary_sponsor['url']) ? '<a href="' . esc_url($primary_sponsor['url']) . '" target="_blank" rel="noopener">' . $img . '</a>' : $img)
            . '</div>';
    }

    // Title
    $title_html = '';
    if (!empty($atts['title'])) {
        $title_html = '<div class="lgw-title">' . esc_html($atts['title']) . '</div>';
    }

    // Extra sponsors JSON for JS random rotation below table
    $extra_json = esc_attr(json_encode($extra_sponsors));

    // ── Attempt server-side render from DB cache ───────────────────────────────
    $ssr = (function_exists('lgw_cache_render_division'))
        ? lgw_cache_render_division(
            $atts['csv'],
            trim($atts['title']),
            intval($atts['promote']),
            intval($atts['relegate']),
            intval($atts['max_points'])
          )
        : ['hit' => false];

    // Build panel contents — pre-rendered from cache or Loading... shell for XHR fallback
    if ($ssr['hit']) {
        $table_panel    = $ssr['table_html'];
        $fixtures_panel = $ssr['fixtures_html'];
        // data-season carries the exact season the WP cache was read from, so the
        // admin fixture editor targets the right cache key without guessing.
        $prerendered_attr = ' data-prerendered="1" data-season="' . esc_attr($ssr['season_id'] ?? '') . '"';
        $cached_attr      = ' data-cached="' . esc_attr($ssr['cached_json']) . '"'
                          . ' data-teams="'  . esc_attr($ssr['teams_json'])  . '"';
    } else {
        $table_panel      = '<div class="lgw-status">Loading&hellip;</div>';
        $fixtures_panel   = '<div class="lgw-status">Loading&hellip;</div>';
        $prerendered_attr = '';
        $cached_attr      = '';
    }

    return $theme_style
        . '<div class="lgw-widget-wrap" id="' . $id . '-wrap">'
        . $primary_html
        . $title_html
        . '<div class="lgw-w" id="' . $id . '"'
        . ' data-csv="' . $csv_escaped . '"'
        . ' data-division="' . esc_attr(trim($atts['title'])) . '"'
        . ' data-promote="' . intval($atts['promote']) . '"'
        . ' data-relegate="' . intval($atts['relegate']) . '"'
        . ' data-sponsors="' . $extra_json . '"'
        . ' data-maxpts="' . max(6, min(7, intval($atts['max_points']))) . '"'
        . (!empty($seasons_data) ? ' data-seasons="' . $seasons_json . '"' : '')
        . $prerendered_attr
        . $cached_attr
        . '>'
        . '<div class="lgw-tabs">'
        . '<div class="lgw-tab active" data-tab="table">League Table</div>'
        . '<div class="lgw-tab" data-tab="fixtures">Fixtures &amp; Results</div>'
        . '<div class="lgw-tab" data-tab="progress">&#x1F4C8; Progress</div>'
        . '</div>'
        . '<div class="lgw-panel active" data-panel="table">' . $table_panel . '</div>'
        . '<div class="lgw-panel" data-panel="fixtures">' . $fixtures_panel . '</div>'
        . '<div class="lgw-panel" data-panel="progress"></div>'
        . '</div>'
        . '</div>';
}

// ── 4. Admin Settings Page ────────────────────────────────────────────────────
add_action('admin_menu', 'lgw_admin_menu');
function lgw_admin_menu() {
    // Top-level LGW menu
    add_menu_page(
        'LGW Scorecards',
        'LGW',
        'manage_options',
        'lgw-scorecards',
        'lgw_scorecards_admin_page',
        'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIj4KICA8cG9seWdvbiBwb2ludHM9IjEwLDAgMjAsNSAyMCwxNSAxMCwyMCAwLDE1IDAsNSIgZmlsbD0iIzA3MmE4MiIvPgogIDxwb2x5Z29uIHBvaW50cz0iMTAsMiAxOCw2IDE4LDE0IDEwLDE4IDIsMTQgMiw2IiBmaWxsPSJub25lIiBzdHJva2U9IiMxMzgyMTEiIHN0cm9rZS13aWR0aD0iMS4yIi8+CiAgPHRleHQgeD0iMTAiIHk9IjExIiBmb250LWZhbWlseT0ic3lzdGVtLXVpLHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iNS41IiBmb250LXdlaWdodD0iODAwIiBmaWxsPSIjZmNmY2ZjIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkb21pbmFudC1iYXNlbGluZT0iY2VudHJhbCIgbGV0dGVyLXNwYWNpbmc9IjAuMyI+TEdXPC90ZXh0Pgo8L3N2Zz4K',
        30
    );
    // Rename the auto-created first submenu item
    add_submenu_page(
        'lgw-scorecards',
        'LGW Scorecards',
        '📋 Scorecards',
        'manage_options',
        'lgw-scorecards',
        'lgw_scorecards_admin_page'
    );
    // Players
    add_submenu_page(
        'lgw-scorecards',
        'Player Tracking',
        '👥 Players',
        'manage_options',
        'lgw-players',
        'lgw_players_admin_page'
    );
    // Clubs — function defined in lgw-clubs.php
    if (function_exists('lgw_clubs_register_submenu')) {
        lgw_clubs_register_submenu();
    }
    // Cups — function defined in lgw-cup.php
    if (function_exists('lgw_cups_register_submenu')) {
        lgw_cups_register_submenu();
    }
    // Championships — function defined in lgw-champ.php
    if (function_exists('lgw_champs_register_submenu')) {
        lgw_champs_register_submenu();
    }
    // Multi-Discipline Championships — function defined in lgw-multichamp.php
    if (function_exists('lgw_multichamp_register_submenu')) {
        lgw_multichamp_register_submenu();
    }
    // Seasons — function defined in lgw-seasons.php
    if (function_exists('lgw_seasons_register_submenu')) {
        lgw_seasons_register_submenu();
    }
    // League Setup — cache, API key, Drive/Sheets integration, shortcode reference
    add_submenu_page(
        'lgw-scorecards',
        'League Setup',
        '⚙️ League Setup',
        'manage_options',
        LGW_SETUP_PAGE,
        'lgw_league_setup_page'
    );
    // Table Compare — WP scorecards vs Sheets CSV side-by-side diff
    add_submenu_page(
        'lgw-scorecards',
        'Table Compare',
        '📊 Table Compare',
        'manage_options',
        'lgw-table-compare',
        'lgw_table_compare_page'
    );
    // Fixture Audit Log — read-only trail of admin fixture edits
    add_submenu_page(
        'lgw-scorecards',
        'Fixture Audit Log',
        '📝 Fixture Audit',
        'manage_options',
        'lgw-fixture-audit',
        'lgw_fixture_audit_page'
    );
    // Settings — theme, sponsors, club badges, clubs/passphrases
    add_submenu_page(
        'lgw-scorecards',
        'LGW Settings',
        '🎨 Settings',
        'manage_options',
        'lgw-settings',
        'lgw_settings_page'
    );
}

// ── Fixture Audit Log page (read-only) ───────────────────────────────────────
function lgw_fixture_audit_page() {
    if (!current_user_can('manage_options')) return;
    $log = get_option('lgw_fixture_edit_log', array());
    if (!is_array($log)) $log = array();
    $wp_mode = function_exists('lgw_source_is_wordpress') && lgw_source_is_wordpress();
    echo '<div class="wrap">';
    if (function_exists('lgw_admin_logo_header')) lgw_admin_logo_header('Fixture Audit Log');
    else echo '<h1>Fixture Audit Log</h1>';

    if (!$wp_mode) {
        echo '<div class="notice notice-info"><p>The data source is currently <strong>Google Sheets</strong>. Fixture editing (and this log) only applies in <strong>WordPress</strong> data-source mode.</p></div>';
    }
    echo '<p>Read-only trail of admin fixture edits, swaps and undos (newest first, last 500). Each row shows the change to a fixture\'s identity, time and scores.</p>';

    if (empty($log)) {
        echo '<p><em>No fixture edits recorded yet.</em></p></div>';
        return;
    }

    $fmt = function($side) {
        $s = esc_html(($side['home'] ?? '') . ' v ' . ($side['away'] ?? ''));
        $meta = array();
        if (($side['date'] ?? '') !== '') $meta[] = esc_html($side['date']);
        if (($side['time'] ?? '') !== '') $meta[] = esc_html($side['time']);
        $sh = $side['shotsHome'] ?? ''; $sa = $side['shotsAway'] ?? '';
        if ($sh !== '' || $sa !== '') $meta[] = esc_html($sh . '–' . $sa);
        return $s . ($meta ? '<br><span style="color:#888;font-size:12px">' . implode(' · ', $meta) . '</span>' : '');
    };
    $badge = array('edit' => '#2271b1', 'swap' => '#7a5', 'revert' => '#c0202a', 'reset' => '#a06');

    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>'
       . '<th>When</th><th>Who</th><th>Division</th><th>Action</th><th>Before</th><th>After</th>'
       . '</tr></thead><tbody>';
    foreach ($log as $e) {
        $act = $e['action'] ?? 'edit';
        $col = $badge[$act] ?? '#555';
        echo '<tr>'
           . '<td>' . esc_html($e['ts'] ?? '') . '</td>'
           . '<td>' . esc_html($e['user'] ?? '') . '</td>'
           . '<td>' . esc_html($e['division'] ?? '') . '<br><span style="color:#888;font-size:12px">' . esc_html($e['season'] ?? '') . '</span></td>'
           . '<td><span style="display:inline-block;padding:2px 8px;border-radius:4px;color:#fff;font-size:12px;background:' . esc_attr($col) . '">' . esc_html(strtoupper($act)) . '</span></td>'
           . '<td>' . $fmt($e['before'] ?? array()) . '</td>'
           . '<td>' . $fmt($e['after'] ?? array()) . '</td>'
           . '</tr>';
    }
    echo '</tbody></table></div>';
}

function lgw_scorecards_admin_page() {
    // ── Data for Quick Score Entry section ────────────────────────────────────
    $drive_opts = lgw_get_option_array('lgw_drive');
    $tabs       = lgw_get_option_array('lgw_drive')['sheets_tabs'] ?? array();
    if ( is_string( $tabs ) ) { $tabs = json_decode( $tabs, true ) ?: array(); }
    $divisions  = array_values(array_filter($tabs, function($t) {
        return !empty($t['csv_url']) && !empty($t['division']);
    }));
    $overrides  = lgw_get_option_array('lgw_score_overrides');
    $scores_nonce = wp_create_nonce('lgw_scores_nonce');
    $sel_div    = isset($_GET['div']) ? intval($_GET['div']) : 0;
    $sel        = $divisions[$sel_div] ?? null;
    $fixtures   = array();
    $fetch_err  = '';
    if ($sel) {
        $resp = wp_remote_get($sel['csv_url'], array('timeout' => 15));
        if (is_wp_error($resp)) {
            $fetch_err = $resp->get_error_message();
        } else {
            $fixtures = lgw_scores_parse_fixtures(wp_remote_retrieve_body($resp), $sel['csv_url'], $overrides);
        }
    }

    // ── Data for Submitted Scorecards section ─────────────────────────────────
    $all_seasons_list   = function_exists('lgw_get_seasons') ? lgw_get_seasons() : array();
    $active_season_id   = function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '';
    $viewing_season_id  = sanitize_text_field($_GET['sc_season'] ?? $active_season_id);
    // Validate: must be a known season ID or empty
    $known_season_ids   = array_map(function($s){ return $s['id']; }, $all_seasons_list);
    if ($viewing_season_id && !in_array($viewing_season_id, $known_season_ids, true)) {
        $viewing_season_id = $active_season_id;
    }
    // Fetch scorecards tagged to the viewed season only — no NOT EXISTS fallback,
    // because untagged cards may belong to any season and would bleed in incorrectly.
    // Untagged cards are surfaced separately via the backfill warning banner.
    $posts_query_args = array(
        'post_type'      => 'lgw_scorecard',
        'posts_per_page' => 200,
        'post_status'    => array('publish', 'draft'),
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    if ($viewing_season_id) {
        $posts_query_args['meta_query'] = array(
            array('key' => 'lgw_sc_season', 'value' => $viewing_season_id, 'compare' => '='),
        );
    }
    $posts    = get_posts($posts_query_args);
    $sc_nonce = wp_create_nonce('lgw_admin_nonce');

    // Count scorecards whose match date falls in this season but are tagged differently (or untagged).
    // Used to show the backfill warning banner on any season view, not just the active one.
    $mismatched_count = 0;
    if ($viewing_season_id && function_exists('lgw_get_season_by_id')) {
        $viewing_season_obj = lgw_get_season_by_id($viewing_season_id);
        if ($viewing_season_obj && !empty($viewing_season_obj['start']) && !empty($viewing_season_obj['end'])) {
            $vs_start = $viewing_season_obj['start']; // Y-m-d
            $vs_end   = $viewing_season_obj['end'];
            $all_sc_ids = get_posts(array(
                'post_type'      => 'lgw_scorecard',
                'posts_per_page' => -1,
                'post_status'    => array('publish', 'draft'),
                'fields'         => 'ids',
            ));
            foreach ($all_sc_ids as $pid) {
                if (get_post_meta($pid, 'lgw_sc_season', true) === $viewing_season_id) continue;
                $sc_data  = get_post_meta($pid, 'lgw_scorecard_data', true);
                $raw_date = $sc_data['date'] ?? '';
                if (!$raw_date) continue;
                $parts = explode('/', $raw_date);
                if (count($parts) === 3) {
                    $ymd = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                    if ($ymd >= $vs_start && $ymd <= $vs_end) $mismatched_count++;
                }
            }
        }
    }
    ?>
    <div class="wrap">
    <?php lgw_page_header('Scorecards'); ?>

    <style>
    /* ── Collapsible sections ── */
    .lgw-section{border:1px solid #c3c4c7;border-radius:4px;margin-bottom:20px;background:#fff}
    .lgw-section-header{display:flex;align-items:center;gap:10px;padding:12px 16px;cursor:pointer;user-select:none;background:#f6f7f7;border-radius:4px;border-bottom:1px solid transparent}
    .lgw-section.open .lgw-section-header{border-bottom-color:#c3c4c7;border-radius:4px 4px 0 0}
    .lgw-section-header h2{margin:0;font-size:14px;font-weight:600;color:#1a2e5a;flex:1}
    .lgw-section-chevron{font-size:12px;color:#666;transition:transform .2s}
    .lgw-section.open .lgw-section-chevron{transform:rotate(90deg)}
    .lgw-section-body{display:none;padding:16px}
    .lgw-section.open .lgw-section-body{display:block}
    .lgw-section-badge{font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;background:#e0e0e0;color:#555}
    .lgw-section-badge.has-items{background:#1a2e5a;color:#fff}
    .lgw-section-badge.has-warn{background:#c0202a;color:#fff}
    /* ── Scorecard styles ── */
    .lgw-sc-status{display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600}
    .lgw-sc-status.pending{background:#fff3cd;color:#856404}
    .lgw-sc-status.confirmed{background:#d1e7dd;color:#0a3622}
    .lgw-sc-status.disputed{background:#f8d7da;color:#842029}
    .lgw-admin-sc-wrap{display:none;background:#f6f7f7;border:1px solid #ddd;padding:16px;margin:8px 0}
    .lgw-admin-sc-wrap.open{display:block}
    .lgw-sc-compare{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:12px}
    .lgw-sc-version{background:#fff;border:1px solid #ddd;padding:12px;border-radius:4px}
    .lgw-sc-version h4{margin:0 0 8px;font-size:13px;color:#1a2e5a}
    .lgw-sc-rink-row{font-size:12px;padding:3px 0;border-bottom:1px solid #eee}
    .lgw-sc-totals{font-weight:600;margin-top:6px;font-size:13px}
    .lgw-resolve-btns{margin-top:10px}
    .lgw-resolve-btns .button{margin-right:8px}
    .lgw-edit-form{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;margin-top:12px}
    .lgw-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px}
    .lgw-edit-row{display:flex;flex-direction:column;gap:4px}
    .lgw-edit-row label{font-weight:600;font-size:12px;color:#555}
    .lgw-edit-rinks-table td{padding:6px 8px;vertical-align:middle}
    .lgw-edit-rinks-table input.large-text{width:100%}
    .lgw-edit-msg.success{color:#0a3622;background:#d1e7dd;padding:6px 10px;border-radius:3px}
    .lgw-edit-msg.error{color:#842029;background:#f8d7da;padding:6px 10px;border-radius:3px}
    .lgw-audit-log{border:1px solid #ddd;border-radius:4px;overflow:hidden;margin-top:4px}
    .lgw-audit-entry{padding:10px 12px;border-bottom:1px solid #eee;font-size:12px}
    .lgw-audit-entry:last-child{border-bottom:none}
    .lgw-audit-header{display:flex;align-items:center;gap:8px;margin-bottom:4px}
    .lgw-audit-icon{font-size:14px}
    .lgw-audit-action{font-weight:700;padding:1px 6px;border-radius:3px;font-size:11px;text-transform:uppercase}
    .lgw-audit-action-edited{background:#fff3cd;color:#856404}
    .lgw-audit-action-confirmed{background:#d1e7dd;color:#0a3622}
    .lgw-audit-action-resolved{background:#cfe2ff;color:#084298}
    .lgw-audit-action-submitted{background:#f0f0f0;color:#333}
    .lgw-audit-user{font-weight:600;color:#1a2e5a}
    .lgw-audit-ts{color:#888;margin-left:auto}
    .lgw-audit-note{color:#444;line-height:1.4}
    .lgw-audit-changes{margin:4px 0 0 18px;padding:0;color:#666}
    .lgw-audit-changes li{margin-bottom:2px}
    .lgw-sc-amended{font-size:10px;background:#fff3cd;color:#856404;padding:1px 5px;border-radius:3px;margin-left:6px;vertical-align:middle}
    .lgw-sc-div-warn{font-size:10px;background:#f8d7da;color:#842029;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle;font-weight:600}
    .lgw-sc-ctx-badge{font-size:10px;font-weight:700;padding:1px 6px;border-radius:3px;margin-left:4px;vertical-align:middle}
    .lgw-sc-ctx-cup{background:#e8f0fe;color:#1a56a0}
    .lgw-sc-ctx-multichamp{background:#eaf3de;color:#276221}
    .lgw-sc-ctx-champ{background:#fff3cd;color:#7a5c00}
    .lgw-sc-mc-ref{font-size:10px;color:#666;margin-left:4px;font-style:italic}
    /* ── Score entry styles ── */
    .lgw-overridden td{background:#fff8f8 !important}
    .lgw-overridden input{color:#8f1520 !important;font-weight:700}
    #lgw-scores-table input[type=number]{padding:3px 4px}
    #lgw-scores-table input:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;outline:0}
    </style>

    <script>
    // ── Section collapse ──────────────────────────────────────────────────────
    function lgwToggleSection(id) {
        var sec = document.getElementById(id);
        if (!sec) return;
        sec.classList.toggle('open');
        try { sessionStorage.setItem('lgw_sec_' + id, sec.classList.contains('open') ? '1' : '0'); } catch(e) {}
    }
    document.addEventListener('DOMContentLoaded', function() {
        // Restore collapse state; default: scores=closed, scorecards=open
        ['lgw-sec-scores','lgw-sec-scorecards'].forEach(function(id) {
            var sec = document.getElementById(id);
            if (!sec) return;
            var stored;
            try { stored = sessionStorage.getItem('lgw_sec_' + id); } catch(e) {}
            var defaultOpen = (id === 'lgw-sec-scorecards');
            if (stored === null ? defaultOpen : stored === '1') sec.classList.add('open');
        });
    });

    // ── Scorecard panel toggle ────────────────────────────────────────────────
    function lgwResolve(postId, version, nonce){
        if(!confirm('Accept the '+version+' version as the official result?')) return;
        var data=new FormData();
        data.append('action','lgw_admin_resolve');
        data.append('post_id',postId);
        data.append('version',version);
        data.append('nonce',nonce);
        fetch(ajaxurl,{method:'POST',body:data,credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(res){
                var msg=document.getElementById('lgw-resolve-msg-'+postId);
                if(res.success){
                    msg.style.display='block';
                    msg.textContent=res.data.message||'Resolved.';
                    setTimeout(function(){location.reload();},1500);
                } else { alert('Error: '+(res.data||'unknown')); }
            });
    }
    function lgwShowPanel(postId, panel) {
        var wrap = document.getElementById('sc-'+postId);
        if (!wrap) return;
        var isOpen = wrap.classList.contains('open');
        var current = wrap.dataset.panel || '';
        if (isOpen && current === panel) { wrap.classList.remove('open'); wrap.dataset.panel = ''; return; }
        wrap.classList.add('open');
        wrap.dataset.panel = panel;
        wrap.querySelectorAll('.lgw-sc-subpanel').forEach(function(p){
            p.style.display = p.dataset.panel === panel ? '' : 'none';
        });
    }
    document.addEventListener('DOMContentLoaded', function(){
        // Scorecard edit save
        document.querySelectorAll('.lgw-save-edit').forEach(function(btn){
            btn.addEventListener('click', function(){
                var postId=btn.dataset.postid, nonce=btn.dataset.nonce;
                var isDisputed=btn.dataset.disputed==='1';
                var wrap=document.getElementById('sc-'+postId);
                var form=wrap?wrap.querySelector('.lgw-edit-form'):null;
                var msgEl=form?form.querySelector('.lgw-edit-msg'):null;
                if(!form) return;
                var data=new FormData();
                data.append('action','lgw_admin_edit_scorecard');
                data.append('post_id',postId); data.append('nonce',nonce);
                ['home_team','away_team','match_date','venue','division','competition',
                 'home_total','away_total','home_points','away_points'].forEach(function(f){
                    var el=form.querySelector('[name="'+f+'"]'); if(el) data.append(f,el.value);
                });
                form.querySelectorAll('[name="rink_num[]"]').forEach(function(el){ data.append('rink_num[]',el.value); });
                form.querySelectorAll('[name="rink_home_score[]"]').forEach(function(el){ data.append('rink_home_score[]',el.value); });
                form.querySelectorAll('[name="rink_away_score[]"]').forEach(function(el){ data.append('rink_away_score[]',el.value); });
                form.querySelectorAll('[name="rink_home_players[]"]').forEach(function(el){ data.append('rink_home_players[]',el.value); });
                form.querySelectorAll('[name="rink_away_players[]"]').forEach(function(el){ data.append('rink_away_players[]',el.value); });
                btn.disabled=true; btn.textContent='Saving…';
                fetch(ajaxurl,{method:'POST',body:data,credentials:'same-origin'})
                    .then(function(r){ return r.text().then(function(t){ try{return JSON.parse(t);}catch(e){throw new Error('Bad JSON: '+t.slice(0,200));} }); })
                    .then(function(res){
                        var defaultLabel=isDisputed?'⚖️ Save & Resolve Dispute':'💾 Save Changes';
                        btn.disabled=false; btn.textContent=defaultLabel;
                        if(msgEl){ msgEl.style.display=''; msgEl.className='lgw-edit-msg '+(res.success?'success':'error');
                            msgEl.textContent=res.success?(res.data.message||'Saved.'):('Error: '+(res.data||'unknown'));
                            if(res.success){
                                // If this was a disputed card, update the status badge immediately
                                if(isDisputed && wrap){
                                    var row=wrap.closest('tr') ? wrap.closest('tr').previousElementSibling : null;
                                    if(row){
                                        var badge=row.querySelector('.lgw-sc-status');
                                        if(badge){ badge.className='lgw-sc-status confirmed'; badge.textContent='Confirmed'; }
                                        row.dataset.status='confirmed';
                                    }
                                    // Remove the disputed banner from the form
                                    var notice=form.querySelector('.lgw-disputed-edit-notice');
                                    if(notice) notice.remove();
                                    // Remove data-disputed so button label resets on next open
                                    btn.removeAttribute('data-disputed');
                                    btn.textContent='💾 Save Changes';
                                }
                                setTimeout(function(){location.reload();},1800);
                            }
                        }
                    }).catch(function(err){
                        var defaultLabel=isDisputed?'⚖️ Save & Resolve Dispute':'💾 Save Changes';
                        btn.disabled=false; btn.textContent=defaultLabel;
                        if(msgEl){ msgEl.style.display=''; msgEl.className='lgw-edit-msg error'; msgEl.textContent='Request failed: '+err.message; }
                    });
            });
        });

        // ── Score entry ───────────────────────────────────────────────────────
        var scoresNonce = <?php echo json_encode($scores_nonce); ?>;
        var scoresAjax  = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;

        function saveRow(row, sh, sa, ph, pa) {
            var status = row.querySelector('.lgw-save-status');
            status.textContent='Saving…'; status.style.color='#888';
            var data=new FormData();
            data.append('action','lgw_save_score_override'); data.append('nonce',scoresNonce);
            data.append('csv_url',row.dataset.csv); data.append('date',row.dataset.date);
            data.append('home',row.dataset.home);   data.append('away',row.dataset.away);
            data.append('sh',sh); data.append('sa',sa); data.append('ph',ph); data.append('pa',pa);
            fetch(scoresAjax,{method:'POST',body:data})
                .then(function(r){return r.json();})
                .then(function(r){
                    if(r.success){
                        var clearing=(sh===''&&sa===''&&ph===''&&pa==='');
                        var sheet=r.data&&r.data.sheet?r.data.sheet:'';
                        var sheetLabel={'sheet_ok':' + sheet ✓','sheet_error':' (sheet error)',
                            'row_not_found':' (row not found)','auth_failed':' (auth failed)',
                            'fetch_failed':' (sheet fetch failed)','no_tab':' (no tab mapped)',
                            'no_spreadsheet_id':' (no spreadsheet ID)'}[sheet]||'';
                        status.textContent=clearing?'Cleared':('✓ Saved'+sheetLabel);
                        status.style.color=clearing?'#888':(sheet==='sheet_ok'||sheet==='sheets_disabled')?'#2a7a2a':'#c07000';
                        row.classList.toggle('lgw-overridden',!clearing);
                        var cb=row.querySelector('.lgw-clear-override');
                        if(!clearing&&!cb){
                            cb=document.createElement('button'); cb.type='button';
                            cb.className='lgw-clear-override button-link';
                            cb.style.cssText='color:#c0202a;font-size:11px;display:block';
                            cb.textContent='✕ clear';
                            row.querySelector('.lgw-save-status').parentNode.appendChild(cb);
                            bindClear(row,cb);
                        } else if(clearing&&cb){ cb.remove(); }
                        setTimeout(function(){status.textContent='';},4000);
                    } else { status.textContent='✗ Error'; status.style.color='#c0202a'; }
                }).catch(function(){ status.textContent='✗ Error'; status.style.color='#c0202a'; });
        }
        function bindClear(row,btn){
            btn.addEventListener('click',function(){
                row.querySelector('.lgw-sh').value='';
                row.querySelector('.lgw-sa').value='';
                row.querySelector('.lgw-ph').value='';
                row.querySelector('.lgw-pa').value='';
                saveRow(row,'','','','');
            });
        }
        document.querySelectorAll('.lgw-score-row').forEach(function(row){
            row.querySelectorAll('input').forEach(function(inp){
                inp.addEventListener('blur',function(){
                    saveRow(row,
                        row.querySelector('.lgw-sh').value.trim(),
                        row.querySelector('.lgw-sa').value.trim(),
                        row.querySelector('.lgw-ph').value.trim(),
                        row.querySelector('.lgw-pa').value.trim());
                });
            });
            var cb=row.querySelector('.lgw-clear-override'); if(cb) bindClear(row,cb);
        });
        var clearAll=document.getElementById('lgw-clear-all');
        if(clearAll){
            clearAll.addEventListener('click',function(){
                if(!confirm('Remove all score overrides for this division?')) return;
                var data=new FormData();
                data.append('action','lgw_clear_score_overrides');
                data.append('nonce',scoresNonce); data.append('csv_url',clearAll.dataset.csv);
                fetch(scoresAjax,{method:'POST',body:data}).then(function(r){return r.json();})
                    .then(function(r){if(r.success) location.reload();});
            });
        }
        // ── Backfill untagged scorecard seasons ──────────────────────────────
        var backfillBtn = document.getElementById('lgw-backfill-sc-seasons-btn');
        if (backfillBtn) {
            backfillBtn.addEventListener('click', function() {
                if (!confirm('Tag all untagged scorecards to the current season? This cannot be undone.')) return;
                backfillBtn.disabled = true;
                backfillBtn.textContent = '⏳ Tagging\u2026';
                var msg = document.getElementById('lgw-backfill-sc-msg');
                var fd = new FormData();
                fd.append('action',    'lgw_backfill_sc_seasons');
                fd.append('nonce',     backfillBtn.dataset.nonce);
                fd.append('season_id', backfillBtn.dataset.season);
                fetch(scoresAjax, {method:'POST', body:fd})
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            if (msg) msg.textContent = '✅ Tagged ' + (r.data.count || 0) + ' scorecard(s).';
                            setTimeout(function(){ location.reload(); }, 1200);
                        } else {
                            backfillBtn.disabled = false;
                            backfillBtn.textContent = '🏷️ Tag all to this season';
                            if (msg) msg.textContent = '❌ ' + (r.data || 'Failed');
                        }
                    })
                    .catch(function(){
                        backfillBtn.disabled = false;
                        backfillBtn.textContent = '🏷️ Tag all to this season';
                        if (msg) msg.textContent = '❌ Network error';
                    });
            });
        }
        // ── Season tag dropdown ──────────────────────────────────────────────────
        document.querySelectorAll('.lgw-sc-season-select').forEach(function(sel) {
            sel.addEventListener('change', function() {
                var postId  = sel.dataset.id;
                var nonce   = sel.dataset.nonce;
                var season  = sel.value;
                var statusEl = sel.parentNode.querySelector('.lgw-retag-status');
                sel.disabled = true;
                if (statusEl) statusEl.textContent = '⏳';
                var fd = new FormData();
                fd.append('action',    'lgw_retag_scorecard');
                fd.append('nonce',     nonce);
                fd.append('post_id',   postId);
                fd.append('season_id', season);
                fetch(scoresAjax, {method:'POST', body:fd})
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        sel.disabled = false;
                        if (r.success) {
                            if (statusEl) { statusEl.textContent = '✅'; setTimeout(function(){ statusEl.textContent=''; }, 2000); }
                        } else {
                            if (statusEl) statusEl.textContent = '❌ ' + (r.data || 'Failed');
                        }
                    })
                    .catch(function(){
                        sel.disabled = false;
                        if (statusEl) statusEl.textContent = '❌ Network error';
                    });
            });
        });

        // ── Quick Score Entry — date filter ─────────────────────────────────
        var dateFilter = document.getElementById('lgw-date-filter');
        var dateCount  = document.getElementById('lgw-date-filter-count');
        if (dateFilter) {
            function applyDateFilter() {
                var val = dateFilter.value;
                var rows = document.querySelectorAll('.lgw-score-row');
                var shown = 0;
                rows.forEach(function(r) {
                    var match = !val || r.dataset.date === val;
                    r.style.display = match ? '' : 'none';
                    if (match) shown++;
                });
                if (dateCount) {
                    dateCount.textContent = val ? shown + ' fixture' + (shown !== 1 ? 's' : '') + ' on this date' : '';
                }
                // Scroll first matching row into view
                if (val) {
                    var first = document.querySelector('.lgw-score-row[data-date="' + val + '"]');
                    if (first) first.scrollIntoView({behavior:'smooth', block:'nearest'});
                }
            }
            dateFilter.addEventListener('change', applyDateFilter);
        }

        // ── Submitted Scorecards — division + status filters ─────────────────
        var scDivFilter    = document.getElementById('lgw-sc-div-filter');
        var scStatusFilter = document.getElementById('lgw-sc-status-filter');
        var scFilterCount  = document.getElementById('lgw-sc-filter-count');
        function applyScFilters() {
            var divVal    = scDivFilter    ? scDivFilter.value    : '';
            var statusVal = scStatusFilter ? scStatusFilter.value : '';
            var rows = document.querySelectorAll('#lgw-sc-table .lgw-sc-row');
            var shown = 0;
            rows.forEach(function(row) {
                // Each data row is followed by a detail row (td colspan=7)
                var detailRow = row.nextElementSibling;
                var matchDiv    = !divVal    || row.dataset.division === divVal;
                var matchStatus = !statusVal || row.dataset.status   === statusVal;
                var visible = matchDiv && matchStatus;
                row.style.display = visible ? '' : 'none';
                if (detailRow) detailRow.style.display = visible ? '' : 'none';
                if (visible) shown++;
            });
            if (scFilterCount) {
                var hasFilter = divVal || statusVal;
                scFilterCount.textContent = hasFilter ? shown + ' scorecard' + (shown !== 1 ? 's' : '') + ' shown' : '';
            }
        }
        if (scDivFilter)    scDivFilter.addEventListener('change',    applyScFilters);
        if (scStatusFilter) scStatusFilter.addEventListener('change', applyScFilters);

    });
    </script>

    <?php
    // Badge counts for section headers
    $disputed_count = 0;
    $pending_count  = 0;
    foreach ($posts as $p) {
        $st = get_post_meta($p->ID, 'lgw_sc_status', true) ?: 'pending';
        if ($st === 'disputed') $disputed_count++;
        elseif ($st === 'pending') $pending_count++;
    }
    $override_count = count(array_filter($overrides, function($v) use ($divisions) {
        foreach ($divisions as $d) { if (($v['csv_url']??'') === $d['csv_url']) return true; }
        return false;
    }));
    ?>

    <!-- ── Quick Score Entry section ── -->
    <div class="lgw-section" id="lgw-sec-scores">
        <div class="lgw-section-header" onclick="lgwToggleSection('lgw-sec-scores')">
            <span class="lgw-section-chevron">&#9654;</span>
            <h2>📝 Quick Score Entry</h2>
            <?php if ($override_count > 0): ?>
                <span class="lgw-section-badge has-items"><?php echo $override_count; ?> override<?php echo $override_count !== 1 ? 's' : ''; ?> active</span>
            <?php endif; ?>
        </div>
        <div class="lgw-section-body">
        <?php if (empty($divisions)): ?>
            <div class="notice notice-warning inline"><p>No divisions with CSV URLs configured. Go to <a href="<?php echo admin_url('admin.php?page=' . LGW_SETUP_PAGE); ?>">League Setup</a> and scroll down to <strong>Google Sheets Writeback</strong>.</p></div>
        <?php else: ?>
            <p style="color:#666;font-size:13px;margin-top:0">Enter or correct scores before full scorecards are submitted. Saves directly to Google Sheets. Leave all fields blank to remove an override.</p>
            <div style="margin-bottom:12px">
                <strong>Division:</strong>
                <?php foreach ($divisions as $i => $d): ?>
                    <a href="<?php echo admin_url('admin.php?page=lgw-scorecards&div=' . $i); ?>"
                       class="button<?php echo $i === $sel_div ? ' button-primary' : ''; ?>"
                       style="margin-left:6px"><?php echo esc_html($d['division']); ?></a>
                <?php endforeach; ?>
            </div>
            <?php if ($fetch_err): ?>
                <div class="notice notice-error inline"><p>Could not fetch CSV: <?php echo esc_html($fetch_err); ?></p></div>
            <?php elseif ($sel && empty($fixtures)): ?>
                <div class="notice notice-warning inline"><p>No fixtures found in CSV for this division.</p></div>
            <?php elseif ($sel): ?>
            <div style="margin-bottom:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <label for="lgw-date-filter" style="font-weight:600;font-size:13px">Jump to date:</label>
                <select id="lgw-date-filter" style="font-size:13px;max-width:240px">
                    <option value="">— All dates —</option>
                    <?php
                    $seen_dates = array();
                    foreach ($fixtures as $fx) {
                        if (!in_array($fx['date'], $seen_dates, true)) {
                            $seen_dates[] = $fx['date'];
                            echo '<option value="' . esc_attr($fx['date']) . '">' . esc_html($fx['date']) . '</option>';
                        }
                    }
                    ?>
                </select>
                <span id="lgw-date-filter-count" style="font-size:12px;color:#666"></span>
            </div>
            <table class="widefat striped" id="lgw-scores-table" style="font-size:13px;max-width:900px">
                <thead><tr>
                    <th style="width:130px">Date</th>
                    <th style="text-align:right">Home</th>
                    <th style="text-align:center;width:130px">Score</th>
                    <th>Away</th>
                    <th style="text-align:center;width:90px">Pts</th>
                    <th style="width:80px">Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($fixtures as $fx): ?>
                <?php $has_override = $fx['overridden']; ?>
                <tr class="lgw-score-row<?php echo $has_override ? ' lgw-overridden' : ''; ?>"
                    data-csv="<?php echo esc_attr($sel['csv_url']); ?>"
                    data-date="<?php echo esc_attr($fx['date']); ?>"
                    data-home="<?php echo esc_attr($fx['home']); ?>"
                    data-away="<?php echo esc_attr($fx['away']); ?>">
                    <td style="font-size:12px;color:#666"><?php echo esc_html($fx['date']); ?></td>
                    <td style="text-align:right;font-weight:600"><?php echo esc_html($fx['home']); ?></td>
                    <td style="text-align:center">
                        <div style="display:flex;align-items:center;justify-content:center;gap:4px">
                            <input type="number" class="lgw-sh small-text" value="<?php echo esc_attr($fx['sh']); ?>"
                                   placeholder="<?php echo esc_attr($fx['sh_orig']); ?>" min="0" style="width:48px;text-align:center">
                            <span>–</span>
                            <input type="number" class="lgw-sa small-text" value="<?php echo esc_attr($fx['sa']); ?>"
                                   placeholder="<?php echo esc_attr($fx['sa_orig']); ?>" min="0" style="width:48px;text-align:center">
                        </div>
                    </td>
                    <td style="font-weight:600"><?php echo esc_html($fx['away']); ?></td>
                    <td style="text-align:center">
                        <div style="display:flex;align-items:center;justify-content:center;gap:2px">
                            <input type="number" class="lgw-ph small-text" value="<?php echo esc_attr($fx['ph']); ?>"
                                   placeholder="<?php echo esc_attr($fx['ph_orig']); ?>" min="0" max="7" style="width:38px;text-align:center">
                            <span>–</span>
                            <input type="number" class="lgw-pa small-text" value="<?php echo esc_attr($fx['pa']); ?>"
                                   placeholder="<?php echo esc_attr($fx['pa_orig']); ?>" min="0" max="7" style="width:38px;text-align:center">
                        </div>
                    </td>
                    <td style="text-align:center">
                        <span class="lgw-save-status" style="font-size:11px"></span>
                        <?php if ($has_override): ?>
                            <button type="button" class="lgw-clear-override button-link" style="color:#c0202a;font-size:11px;display:block">✕ clear</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:12px">
                <button type="button" id="lgw-clear-all" class="button button-secondary" style="color:#c0202a"
                        data-csv="<?php echo esc_attr($sel['csv_url']); ?>">Clear all overrides for this division</button>
            </p>
            <?php endif; ?>
        <?php endif; ?>
        </div><!-- /.lgw-section-body -->
    </div><!-- /#lgw-sec-scores -->

    <!-- ── Submitted Scorecards section ── -->
    <?php
    $sc_badge_cls = $disputed_count > 0 ? 'has-warn' : ($pending_count > 0 ? 'has-items' : '');
    $sc_badge_txt = $disputed_count > 0
        ? $disputed_count . ' disputed'
        : ($pending_count > 0 ? $pending_count . ' pending' : count($posts) . ' total');
    ?>
    <div class="lgw-section" id="lgw-sec-scorecards">
        <div class="lgw-section-header" onclick="lgwToggleSection('lgw-sec-scorecards')">
            <span class="lgw-section-chevron">&#9654;</span>
            <h2>📋 Submitted Scorecards</h2>
            <?php if (!empty($posts)): ?>
                <span class="lgw-section-badge <?php echo esc_attr($sc_badge_cls); ?>"><?php echo esc_html($sc_badge_txt); ?></span>
            <?php endif; ?>
        </div>
        <div class="lgw-section-body">

        <?php if (!empty($all_seasons_list)): ?>
        <!-- ── Season switcher ── -->
        <div class="lgw-season-switcher" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;align-items:center">
            <span style="font-size:12px;font-weight:600;color:#555;margin-right:4px">Season:</span>
            <?php
            // Active season first, then archived newest-first
            $sw_seasons = array();
            foreach ($all_seasons_list as $s) { if (!empty($s['active'])) { $sw_seasons[] = $s; break; } }
            $archived_sw = array_values(array_filter($all_seasons_list, function($s){ return empty($s['active']); }));
            usort($archived_sw, function($a,$b){ return strcmp($b['id'],$a['id']); });
            $sw_seasons = array_merge($sw_seasons, $archived_sw);
            foreach ($sw_seasons as $sw_s):
                $sw_active = ($sw_s['id'] === $viewing_season_id);
                $sw_url    = admin_url('admin.php?page=lgw-scorecards&sc_season=' . urlencode($sw_s['id']) . '#lgw-sec-scorecards');
            ?>
            <a href="<?php echo esc_url($sw_url); ?>"
               class="button button-small<?php echo $sw_active ? ' button-primary' : ''; ?>"
               style="<?php echo $sw_active ? 'font-weight:700' : ''; ?>">
                <?php echo esc_html($sw_s['label'] ?: $sw_s['id']); ?>
                <?php if (!empty($sw_s['active'])): ?><span style="font-size:10px;opacity:.8"> ★</span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($posts)): ?>
            <p>No scorecards found for this season.</p>
        <?php else: ?>

        <?php if ($mismatched_count > 0): ?>
        <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span>⚠️ <strong><?php echo $mismatched_count; ?> scorecard<?php echo $mismatched_count > 1 ? 's have' : ' has'; ?> a match date in this season but <?php echo $mismatched_count > 1 ? 'are' : 'is'; ?> tagged to a different season.</strong> Run backfill to reassign.</span>
            <button type="button" class="button button-small" id="lgw-backfill-sc-seasons-btn"
                    data-season="<?php echo esc_attr($viewing_season_id); ?>"
                    data-nonce="<?php echo wp_create_nonce('lgw_backfill_sc_seasons_' . $viewing_season_id); ?>">
                🏷️ Reassign to this season
            </button>
            <span id="lgw-backfill-sc-msg" style="font-size:12px"></span>
        </div>
        <?php endif; ?>

        <?php
        // Collect unique divisions for the filter dropdown
        $sc_divisions = array();
        foreach ($posts as $_sp) {
            $_sc_d = get_post_meta($_sp->ID, 'lgw_scorecard_data', true);
            $_div  = trim($_sc_d['division'] ?? '');
            if ($_div && !in_array($_div, $sc_divisions, true)) $sc_divisions[] = $_div;
        }
        sort($sc_divisions);
        ?>
        <div style="margin-bottom:10px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
            <?php if (!empty($sc_divisions)): ?>
            <div style="display:flex;align-items:center;gap:6px">
                <label for="lgw-sc-div-filter" style="font-weight:600;font-size:13px">Division:</label>
                <select id="lgw-sc-div-filter" style="font-size:13px;max-width:200px">
                    <option value="">— All —</option>
                    <?php foreach ($sc_divisions as $_d): ?>
                    <option value="<?php echo esc_attr($_d); ?>"><?php echo esc_html($_d); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div style="display:flex;align-items:center;gap:6px">
                <label for="lgw-sc-status-filter" style="font-weight:600;font-size:13px">Status:</label>
                <select id="lgw-sc-status-filter" style="font-size:13px">
                    <option value="">— All —</option>
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="disputed">Disputed</option>
                    <option value="admin_resolved">Admin resolved</option>
                </select>
            </div>
            <span id="lgw-sc-filter-count" style="font-size:12px;color:#666"></span>
        </div>
        <table class="widefat striped" id="lgw-sc-table">
        <thead><tr>
            <th>Match</th><th>Division</th>
            <th>Season</th>
            <th>Result (home v away)</th>
            <th>Status</th>
            <th>Submitted</th>
            <th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($posts as $p):
        try {
            $sc       = get_post_meta($p->ID, 'lgw_scorecard_data',  true);
            $status   = get_post_meta($p->ID, 'lgw_sc_status',       true) ?: 'pending';
            $sub_by   = get_post_meta($p->ID, 'lgw_submitted_by',    true);
            $con_by   = get_post_meta($p->ID, 'lgw_confirmed_by',    true);
            $away_sc  = get_post_meta($p->ID, 'lgw_away_scorecard',  true);
            $sc_ctx   = get_post_meta($p->ID, 'lgw_sc_context',      true) ?: 'league';
            $mc_game  = get_post_meta($p->ID, 'lgw_multichamp_game_id', true);
            // Re-evaluate live so stale flags don't persist after division is corrected
            $drive_opts_list  = lgw_get_option_array('lgw_drive');
            $sc_arr           = is_array($sc) ? $sc : array(); // guard: meta may be false on new/malformed posts
            // Treat cup scorecards as cup even if the context meta is stale/mis-tagged
            $is_league_ctx_list = ($sc_ctx === 'league') && !(function_exists('lgw_scorecard_is_cup_by_data') && lgw_scorecard_is_cup_by_data($sc_arr));
            $resolved_tab_list = lgw_sheets_tab_for_division($sc_arr['division'] ?? '', $drive_opts_list);
            $div_unresolved   = $is_league_ctx_list && (empty($sc_arr['division']) || !$resolved_tab_list);
            if (!$div_unresolved) delete_post_meta($p->ID, 'lgw_division_unresolved');
            $result   = ($sc_arr && isset($sc_arr['home_total']))
                ? $sc_arr['home_total'].' ('.($sc_arr['home_points'] ?? '?').'pts) – '.$sc_arr['away_total'].' ('.($sc_arr['away_points'] ?? '?').'pts)'
                : '—';
            $status_labels = array('pending'=>'Pending','confirmed'=>'Confirmed','disputed'=>'Disputed');
            $sc_tag    = get_post_meta($p->ID, 'lgw_sc_season', true) ?: '';
        ?>
        <tr class="lgw-sc-row" data-division="<?php echo esc_attr($sc_arr['division'] ?? ''); ?>" data-status="<?php echo esc_attr($status); ?>">
            <td>
                <strong><?php echo esc_html($p->post_title); ?></strong>
                <?php if (get_post_meta($p->ID, 'lgw_admin_edited', true)): ?>
                    <span class="lgw-sc-amended" title="Amended by admin">Amended</span>
                <?php endif; ?>
                <?php
                $ctx_labels = [ 'league' => '', 'cup' => '🏆 Cup', 'multichamp' => '🏅 Multi-champ', 'champ' => '🎯 Champ' ];
                $ctx_label  = $ctx_labels[ $sc_ctx ] ?? esc_html( $sc_ctx );
                if ( $ctx_label ) echo ' <span class="lgw-sc-ctx-badge lgw-sc-ctx-' . esc_attr($sc_ctx) . '">' . $ctx_label . '</span>';
                if ( $mc_game ) echo ' <span class="lgw-sc-mc-ref" title="' . esc_attr($mc_game) . '">ref: ' . esc_html( implode( ' · ', array_slice( explode(':', $mc_game), 2 ) ) ) . '</span>';
                ?>
            </td>
            <td>
                <?php echo esc_html($sc_arr['division'] ?? '—'); ?>
                <?php if ($div_unresolved): ?>
                    <span class="lgw-sc-div-warn" title="Division not matched to a sheet tab — sheet writeback will be skipped until corrected">⚠️ Unresolved</span>
                <?php endif; ?>
            </td>
            <td>
                <?php
                $sc_nonce_tag = wp_create_nonce('lgw_retag_sc_' . $p->ID);
                ?>
                <select class="lgw-sc-season-select" style="max-width:110px;font-size:12px"
                        data-id="<?php echo $p->ID; ?>"
                        data-nonce="<?php echo esc_attr($sc_nonce_tag); ?>">
                    <option value="" <?php selected($sc_tag, ''); ?>>— untagged —</option>
                    <?php foreach ($all_seasons_list as $sw_opt): ?>
                    <option value="<?php echo esc_attr($sw_opt['id']); ?>"
                            <?php selected($sc_tag, $sw_opt['id']); ?>>
                        <?php echo esc_html($sw_opt['label'] ?: $sw_opt['id']); ?>
                        <?php if (!empty($sw_opt['active'])) echo ' ★'; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="lgw-retag-status" style="font-size:11px;display:block;margin-top:2px"></span>
            </td>
            <td><?php echo esc_html($result); ?></td>
            <td><span class="lgw-sc-status <?php echo esc_attr($status); ?>"><?php echo $status_labels[$status] ?? $status; ?></span></td>
            <td><?php echo get_the_date('d M Y H:i', $p); ?><br><small>by <?php echo esc_html($sub_by ?: '—'); ?></small></td>
            <td style="white-space:nowrap">
                <button class="button button-small" onclick="lgwShowPanel(<?php echo $p->ID; ?>,'view')">View</button>
                <button class="button button-small" onclick="lgwShowPanel(<?php echo $p->ID; ?>,'edit')">✏️ Edit</button>
                <button class="button button-small" onclick="lgwShowPanel(<?php echo $p->ID; ?>,'history')">📋 History</button>
                <a href="<?php echo get_delete_post_link($p->ID); ?>" class="button button-small button-link-delete" onclick="return confirm('Delete this scorecard?')">Delete</a>
            </td>
        </tr>
        <tr>
            <td colspan="7" style="padding:0">
            <div class="lgw-admin-sc-wrap" id="sc-<?php echo $p->ID; ?>">
                <div class="lgw-sc-subpanel" data-panel="view">
                <?php if ($status === 'disputed' && $away_sc): ?>
                    <p><strong>⚠️ Disputed result</strong> — <?php echo esc_html($sub_by); ?> submitted first, <?php echo esc_html($con_by); ?> submitted a different result.</p>
                    <div class="lgw-sc-compare">
                        <div class="lgw-sc-version">
                            <h4>Version A — submitted by <?php echo esc_html($sub_by); ?></h4>
                            <?php lgw_admin_render_sc_summary($sc); ?>
                        </div>
                        <div class="lgw-sc-version">
                            <h4>Version B — submitted by <?php echo esc_html($con_by); ?></h4>
                            <?php lgw_admin_render_sc_summary($away_sc); ?>
                        </div>
                    </div>
                    <div class="lgw-resolve-btns">
                        <button class="button button-primary" onclick="lgwResolve(<?php echo $p->ID; ?>,'home','<?php echo $sc_nonce; ?>')">✅ Accept Version A</button>
                        <button class="button button-primary" onclick="lgwResolve(<?php echo $p->ID; ?>,'away','<?php echo $sc_nonce; ?>')">✅ Accept Version B</button>
                    </div>
                    <div id="lgw-resolve-msg-<?php echo $p->ID; ?>" style="display:none;margin-top:8px;padding:8px;background:#d1e7dd;color:#0a3622;border-radius:4px"></div>
                <?php else: ?>
                    <div class="lgw-sc-version">
                        <?php lgw_admin_render_sc_summary($sc); ?>
                        <?php if ($status === 'confirmed'): ?>
                            <p style="color:#0a3622;font-size:12px">✅ Confirmed by <?php echo esc_html($con_by); ?></p>
                        <?php elseif ($status === 'pending'): ?>
                            <p style="color:#856404;font-size:12px">⏳ Awaiting confirmation from the other club</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                </div>
                <div class="lgw-sc-subpanel" data-panel="edit" style="display:none">
                    <?php lgw_render_admin_edit_form($p->ID, $sc); ?>
                </div>
                <div class="lgw-sc-subpanel" data-panel="history" style="display:none">
                    <h4 style="margin:0 0 10px;color:#1a2e5a">📋 Audit History</h4>
                    <?php lgw_render_audit_log($p->ID); ?>
                    <h4 style="margin:14px 0 6px;color:#1a2e5a">☁️ Google Drive Log</h4>
                    <?php lgw_render_drive_log($p->ID); ?>
                    <?php lgw_render_sheets_log($p->ID); ?>
                </div>
            </div>
            </td>
        </tr>
        <?php
        } catch (\Throwable $e) {
            $pid_str = isset($p->ID) ? $p->ID : 'unknown';
            $sc_dump = isset($sc) ? (is_array($sc) ? json_encode(array_intersect_key($sc, array_flip(['home_team','away_team','date','division','home_total','away_total','home_points','away_points']))) : var_export($sc, true)) : 'not set';
            error_log('[LGW] Scorecard listing fatal — post ID: ' . $pid_str
                . ' | error: ' . $e->getMessage()
                . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
                . ' | sc_data: ' . $sc_dump);
            echo '<tr><td colspan="7" style="background:#f8d7da;color:#842029;padding:8px 12px;font-size:12px">'
                . '⚠️ Error rendering scorecard post #' . intval($pid_str)
                . ' — ' . esc_html($e->getMessage())
                . ' in ' . esc_html(basename($e->getFile())) . ':' . intval($e->getLine())
                . ' (check PHP error log for details)</td></tr>';
        }
        endforeach; ?>
        </tbody>
        </table>
        <?php endif; ?>

        <!-- ── Concessions management ── -->
        <div style="margin-top:28px">
        <h3 style="margin-bottom:10px">🏳️ Conceded Fixtures</h3>
        <?php
        $all_concessions = lgw_get_concessions();
        if (empty($all_concessions)):
        ?>
        <p style="color:#666;font-size:13px">No conceded fixtures recorded.</p>
        <?php else: ?>
        <table class="widefat striped" style="max-width:900px">
            <thead><tr>
                <th>Home</th><th>Away</th><th>Date</th><th>Conceding Team</th><th>Conceded On</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($all_concessions as $ck => $ce):
                $parts   = explode('||', $ck);
                $c_home  = $parts[0] ?? '';
                $c_away  = $parts[1] ?? '';
                $c_date  = $parts[2] ?? '';
                $c_side  = $ce['conceding_team'] ?? 'away';
                $c_name  = $c_side === 'home' ? $c_home : $c_away;
                $c_on    = $ce['conceded_on'] ?? '';
                $c_sc_id = $ce['scorecard_id'] ?? '';
            ?>
            <tr>
                <td><?php echo esc_html(ucwords($c_home)); ?></td>
                <td><?php echo esc_html(ucwords($c_away)); ?></td>
                <td><?php echo esc_html($c_date); ?></td>
                <td><span class="lgw-badge-pill"><?php echo esc_html(ucwords($c_name)); ?></span></td>
                <td style="color:#888;font-size:12px"><?php echo esc_html($c_on ?: '—'); ?></td>
                <td>
                    <button class="button button-small lgw-concession-clear-btn"
                        data-key="<?php echo esc_attr($ck); ?>"
                        data-home="<?php echo esc_attr($c_home); ?>"
                        data-away="<?php echo esc_attr($c_away); ?>"
                        data-date="<?php echo esc_attr($c_date); ?>"
                        data-side="<?php echo esc_attr($c_side); ?>"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('lgw_submit_nonce')); ?>"
                        style="color:#b32d2e;border-color:#b32d2e">
                        ✕ Clear
                    </button>
                    <span class="lgw-concession-clear-status" style="margin-left:8px;font-size:12px;display:none"></span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        (function(){
            document.querySelectorAll('.lgw-concession-clear-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    if(!confirm('Clear this concession and restore the fixture to unplayed?')) return;
                    var row    = btn.closest('tr');
                    var status = row.querySelector('.lgw-concession-clear-status');
                    btn.disabled = true; btn.textContent = '⏳';
                    var fd = new FormData();
                    fd.append('action',            'lgw_save_concession');
                    fd.append('nonce',             btn.dataset.nonce);
                    fd.append('home',              btn.dataset.home);
                    fd.append('away',              btn.dataset.away);
                    fd.append('date',              btn.dataset.date);
                    fd.append('division',          '');
                    fd.append('concession_action', 'clear');
                    fd.append('conceding_team',    btn.dataset.side);
                    fetch(ajaxurl, {method:'POST', body:fd})
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if(res.success){
                                status.textContent = '✅ Cleared';
                                status.style.display = 'inline';
                                status.style.color = 'green';
                                row.style.opacity = '0.4';
                                btn.style.display = 'none';
                            } else {
                                btn.disabled = false; btn.textContent = '✕ Clear';
                                status.textContent = '❌ ' + (res.data || 'Failed');
                                status.style.display = 'inline';
                                status.style.color = 'red';
                            }
                        })
                        .catch(function(){
                            btn.disabled = false; btn.textContent = '✕ Clear';
                            status.textContent = '❌ Network error';
                            status.style.display = 'inline';
                        });
                });
            });
        })();
        </script>
        <?php endif; ?>
        </div>

        </div><!-- /.lgw-section-body -->
    </div><!-- /#lgw-sec-scorecards -->

    </div><!-- /.wrap -->
    <?php
}

function lgw_admin_render_sc_summary($sc) {
    if (!$sc) { echo '<p>No data.</p>'; return; }
    echo '<div class="lgw-sc-rink-row"><strong>'.esc_html($sc['home_team'] ?? '').' v '.esc_html($sc['away_team'] ?? '').'</strong></div>';
    foreach (($sc['rinks'] ?? array()) as $rk) {
        echo '<div class="lgw-sc-rink-row">Rink '.$rk['rink'].': '.intval($rk['home_score']).' – '.intval($rk['away_score']).'</div>';
    }
    echo '<div class="lgw-sc-totals">Total: '.($sc['home_total'] ?? '?').' – '.($sc['away_total'] ?? '?')
        .'&nbsp;&nbsp;Points: '.($sc['home_points'] ?? '?').' – '.($sc['away_points'] ?? '?').'</div>';
}

add_action('admin_enqueue_scripts', 'lgw_admin_enqueue');
function lgw_admin_enqueue($hook) {
    $lgw_hooks = array(
        'lgw_page_lgw-settings',        // Settings submenu
        'lgw_page_lgw-league-setup',    // League Setup submenu
        'lgw_page_lgw-cups',            // Cups submenu
        'lgw_page_lgw-champs',          // Championships submenu
        'toplevel_page_lgw-scorecards',   // Top-level itself
    );
    if (!in_array($hook, $lgw_hooks, true)) return;
    wp_enqueue_media();
    wp_enqueue_script('lgw-admin', plugin_dir_url(__FILE__) . 'lgw-admin.js', array('jquery'), LGW_VERSION, true);
    wp_enqueue_style('lgw-admin', plugin_dir_url(__FILE__) . 'lgw-admin.css', array(), LGW_VERSION);
}

add_action('admin_post_lgw_save_settings', 'lgw_save_settings');
function lgw_save_settings() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('lgw_settings_nonce');

    // Club badges — now derived from the merged club rows (lgw_club_name + lgw_image + lgw_badge_type)
    $club_names_for_badges = isset($_POST['lgw_club_name']) ? array_map('sanitize_text_field', $_POST['lgw_club_name']) : array();
    $images                = isset($_POST['lgw_image'])      ? array_map('esc_url_raw',         $_POST['lgw_image'])      : array();
    $types                 = isset($_POST['lgw_badge_type']) ? array_map('sanitize_text_field', $_POST['lgw_badge_type']) : array();

    $badges      = array();
    $club_badges = array();
    foreach ($club_names_for_badges as $i => $team) {
        $team = trim($team);
        if ($team !== '' && !empty($images[$i])) {
            $type = isset($types[$i]) ? $types[$i] : 'club';
            if ($type === 'exact') {
                $badges[$team] = $images[$i];
            } else {
                $club_badges[$team] = $images[$i];
            }
        }
    }
    update_option('lgw_badges',      $badges);
    update_option('lgw_club_badges', $club_badges);

    // Sponsors
    $sp_images = isset($_POST['lgw_sp_image']) ? array_map('esc_url_raw',         $_POST['lgw_sp_image']) : array();
    $sp_urls   = isset($_POST['lgw_sp_url'])   ? array_map('esc_url_raw',         $_POST['lgw_sp_url'])   : array();
    $sp_names  = isset($_POST['lgw_sp_name'])  ? array_map('sanitize_text_field', $_POST['lgw_sp_name'])  : array();
    $sponsors  = array();
    foreach ($sp_images as $i => $img) {
        if (!empty($img)) {
            $sponsors[] = array(
                'image' => $img,
                'url'   => !empty($sp_urls[$i])  ? $sp_urls[$i]  : '',
                'name'  => !empty($sp_names[$i]) ? $sp_names[$i] : '',
            );
        }
    }
    update_option('lgw_sponsors', $sponsors);

    // Theme colours + font
    $allowed_fonts = array_keys( lgw_font_options() );
    $font_raw      = sanitize_text_field( $_POST['lgw_font_family'] ?? '' );
    $theme = array(
        'color_primary'   => sanitize_hex_color($_POST['lgw_color_primary']   ?? '') ?: '',
        'color_secondary' => sanitize_hex_color($_POST['lgw_color_secondary'] ?? '') ?: '',
        'color_bg'        => sanitize_hex_color($_POST['lgw_color_bg']        ?? '') ?: '',
        'font_family'     => in_array( $font_raw, $allowed_fonts ) ? $font_raw : '',
    );
    update_option('lgw_theme', $theme);

    // Clubs / passphrases
    $club_names     = isset($_POST['lgw_club_name']) ? array_map('sanitize_text_field', $_POST['lgw_club_name']) : array();
    $club_pins      = isset($_POST['lgw_club_pin'])  ? $_POST['lgw_club_pin']  : array();
    $existing_clubs = get_option('lgw_clubs', array());
    $clubs = array();
    foreach ($club_names as $i => $name) {
        $name = trim($name);
        if ($name === '') continue;
        $pin_raw        = trim($club_pins[$i] ?? '');
        $pin_normalised = strtolower(preg_replace('/\s+/', ' ', $pin_raw));
        $existing_hash  = '';
        foreach ($existing_clubs as $ec) {
            if (strtolower($ec['name']) === strtolower($name)) { $existing_hash = $ec['pin']; break; }
        }
        $clubs[] = array(
            'name' => $name,
            'pin'  => $pin_normalised !== '' ? hash('sha256', $pin_normalised) : $existing_hash,
        );
    }
    update_option('lgw_clubs', $clubs);

    // GitHub Personal Access Token (for private repo auto-updates)
    if (isset($_POST['lgw_github_token'])) {
        update_option('lgw_github_token', sanitize_text_field($_POST['lgw_github_token']));
    }

    // Scorecard submission mode
    $allowed_modes = array('disabled', 'admin_only', 'open');
    $submission_mode = isset($_POST['lgw_submission_mode']) && in_array($_POST['lgw_submission_mode'], $allowed_modes)
        ? $_POST['lgw_submission_mode'] : 'open';
    update_option('lgw_submission_mode', $submission_mode);

    wp_redirect(admin_url('admin.php?page=lgw-settings&saved=1'));
    exit;
}

add_action('admin_post_lgw_save_league_setup', 'lgw_save_league_setup');
function lgw_save_league_setup() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('lgw_league_setup_nonce');

    // Data source
    $allowed_sources = array('google_sheets', 'upload', 'wordpress');
    $data_source = isset($_POST['lgw_data_source']) && in_array($_POST['lgw_data_source'], $allowed_sources)
        ? $_POST['lgw_data_source'] : 'google_sheets';
    update_option('lgw_data_source', $data_source);

    // Cache duration (Google Sheets only)
    $cache_mins = isset($_POST['lgw_cache_mins']) ? max(1, intval($_POST['lgw_cache_mins'])) : 5;
    update_option('lgw_cache_mins', $cache_mins);

    // Division cache cron sync interval
    $allowed_intervals = array('lgw_15min', 'lgw_30min', 'hourly', 'lgw_4hour');
    $cron_interval = isset($_POST['lgw_cache_cron_interval']) && in_array($_POST['lgw_cache_cron_interval'], $allowed_intervals)
        ? $_POST['lgw_cache_cron_interval'] : 'hourly';
    update_option('lgw_cache_cron_interval', $cron_interval);

    // Photo analysis provider
    $allowed_providers = array('disabled', 'anthropic', 'openai', 'gemini');
    $vision_provider = isset($_POST['lgw_vision_provider']) && in_array($_POST['lgw_vision_provider'], $allowed_providers)
        ? $_POST['lgw_vision_provider'] : 'anthropic';
    update_option('lgw_vision_provider', $vision_provider);

    // Anthropic API key
    if (isset($_POST['lgw_anthropic_key'])) {
        update_option('lgw_anthropic_key', sanitize_text_field($_POST['lgw_anthropic_key']));
    }

    // Drive + Sheets (only save if Google Sheets is the data source)
    lgw_drive_save_settings();

    // Merged divisions table — save to active season AND sheets_tabs simultaneously
    $div_names          = isset($_POST['lgw_div_name'])           ? array_map('sanitize_text_field', $_POST['lgw_div_name'])           : array();
    $div_urls           = isset($_POST['lgw_div_csv_url'])        ? array_map('esc_url_raw',         $_POST['lgw_div_csv_url'])        : array();
    $div_tabs           = isset($_POST['lgw_div_tab'])            ? array_map('sanitize_text_field', $_POST['lgw_div_tab'])            : array();
    $div_spreadsheet_ids = isset($_POST['lgw_div_spreadsheet_id']) ? array_map('sanitize_text_field', $_POST['lgw_div_spreadsheet_id']) : array();

    $divisions  = array();
    $sheets_tabs = array();
    foreach ($div_names as $i => $name) {
        $name           = trim($name);
        $url            = trim($div_urls[$i]            ?? '');
        $tab            = trim($div_tabs[$i]            ?? '');
        $spreadsheet_id = trim($div_spreadsheet_ids[$i] ?? '');
        if ($name !== '' && $url !== '') {
            $divisions[]   = array('division' => $name, 'csv_url' => $url);
            $sheets_tabs[] = array(
                'division'       => $name,
                'csv_url'        => $url,
                'tab'            => $tab,
                'spreadsheet_id' => $spreadsheet_id,
            );
        }
    }

    // Update active season divisions
    $seasons = lgw_get_seasons();
    $found   = false;
    foreach ($seasons as &$s) {
        if (!empty($s['active'])) {
            $s['divisions'] = $divisions;
            $found = true;
            break;
        }
    }
    unset($s);
    if (!$found && !empty($divisions)) {
        $year     = date('Y');
        $seasons[] = array(
            'id'        => $year,
            'label'     => $year . ' Season',
            'active'    => true,
            'divisions' => $divisions,
        );
    }
    update_option('lgw_seasons', $seasons);

    // Update drive/sheets tabs
    $opts_drive = lgw_get_option_array('lgw_drive');
    $opts_drive['sheets_enabled'] = !empty($_POST['lgw_sheets_enabled']) ? 1 : 0;
    $opts_drive['sheets_id']      = sanitize_text_field($_POST['lgw_sheets_id'] ?? '');
    $opts_drive['sheets_tabs']    = $sheets_tabs;
    update_option('lgw_drive', $opts_drive);

    // Points system
    $pts_rink    = isset($_POST['lgw_points_per_rink']) ? max(0, floatval($_POST['lgw_points_per_rink'])) : 1;
    $pts_overall = isset($_POST['lgw_points_overall'])  ? max(0, floatval($_POST['lgw_points_overall']))  : 3;
    update_option('lgw_points_per_rink',  $pts_rink);
    update_option('lgw_points_overall',   $pts_overall);

    wp_redirect(admin_url('admin.php?page=' . LGW_SETUP_PAGE . '&saved=1'));
    exit;
}

// ── Font options ──────────────────────────────────────────────────────────────

function lgw_font_options() {
    return [
        'Saira'          => [ 'label' => 'Saira (default)',       'weights' => '400;600;700' ],
        'Inter'          => [ 'label' => 'Inter',                 'weights' => '400;600;700' ],
        'Roboto'         => [ 'label' => 'Roboto',                'weights' => '400;700' ],
        'Oswald'         => [ 'label' => 'Oswald',                'weights' => '400;600;700' ],
        'Barlow'         => [ 'label' => 'Barlow',                'weights' => '400;600;700' ],
        'Nunito+Sans'    => [ 'label' => 'Nunito Sans',           'weights' => '400;600;700' ],
        'Raleway'        => [ 'label' => 'Raleway',               'weights' => '400;600;700' ],
        'Exo+2'          => [ 'label' => 'Exo 2',                 'weights' => '400;600;700' ],
        'Titillium+Web'  => [ 'label' => 'Titillium Web',        'weights' => '400;600;700' ],
        'DM+Sans'        => [ 'label' => 'DM Sans',               'weights' => '400;600;700' ],
    ];
}

function lgw_font_display_name( $key ) {
    return lgw_font_options()[ $key ]['label'] ?? $key;
}

// ── Theme colour helpers ───────────────────────────────────────────────────────

function lgw_hex_to_rgb($hex) {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return array(hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2)));
}

function lgw_rgb_to_hex($r, $g, $b) {
    return sprintf('#%02x%02x%02x', max(0,min(255,$r)), max(0,min(255,$g)), max(0,min(255,$b)));
}

// Lighten a hex colour by $amount (0-255 per channel)
function lgw_theme_lighten($hex, $amount) {
    list($r,$g,$b) = lgw_hex_to_rgb($hex);
    return lgw_rgb_to_hex($r+$amount, $g+$amount, $b+$amount);
}

// Darken a hex colour by $pct percent (0-100)
function lgw_theme_darken($hex, $pct) {
    list($r,$g,$b) = lgw_hex_to_rgb($hex);
    $f = 1 - ($pct / 100);
    return lgw_rgb_to_hex(intval($r*$f), intval($g*$f), intval($b*$f));
}

// Mix hex colour with white by $whitePct percent (higher = more white)
function lgw_theme_mix($hex, $white, $whitePct) {
    list($r1,$g1,$b1) = lgw_hex_to_rgb($hex);
    list($r2,$g2,$b2) = lgw_hex_to_rgb($white);
    $t = $whitePct / 100;
    return lgw_rgb_to_hex(intval($r1+(($r2-$r1)*$t)), intval($g1+(($g2-$g1)*$t)), intval($b1+(($b2-$b1)*$t)));
}

// ── Shared helper: resolve badge URL for a team/club name ─────────────────────
function lgw_resolve_badge_url($name, $badges, $club_badges) {
    if (!$name) return '';
    // 1. Exact match in team badges
    if (isset($badges[$name])) return $badges[$name];
    $upper = strtoupper($name);
    // 2. Case-insensitive exact match in team badges
    foreach ($badges as $key => $url) {
        if (strtoupper($key) === $upper) return $url;
    }
    // 3. Exact case-insensitive match in club badges (mirrors champBadge step 2)
    foreach ($club_badges as $club => $url) {
        if (strtoupper($club) === $upper) return $url;
    }
    // 4. Bidirectional prefix match in club badges — pick longest key that matches
    //    Covers: entry="Ballymena A", key="Ballymena"  (entry starts with key)
    //    And:    entry="Ballymena",   key="Ballymena BC" (key starts with entry)
    $best_key = ''; $best_url = '';
    foreach ($club_badges as $club => $url) {
        $cu = strtoupper($club);
        if (strpos($upper, $cu) === 0 || strpos($cu, $upper) === 0) {
            if (strlen($club) > strlen($best_key)) { $best_key = $club; $best_url = $url; }
        }
    }
    return $best_url;
}

// ── Shared helper: render a pre-draw entry list (badge + name rows) ────────────
// $entries  — flat array of entry strings
// $is_champ — true = "Player(s), Club" format; false = plain team name
function lgw_render_entry_list($entries, $is_champ = false) {
    if (empty($entries)) return '';
    $badges      = get_option('lgw_badges',      array());
    $club_badges = get_option('lgw_club_badges', array());
    $out = '<div class="lgw-entry-list">';
    foreach ($entries as $entry) {
        $entry = trim($entry);
        if (!$entry) continue;
        if ($is_champ) {
            $comma  = strpos($entry, ',');
            $player = $comma !== false ? trim(substr($entry, 0, $comma)) : $entry;
            $club   = $comma !== false ? trim(substr($entry, $comma + 1)) : '';
            $badge_url = lgw_resolve_badge_url($club, $badges, $club_badges);
            $badge_html = $badge_url
                ? '<img class="lgw-entry-badge" src="' . esc_url($badge_url) . '" alt="">'
                : '<span class="lgw-entry-badge-placeholder"></span>';
            $out .= '<div class="lgw-entry-row">'
                . $badge_html
                . '<span class="lgw-entry-name">' . esc_html($player) . '</span>'
                . ($club ? '<span class="lgw-entry-club">' . esc_html($club) . '</span>' : '')
                . '</div>';
        } else {
            $badge_url  = lgw_resolve_badge_url($entry, $badges, $club_badges);
            $badge_html = $badge_url
                ? '<img class="lgw-entry-badge" src="' . esc_url($badge_url) . '" alt="">'
                : '<span class="lgw-entry-badge-placeholder"></span>';
            $out .= '<div class="lgw-entry-row">'
                . $badge_html
                . '<span class="lgw-entry-name">' . esc_html($entry) . '</span>'
                . '</div>';
        }
    }
    $out .= '</div>';
    return $out;
}

// Clear cache action
add_action('admin_post_lgw_clear_cache', 'lgw_clear_cache');
function lgw_clear_cache() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('lgw_clear_cache_nonce');
    global $wpdb;
    // Clear transient CSV cache
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lgw_csv_%' OR option_name LIKE '_transient_timeout_lgw_csv_%'");
    // Clear DB-primary division cache
    if (function_exists('lgw_cache_invalidate_all')) {
        lgw_cache_invalidate_all();
    }
    wp_redirect(admin_url('admin.php?page=' . LGW_SETUP_PAGE . '&cleared=1'));
    exit;
}

// Reset theme colours — must run on admin_init before any output
add_action('admin_init', 'lgw_maybe_reset_theme');
function lgw_maybe_reset_theme() {
    if (!isset($_GET['lgw_reset_theme'])) return;
    if (!isset($_GET['page']) || $_GET['page'] !== 'lgw-settings') return;
    if (!current_user_can('manage_options')) return;
    check_admin_referer('lgw_reset_theme_nonce');
    update_option('lgw_theme', array());
    wp_redirect(admin_url('admin.php?page=lgw-settings&saved=1'));
    exit;
}

function lgw_settings_page() {
    $badges = get_option('lgw_badges', array());
    $saved  = isset($_GET['saved']);
    ?>
    <div class="wrap lgw-admin-wrap">
        <?php lgw_page_header('Settings'); ?>
        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible"><p>Update check complete — both GitHub and WordPress update caches cleared. Scroll down to Plugin Updates to see the latest release.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('lgw_settings_nonce'); ?>
            <input type="hidden" name="action" value="lgw_save_settings">

            <h2>Clubs &amp; Badges</h2>
            <p>Add clubs and set a passphrase for each one. Used across all features — scorecards, cups, and championships.<br>
            <em>Tip: the <a href="https://what3words.com" target="_blank">what3words</a> address for your clubhouse makes a good passphrase (e.g. <code>filled.count.ripen</code>).</em>
            Leave the passphrase blank when editing to keep the existing one.<br>
            Optionally assign a badge image per club. Set <strong>Type</strong> to <strong>Club prefix</strong> to match multiple teams (e.g. <code>MALONE</code> matches <code>MALONE A</code>, <code>MALONE B</code>); use <strong>Exact</strong> for a team-specific badge.</p>
            <?php
            $clubs       = get_option('lgw_clubs',       array());
            $badges      = get_option('lgw_badges',      array());
            $club_badges = get_option('lgw_club_badges', array());
            if (empty($clubs)) $clubs = array(array('name'=>'','pin'=>''));
            // Build a badge lookup keyed by club name (case-insensitive)
            $badge_lookup = array(); // name_lc => ['image'=>'', 'type'=>'']
            foreach ($badges as $team => $img) {
                $badge_lookup[strtolower($team)] = array('image' => $img, 'type' => 'exact');
            }
            foreach ($club_badges as $team => $img) {
                $badge_lookup[strtolower($team)] = array('image' => $img, 'type' => 'club');
            }
            ?>
            <table class="widefat lgw-badge-table" id="lgw-club-table">
                <thead>
                    <tr>
                        <th>Club Name</th>
                        <th>Passphrase <span style="font-weight:400;color:#666">(leave blank to keep existing)</span></th>
                        <th>Badge Type</th>
                        <th>Badge Image</th>
                        <th>Preview</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($clubs as $club):
                    $b     = $badge_lookup[strtolower($club['name'])] ?? array('image'=>'','type'=>'club');
                    $bimg  = $b['image'];
                    $btype = $b['type'];
                ?>
                <tr class="lgw-club-row">
                    <td><input type="text" name="lgw_club_name[]" value="<?php echo esc_attr($club['name']); ?>" placeholder="e.g. Ards" class="regular-text" style="width:120px"></td>
                    <td><input type="text" name="lgw_club_pin[]" value="" placeholder="<?php echo $club['pin'] ? '(set — enter new to change)' : 'word.word.word'; ?>" autocomplete="off" autocapitalize="none" spellcheck="false" class="regular-text" style="width:180px"></td>
                    <td>
                        <select name="lgw_badge_type[]" class="lgw-badge-type">
                            <option value="club"  <?php selected($btype,'club');  ?>>Club prefix</option>
                            <option value="exact" <?php selected($btype,'exact'); ?>>Exact</option>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="lgw_image[]" value="<?php echo esc_url($bimg); ?>" placeholder="Image URL" class="regular-text lgw-image-url" readonly style="width:140px">
                        <button type="button" class="button lgw-pick-image">Choose</button>
                    </td>
                    <td><img class="lgw-badge-preview" src="<?php echo esc_url($bimg); ?>" style="width:40px;height:40px;object-fit:contain;<?php echo $bimg ? '' : 'display:none;'; ?>"></td>
                    <td><button type="button" class="button-link-delete lgw-remove-row">Remove</button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="lgw-add-club">+ Add Club</button></p>

            <hr>
            <h2>Theme &amp; Font</h2>
            <p>Default appearance for all widgets on this site. Colours can be overridden per-widget using shortcode attributes: <code>color_primary</code>, <code>color_secondary</code>, <code>color_bg</code>.</p>
            <?php $theme = get_option('lgw_theme', array()); ?>
            <table class="form-table">
                <tr>
                    <th>Widget Font</th>
                    <td>
                        <?php
                        $cur_font  = $theme['font_family'] ?? 'Saira';
                        $font_opts = lgw_font_options();
                        ?>
                        <select name="lgw_font_family" id="lgw-font-picker" style="min-width:200px">
                            <?php foreach ( $font_opts as $key => $meta ) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($cur_font, $key); ?>>
                                <?php echo esc_html($meta['label']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span id="lgw-font-preview" style="margin-left:14px;font-size:16px;font-weight:700;transition:font-family .3s">
                            The quick brown fox
                        </span>
                        <p class="description">Applies to all league, cup, and championship widgets.</p>
                        <style id="lgw-font-preview-style"></style>
                        <script>
                        (function(){
                            var sel = document.getElementById('lgw-font-picker');
                            var prev = document.getElementById('lgw-font-preview');
                            var styleEl = document.getElementById('lgw-font-preview-style');
                            var loaded = {};
                            function loadAndPreview(key) {
                                var fontName = key.replace(/\+/g, ' ');
                                prev.style.fontFamily = "'" + fontName + "', Arial, sans-serif";
                                if (!loaded[key]) {
                                    loaded[key] = true;
                                    var link = document.createElement('link');
                                    link.rel = 'stylesheet';
                                    link.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(fontName) + ':wght@400;700&display=swap';
                                    document.head.appendChild(link);
                                }
                            }
                            if (sel) {
                                loadAndPreview(sel.value);
                                sel.addEventListener('change', function(){ loadAndPreview(this.value); });
                            }
                        })();
                        </script>
                    </td>
                </tr>
                <tr>
                    <th>Primary Colour <span style="font-weight:400;color:#666">(tabs, headers, accents)</span></th>
                    <td>
                        <input type="color" value="<?php echo esc_attr($theme['color_primary'] ?? '#1a2e5a'); ?>">
                        <input type="text" name="lgw_color_primary" value="<?php echo esc_attr($theme['color_primary'] ?? '#1a2e5a'); ?>" class="small-text lgw-hex-input" maxlength="7" placeholder="#1a2e5a">
                        <?php if (empty($theme['color_primary'])): ?><em style="color:#999;font-size:12px">Using default navy</em><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Secondary Colour <span style="font-weight:400;color:#666">(highlight / gold)</span></th>
                    <td>
                        <input type="color" value="<?php echo esc_attr($theme['color_secondary'] ?? '#e8b400'); ?>">
                        <input type="text" name="lgw_color_secondary" value="<?php echo esc_attr($theme['color_secondary'] ?? '#e8b400'); ?>" class="small-text lgw-hex-input" maxlength="7" placeholder="#e8b400">
                        <?php if (empty($theme['color_secondary'])): ?><em style="color:#999;font-size:12px">Using default gold</em><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Background Colour</th>
                    <td>
                        <input type="color" value="<?php echo esc_attr($theme['color_bg'] ?? '#ffffff'); ?>">
                        <input type="text" name="lgw_color_bg" value="<?php echo esc_attr($theme['color_bg'] ?? '#ffffff'); ?>" class="small-text lgw-hex-input" maxlength="7" placeholder="#ffffff">
                        <?php if (empty($theme['color_bg'])): ?><em style="color:#999;font-size:12px">Using default white</em><?php endif; ?>
                    </td>
                </tr>
            </table>
            <p style="margin-top:0"><a href="<?php echo wp_nonce_url(admin_url('admin.php?page=lgw-settings&lgw_reset_theme=1'), 'lgw_reset_theme_nonce'); ?>" class="button" onclick="return confirm('Reset theme colours to defaults?')">Reset to defaults</a></p>

            <hr>
            <h2>Sponsors</h2>
            <p>The <strong>first sponsor</strong> appears above the division title. Additional sponsors rotate randomly below the league table. Add a per-division override via the shortcode if needed.</p>
            <table class="widefat lgw-badge-table" id="lgw-sponsor-table">
                <thead>
                    <tr><th>Sponsor Name / Alt Text</th><th>Logo Image</th><th>Link URL</th><th>Preview</th><th></th></tr>
                </thead>
                <tbody>
                    <?php
                    $sponsors = get_option('lgw_sponsors', array());
                    if (empty($sponsors)) $sponsors = array(array('image'=>'','url'=>'','name'=>''));
                    foreach ($sponsors as $sp): ?>
                    <tr class="lgw-sponsor-row">
                        <td><input type="text" name="lgw_sp_name[]" value="<?php echo esc_attr($sp['name']); ?>" placeholder="e.g. Acme Ltd" class="regular-text"></td>
                        <td>
                            <input type="text" name="lgw_sp_image[]" value="<?php echo esc_url($sp['image']); ?>" placeholder="Image URL" class="regular-text lgw-image-url" readonly>
                            <button type="button" class="button lgw-pick-image">Choose Image</button>
                        </td>
                        <td><input type="text" name="lgw_sp_url[]" value="<?php echo esc_url($sp['url']); ?>" placeholder="https://" class="regular-text"></td>
                        <td><img class="lgw-badge-preview" src="<?php echo esc_url($sp['image']); ?>" style="<?php echo $sp['image'] ? '' : 'display:none;'; ?>height:40px;object-fit:contain;max-width:120px;"></td>
                        <td><button type="button" class="button-link-delete lgw-remove-row">Remove</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="lgw-add-sponsor">+ Add Sponsor</button></p>

            <hr>
            <h2>📋 Scorecard Submission</h2>
            <p>Control who can submit scorecards. Use <strong>Admin only</strong> to test the workflow before releasing it to clubs.</p>
            <?php $submission_mode = get_option('lgw_submission_mode', 'open'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Submission Mode</th>
                    <td>
                        <label style="display:block;margin-bottom:8px">
                            <input type="radio" name="lgw_submission_mode" value="disabled" <?php checked($submission_mode, 'disabled'); ?>>
                            <strong>Disabled</strong> — scorecard submission is off; fixture modal shows no submit option
                        </label>
                        <label style="display:block;margin-bottom:8px">
                            <input type="radio" name="lgw_submission_mode" value="admin_only" <?php checked($submission_mode, 'admin_only'); ?>>
                            <strong>Admin only</strong> — only logged-in WP admins can submit scorecards via the fixture modal
                        </label>
                        <label style="display:block">
                            <input type="radio" name="lgw_submission_mode" value="open" <?php checked($submission_mode, 'open'); ?>>
                            <strong>Open</strong> — clubs can submit after passphrase login (full public flow)
                        </label>
                    </td>
                </tr>
            </table>

            <hr>
            <h2>🔧 Plugin Updates</h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="lgw_github_token">GitHub Personal Access Token</label></th>
                    <td>
                        <?php $gh_token = get_option('lgw_github_token', ''); ?>
                        <input type="password" id="lgw_github_token" name="lgw_github_token" value="<?php echo esc_attr($gh_token); ?>" placeholder="ghp_…" style="width:380px">
                        <p class="description">
                            Required only if the plugin GitHub repo is <strong>private</strong>. Allows WordPress to check for and download updates automatically.<br>
                            Generate a classic token at <a href="https://github.com/settings/tokens" target="_blank">github.com/settings/tokens</a> with the <code>repo</code> scope.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>

        <hr>
        <?php
        $github_user = 'dbinterz';
        $github_repo = 'lgw-division-widget';
        $plugin_slug = plugin_basename(LGW_PLUGIN_FILE);
        $api_response = wp_remote_get(
            "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest",
            lgw_github_request_args()
        );
        $api_ok      = !is_wp_error($api_response) && wp_remote_retrieve_response_code($api_response) === 200;
        $release     = $api_ok ? json_decode(wp_remote_retrieve_body($api_response)) : null;
        $latest_tag  = $release->tag_name ?? null;
        $latest_ver  = $latest_tag ? ltrim($latest_tag, 'v') : null;
        $wp_transient    = get_site_transient('update_plugins');
        $wp_update_entry = $wp_transient->response[$plugin_slug] ?? null;
        $wp_no_update    = $wp_transient->no_update[$plugin_slug] ?? null;
        $has_token       = (bool) get_option('lgw_github_token', '');
        ?>
        <table class="form-table" style="max-width:700px">
            <tr><th style="width:200px">Installed version</th><td><code><?php echo LGW_VERSION; ?></code></td></tr>
            <tr><th>GitHub API</th><td>
                <?php $diag_code = !is_wp_error($api_response) ? wp_remote_retrieve_response_code($api_response) : 0; ?>
                <?php if (!$api_ok): ?>
                    <span style="color:#c0202a">&#10007; Could not reach GitHub API — HTTP <?php echo $diag_code; ?>
                    <?php if ($diag_code === 404 && !$has_token): ?>
                        <strong>— repo may be private. Add a GitHub Personal Access Token above.</strong>
                    <?php elseif ($diag_code === 401): ?>
                        — token rejected (check it has <code>repo</code> scope and hasn't expired)
                    <?php endif; ?></span>
                <?php else: ?>
                    <span style="color:#2a7a2a">&#10003; Reachable<?php if ($has_token): ?> (authenticated)<?php endif; ?></span>
                <?php endif; ?>
            </td></tr>
            <?php if ($latest_tag): ?>
            <tr><th>Latest release</th><td><code><?php echo esc_html($latest_tag); ?></code>
                <?php if ($latest_ver && version_compare($latest_ver, LGW_VERSION, '>')): ?>
                    <span style="color:#2a7a2a;font-weight:600"> &#8593; Newer than installed</span>
                <?php elseif ($latest_ver && version_compare($latest_ver, LGW_VERSION, '==')): ?>
                    <span style="color:#888"> = Up to date</span>
                <?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <tr><th>WP update transient</th><td>
                <?php if ($wp_update_entry): ?>
                    <span style="color:#2a7a2a;font-weight:600">&#10003; Update queued</span> — WP sees v<code><?php echo esc_html($wp_update_entry->new_version); ?></code> available
                <?php elseif ($wp_no_update): ?>
                    <span style="color:#888">No update — WP sees this as current</span>
                <?php else: ?>
                    <span style="color:#c07000">Not in transient — WP has not checked yet</span>
                <?php endif; ?>
            </td></tr>
        </table>
        <p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline">
            <?php wp_nonce_field('lgw_check_updates_nonce'); ?>
            <input type="hidden" name="action" value="lgw_check_updates">
            <input type="submit" class="button button-secondary" value="Force Update Check Now">
        </form>
        <?php if ($wp_update_entry): ?>
        &nbsp;<a href="<?php echo admin_url('plugins.php'); ?>" class="button button-primary">Go to Plugins page to update</a>
        <?php endif; ?>
        &nbsp;
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline">
            <?php wp_nonce_field('lgw_test_download_nonce'); ?>
            <input type="hidden" name="action" value="lgw_test_download">
            <input type="submit" class="button button-secondary" value="Test Download URL">
        </form>
        </p>
        <?php if (isset($_GET['dl_test'])): ?>
        <div style="margin-top:12px;padding:12px;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;font-family:monospace;font-size:12px;max-width:700px">
            <?php echo wp_kses_post(base64_decode(sanitize_text_field($_GET['dl_test']))); ?>
        </div>
        <?php endif; ?>

    </div>
    <script>
    document.querySelectorAll('input[type="color"]').forEach(function(picker) {
        var row = picker.parentNode;
        var hex = row.querySelector('.lgw-hex-input');
        if (!hex) return;
        picker.addEventListener('input', function() { hex.value = picker.value; });
        hex.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) picker.value = hex.value;
        });
    });
    </script>
    <?php
}

// ── League Setup page ─────────────────────────────────────────────────────────

/**
 * Renders the unified Active Season Divisions table used in the League Setup form.
 * Columns: Division name | Published CSV URL | Sheet tab name
 * Replaces the separate Seasons active-divisions and Sheets tab-mapping tables.
 */
function lgw_league_setup_divisions_html() {
    $opts         = lgw_get_option_array('lgw_drive');
    $enabled      = !empty($opts['sheets_enabled']);
    $nonce_test   = wp_create_nonce('lgw_sheets_test');

    // Seed rows: merge active season divisions with existing tab/spreadsheet settings.
    $active        = lgw_get_active_season();
    $season_divs   = $active['divisions'] ?? [];
    $existing_tabs = $opts['sheets_tabs'] ?? [];
    if ( is_string( $existing_tabs ) ) { $existing_tabs = json_decode( $existing_tabs, true ) ?: []; }

    // Build lookup by csv_url for existing tab config
    $entry_by_url = [];
    foreach ($existing_tabs as $t) {
        $url = $t['csv_url'] ?? '';
        if ($url) $entry_by_url[$url] = $t;
    }
    $entry_by_div = [];
    foreach ($existing_tabs as $t) {
        $div = strtolower(trim($t['division'] ?? ''));
        if ($div) $entry_by_div[$div] = $t;
    }

    $rows = [];
    foreach ($season_divs as $d) {
        $url   = $d['csv_url']  ?? '';
        $div   = $d['division'] ?? '';
        $entry = $entry_by_url[$url] ?? $entry_by_div[strtolower(trim($div))] ?? [];
        $rows[] = [
            'division'       => $div,
            'csv_url'        => $url,
            'tab'            => $entry['tab']            ?? '',
            'spreadsheet_id' => $entry['spreadsheet_id'] ?? '',
        ];
    }
    if (empty($rows)) {
        foreach ($existing_tabs as $t) {
            $rows[] = [
                'division'       => $t['division']       ?? '',
                'csv_url'        => $t['csv_url']        ?? '',
                'tab'            => $t['tab']             ?? '',
                'spreadsheet_id' => $t['spreadsheet_id'] ?? '',
            ];
        }
    }
    if (empty($rows)) {
        $rows = [['division' => '', 'csv_url' => '', 'tab' => '', 'spreadsheet_id' => '']];
    }
    ?>
    <hr>
    <h2>🏟 Active Season Divisions</h2>
    <p>Each row defines one division for the current active season. The <strong>Spreadsheet ID</strong> is the editable sheet ID (from the URL between <code>/d/</code> and <code>/edit</code>) — used for writeback. The <strong>CSV URL</strong> is the published read-only URL — used by the front-end widget.</p>

    <table id="lgw-divisions-table" style="border-collapse:collapse;margin-bottom:8px;width:100%">
        <thead><tr>
            <th style="padding:4px 8px;text-align:left;font-weight:600;font-size:12px;color:#666;width:130px">Division name</th>
            <th style="padding:4px 8px;text-align:left;font-weight:600;font-size:12px;color:#666;width:200px">Spreadsheet ID <span style="font-weight:400">(for writeback)</span></th>
            <th style="padding:4px 8px;text-align:left;font-weight:600;font-size:12px;color:#666;width:110px">Sheet tab</th>
            <th style="padding:4px 8px;text-align:left;font-weight:600;font-size:12px;color:#666">Published CSV URL <span style="font-weight:400">(for widget)</span></th>
            <th style="width:60px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
        <tr class="lgw-division-row">
            <td style="padding:4px 8px">
                <input type="text" name="lgw_div_name[]"
                    value="<?php echo esc_attr($row['division']); ?>"
                    placeholder="e.g. Division 1" style="width:120px">
            </td>
            <td style="padding:4px 8px">
                <input type="text" name="lgw_div_spreadsheet_id[]"
                    value="<?php echo esc_attr($row['spreadsheet_id']); ?>"
                    placeholder="1aBcDeFgHiJkLmNo…" style="width:190px">
            </td>
            <td style="padding:4px 8px">
                <input type="text" name="lgw_div_tab[]"
                    value="<?php echo esc_attr($row['tab']); ?>"
                    placeholder="e.g. Div 1" style="width:100px">
            </td>
            <td style="padding:4px 8px">
                <input type="url" name="lgw_div_csv_url[]"
                    value="<?php echo esc_attr($row['csv_url']); ?>"
                    placeholder="https://docs.google.com/spreadsheets/…&output=csv"
                    style="width:100%;min-width:180px">
            </td>
            <td style="padding:4px 8px">
                <button type="button" class="button button-small lgw-remove-division-row"
                    <?php echo count($rows) <= 1 ? 'style="display:none"' : ''; ?>>Remove</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p><button type="button" class="button button-small" id="lgw-add-division-row">+ Add division</button></p>

    <hr>
    <h2>📋 Google Sheets Writeback</h2>
    <p>When a scorecard is confirmed, the result is automatically written to the spreadsheet configured for that division above. Each division can point to a different spreadsheet — useful when seasons use separate files.</p>
    <?php if ( get_option( 'lgw_data_source', 'google_sheets' ) === 'wordpress' ) : ?>
    <p class="description" style="color:#8a5a00"><strong>WordPress mode:</strong> writeback is optional — standings no longer depend on the sheet. Leave it on only if you want the Google Sheet kept mirrored as a backup.</p>
    <?php endif; ?>
    <table class="form-table">
    <tr>
        <th>Enable writeback</th>
        <td><label>
            <input type="checkbox" name="lgw_sheets_enabled" value="1" <?php checked($enabled); ?>>
            Write confirmed results to Google Sheets automatically
        </label></td>
    </tr>
    <tr>
        <th>Test connection</th>
        <td>
            <button type="button" class="button" id="lgw-sheets-test" data-nonce="<?php echo $nonce_test; ?>">
                Test Sheets Access
            </button>
            <span id="lgw-sheets-test-result" style="margin-left:10px;font-size:13px"></span>
            <p class="description">Tests auth against the first configured division's spreadsheet.</p>
        </td>
    </tr>
    </table>
    </table>

    <script>
    (function(){
        // Add division row
        document.getElementById('lgw-add-division-row').addEventListener('click', function() {
            var tbody = document.querySelector('#lgw-divisions-table tbody');
            var row = document.createElement('tr');
            row.className = 'lgw-division-row';
            row.innerHTML =
                '<td style="padding:4px 8px"><input type="text" name="lgw_div_name[]" placeholder="e.g. Division 2" style="width:120px"></td>'
                + '<td style="padding:4px 8px"><input type="text" name="lgw_div_spreadsheet_id[]" placeholder="1aBcDeFgHiJkLmNo…" style="width:190px"></td>'
                + '<td style="padding:4px 8px"><input type="text" name="lgw_div_tab[]" placeholder="e.g. Div 2" style="width:100px"></td>'
                + '<td style="padding:4px 8px"><input type="url" name="lgw_div_csv_url[]" placeholder="https://docs.google.com/spreadsheets/…&output=csv" style="width:100%;min-width:180px"></td>'
                + '<td style="padding:4px 8px"><button type="button" class="button button-small lgw-remove-division-row">Remove</button></td>';
            tbody.appendChild(row);
            document.querySelectorAll('.lgw-remove-division-row').forEach(function(b){ b.style.display=''; });
        });
        // Remove division row
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('lgw-remove-division-row')) {
                var tbody = document.querySelector('#lgw-divisions-table tbody');
                if (tbody.querySelectorAll('.lgw-division-row').length > 1) {
                    e.target.closest('.lgw-division-row').remove();
                }
            }
        });
        // Test Sheets connection — use first row's spreadsheet ID
        var testBtn = document.getElementById('lgw-sheets-test');
        if (testBtn) {
            testBtn.addEventListener('click', function() {
                var res = document.getElementById('lgw-sheets-test-result');
                var sidInput = document.querySelector('[name="lgw_div_spreadsheet_id[]"]');
                var sid = sidInput ? sidInput.value.trim() : '';
                if (!sid) { res.textContent = '⚠️ Enter a Spreadsheet ID first'; res.style.color='#c07000'; return; }
                testBtn.disabled = true;
                res.textContent = 'Testing…'; res.style.color = '#333';
                var fd = new FormData();
                fd.append('action', 'lgw_sheets_test');
                fd.append('nonce', testBtn.dataset.nonce);
                fd.append('sheets_id', sid);
                fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        testBtn.disabled = false;
                        res.textContent = r.success ? '✅ ' + r.data : '❌ ' + (r.data || 'Failed');
                        res.style.color = r.success ? 'green' : 'red';
                    })
                    .catch(function(e){
                        testBtn.disabled = false;
                        res.textContent = '❌ Request failed: ' + e;
                        res.style.color = 'red';
                    });
            });
        }
    })();
    </script>
    <?php
}

function lgw_league_setup_page() {
    $saved   = isset($_GET['saved']);
    $cleared = isset($_GET['cleared']);
    $s       = lgw_drive_get_settings();
    $has_oauth = !empty($s['oauth_refresh_token']);
    $oauth_url = !empty($s['oauth_client_id']) ? lgw_drive_oauth_auth_url($s['oauth_client_id']) : '';
    $data_source = get_option('lgw_data_source', 'google_sheets');
    ?>
    <div class="wrap lgw-admin-wrap">
        <?php lgw_page_header('League Setup'); ?>
        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
        <?php endif; ?>
        <?php if ($cleared): ?>
            <div class="notice notice-success is-dismissible"><p>Cache cleared — all divisions will fetch fresh data on next load.</p></div>
        <?php endif; ?>
        <?php if (!empty($_GET['lgw_oauth_connected'])): ?>
            <div class="notice notice-success is-dismissible"><p>✅ Google account connected successfully.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('lgw_league_setup_nonce'); ?>
            <input type="hidden" name="action" value="lgw_save_league_setup">

            <!-- ── 1. Data Source ── -->
            <h2>📊 Data Source</h2>
            <p>Choose how league tables and fixtures data is loaded into the widget.</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="lgw_data_source">Data source</label></th>
                    <td>
                        <select id="lgw_data_source" name="lgw_data_source" onchange="lgwToggleDataSource(this.value)">
                            <option value="google_sheets" <?php selected($data_source, 'google_sheets'); ?>>Google Sheets CSV</option>
                            <option value="wordpress" <?php selected($data_source, 'wordpress'); ?>>WordPress DB (Google Sheets backup)</option>
                            <option value="upload" <?php selected($data_source, 'upload'); ?> disabled>Spreadsheet upload — coming soon</option>
                        </select>
                    </td>
                </tr>
            </table>

            <div id="lgw-ds-wordpress" class="lgw-ds-section">
                <div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;max-width:760px">
                    <p style="margin:0 0 6px"><strong>WordPress-authoritative mode.</strong> Standings and fixtures are served from the WordPress database and kept up to date by confirmed scorecards and concessions. Google Sheets is used only to <em>seed</em> a division the first time and as an automatic fallback if a division has no WP data yet.</p>
                    <ul style="margin:0 0 6px 18px;list-style:disc;font-size:13px;color:#333">
                        <li>Switching is safe and reversible — divisions with no WP data fall back to the live CSV, so nothing goes blank.</li>
                        <li>The CSV cron no longer overwrites WP standings; it only fills empty divisions.</li>
                        <li>Seed a division from its CSV with the <strong>Sync</strong> buttons in the Division Cache panel below.</li>
                    </ul>
                    <p style="margin:0;font-size:13px;color:#666">Keep the published CSV URLs configured — they remain the backup source.</p>
                </div>
            </div>

            <div id="lgw-ds-google_sheets" class="lgw-ds-section">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="lgw_cache_mins">Cache duration (minutes)</label></th>
                        <td>
                            <input type="number" id="lgw_cache_mins" name="lgw_cache_mins" value="<?php echo intval(get_option('lgw_cache_mins', 5)); ?>" min="1" max="60" style="width:80px">
                            <p class="description">How long to cache CSV data from Google Sheets. 5 minutes is a good default. Lower = fresher data but more requests.</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <?php $clear_cache_url = wp_nonce_url(admin_url('admin-post.php?action=lgw_clear_cache'), 'lgw_clear_cache_nonce'); ?>
                    <a href="<?php echo esc_url($clear_cache_url); ?>" class="button button-secondary">🗑 Clear Cache Now</a>
                    <span style="margin-left:8px;color:#666;font-size:13px">Force all divisions to fetch fresh data on next page load.</span>
                </p>
            </div>

            <?php if (function_exists('lgw_cache_settings_html')) lgw_cache_settings_html(); ?>

            <hr>
            <!-- ── 2. Points System ── -->
            <h2>🏅 Points System</h2>
            <p>Configure how points are calculated per match. Points are auto-suggested in the scorecard form based on rink scores.</p>
            <?php
                $pts_rink    = floatval(get_option('lgw_points_per_rink', 1));
                $pts_overall = floatval(get_option('lgw_points_overall',  3));
                $pts_total_4 = ($pts_rink * 4) + $pts_overall;
                $pts_total_3 = ($pts_rink * 3) + $pts_overall;
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="lgw_points_per_rink">Points per rink win</label></th>
                    <td>
                        <input type="number" id="lgw_points_per_rink" name="lgw_points_per_rink"
                            value="<?php echo esc_attr($pts_rink); ?>" min="0" step="0.5" style="width:80px">
                        <p class="description">Points awarded for winning a single rink. Half this value is awarded for a rink draw. Default: 1.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="lgw_points_overall">Points for overall match win</label></th>
                    <td>
                        <input type="number" id="lgw_points_overall" name="lgw_points_overall"
                            value="<?php echo esc_attr($pts_overall); ?>" min="0" step="0.5" style="width:80px">
                        <p class="description">Bonus points for winning the overall match (more total shots). Half this value for an overall draw. Default: 3.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Maximum points per match</th>
                    <td>
                        <p id="lgw-pts-preview" style="margin:0;font-size:13px;color:#444">
                            <?php printf(
                                '<strong>%s</strong> for a 4-rink match &nbsp;|&nbsp; <strong>%s</strong> for a 3-rink match',
                                esc_html(rtrim(rtrim(number_format($pts_total_4, 1), '0'), '.')),
                                esc_html(rtrim(rtrim(number_format($pts_total_3, 1), '0'), '.'))
                            ); ?>
                        </p>
                        <p class="description">Auto-calculated from the values above. This is what the scorecard form will use as its <code>max_points</code> target.</p>
                    </td>
                </tr>
            </table>
            <script>
            (function(){
                function updatePtsPreview(){
                    var r = parseFloat(document.getElementById('lgw_points_per_rink').value) || 0;
                    var o = parseFloat(document.getElementById('lgw_points_overall').value) || 0;
                    var t4 = (r*4)+o, t3 = (r*3)+o;
                    function fmt(n){ return parseFloat(n.toFixed(1)).toString(); }
                    var el = document.getElementById('lgw-pts-preview');
                    if(el) el.innerHTML = '<strong>'+fmt(t4)+'</strong> for a 4-rink match &nbsp;|&nbsp; <strong>'+fmt(t3)+'</strong> for a 3-rink match';
                }
                ['lgw_points_per_rink','lgw_points_overall'].forEach(function(id){
                    var el = document.getElementById(id);
                    if(el) el.addEventListener('input', updatePtsPreview);
                });
            })();
            </script>

            <hr>
            <!-- ── 3. Photo Analysis ── -->
            <h2>📷 Photo Analysis</h2>
            <p>AI-powered reading of scorecard photos uploaded by clubs. Parses the scorecard image and pre-fills scores automatically.</p>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="lgw_vision_provider">Provider</label></th>
                    <td>
                        <select id="lgw_vision_provider" name="lgw_vision_provider" onchange="lgwToggleVisionProvider(this.value)">
                            <?php $vp = get_option('lgw_vision_provider', 'anthropic'); ?>
                            <option value="disabled"  <?php selected($vp, 'disabled');  ?>>Disabled</option>
                            <option value="anthropic" <?php selected($vp, 'anthropic'); ?>>Claude (Anthropic)</option>
                            <option value="openai"    <?php selected($vp, 'openai');    ?> disabled>GPT-4o (OpenAI) — coming soon</option>
                            <option value="gemini"    <?php selected($vp, 'gemini');    ?> disabled>Gemini (Google) — coming soon</option>
                        </select>
                        <p class="description">Choose the AI service used to read scorecard photos.</p>
                    </td>
                </tr>
            </table>
            <div id="lgw-vision-anthropic" class="lgw-vision-section" style="<?php echo ($vp !== 'anthropic') ? 'display:none' : ''; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="lgw_anthropic_key">Anthropic API Key</label></th>
                        <td>
                            <?php $key = get_option('lgw_anthropic_key', ''); ?>
                            <input type="password" id="lgw_anthropic_key" name="lgw_anthropic_key" value="<?php echo esc_attr($key); ?>" placeholder="sk-ant-…" style="width:380px">
                            <p class="description">
                                Used to call the Claude API for scorecard photo reading.<br>
                                Get a key at <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>.
                                Standard API usage charges apply.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            <div id="lgw-vision-disabled" class="lgw-vision-section" style="<?php echo ($vp !== 'disabled') ? 'display:none' : ''; ?>">
                <p style="color:#666;font-size:13px;margin-left:2px">Photo upload will be hidden from the scorecard submission form.</p>
            </div>

            <hr>
            <!-- ── 3. Google Integration ── -->
            <div id="lgw-ds-google_sheets-integration" class="lgw-ds-section">
                <h2>🔑 Google Integration</h2>
                <p>One Google OAuth connection covers both Drive and Sheets. Set up your credentials once and enable the features you need below.</p>

                <h3 style="margin-bottom:4px">OAuth Credentials</h3>
                <p class="description" style="margin-bottom:12px">
                    Create an OAuth 2.0 Client ID in <a href="https://console.cloud.google.com" target="_blank">Google Cloud Console</a> → APIs &amp; Services → Credentials.<br>
                    Set the authorised redirect URI to: <code><?php echo esc_html(lgw_drive_oauth_redirect_uri()); ?></code><br>
                    Enable the <strong>Google Drive API</strong> and <strong>Google Sheets API</strong> in your project.
                </p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="lgw_oauth_client_id">Client ID</label></th>
                        <td>
                            <input type="text" id="lgw_oauth_client_id" name="lgw_oauth_client_id"
                                value="<?php echo esc_attr($s['oauth_client_id'] ?? ''); ?>"
                                class="regular-text" placeholder="123456789-abc.apps.googleusercontent.com">
                            <p class="description">The OAuth 2.0 Client ID from Google Cloud Console.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lgw_oauth_client_secret">Client Secret</label></th>
                        <td>
                            <input type="password" id="lgw_oauth_client_secret" name="lgw_oauth_client_secret"
                                value="<?php echo esc_attr($s['oauth_client_secret'] ?? ''); ?>"
                                class="regular-text" placeholder="GOCSPX-…">
                            <p class="description">The OAuth 2.0 Client Secret from Google Cloud Console.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Google Account</th>
                        <td>
                            <?php if ($has_oauth): ?>
                                <div style="padding:10px 14px;background:#d1e7dd;color:#0a3622;border-radius:4px;font-size:13px;margin-bottom:10px;display:inline-block">
                                    ✅ <strong>Google account connected</strong> — OAuth refresh token stored.
                                </div><br>
                                <?php if ($oauth_url): ?>
                                    <a href="<?php echo esc_url($oauth_url); ?>" class="button" style="margin-right:8px">🔄 Reconnect</a>
                                <?php endif; ?>
                                <button type="button" class="button" id="lgw-oauth-disconnect" style="color:#842029">✖ Disconnect</button>
                            <?php elseif ($oauth_url): ?>
                                <a href="<?php echo esc_url($oauth_url); ?>" class="button button-primary">🔗 Connect Google Account</a>
                                <p class="description" style="margin-top:6px">Save your Client ID and Secret first, then click Connect.</p>
                            <?php else: ?>
                                <p class="description">Enter your Client ID and Secret above and save to see the Connect button.</p>
                            <?php endif; ?>
                            <input type="hidden" name="lgw_oauth_disconnect" id="lgw-oauth-disconnect-flag" value="">
                        </td>
                    </tr>
                </table>

                <h3 style="margin-top:24px;margin-bottom:4px">Drive PDF Archive</h3>
                <p class="description" style="margin-bottom:12px">Automatically saves a PDF of each confirmed scorecard to a Google Drive folder.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Drive Upload</th>
                        <td>
                            <label>
                                <input type="checkbox" name="lgw_drive_enabled" value="1" <?php checked(!empty($s['enabled'])); ?>>
                                Save confirmed scorecards as PDFs to Google Drive
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lgw_drive_root_folder">Drive Folder ID</label></th>
                        <td>
                            <input type="text" id="lgw_drive_root_folder" name="lgw_drive_root_folder"
                                value="<?php echo esc_attr($s['root_folder_id']); ?>"
                                class="regular-text" placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74o">
                            <p class="description">
                                The ID of the Google Drive folder for the archive.<br>
                                Find it in the folder URL: <code>drive.google.com/drive/folders/<strong style="color:#1a2e5a">THIS_PART</strong></code>
                            </p>
                        </td>
                    </tr>
                </table>
                <p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <?php $nonce_test = wp_create_nonce('lgw_drive_test'); ?>
                    <button type="button" class="button" id="lgw-drive-test" data-nonce="<?php echo $nonce_test; ?>">
                        🔌 Test Drive Connection
                    </button>
                    <span id="lgw-drive-test-result" style="font-size:13px"></span>
                </p>

                <?php lgw_league_setup_divisions_html(); ?>

                <h3 style="margin-top:24px;margin-bottom:4px">Service Account (Legacy)</h3>
                <p class="description" style="margin-bottom:12px">
                    Alternative to OAuth. Requires a service account key JSON file.<br>
                    <strong>Note:</strong> service accounts cannot upload to personal Gmail Drive — use OAuth instead.
                </p>
                <?php
                $nonce_key = wp_create_nonce('lgw_drive_upload_key');
                $has_key   = !empty($s['key_path']) && file_exists($s['key_path']);
                $key_email = '';
                if ($has_key) {
                    $kd = json_decode(file_get_contents($s['key_path']), true);
                    $key_email = $kd['client_email'] ?? '';
                }
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Service Account Key</th>
                        <td>
                            <?php if ($has_key): ?>
                                <div id="lgw-key-current" style="margin-bottom:10px;padding:10px 14px;background:#d1e7dd;color:#0a3622;border-radius:4px;font-size:13px">
                                    ✅ <strong>Key uploaded</strong><br>
                                    <span style="font-family:monospace;font-size:12px"><?php echo esc_html($key_email); ?></span>
                                </div>
                            <?php else: ?>
                                <div id="lgw-key-current" style="margin-bottom:10px;padding:10px 14px;background:#f0f0f0;color:#555;border-radius:4px;font-size:13px">
                                    No service account key uploaded
                                </div>
                            <?php endif; ?>
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                <label class="button" for="lgw-key-file-input" style="cursor:pointer">
                                    📁 <?php echo $has_key ? 'Replace Key File' : 'Upload Key File'; ?>
                                </label>
                                <input type="file" id="lgw-key-file-input" accept=".json,application/json" style="display:none">
                                <span id="lgw-key-upload-status" style="font-size:13px"></span>
                            </div>
                            <input type="hidden" name="lgw_drive_key_path" value="<?php echo esc_attr($s['key_path'] ?? ''); ?>" id="lgw-key-path-field">
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button('Save League Setup'); ?>
        </form>

        <hr>
        <!-- ── Shortcode Reference ── -->
        <h2>Shortcode Reference</h2>

        <h3 style="margin-bottom:4px">League table &amp; fixtures</h3>
        <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block;margin-top:0">[lgw_division csv="YOUR_CSV_URL" title="Division 1"]</pre>
        <table class="widefat striped" style="max-width:680px;margin-top:8px">
            <thead><tr><th style="width:160px">Parameter</th><th>Description</th><th style="width:80px">Required</th></tr></thead>
            <tbody>
            <tr><td><code>csv</code></td><td>Published Google Sheets CSV URL</td><td>Yes</td></tr>
            <tr><td><code>title</code></td><td>Heading above the widget</td><td>No</td></tr>
            <tr><td><code>promote</code></td><td>Promotion places to highlight (default: 0)</td><td>No</td></tr>
            <tr><td><code>relegate</code></td><td>Relegation places to highlight (default: 0)</td><td>No</td></tr>
            <tr><td><code>color_primary</code></td><td>Override primary colour for this widget</td><td>No</td></tr>
            <tr><td><code>color_secondary</code></td><td>Override secondary colour for this widget</td><td>No</td></tr>
            <tr><td><code>color_bg</code></td><td>Override background colour for this widget</td><td>No</td></tr>
            <tr><td><code>sponsor_img</code></td><td>Override primary sponsor image for this division</td><td>No</td></tr>
            <tr><td><code>sponsor_url</code></td><td>Override primary sponsor link for this division</td><td>No</td></tr>
            <tr><td><code>sponsor_name</code></td><td>Override primary sponsor alt text for this division</td><td>No</td></tr>
            </tbody>
        </table>

        <h3 style="margin-top:20px;margin-bottom:4px">Scorecard submission form</h3>
        <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block;margin-top:0">[lgw_submit]</pre>
        <p style="margin-top:6px;color:#555;font-size:13px">Allows clubs to submit and confirm match scorecards using their passphrase.</p>

        <h3 style="margin-top:20px;margin-bottom:4px">Cup bracket</h3>
        <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block;margin-top:0">[lgw_cup id="cup-2025"]</pre>

        <h3 style="margin-top:20px;margin-bottom:4px">National championship</h3>
        <pre style="background:#f6f7f7;padding:12px;border:1px solid #ddd;display:inline-block;margin-top:0">[lgw_champ id="singles-2025"]</pre>
    </div>
    <script>
    function lgwToggleDataSource(val) {
        document.querySelectorAll('.lgw-ds-section').forEach(function(el) {
            el.style.display = 'none';
        });
        var target = document.getElementById('lgw-ds-' + val);
        if (target) target.style.display = '';
        // WordPress mode still uses the CSV URLs as seed/backup, so keep the
        // Google Sheets integration (CSV URL config) visible there too.
        var goog = document.getElementById('lgw-ds-google_sheets-integration');
        if (goog) goog.style.display = (val === 'google_sheets' || val === 'wordpress') ? '' : 'none';
    }
    function lgwToggleVisionProvider(val) {
        document.querySelectorAll('.lgw-vision-section').forEach(function(el) {
            el.style.display = 'none';
        });
        var target = document.getElementById('lgw-vision-' + val);
        if (target) target.style.display = '';
    }
    // OAuth disconnect
    var discBtn = document.getElementById('lgw-oauth-disconnect');
    if (discBtn) {
        discBtn.addEventListener('click', function() {
            if (confirm('Disconnect Google account? Drive and Sheets features will stop working until you reconnect.')) {
                document.getElementById('lgw-oauth-disconnect-flag').value = '1';
                discBtn.closest('form').submit();
            }
        });
    }
    // Drive test button
    var driveTest = document.getElementById('lgw-drive-test');
    if (driveTest) {
        driveTest.addEventListener('click', function() {
            var result = document.getElementById('lgw-drive-test-result');
            result.textContent = 'Testing…';
            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=lgw_drive_test&nonce=' + driveTest.dataset.nonce
            }).then(r => r.json()).then(function(data) {
                result.textContent = data.success ? '✅ ' + data.data : '❌ ' + (data.data || 'Failed');
                result.style.color = data.success ? '#2a7a2a' : '#c0202a';
            }).catch(function() {
                result.textContent = '❌ Request failed';
                result.style.color = '#c0202a';
            });
        });
    }
    // Service account key upload
    var keyInput = document.getElementById('lgw-key-file-input');
    if (keyInput) {
        keyInput.addEventListener('change', function() {
            if (!this.files[0]) return;
            var fd = new FormData();
            fd.append('action', 'lgw_drive_upload_key');
            fd.append('nonce', '<?php echo wp_create_nonce('lgw_drive_upload_key'); ?>');
            fd.append('key_file', this.files[0]);
            var status = document.getElementById('lgw-key-upload-status');
            status.textContent = 'Uploading…';
            fetch(ajaxurl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(function(data) {
                    if (data.success) {
                        document.getElementById('lgw-key-path-field').value = data.data.path;
                        document.getElementById('lgw-key-current').innerHTML = '✅ <strong>Key uploaded</strong><br><span style="font-family:monospace;font-size:12px">' + data.data.email + '</span>';
                        document.getElementById('lgw-key-current').style.background = '#d1e7dd';
                        document.getElementById('lgw-key-current').style.color = '#0a3622';
                        status.textContent = '';
                    } else {
                        status.textContent = '❌ ' + (data.data || 'Upload failed');
                        status.style.color = '#c0202a';
                    }
                });
        });
    }
    // Init data source toggle on load
    lgwToggleDataSource(document.getElementById('lgw_data_source').value);
    </script>
    <?php
}

// ── Import Passphrases Tool ───────────────────────────────────────────────────
add_action('admin_menu', 'lgw_import_passphrases_menu');
function lgw_import_passphrases_menu() {
    // Only show if the tool hasn't been disabled
    if (get_option('lgw_import_tool_disabled')) return;
    add_submenu_page(
        'lgw-scorecards',
        'Import Passphrases',
        '🔑 Import Passphrases',
        'manage_options',
        'lgw-import-passphrases',
        'lgw_import_passphrases_page'
    );
}

function lgw_import_col_to_idx($col) {
    $idx = 0;
    foreach (str_split($col) as $c) {
        $idx = $idx * 26 + (ord($c) - ord('A') + 1);
    }
    return $idx - 1;
}

function lgw_read_passphrases_from_xlsx($file_path) {
    if (!class_exists('ZipArchive')) return new WP_Error('no_zip', 'ZipArchive not available — install php-zip.');
    $zip = new ZipArchive();
    if ($zip->open($file_path) !== true) return new WP_Error('bad_zip', 'Could not open xlsx file.');

    $strings = array();
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ss, $m);
        $strings = array_map('html_entity_decode', $m[1]);
    }
    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheet_xml) return new WP_Error('no_sheet', 'Could not read sheet data from xlsx.');

    $grid  = array();
    $cells = explode('</c>', $sheet_xml);
    foreach ($cells as $cell_str) {
        if (!preg_match('/\br="([A-Z]+)(\d+)"/', $cell_str, $ref)) continue;
        $col     = lgw_import_col_to_idx($ref[1]);
        $row_num = intval($ref[2]);
        $type    = '';
        if (preg_match('/\bt="([^"]*)"/', $cell_str, $tm)) $type = $tm[1];
        if (!preg_match('/<v>(.*?)<\/v>/s', $cell_str, $vm)) continue;
        $val = $vm[1];
        $grid[$row_num][$col] = ($type === 's') ? ($strings[intval($val)] ?? '') : $val;
    }

    // Col 0 = Club Name, Col 3 = Passphrase. Skip rows 1+2 (header + instructions).
    $pairs = array();
    ksort($grid);
    foreach ($grid as $row_num => $row) {
        if ($row_num <= 2) continue;
        $name   = isset($row[0]) ? trim($row[0]) : '';
        $phrase = isset($row[3]) ? trim($row[3]) : '';
        if ($name === '') continue;
        $pairs[$name] = $phrase;
    }
    return $pairs;
}

function lgw_import_passphrases_page() {
    if (!current_user_can('manage_options')) wp_die('Unauthorised');

    // Handle disable tool
    if (isset($_POST['lgw_disable_tool']) && check_admin_referer('lgw_import_tool_nonce')) {
        update_option('lgw_import_tool_disabled', 1);
        echo '<div class="wrap"><div class="notice notice-success"><p>Import tool removed from menu. You can re-enable it by deleting the <code>lgw_import_tool_disabled</code> option from the database.</p></div></div>';
        return;
    }

    $result = null;
    $errors = array();

    // Handle upload + import
    if (isset($_POST['lgw_do_import']) && check_admin_referer('lgw_import_tool_nonce')) {
        if (empty($_FILES['lgw_xlsx']['tmp_name'])) {
            $errors[] = 'No file uploaded.';
        } else {
            $pairs = lgw_read_passphrases_from_xlsx($_FILES['lgw_xlsx']['tmp_name']);
            if (is_wp_error($pairs)) {
                $errors[] = $pairs->get_error_message();
            } else {
                $existing = get_option('lgw_clubs', array());
                $indexed  = array();
                foreach ($existing as $club) {
                    $indexed[strtolower($club['name'])] = $club;
                }
                $updated = $inserted = $skipped = 0;
                foreach ($pairs as $name => $phrase) {
                    $key = strtolower($name);
                    if ($phrase === '') {
                        if (!isset($indexed[$key])) { $indexed[$key] = array('name' => $name, 'pin' => ''); $inserted++; }
                        else $skipped++;
                        continue;
                    }
                    $normalised = strtolower(preg_replace('/\s+/', ' ', $phrase));
                    $hash = hash('sha256', $normalised);
                    if (isset($indexed[$key])) { $indexed[$key]['pin'] = $hash; $updated++; }
                    else { $indexed[$key] = array('name' => $name, 'pin' => $hash); $inserted++; }
                }
                update_option('lgw_clubs', array_values($indexed));
                $result = array('updated' => $updated, 'inserted' => $inserted, 'skipped' => $skipped, 'pairs' => $pairs);
            }
        }
    }
    ?>
    <div class="wrap">
        <?php lgw_page_header('Import Club Passphrases'); ?>
        <p>Upload the <code>lgw-club-passphrases.xlsx</code> file with the Passphrase column filled in. The tool will upsert all clubs — updating existing ones and adding any new ones.</p>

        <?php if (!empty($errors)): ?>
            <div class="notice notice-error"><p><?php echo implode('<br>', array_map('esc_html', $errors)); ?></p></div>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="notice notice-success">
                <p>✅ <strong>Import complete.</strong> Passphrases set: <strong><?php echo $result['updated']; ?></strong> &nbsp;|&nbsp; New clubs added: <strong><?php echo $result['inserted']; ?></strong> &nbsp;|&nbsp; Skipped (no passphrase): <strong><?php echo $result['skipped']; ?></strong></p>
            </div>
            <?php if (!empty($result['pairs'])): ?>
            <h3>Imported rows</h3>
            <table class="widefat striped" style="max-width:500px">
                <thead><tr><th>Club</th><th>Passphrase</th></tr></thead>
                <tbody>
                <?php foreach ($result['pairs'] as $name => $phrase): ?>
                    <tr>
                        <td><?php echo esc_html($name); ?></td>
                        <td><?php echo $phrase ? esc_html($phrase) : '<em style="color:#999">— none —</em>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" style="margin-top:20px">
            <?php wp_nonce_field('lgw_import_tool_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="lgw_xlsx">Passphrases xlsx</label></th>
                    <td>
                        <input type="file" name="lgw_xlsx" id="lgw_xlsx" accept=".xlsx">
                        <p class="description">Upload <code>lgw-club-passphrases.xlsx</code> with the Passphrase column (column D) filled in.</p>
                    </td>
                </tr>
            </table>
            <p><input type="submit" name="lgw_do_import" class="button button-primary" value="Import Passphrases"></p>
        </form>

        <hr style="margin-top:40px">
        <h3>Remove this tool</h3>
        <p>Once you've finished importing, remove this page from the menu to keep the admin tidy.</p>
        <form method="post">
            <?php wp_nonce_field('lgw_import_tool_nonce'); ?>
            <p><input type="submit" name="lgw_disable_tool" class="button button-secondary" value="Remove Import Tool from Menu"
               onclick="return confirm('Remove the import tool from the admin menu?')"></p>
        </form>
    </div>
    <?php
}

// ── Sync score override from a confirmed/edited scorecard ────────────────────
/**
 * After a scorecard is confirmed or admin-edited, write the result into the
 * lgw_score_overrides WP option so the front-end widget shows the updated
 * score immediately — before the Google Sheets published CSV regenerates.
 *
 * Requires the division to be mapped to a csv_url in lgw_drive.sheets_tabs.
 * The override key format mirrors lgw_scores_parse_fixtures:
 *   {csv_url}||{sheet_date}||{home_team}||{away_team}
 */
function lgw_sync_override_from_scorecard($post_id, $sc = null) {
    if (!$sc) $sc = get_post_meta($post_id, 'lgw_scorecard_data', true);
    if (!$sc) return;

    $home     = trim($sc['home_team']  ?? '');
    $away     = trim($sc['away_team']  ?? '');
    $date_raw = trim($sc['date']       ?? ''); // played date, dd/mm/yyyy
    $division = trim($sc['division']   ?? '');

    if (!$home || !$away || !$date_raw) {
        lgw_sheets_log($post_id, 'warn', 'Override sync skipped — missing home, away, or date');
        return;
    }

    // Find the csv_url for this division — try sheets_tabs first, then active season
    $opts    = lgw_get_option_array('lgw_drive');
    $entry   = $division ? lgw_sheets_entry_for_division($division, $opts) : null;
    $csv_url = trim($entry['csv_url'] ?? '');

    // Fallback: look up csv_url from the active season divisions
    if (!$csv_url && $division && function_exists('lgw_get_active_season_id')) {
        $active_id = lgw_get_active_season_id();
        if ($active_id && function_exists('lgw_season_csv_for_division')) {
            $csv_url = lgw_season_csv_for_division($active_id, $division);
        }
    }

    if (!$csv_url) {
        lgw_sheets_log($post_id, 'warn', 'Override sync skipped — no CSV URL found for division: ' . ($division ?: '(empty)'));
        return;
    }

    // Determine the fixture date as it appears in the CSV.
    // The override key MUST use the CSV's own date string for the fixture row —
    // not the played date — because the widget builds keys from the CSV dates.
    // Strategy: fetch the CSV and find the row for this home/away pair; use
    // whatever date the CSV has for that row. Fall back to lgw_fixture_date meta,
    // then finally to the scorecard played date.
    $sheet_date = lgw_sync_get_fixture_date_from_csv($csv_url, $home, $away);

    if (!$sheet_date) {
        // Fallback 1: lgw_fixture_date meta (set by front-end fixture-click submissions)
        $fixture_date_raw = trim(get_post_meta($post_id, 'lgw_fixture_date', true));
        // Fallback 2: played date from scorecard
        if (!$fixture_date_raw) $fixture_date_raw = $date_raw;
        $sheet_date = lgw_sheets_format_date($fixture_date_raw);
    }

    if (!$sheet_date) {
        lgw_sheets_log($post_id, 'warn', 'Override sync skipped — could not determine fixture date for: ' . $home . ' v ' . $away);
        return;
    }

    $key = $csv_url . '||' . $sheet_date . '||' . $home . '||' . $away;

    $overrides       = lgw_get_option_array('lgw_score_overrides');
    $overrides[$key] = array(
        'csv_url' => $csv_url,
        'date'    => $sheet_date,
        'home'    => $home,
        'away'    => $away,
        'sh'      => (string)($sc['home_total']  ?? 0),
        'sa'      => (string)($sc['away_total']  ?? 0),
        'ph'      => (string)($sc['home_points'] ?? 0),
        'pa'      => (string)($sc['away_points'] ?? 0),
    );
    update_option('lgw_score_overrides', $overrides);

    lgw_sheets_log($post_id, 'info', 'Override synced — key: ' . $sheet_date . ' | ' . $home . ' v ' . $away
        . ' | ' . ($sc['home_total'] ?? 0) . '-' . ($sc['away_total'] ?? 0)
        . ' (' . ($sc['home_points'] ?? 0) . 'pts-' . ($sc['away_points'] ?? 0) . 'pts)');
}

/**
 * Fetch the CSV for a division and find the scheduled fixture date for a
 * given home/away team pair.  Returns the date string as it appears in the
 * CSV (e.g. "Sat 9-May-2026"), or empty string if not found.
 */
function lgw_sync_get_fixture_date_from_csv($csv_url, $home, $away) {
    $cache_key = 'lgw_csv_' . md5($csv_url);
    $body = get_transient($cache_key);
    if ($body === false) {
        $response = wp_remote_get($csv_url, ['timeout' => 10, 'user-agent' => 'Mozilla/5.0']);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return '';
        $body = wp_remote_retrieve_body($response);
        $cache_mins = intval(get_option('lgw_cache_mins', 5));
        set_transient($cache_key, $body, $cache_mins * MINUTE_IN_SECONDS);
    }

    $rows     = array_map('str_getcsv', explode("\n", str_replace("\r", '', $body)));
    $date_re  = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/';
    $home_lc  = strtolower(trim($home));
    $away_lc  = strtolower(trim($away));

    // Detect team name column indices from the header row
    $col_hteam = 2; $col_ateam = 10;
    $in_fixtures = false;
    $cur_date = '';

    foreach ($rows as $row) {
        $joined = implode('', $row);
        if (!$in_fixtures) {
            if (stripos($joined, 'FIXTURES') !== false) { $in_fixtures = true; }
            continue;
        }
        // Header row
        if (stripos($joined, 'HTeam') !== false || stripos($joined, 'HPts') !== false) {
            foreach ($row as $c => $v) {
                $v = trim($v);
                if ($v === 'HTeam') $col_hteam = $c;
                if ($v === 'ATeam') $col_ateam = $c;
            }
            continue;
        }
        $first = trim($row[0] ?? $row[1] ?? '');
        if (preg_match($date_re, $first)) { $cur_date = $first; continue; }
        $ht = strtolower(trim($row[$col_hteam] ?? ''));
        $at = strtolower(trim($row[$col_ateam] ?? ''));
        if ($ht === $home_lc && $at === $away_lc) return $cur_date;
    }
    return '';
}


// ── Scorecard deletion: clear sheet result, override, cache, appearances ──────
/**
 * When an lgw_scorecard post is deleted (trashed permanently or force-deleted),
 * reverse everything that confirmation wrote:
 *  - clears the score/points cells in the Google Sheet (if previously written)
 *  - removes the matching lgw_score_overrides entry
 *  - restores the cached fixture to unplayed (lgw_cache_wipe_fixture_result)
 *  - removes player appearance records logged for this scorecard
 *
 * Hooked on before_delete_post so post meta is still readable.
 */
add_action('before_delete_post', 'lgw_scorecard_on_delete');
add_action('wp_trash_post',      'lgw_scorecard_on_delete');
function lgw_scorecard_on_delete($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'lgw_scorecard') return;

    $sc = get_post_meta($post_id, 'lgw_scorecard_data', true);
    if (!is_array($sc)) $sc = array();

    $home     = trim($sc['home_team'] ?? '');
    $away     = trim($sc['away_team'] ?? '');
    $date_raw = trim($sc['date']      ?? '');
    $division = trim($sc['division']  ?? '');

    // 1. Clear the result from the Google Sheet (only if it was ever written there)
    $status = get_post_meta($post_id, 'lgw_sc_status', true);
    if ($status === 'confirmed' && $home && $away) {
        $opts = lgw_get_option_array('lgw_drive');
        if (!empty($opts['sheets_enabled']) && !get_post_meta($post_id, 'lgw_skip_google', true)) {
            if (function_exists('lgw_sheets_clear_result')) {
                lgw_sheets_clear_result($post_id, $sc);
            }
        }
    }

    // 2. Remove any matching score override(s) for this home/away pair
    if ($home && $away) {
        $overrides = lgw_get_option_array('lgw_score_overrides');
        $changed   = false;
        foreach ($overrides as $ok => $ov) {
            if (strcasecmp(trim($ov['home'] ?? ''), $home) === 0
             && strcasecmp(trim($ov['away'] ?? ''), $away) === 0) {
                unset($overrides[$ok]);
                $changed = true;
            }
        }
        if ($changed) update_option('lgw_score_overrides', $overrides);
    }

    // 3. Restore the cached fixture to unplayed so the table updates immediately
    if ($home && $away && $division && function_exists('lgw_cache_wipe_fixture_result')) {
        $season_id = '';
        $sc_season = get_post_meta($post_id, 'lgw_sc_season', true);
        if ($sc_season) {
            $season_id = $sc_season;
        } elseif (function_exists('lgw_get_active_season_id')) {
            $season_id = lgw_get_active_season_id();
        }
        if ($season_id) {
            lgw_cache_wipe_fixture_result($home, $away, $season_id, $division);
        }
    }

    // 4. Player appearance cleanup is handled separately by
    //    lgw_on_scorecard_deleted() in lgw-players.php (also hooked on
    //    before_delete_post / wp_trash_post).
}

// ── Quick Score Entry — AJAX: save a single fixture override ─────────────────
add_action('lgw_scorecard_confirmed',    'lgw_sync_override_from_scorecard');
add_action('lgw_scorecard_admin_edited', 'lgw_sync_override_from_scorecard');
add_action('wp_ajax_lgw_save_score_override', 'lgw_ajax_save_score_override');
function lgw_ajax_save_score_override() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    check_ajax_referer('lgw_scores_nonce', 'nonce');

    $csv_url = esc_url_raw($_POST['csv_url'] ?? '');
    $date    = sanitize_text_field($_POST['date'] ?? '');
    $home    = sanitize_text_field($_POST['home'] ?? '');
    $away    = sanitize_text_field($_POST['away'] ?? '');
    $sh      = sanitize_text_field($_POST['sh']   ?? '');
    $sa      = sanitize_text_field($_POST['sa']   ?? '');
    $ph      = sanitize_text_field($_POST['ph']   ?? '');
    $pa      = sanitize_text_field($_POST['pa']   ?? '');

    if (!$csv_url || !$date || !$home || !$away) wp_send_json_error('Missing fields');

    $clearing = ($sh === '' && $sa === '' && $ph === '' && $pa === '');

    // 1. Update WP option immediately (front end sees this until CSV cache refreshes)
    $key       = $csv_url . '||' . $date . '||' . $home . '||' . $away;
    $overrides = lgw_get_option_array('lgw_score_overrides');
    if ($clearing) {
        unset($overrides[$key]);
    } else {
        $overrides[$key] = compact('csv_url','date','home','away','sh','sa','ph','pa');
    }
    update_option('lgw_score_overrides', $overrides);

    // 2. Write through to Google Sheet if Sheets writeback is configured
    $sheet_msg = 'sheets_disabled';
    $opts = lgw_get_option_array('lgw_drive');
    if (!empty($opts['sheets_enabled'])) {
        // Find matching division entry by csv_url
        $div_entry = null;
        $sheets_tabs_raw = $opts['sheets_tabs'] ?? [];
        if ( is_string( $sheets_tabs_raw ) ) { $sheets_tabs_raw = json_decode( $sheets_tabs_raw, true ) ?: []; }
        foreach ($sheets_tabs_raw as $entry) {
            if (esc_url_raw($entry['csv_url'] ?? '') === $csv_url) {
                $div_entry = $entry;
                break;
            }
        }
        $tab         = trim($div_entry['tab']            ?? '');
        $spreadsheet = trim($div_entry['spreadsheet_id'] ?? $opts['sheets_id'] ?? '');

        if ($tab && $spreadsheet) {
            $token = lgw_drive_get_access_token();
            if ($token) {
                $sheet_data = lgw_sheets_fetch($token, $spreadsheet, $tab);
                if ($sheet_data !== false) {
                    $cols  = lgw_sheets_detect_cols($sheet_data);
                    $match = lgw_sheets_find_row($sheet_data, $cols, $home, $away, $date);
                    if ($match !== false) {
                        list($row_index) = $match;
                        $values = $clearing
                            ? ['home_score'=>'','away_score'=>'','home_pts'=>'','away_pts'=>'']
                            : ['home_score'=>$sh,'away_score'=>$sa,'home_pts'=>$ph,'away_pts'=>$pa];
                        $requests = lgw_sheets_build_update($spreadsheet, $tab, $row_index, $cols, $values);
                        $ok = lgw_sheets_batch_update($token, $spreadsheet, $requests);
                        if ($ok) {
                            delete_transient('lgw_csv_' . md5($csv_url));
                            $sheet_msg = 'sheet_ok';
                        } else {
                            $sheet_msg = 'sheet_error';
                        }
                    } else {
                        // Diagnostic: collect candidate team pairs from the sheet so the mismatch is visible
                        $diag_teams = [];
                        foreach ($sheet_data as $row) {
                            $ht = trim($row[$cols['hteam']] ?? '');
                            $at = trim($row[$cols['ateam']] ?? '');
                            if ($ht && $at) $diag_teams[] = $ht . ' v ' . $at;
                        }
                        $sheet_msg = 'row_not_found';
                        wp_send_json_success([
                            'sheet'       => $sheet_msg,
                            'debug'       => [
                                'looking_for' => $home . ' v ' . $away . ' on ' . $date,
                                'tab'         => $tab,
                                'col_hteam'   => $cols['hteam'],
                                'col_ateam'   => $cols['ateam'],
                                'sheet_teams' => array_slice(array_unique($diag_teams), 0, 20),
                            ],
                        ]);
                    }
                } else {
                    $sheet_msg = 'fetch_failed';
                }
            } else {
                $sheet_msg = 'auth_failed';
            }
        } else {
            $sheet_msg = !$tab ? 'no_tab' : 'no_spreadsheet_id';
        }
    }

    wp_send_json_success(array('sheet' => $sheet_msg));
}

// ── Quick Score Entry — AJAX: clear all overrides for a CSV URL ──────────────
add_action('wp_ajax_lgw_clear_score_overrides', 'lgw_ajax_clear_score_overrides');
function lgw_ajax_clear_score_overrides() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    check_ajax_referer('lgw_scores_nonce', 'nonce');
    $csv_url = esc_url_raw($_POST['csv_url'] ?? '');
    if (!$csv_url) {
        update_option('lgw_score_overrides', array());
    } else {
        $overrides = lgw_get_option_array('lgw_score_overrides');
        foreach ($overrides as $k => $v) {
            if (($v['csv_url'] ?? '') === $csv_url) unset($overrides[$k]);
        }
        update_option('lgw_score_overrides', $overrides);
    }
    wp_send_json_success('Cleared');
}

// ── Retag a single scorecard to a specific season ────────────────────────────
add_action('wp_ajax_lgw_retag_scorecard', 'lgw_ajax_retag_scorecard');
function lgw_ajax_retag_scorecard() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $post_id   = intval($_POST['post_id'] ?? 0);
    $season_id = sanitize_text_field($_POST['season_id'] ?? '');
    if (!$post_id) wp_send_json_error('Missing post ID');
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'lgw_retag_sc_' . $post_id)) {
        wp_send_json_error('Nonce invalid');
    }
    if (!get_post($post_id) || get_post_type($post_id) !== 'lgw_scorecard') {
        wp_send_json_error('Invalid scorecard');
    }
    if ($season_id === '') {
        delete_post_meta($post_id, 'lgw_sc_season');
    } else {
        // Validate season ID exists
        $known = array_map(function($s){ return $s['id']; }, lgw_get_seasons());
        if (!in_array($season_id, $known, true)) wp_send_json_error('Unknown season');
        update_post_meta($post_id, 'lgw_sc_season', $season_id);
    }
    wp_send_json_success(array('post_id' => $post_id, 'season_id' => $season_id));
}


// ── Backfill scorecard season tags ───────────────────────────────────────────
add_action('wp_ajax_lgw_backfill_sc_seasons', 'lgw_ajax_backfill_sc_seasons');
function lgw_ajax_backfill_sc_seasons() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    $season_id = sanitize_text_field($_POST['season_id'] ?? '');
    if (!$season_id) wp_send_json_error('Missing season ID');
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'lgw_backfill_sc_seasons_' . $season_id)) {
        wp_send_json_error('Nonce invalid');
    }

    // Strategy 1: scorecards with no season tag at all
    $untagged = get_posts(array(
        'post_type'      => 'lgw_scorecard',
        'posts_per_page' => -1,
        'post_status'    => array('publish', 'draft'),
        'fields'         => 'ids',
        'meta_query'     => array(
            array('key' => 'lgw_sc_season', 'compare' => 'NOT EXISTS'),
        ),
    ));

    // Strategy 2: scorecards within the season's date range (catches wrong-season tags)
    $by_date = array();
    if (function_exists('lgw_get_season_by_id')) {
        $season_obj = lgw_get_season_by_id($season_id);
        if ($season_obj && !empty($season_obj['start']) && !empty($season_obj['end'])) {
            $start = $season_obj['start'];
            $end   = $season_obj['end'];
            $all_sc = get_posts(array(
                'post_type'      => 'lgw_scorecard',
                'posts_per_page' => -1,
                'post_status'    => array('publish', 'draft'),
                'fields'         => 'ids',
            ));
            foreach ($all_sc as $pid) {
                if (in_array($pid, $untagged)) continue;
                $sc_data  = get_post_meta($pid, 'lgw_scorecard_data', true);
                $raw_date = $sc_data['date'] ?? '';
                if (!$raw_date) continue;
                $parts = explode('/', $raw_date);
                if (count($parts) === 3) {
                    $ymd = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                    if ($ymd >= $start && $ymd <= $end) $by_date[] = $pid;
                }
            }
        }
    }

    $all_ids = array_unique(array_merge($untagged, $by_date));
    $count   = 0;
    foreach ($all_ids as $pid) {
        update_post_meta($pid, 'lgw_sc_season', $season_id);
        $count++;
    }
    wp_send_json_success(array('count' => $count, 'season_id' => $season_id));
}

// ── Quick Score Entry — parse fixtures from CSV ──────────────────────────────
function lgw_scores_parse_fixtures($csv_body, $csv_url, $overrides) {
    $rows  = array_map('str_getcsv', explode("\n", str_replace("\r",'',$csv_body)));
    $start = -1;
    foreach ($rows as $i => $r) {
        if (stripos(implode('',$r),'FIXTURES') !== false) { $start = $i+1; break; }
    }
    if ($start < 0) return array();

    $colPtsH=0;$colHTeam=2;$colHScore=7;$colAScore=9;$colATeam=10;$colPtsA=15;
    for ($h=$start; $h<min($start+5,count($rows)); $h++) {
        if (stripos(implode('',$rows[$h]),'HPts') !== false) {
            foreach ($rows[$h] as $c => $v) {
                $v=trim($v);
                if($v==='HPts')                     $colPtsH=$c;
                if($v==='HTeam')                    $colHTeam=$c;
                if($v==='HScore')                   $colHScore=$c;
                if(in_array($v,['AScore','Ascore'])) $colAScore=$c;
                if($v==='ATeam')                    $colATeam=$c;
                if($v==='APts')                     $colPtsA=$c;
            }
            $start=$h+1; break;
        }
    }

    $dateRe='/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/';
    $fixtures=array(); $curDate='';
    for ($i=$start; $i<count($rows); $i++) {
        $r=$rows[$i]; $first=trim($r[0]??$r[1]??'');
        if (preg_match($dateRe,$first)) { $curDate=$first; continue; }
        if (!$curDate) continue;
        $home=trim($r[$colHTeam]??''); $away=trim($r[$colATeam]??'');
        if (!$home||!$away) continue;
        $sh_orig=trim($r[$colHScore]??''); $sa_orig=trim($r[$colAScore]??'');
        $ph_orig=trim($r[$colPtsH]??'');   $pa_orig=trim($r[$colPtsA]??'');
        $key=$csv_url.'||'.$curDate.'||'.$home.'||'.$away;
        $ov=$overrides[$key]??null;
        $fixtures[]=array(
            'date'=>$curDate,'home'=>$home,'away'=>$away,
            'sh'=>$ov?$ov['sh']:'','sa'=>$ov?$ov['sa']:'',
            'ph'=>$ov?$ov['ph']:'','pa'=>$ov?$ov['pa']:'',
            'sh_orig'=>$sh_orig,'sa_orig'=>$sa_orig,
            'ph_orig'=>$ph_orig,'pa_orig'=>$pa_orig,
            'overridden'=>$ov!==null,
        );
    }
    return $fixtures;
}

// ── Season Progress chart: AJAX handler ──────────────────────────────────────

add_action('wp_ajax_lgw_season_progress',        'lgw_ajax_season_progress');
add_action('wp_ajax_nopriv_lgw_season_progress', 'lgw_ajax_season_progress');
function lgw_ajax_season_progress() {
    check_ajax_referer('lgw_season_progress_nonce', 'nonce');

    $division  = sanitize_text_field( $_POST['division']  ?? '' );
    $season_id = sanitize_text_field( $_POST['season_id'] ?? '' );

    $meta_query = array(
        'relation' => 'AND',
        array( 'key' => 'lgw_sc_context', 'value' => 'league',    'compare' => '=' ),
        array( 'key' => 'lgw_sc_status',  'value' => 'confirmed', 'compare' => '=' ),
    );
    if ( $season_id ) {
        $meta_query[] = array( 'key' => 'lgw_sc_season', 'value' => $season_id, 'compare' => '=' );
    }

    $posts = get_posts( array(
        'post_type'      => 'lgw_scorecard',
        'posts_per_page' => 1000,
        'post_status'    => 'publish',
        'meta_query'     => $meta_query,
    ) );

    $div_lower = $division ? strtolower( trim( $division ) ) : '';

    // Group matches by fixture date timestamp
    $by_date = array();
    foreach ( $posts as $p ) {
        if ( get_post_meta( $p->ID, 'lgw_sc_null_void', true ) === '1' ) continue;
        $sc = get_post_meta( $p->ID, 'lgw_scorecard_data', true );
        if ( ! is_array( $sc ) ) continue;
        $home = $sc['home_team'] ?? '';
        $away = $sc['away_team'] ?? '';
        if ( ! $home || ! $away ) continue;

        if ( $div_lower ) {
            $sc_div   = strtolower( trim( $sc['division'] ?? '' ) );
            $meta_div = strtolower( trim( get_post_meta( $p->ID, 'lgw_sc_division', true ) ) );
            if ( $sc_div !== $div_lower && $meta_div !== $div_lower ) continue;
        }

        // Use the actual played date for chart grouping — brought-forward games
        // have an lgw_fixture_date set to the original scheduled date, but the
        // scorecard's 'date' field records when it was actually played.
        $played_date = $sc['date'] ?? '';
        if ( ! $played_date ) $played_date = get_post_meta( $p->ID, 'lgw_fixture_date', true );
        if ( ! $played_date ) $played_date = $sc['fixture_date'] ?? '';
        if ( ! $played_date ) continue;

        $ts = lgw_parse_any_date( $played_date );
        if ( ! $ts ) continue;

        $by_date[ $ts ][] = array(
            'home'         => $home,
            'away'         => $away,
            'home_pts'     => (float)( $sc['home_points'] ?? 0 ),
            'away_pts'     => (float)( $sc['away_points'] ?? 0 ),
            'home_shots'   => (int)(   $sc['home_total']  ?? 0 ),
            'away_shots'   => (int)(   $sc['away_total']  ?? 0 ),
            'date_label'   => $played_date,
        );
    }

    if ( empty( $by_date ) ) {
        wp_send_json_success( array( 'dates' => array(), 'series' => array() ) );
        return;
    }

    ksort( $by_date );

    // Walk through each match date, accumulate running totals, snapshot table
    $running  = array(); // team => [pts, f, a]
    $dates    = array();
    $snaps    = array(); // [ team => [pts, pos], ... ] per date

    foreach ( $by_date as $ts => $matches ) {
        $dates[] = $matches[0]['date_label'];

        foreach ( $matches as $m ) {
            foreach ( array( 'home' => $m['home'], 'away' => $m['away'] ) as $side => $name ) {
                if ( ! isset( $running[ $name ] ) ) {
                    $running[ $name ] = array( 'pts' => 0.0, 'f' => 0, 'a' => 0 );
                }
            }
            $running[ $m['home'] ]['pts'] += $m['home_pts'];
            $running[ $m['home'] ]['f']   += $m['home_shots'];
            $running[ $m['home'] ]['a']   += $m['away_shots'];
            $running[ $m['away'] ]['pts'] += $m['away_pts'];
            $running[ $m['away'] ]['f']   += $m['away_shots'];
            $running[ $m['away'] ]['a']   += $m['home_shots'];
        }

        // Sort by pts desc then shot-diff desc for position
        $sorted = $running;
        uasort( $sorted, function( $a, $b ) {
            if ( $b['pts'] !== $a['pts'] ) return $b['pts'] <=> $a['pts'];
            return ( $b['f'] - $b['a'] ) <=> ( $a['f'] - $a['a'] );
        } );

        $snap = array(); $pos = 1;
        foreach ( $sorted as $name => $t ) {
            $snap[ $name ] = array( 'pts' => $t['pts'], 'pos' => $pos++ );
        }
        $snaps[] = $snap;
    }

    // Build per-team series (null where team hadn't appeared yet)
    $all_teams = array_keys( $running );
    $series    = array();
    foreach ( $all_teams as $team ) {
        $pts_arr = array();
        $pos_arr = array();
        foreach ( $snaps as $snap ) {
            $pts_arr[] = isset( $snap[ $team ] ) ? $snap[ $team ]['pts'] : null;
            $pos_arr[] = isset( $snap[ $team ] ) ? $snap[ $team ]['pos'] : null;
        }
        $series[ $team ] = array( 'pts' => $pts_arr, 'pos' => $pos_arr );
    }

    wp_send_json_success( array(
        'dates'      => $dates,
        'series'     => $series,
        'team_count' => count( $all_teams ),
    ) );
}

// ── Table Compare: helpers + AJAX + admin page ───────────────────────────────

/**
 * Parse the LEAGUE TABLE section of a Sheets CSV into an array of team rows.
 * Returns: [ ['pos'=>1,'team'=>'...','pl'=>0,'w'=>0,'d'=>0,'l'=>0,'f'=>0,'a'=>0,'pts'=>0], ... ]
 */
function lgw_parse_csv_table( $csv_body ) {
    $rows  = array_map( 'str_getcsv', explode( "\n", str_replace( "\r", '', $csv_body ) ) );
    $start = -1;
    foreach ( $rows as $i => $r ) {
        if ( stripos( implode( '', $r ), 'LEAGUE TABLE' ) !== false ) { $start = $i + 1; break; }
    }
    if ( $start < 0 ) return array();
    while ( $start < count( $rows ) && empty( array_filter( $rows[ $start ] ) ) ) $start++;
    // Find header row (starts with POS)
    while ( $start < count( $rows ) && ( $rows[ $start ][0] ?? '' ) !== 'POS' ) $start++;
    if ( $start >= count( $rows ) ) return array();

    $hdr = $rows[ $start ];
    $col = array( 'pos' => 0, 'team' => 1, 'pl' => -1, 'w' => -1, 'd' => -1, 'l' => -1, 'f' => -1, 'a' => -1, 'pts' => -1 );
    foreach ( $hdr as $c => $v ) {
        $v = strtoupper( trim( $v ) );
        if ( $v === 'POS' )                          $col['pos']  = $c;
        elseif ( $v === 'TEAM' )                     $col['team'] = $c;
        elseif ( in_array( $v, ['PL','PLAYED'] ) )   $col['pl']   = $c;
        elseif ( in_array( $v, ['W','WON'] ) )       $col['w']    = $c;
        elseif ( in_array( $v, ['D','DRAWN'] ) )     $col['d']    = $c;
        elseif ( in_array( $v, ['L','LOST'] ) )      $col['l']    = $c;
        elseif ( $v === 'FOR' )                      $col['f']    = $c;
        elseif ( in_array( $v, ['AGAINST','AGN'] ) ) $col['a']    = $c;
        elseif ( in_array( $v, ['PTS','POINTS'] ) )  $col['pts']  = $c;
    }
    // Positional fallbacks matching JS parseTableRows
    if ( $col['pl']  < 0 ) $col['pl']  = 5;
    if ( $col['pts'] < 0 ) $col['pts'] = 7;
    if ( $col['w']   < 0 ) $col['w']   = 9;
    if ( $col['l']   < 0 ) $col['l']   = 10;
    if ( $col['d']   < 0 ) $col['d']   = 11;
    if ( $col['f']   < 0 ) $col['f']   = 12;
    if ( $col['a']   < 0 ) $col['a']   = 14;

    $teams = array();
    for ( $i = $start + 1; $i < count( $rows ); $i++ ) {
        $r = $rows[ $i ];
        $pos  = trim( $r[ $col['pos']  ] ?? '' );
        $team = trim( $r[ $col['team'] ] ?? '' );
        if ( ! $pos || ! $team || ! is_numeric( $pos ) ) break; // end of table
        $teams[] = array(
            'pos'  => (int) $pos,
            'team' => $team,
            'pl'   => (int) ( $r[ $col['pl']  ] ?? 0 ),
            'w'    => (int) ( $r[ $col['w']   ] ?? 0 ),
            'd'    => (int) ( $r[ $col['d']   ] ?? 0 ),
            'l'    => (int) ( $r[ $col['l']   ] ?? 0 ),
            'f'    => (int) ( $r[ $col['f']   ] ?? 0 ),
            'a'    => (int) ( $r[ $col['a']   ] ?? 0 ),
            'pts'  => (float) ( $r[ $col['pts'] ] ?? 0 ),
        );
    }
    return $teams;
}

/**
 * Parse the FIXTURES section of a Sheets CSV and return only played rows.
 * Returns: [ ['date'=>'...','home'=>'...','away'=>'...','sh'=>0,'sa'=>0,'ph'=>0,'pa'=>0], ... ]
 */
function lgw_parse_csv_fixtures_played( $csv_body ) {
    $rows  = array_map( 'str_getcsv', explode( "\n", str_replace( "\r", '', $csv_body ) ) );
    $start = -1;
    foreach ( $rows as $i => $r ) {
        if ( stripos( implode( '', $r ), 'FIXTURES' ) !== false ) { $start = $i + 1; break; }
    }
    if ( $start < 0 ) return array();

    $colPH = 0; $colHT = 2; $colHS = 7; $colAS = 9; $colAT = 10; $colPA = 15;
    for ( $h = $start; $h < min( $start + 5, count( $rows ) ); $h++ ) {
        $joined = strtolower( implode( '', $rows[ $h ] ) );
        if ( strpos( $joined, 'hpts' ) !== false || strpos( $joined, 'homepts' ) !== false ) {
            foreach ( $rows[ $h ] as $c => $v ) {
                $v = trim( $v );
                $vl = strtolower( $v );
                if ( in_array( $vl, ['hpts','homepts'] ) )     $colPH = $c;
                if ( in_array( $vl, ['hteam','home'] ) )       $colHT = $c;
                if ( in_array( $vl, ['hscore','home shots'] ) ) $colHS = $c;
                if ( in_array( $vl, ['ascore','away shots'] ) ) $colAS = $c;
                if ( in_array( $vl, ['ateam','away'] ) )       $colAT = $c;
                if ( in_array( $vl, ['apts','awaypts'] ) )     $colPA = $c;
            }
            $start = $h + 1; break;
        }
    }

    $dateRe  = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/';
    $played  = array(); $curDate = '';
    for ( $i = $start; $i < count( $rows ); $i++ ) {
        $r     = $rows[ $i ];
        $first = trim( $r[0] ?? $r[1] ?? '' );
        if ( preg_match( $dateRe, $first ) ) { $curDate = $first; continue; }
        if ( ! $curDate ) continue;
        $home = trim( $r[ $colHT ] ?? '' );
        $away = trim( $r[ $colAT ] ?? '' );
        if ( ! $home || ! $away ) continue;
        $sh = trim( $r[ $colHS ] ?? '' );
        $sa = trim( $r[ $colAS ] ?? '' );
        $ph = trim( $r[ $colPH ] ?? '' );
        $pa = trim( $r[ $colPA ] ?? '' );
        // Only include rows with at least one score value
        if ( $sh === '' && $sa === '' && $ph === '' && $pa === '' ) continue;
        if ( $sh === '0' && $sa === '0' && $ph === '0' && $pa === '0' ) continue;
        $played[] = array(
            'date' => $curDate, 'home' => $home, 'away' => $away,
            'sh' => $sh, 'sa' => $sa, 'ph' => $ph, 'pa' => $pa,
        );
    }
    return $played;
}

/**
 * Calculate a league table from WP scorecards for a given division + season.
 * Excludes null & void scorecards. Returns same shape as lgw_parse_csv_table().
 */
function lgw_calculate_wp_table( $division, $season_id ) {
    $meta_query = array(
        'relation' => 'AND',
        array( 'key' => 'lgw_sc_context', 'value' => 'league', 'compare' => '=' ),
        array( 'key' => 'lgw_sc_status',  'value' => 'confirmed', 'compare' => '=' ),
    );
    if ( $season_id ) {
        $meta_query[] = array( 'key' => 'lgw_sc_season', 'value' => $season_id, 'compare' => '=' );
    }
    $posts = get_posts( array(
        'post_type'      => 'lgw_scorecard',
        'posts_per_page' => 1000,
        'post_status'    => 'publish',
        'meta_query'     => $meta_query,
    ) );

    // Division is stored inside lgw_scorecard_data, not as a separate meta key on regular scorecards.
    // Filter in PHP after loading the data rather than via meta_query.
    $div_lower = $division ? strtolower( trim( $division ) ) : '';

    $teams = array(); // keyed by team name (lowercase)
    foreach ( $posts as $p ) {
        // Skip null & void
        if ( get_post_meta( $p->ID, 'lgw_sc_null_void', true ) === '1' ) continue;
        $sc = get_post_meta( $p->ID, 'lgw_scorecard_data', true );
        if ( ! is_array( $sc ) ) continue;
        $home = $sc['home_team'] ?? '';
        $away = $sc['away_team'] ?? '';
        if ( ! $home || ! $away ) continue;
        // Division filter — also check the lgw_sc_division meta (set by concession/null-void handlers)
        if ( $div_lower ) {
            $sc_div  = strtolower( trim( $sc['division'] ?? '' ) );
            $meta_div = strtolower( trim( get_post_meta( $p->ID, 'lgw_sc_division', true ) ) );
            if ( $sc_div !== $div_lower && $meta_div !== $div_lower ) continue;
        }

        $sh  = (int) ( $sc['home_total']  ?? 0 );
        $sa  = (int) ( $sc['away_total']  ?? 0 );
        $ph  = (float) ( $sc['home_points'] ?? 0 );
        $pa  = (float) ( $sc['away_points'] ?? 0 );

        // W/D/L from shots; concessions have 50-0 so they sort correctly too
        $home_won  = $sh > $sa;
        $away_won  = $sa > $sh;
        $draw      = $sh === $sa;

        foreach ( array( 'home' => $home, 'away' => $away ) as $side => $name ) {
            $k = strtolower( $name );
            if ( ! isset( $teams[ $k ] ) ) {
                $teams[ $k ] = array( 'team' => $name, 'pl' => 0, 'w' => 0, 'd' => 0, 'l' => 0, 'f' => 0, 'a' => 0, 'pts' => 0 );
            }
            $teams[ $k ]['pl']++;
            if ( $side === 'home' ) {
                $teams[ $k ]['f']   += $sh;
                $teams[ $k ]['a']   += $sa;
                $teams[ $k ]['pts'] += $ph;
                if ( $home_won ) $teams[ $k ]['w']++;
                elseif ( $draw ) $teams[ $k ]['d']++;
                else             $teams[ $k ]['l']++;
            } else {
                $teams[ $k ]['f']   += $sa;
                $teams[ $k ]['a']   += $sh;
                $teams[ $k ]['pts'] += $pa;
                if ( $away_won ) $teams[ $k ]['w']++;
                elseif ( $draw ) $teams[ $k ]['d']++;
                else             $teams[ $k ]['l']++;
            }
        }
    }

    // Sort: Pts desc, then shots-diff desc, then shots-for desc
    usort( $teams, function( $a, $b ) {
        if ( $b['pts'] !== $a['pts'] ) return $b['pts'] <=> $a['pts'];
        $da = $a['f'] - $a['a'];
        $db = $b['f'] - $b['a'];
        if ( $db !== $da ) return $db <=> $da;
        return $b['f'] <=> $a['f'];
    } );

    foreach ( $teams as $i => &$t ) {
        $t['pos'] = $i + 1;
    }
    unset( $t );

    return array_values( $teams );
}

// AJAX handler for table comparison
add_action( 'wp_ajax_lgw_table_compare', 'lgw_ajax_table_compare' );
function lgw_ajax_table_compare() {
    check_ajax_referer( 'lgw_table_compare_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

    $csv_url   = esc_url_raw( $_POST['csv_url']  ?? '' );
    $division  = sanitize_text_field( $_POST['division']  ?? '' );
    $season_id = sanitize_text_field( $_POST['season_id'] ?? '' );

    if ( ! $csv_url ) wp_send_json_error( 'No CSV URL provided' );

    // Fetch CSV (bypass plugin proxy — server-side direct fetch)
    $resp = wp_remote_get( $csv_url, array( 'timeout' => 15 ) );
    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( 'CSV fetch failed: ' . $resp->get_error_message() );
    }
    $code = wp_remote_retrieve_response_code( $resp );
    if ( $code !== 200 ) {
        wp_send_json_error( 'CSV fetch returned HTTP ' . $code );
    }
    $body = wp_remote_retrieve_body( $resp );

    $sheets_table    = lgw_parse_csv_table( $body );
    $played_fixtures = lgw_parse_csv_fixtures_played( $body );
    $wp_table        = lgw_calculate_wp_table( $division, $season_id );

    // Build a lookup of WP scorecards by match key to identify coverage gaps.
    // Do not filter by lgw_sc_division meta — regular scorecards store division
    // inside lgw_scorecard_data only, not as a separate meta key.
    $gap_meta_query = array(
        'relation' => 'AND',
        array( 'key' => 'lgw_sc_context', 'value' => 'league', 'compare' => '=' ),
        array( 'key' => 'lgw_sc_status',  'value' => 'confirmed', 'compare' => '=' ),
    );
    if ( $season_id ) $gap_meta_query[] = array( 'key' => 'lgw_sc_season', 'value' => $season_id, 'compare' => '=' );
    $sc_posts = get_posts( array(
        'post_type'      => 'lgw_scorecard',
        'posts_per_page' => 1000,
        'post_status'    => 'publish',
        'meta_query'     => $gap_meta_query,
    ) );
    $div_lower = $division ? strtolower( trim( $division ) ) : '';
    $wp_keys = array();
    foreach ( $sc_posts as $p ) {
        $sc_d = get_post_meta( $p->ID, 'lgw_scorecard_data', true );
        if ( ! is_array( $sc_d ) ) continue;
        if ( $div_lower ) {
            $sc_div   = strtolower( trim( $sc_d['division'] ?? '' ) );
            $meta_div = strtolower( trim( get_post_meta( $p->ID, 'lgw_sc_division', true ) ) );
            if ( $sc_div !== $div_lower && $meta_div !== $div_lower ) continue;
        }
        $home = strtolower( trim( $sc_d['home_team'] ?? '' ) );
        $away = strtolower( trim( $sc_d['away_team'] ?? '' ) );
        if ( ! $home || ! $away ) continue;
        // Use lgw_fixture_date meta (the scheduled date) for matching against CSV fixture rows.
        // Fall back to scorecard date field if fixture_date not stored.
        $fix_date = get_post_meta( $p->ID, 'lgw_fixture_date', true );
        if ( ! $fix_date ) $fix_date = $sc_d['fixture_date'] ?? $sc_d['date'] ?? '';
        $fix_date = strtolower( trim( $fix_date ) );
        if ( $fix_date ) {
            $wp_keys[ $home . '||' . $away . '||' . $fix_date ] = true;
        }
        // Also index without date to catch scorecards missing fixture_date
        $wp_keys[ $home . '||' . $away ] = true;
    }

    // Find played fixtures in CSV that have no WP scorecard.
    // Check keyed with date first; fall back to home||away only (covers scorecards
    // that pre-date lgw_fixture_date meta storage).
    $gaps = array();
    foreach ( $played_fixtures as $fx ) {
        $h        = strtolower( $fx['home'] );
        $a        = strtolower( $fx['away'] );
        $d        = strtolower( $fx['date'] );
        $key_full = $h . '||' . $a . '||' . $d;
        $key_bare = $h . '||' . $a;
        if ( ! isset( $wp_keys[ $key_full ] ) && ! isset( $wp_keys[ $key_bare ] ) ) {
            $gaps[] = $fx;
        }
    }

    wp_send_json_success( array(
        'sheets_table'    => $sheets_table,
        'wp_table'        => $wp_table,
        'gaps'            => $gaps,
        'wp_scorecard_ct' => count( $sc_posts ),
        'played_ct'       => count( $played_fixtures ),
    ) );
}

function lgw_table_compare_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );

    $drive_opts = lgw_get_option_array( 'lgw_drive' );
    $tabs       = $drive_opts['sheets_tabs'] ?? array();
    if ( is_string( $tabs ) ) $tabs = json_decode( $tabs, true ) ?: array();
    $divisions  = array_values( array_filter( $tabs, function( $t ) {
        return ! empty( $t['csv_url'] ) && ! empty( $t['division'] );
    } ) );

    $season_id  = function_exists( 'lgw_get_active_season_id' ) ? lgw_get_active_season_id() : '';
    $nonce      = wp_create_nonce( 'lgw_table_compare_nonce' );
    $ajax_url   = admin_url( 'admin-ajax.php' );
    ?>
    <div class="wrap">
    <h1>📊 Table Compare</h1>
    <p style="color:#555;max-width:700px">Compares the league table calculated from WP scorecards against the live Sheets CSV. Use this to verify parity before switching the data source to WordPress DB.</p>

    <?php if ( empty( $divisions ) ): ?>
        <div class="notice notice-warning"><p>No divisions with CSV URLs configured. Add them in <a href="<?php echo admin_url('admin.php?page=' . LGW_SETUP_PAGE); ?>">League Setup</a>.</p></div>
    <?php else: ?>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
    <?php foreach ( $divisions as $i => $d ): ?>
        <button class="button lgw-tc-tab" data-idx="<?php echo $i; ?>"
            data-csv="<?php echo esc_attr( $d['csv_url'] ); ?>"
            data-div="<?php echo esc_attr( $d['division'] ); ?>"
            style="font-weight:600"><?php echo esc_html( $d['division'] ); ?></button>
    <?php endforeach; ?>
    </div>

    <div id="lgw-tc-status" style="margin-bottom:12px;color:#555;font-style:italic">Select a division to load comparison.</div>
    <div id="lgw-tc-output"></div>

    <script>
    (function(){
        var nonce = <?php echo json_encode( $nonce ); ?>;
        var ajaxUrl = <?php echo json_encode( $ajax_url ); ?>;
        var seasonId = <?php echo json_encode( $season_id ); ?>;

        document.querySelectorAll('.lgw-tc-tab').forEach(function(btn){
            btn.addEventListener('click', function(){
                document.querySelectorAll('.lgw-tc-tab').forEach(function(b){ b.classList.remove('button-primary'); });
                btn.classList.add('button-primary');
                loadComparison(btn.dataset.csv, btn.dataset.div);
            });
        });

        function loadComparison(csvUrl, division) {
            var status = document.getElementById('lgw-tc-status');
            var out    = document.getElementById('lgw-tc-output');
            status.textContent = 'Loading…';
            out.innerHTML = '';

            var fd = new FormData();
            fd.append('action',    'lgw_table_compare');
            fd.append('nonce',     nonce);
            fd.append('csv_url',   csvUrl);
            fd.append('division',  division);
            fd.append('season_id', seasonId);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(resp){
                    if (!resp.success) { status.textContent = '⚠️ Error: ' + (resp.data||'unknown'); return; }
                    var d = resp.data;
                    status.textContent = division + ' — ' + d.wp_scorecard_ct + ' WP scorecards | '
                        + d.played_ct + ' played in Sheets | '
                        + d.gaps.length + ' gap(s)';
                    out.innerHTML = buildOutput(d, division);
                })
                .catch(function(e){ status.textContent = '⚠️ Request failed: ' + e; });
        }

        function buildOutput(d, division) {
            return buildTableComparison(d.sheets_table, d.wp_table) + buildGaps(d.gaps);
        }

        function buildTableComparison(sheets, wp) {
            // Build lookup of WP rows by team name (lowercase) for diff
            var wpMap = {};
            wp.forEach(function(r){ wpMap[r.team.toLowerCase()] = r; });

            var sheetsMap = {};
            sheets.forEach(function(r){ sheetsMap[r.team.toLowerCase()] = r; });

            // Union of all team names
            var allTeams = [];
            sheets.forEach(function(r){ if(allTeams.indexOf(r.team.toLowerCase())<0) allTeams.push(r.team.toLowerCase()); });
            wp.forEach(function(r){ if(allTeams.indexOf(r.team.toLowerCase())<0) allTeams.push(r.team.toLowerCase()); });

            function cell(sv, wv, key) {
                var match = (sv !== undefined && wv !== undefined && String(sv) === String(wv));
                var mismatch = (sv !== undefined && wv !== undefined && !match);
                var style = mismatch ? ' style="background:#fff3cd;font-weight:700"' : '';
                return '<td'+style+'>'+(wv!==undefined?wv:'—')+'</td>';
            }

            var cols = ['pl','w','d','l','f','a','pts'];
            var labels = ['PL','W','D','L','FOR','AGN','PTS'];

            var hdr = '<tr style="background:#f0f0f0"><th style="text-align:left;padding:4px 8px;min-width:140px">Team</th>';
            labels.forEach(function(l){ hdr += '<th style="padding:4px 8px;text-align:center">'+l+'</th>'; });
            hdr += '<th style="padding:4px 8px;text-align:center;color:#888;font-size:11px">Sheets PTS</th><th style="padding:4px 8px;text-align:center;color:#888;font-size:11px">Sheets PL</th></tr>';

            var rows = '';
            // Show Sheets table order first (authoritative ranking), then WP-only teams
            var ordered = sheets.slice();
            wp.forEach(function(r){
                var found = ordered.some(function(s){ return s.team.toLowerCase()===r.team.toLowerCase(); });
                if(!found) ordered.push(r);
            });

            ordered.forEach(function(sr){
                var k   = sr.team.toLowerCase();
                var wr  = wpMap[k];
                var rowHasDiff = false;
                cols.forEach(function(c){
                    if(wr && sr && String(sr[c]) !== String(wr[c])) rowHasDiff = true;
                });
                var wpOnly  = !sheetsMap[k];
                var shOnly  = !wpMap[k];
                var rowStyle = rowHasDiff ? ' style="background:#fffbf0"'
                             : wpOnly     ? ' style="background:#f0fff0"'
                             : shOnly     ? ' style="background:#fff0f0"'
                             : '';
                rows += '<tr'+rowStyle+'>';
                rows += '<td style="padding:4px 8px;font-weight:600">';
                if(wpOnly)  rows += '<span title="Only in WP scorecards" style="color:#2a7a2a;margin-right:4px">+WP</span>';
                if(shOnly)  rows += '<span title="Only in Sheets, no WP scorecard" style="color:#c02020;margin-right:4px">-WP</span>';
                rows += (wr ? wr.team : sr.team) + '</td>';
                cols.forEach(function(c){
                    var sv = sr ? sr[c] : undefined;
                    var wv = wr ? wr[c] : undefined;
                    var mismatch = (sv!==undefined && wv!==undefined && String(sv)!==String(wv));
                    var s = mismatch ? ' style="background:#ffd700;font-weight:700"' : '';
                    rows += '<td style="text-align:center;padding:4px 8px"'+s+'>'+(wv!==undefined?wv:'—')+'</td>';
                });
                // Sheets values for reference
                rows += '<td style="text-align:center;padding:4px 8px;color:#888;font-size:12px">'+(sr&&sr.pts!==undefined?sr.pts:'—')+'</td>';
                rows += '<td style="text-align:center;padding:4px 8px;color:#888;font-size:12px">'+(sr&&sr.pl!==undefined?sr.pl:'—')+'</td>';
                rows += '</tr>';
            });

            var legend = '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:#555">'
                + '<span><span style="background:#ffd700;padding:1px 6px">&nbsp;</span> Value mismatch</span>'
                + '<span><span style="background:#fffbf0;padding:1px 6px">&nbsp;</span> Row has mismatch</span>'
                + '<span style="color:#2a7a2a">+WP</span> = in WP only &nbsp;'
                + '<span style="color:#c02020">-WP</span> = in Sheets only'
                + '</div>';

            return '<h2 style="margin-top:0">League Table (WP calculated vs Sheets)</h2>'
                + '<p style="font-size:12px;color:#666">Left columns = WP calculated. Right grey columns = Sheets reference. Gold cell = mismatch.</p>'
                + '<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:13px;width:100%">'
                + '<thead>' + hdr + '</thead><tbody>' + rows + '</tbody></table></div>'
                + legend;
        }

        function buildGaps(gaps) {
            if (!gaps.length) {
                return '<div style="margin-top:20px;padding:12px 16px;background:#f0fff0;border:1px solid #c3e6cb;border-radius:6px;color:#2a7a2a;font-weight:600">✅ No gaps — every played result in Sheets has a WP scorecard.</div>';
            }
            var rows = gaps.map(function(g){
                return '<tr>'
                    + '<td style="padding:4px 8px">'+escHtml(g.date)+'</td>'
                    + '<td style="padding:4px 8px;font-weight:600">'+escHtml(g.home)+'</td>'
                    + '<td style="padding:4px 8px;font-weight:600">'+escHtml(g.away)+'</td>'
                    + '<td style="padding:4px 8px;text-align:center">'+escHtml(g.sh)+' – '+escHtml(g.sa)+'</td>'
                    + '<td style="padding:4px 8px;text-align:center">'+escHtml(g.ph)+' – '+escHtml(g.pa)+'</td>'
                    + '</tr>';
            }).join('');
            return '<h2 style="margin-top:24px">⚠️ Coverage Gaps (' + gaps.length + ')</h2>'
                + '<p style="font-size:12px;color:#666">Played results in Sheets with no matching WP scorecard. These will be absent from a WP-calculated table.</p>'
                + '<div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:13px">'
                + '<thead><tr style="background:#f0f0f0">'
                + '<th style="padding:4px 8px;text-align:left">Date</th>'
                + '<th style="padding:4px 8px;text-align:left">Home</th>'
                + '<th style="padding:4px 8px;text-align:left">Away</th>'
                + '<th style="padding:4px 8px;text-align:center">Shots</th>'
                + '<th style="padding:4px 8px;text-align:center">Pts</th>'
                + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
        }

        function escHtml(s) {
            return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

        // Auto-load first division
        var firstBtn = document.querySelector('.lgw-tc-tab');
        if (firstBtn) firstBtn.click();
    })();
    </script>
    <?php endif; ?>
    </div>
    <?php
}

// ── Test data seeding (local environments only) ───────────────────────────────
// lgw_seed_test_clubs() is registered only when WP_ENVIRONMENT_TYPE === 'local'.
// It must never run in production — the environment guard is intentional.
if ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local' ) {
    /**
     * Seed two deterministic test clubs for Playwright E2E tests.
     *
     * Club names:       "Test Club A"  /  "Test Club B"
     * Passphrases:      "test-pass-a"  /  "test-pass-b"
     * Pin storage:      SHA-256 of passphrase, matching the production auth flow.
     *
     * Idempotent — safe to call multiple times; existing clubs with the same
     * name are updated rather than duplicated.
     */
    function lgw_seed_test_clubs() {
        $seed = array(
            array( 'name' => 'Test Club A', 'passphrase' => 'test-pass-a' ),
            array( 'name' => 'Test Club B', 'passphrase' => 'test-pass-b' ),
        );

        $clubs = get_option( 'lgw_clubs', array() );

        foreach ( $seed as $entry ) {
            $name = $entry['name'];
            $pin  = hash( 'sha256', $entry['passphrase'] );
            $found = false;
            foreach ( $clubs as &$club ) {
                if ( strtolower( $club['name'] ) === strtolower( $name ) ) {
                    $club['pin'] = $pin;
                    $found = true;
                    break;
                }
            }
            unset( $club );
            if ( ! $found ) {
                $clubs[] = array( 'name' => $name, 'pin' => $pin );
            }
        }

        update_option( 'lgw_clubs', $clubs );
    }
}
