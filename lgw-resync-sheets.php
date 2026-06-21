<?php
/**
 * lgw-resync-sheets.php
 *
 * Bulk-pushes all confirmed scorecards to Google Sheets WITHOUT touching Drive.
 * Calls lgw_sheets_write_result() directly — bypasses the lgw_scorecard_confirmed
 * hook so lgw_drive_save_scorecard() is never invoked.
 *
 * Copy this file into the plugin directory first, then run:
 *   docker exec lgw-local_wordpress_1 \
 *     wp eval-file /var/www/html/wp-content/plugins/lgw-division-widget/lgw-resync-sheets.php \
 *     --allow-root
 *
 * Optional args (append after --allow-root --):
 *   division="Division 1"   limit to one division
 *   dry_run=1               log what would be sent without writing
 *   season=2026             limit to scorecards tagged with this season
 */

// ── Args ──────────────────────────────────────────────────────────────────────
// Check raw argv so detection works regardless of how WP-CLI parses the args.
$_argv_str       = implode( ' ', $_SERVER['argv'] ?? [] );
$dry_run         = (bool) preg_match( '/dry.run/i', $_argv_str );
$filter_division = $assoc_args['division'] ?? '';
$filter_season   = $assoc_args['season']   ?? '';
// Also support -- division="X" style (positional key=value)
foreach ( $args ?? [] as $_a ) {
    if ( str_starts_with( $_a, 'division=' ) ) $filter_division = substr( $_a, 9 );
    if ( str_starts_with( $_a, 'season=' ) )   $filter_season   = substr( $_a, 7 );
}

// ── Safety check: resolve and name target spreadsheets ───────────────────────
$opts_check = get_option( 'lgw_drive', [] );
$sheet_ids  = array_unique( array_filter( array_column( $opts_check['sheets_tabs'] ?? [], 'spreadsheet_id' ) ) );
if ( ! $sheet_ids && ! empty( $opts_check['sheets_id'] ) ) {
    $sheet_ids = [ $opts_check['sheets_id'] ];
}

if ( $sheet_ids ) {
    $token = function_exists( 'lgw_drive_get_access_token' ) ? lgw_drive_get_access_token() : false;
    foreach ( $sheet_ids as $sid ) {
        $name = $sid;
        if ( $token && function_exists( 'lgw_drive_request' ) ) {
            $resp = lgw_drive_request(
                $token, 'GET',
                "https://sheets.googleapis.com/v4/spreadsheets/$sid?fields=properties.title"
            );
            if ( ! empty( $resp['properties']['title'] ) ) {
                $name = $resp['properties']['title'] . "  ($sid)";
            }
        }
        WP_CLI::log( "  Target sheet: $name" );
    }
} else {
    WP_CLI::log( '  Target sheet: (none configured — writeback will fail)' );
}

if ( $dry_run ) {
    WP_CLI::log( '== DRY RUN — no writes will be made ==' );
} else {
    WP_CLI::log( 'Starting in 5 seconds — Ctrl+C to abort.' );
    sleep( 5 );
}

if ( $filter_division ) WP_CLI::log( "Division filter: $filter_division" );
if ( $filter_season )   WP_CLI::log( "Season filter:   $filter_season" );

// ── Sanity checks ─────────────────────────────────────────────────────────────
if ( ! function_exists( 'lgw_sheets_write_result' ) ) {
    WP_CLI::error( 'lgw_sheets_write_result() not found — is the LGW plugin active?' );
    return;
}

$opts = get_option( 'lgw_drive', [] );
if ( empty( $opts['sheets_enabled'] ) ) {
    WP_CLI::warning( 'Sheets writeback is disabled in LGW Drive settings — rows will not actually be written.' );
}

// ── Query ─────────────────────────────────────────────────────────────────────
$meta_query = [
    [ 'key' => 'lgw_sc_status', 'value' => 'confirmed' ],
];
if ( $filter_season ) {
    $meta_query[] = [ 'key' => 'lgw_sc_season', 'value' => $filter_season ];
}

$ids = get_posts( [
    'post_type'      => 'lgw_scorecard',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'meta_query'     => $meta_query,
    'fields'         => 'ids',
] );

WP_CLI::log( 'Found ' . count( $ids ) . ' confirmed scorecards.' );

// ── Process ───────────────────────────────────────────────────────────────────
$ok = 0; $skipped = 0; $failed = 0;
$progress = WP_CLI\Utils\make_progress_bar( 'Syncing to Sheets', count( $ids ) );

foreach ( $ids as $post_id ) {
    $sc       = get_post_meta( $post_id, 'lgw_scorecard_data', true );
    $division = trim( $sc['division'] ?? '' );
    $label    = sprintf( '#%d  %s v %s  (%s)%s',
        $post_id,
        $sc['home_team'] ?? '?',
        $sc['away_team'] ?? '?',
        $sc['date']      ?? '?',
        $division ? "  [$division]" : ''
    );

    if ( $filter_division && stripos( $division, $filter_division ) === false ) {
        $skipped++;
        $progress->tick();
        continue;
    }

    if ( get_post_meta( $post_id, 'lgw_skip_google', true ) ) {
        WP_CLI::log( "  SKIP $label — lgw_skip_google flag set" );
        $skipped++;
        $progress->tick();
        continue;
    }

    if ( $dry_run ) {
        WP_CLI::log( "  DRY  $label" );
        $ok++;
        $progress->tick();
        continue;
    }

    // Direct call — Drive hook is NOT fired
    $result = lgw_sheets_write_result( $post_id );

    if ( $result === false ) {
        WP_CLI::warning( "  FAIL $label" );
        $failed++;
    } else {
        $ok++;
    }

    $progress->tick();
}

$progress->finish();

WP_CLI::log( '' );
WP_CLI::success( "Done.  Synced: $ok  |  Skipped: $skipped  |  Failed: $failed" );
WP_CLI::log( 'Check Scorecard admin → Sheets Log column for per-card detail.' );
