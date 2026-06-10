<?php
/**
 * lgw-div-cache.php — Division data cache layer
 *
 * Stores a materialised snapshot of each division's standings and fixtures
 * in WP options so the [lgw_division] shortcode can render server-side without
 * any outbound HTTP request on page load.
 *
 * Cache key format:  lgw_div_cache_{season_id}_{division_slug}
 * Populated by:      background WP-Cron (hourly by default)
 *                    scorecard confirmation hook (result merge only)
 *                    admin "Sync now" button
 *
 * The CSV remains the authoritative source for standings. This module never
 * writes standings data — it only reads from the CSV and caches the result.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'LGW_CACHE_HARD_TTL', DAY_IN_SECONDS ); // 24h — serve stale beyond this, fall back to XHR

// ── Cron registration ─────────────────────────────────────────────────────────
add_action( 'lgw_div_cache_cron', 'lgw_cache_sync_all' );

add_action( 'wp', 'lgw_cache_schedule_cron' );
function lgw_cache_schedule_cron() {
    if ( ! wp_next_scheduled( 'lgw_div_cache_cron' ) ) {
        $interval = get_option( 'lgw_cache_cron_interval', 'hourly' );
        wp_schedule_event( time(), $interval, 'lgw_div_cache_cron' );
    }
}

// Reschedule when the interval option changes
add_action( 'update_option_lgw_cache_cron_interval', 'lgw_cache_reschedule_cron', 10, 2 );
function lgw_cache_reschedule_cron( $old, $new ) {
    $timestamp = wp_next_scheduled( 'lgw_div_cache_cron' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'lgw_div_cache_cron' );
    }
    wp_schedule_event( time(), $new, 'lgw_div_cache_cron' );
}

// ── Scorecard hooks ───────────────────────────────────────────────────────────
add_action( 'lgw_scorecard_confirmed',    'lgw_cache_merge_result' );
add_action( 'lgw_scorecard_admin_edited', 'lgw_cache_merge_result' );

// ── AJAX: admin sync now ──────────────────────────────────────────────────────
add_action( 'wp_ajax_lgw_cache_sync_now',       'lgw_ajax_cache_sync_now' );
add_action( 'wp_ajax_lgw_cache_sync_division',  'lgw_ajax_cache_sync_division' );

// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC API
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Read the cached division data.
 *
 * @param  string $season_id   Season ID (e.g. "2026").
 * @param  string $division    Division name as stored in shortcode attr.
 * @return array|null  Cached data array, or null if missing / beyond hard TTL.
 */
function lgw_cache_get_division( $season_id, $division ) {
    $key  = lgw_cache_option_key( $season_id, $division );
    $data = get_option( $key, null );

    if ( ! is_array( $data ) ) return null;

    // Enforce hard TTL — if beyond this we must fall back to the XHR path
    $synced_at = intval( $data['synced_at'] ?? 0 );
    if ( $synced_at > 0 && ( time() - $synced_at ) > LGW_CACHE_HARD_TTL ) {
        return null;
    }

    return $data;
}

/**
 * Write division data to the cache.
 *
 * @param  string $season_id
 * @param  string $division
 * @param  array  $data        Must contain: teams, fixtures, csv_url, synced_at.
 * @return bool
 */
function lgw_cache_set_division( $season_id, $division, $data ) {
    $data['synced_at'] = time();
    $data['season_id'] = $season_id;
    $data['division']  = $division;
    $key = lgw_cache_option_key( $season_id, $division );
    return update_option( $key, $data, false ); // false = do not autoload
}

/**
 * Delete the cache entry for a division, forcing a rebuild on next sync.
 *
 * @param  string $season_id
 * @param  string $division
 */
function lgw_cache_invalidate( $season_id, $division ) {
    delete_option( lgw_cache_option_key( $season_id, $division ) );
}

/**
 * Delete all lgw_div_cache_* options (used by "Clear cache" button).
 */
function lgw_cache_invalidate_all() {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE 'lgw\_div\_cache\_%'"
    );
}

/**
 * Fetch the CSV for a division, parse it, overlay scorecard statuses, and
 * write to cache.
 *
 * @param  string $csv_url
 * @param  string $season_id
 * @param  string $division
 * @return bool  true on success, false on failure (existing cache untouched).
 */
function lgw_cache_sync_from_csv( $csv_url, $season_id, $division ) {
    if ( ! $csv_url ) return false;

    // Fetch — use WP HTTP API with a reasonable timeout
    $response = wp_remote_get( $csv_url, [
        'timeout'    => 15,
        'user-agent' => 'Mozilla/5.0 (LGW-Cache-Sync)',
    ] );

    if ( is_wp_error( $response ) ) {
        lgw_cache_log( 'warn', "CSV fetch failed for {$division}: " . $response->get_error_message() );
        return false;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        lgw_cache_log( 'warn', "CSV returned HTTP {$code} for {$division}" );
        return false;
    }

    $body = wp_remote_retrieve_body( $response );
    if ( empty( $body ) ) {
        lgw_cache_log( 'warn', "Empty CSV body for {$division}" );
        return false;
    }

    // Also refresh the short-lived transient used by the XHR fallback path
    $cache_mins = intval( get_option( 'lgw_cache_mins', 5 ) );
    set_transient( 'lgw_csv_' . md5( $csv_url ), $body, $cache_mins * MINUTE_IN_SECONDS );
    set_transient( 'lgw_csv_stale_' . md5( $csv_url ), $body, DAY_IN_SECONDS );

    // Parse
    $teams    = lgw_cache_parse_teams( $body );
    $fixtures = lgw_cache_parse_fixtures( $body );

    if ( empty( $teams ) && empty( $fixtures ) ) {
        lgw_cache_log( 'warn', "CSV parsed to empty teams+fixtures for {$division} — skipping cache write" );
        return false;
    }

    // Overlay confirmed scorecard statuses from WP DB
    $fixtures = lgw_cache_overlay_scorecard_statuses( $fixtures, $division, $season_id );

    $data = [
        'teams'    => $teams,
        'fixtures' => $fixtures,
        'csv_url'  => $csv_url,
    ];

    lgw_cache_set_division( $season_id, $division, $data );
    lgw_cache_log( 'info', "Synced {$division} — " . count( $teams ) . " teams, " . count( $fixtures ) . " fixtures" );

    return true;
}

/**
 * Sync all active season divisions from their CSV URLs.
 * Called by WP-Cron and the admin "Sync all" button.
 */
function lgw_cache_sync_all() {
    if ( ! function_exists( 'lgw_get_active_season_id' ) ) return;

    $season_id = lgw_get_active_season_id();
    if ( ! $season_id ) return;

    $seasons = get_option( 'lgw_seasons', [] );
    $season  = null;
    foreach ( $seasons as $s ) {
        if ( ( $s['id'] ?? '' ) === $season_id ) { $season = $s; break; }
    }
    if ( ! $season ) return;

    $divisions = $season['divisions'] ?? [];
    if ( empty( $divisions ) ) return;

    $opts = get_option( 'lgw_drive', [] );

    foreach ( $divisions as $div_entry ) {
        // Each division entry is an array: ['division' => 'Division 1', 'csv_url' => '...']
        // Guard against plain strings (future-proofing).
        if ( is_array( $div_entry ) ) {
            $div_name = trim( $div_entry['division'] ?? '' );
            $csv_url  = trim( $div_entry['csv_url']  ?? '' );
        } else {
            $div_name = trim( (string) $div_entry );
            $csv_url  = '';
        }

        if ( ! $div_name ) continue;

        // If no csv_url in the season entry, try sheets_tabs mapping
        if ( ! $csv_url && function_exists( 'lgw_sheets_entry_for_division' ) ) {
            $entry   = lgw_sheets_entry_for_division( $div_name, $opts );
            $csv_url = trim( $entry['csv_url'] ?? '' );
        }

        if ( $csv_url ) {
            lgw_cache_sync_from_csv( $csv_url, $season_id, $div_name );
        }
    }
}

/**
 * Merge a confirmed scorecard result into the cached fixture for its division.
 * Hooked to lgw_scorecard_confirmed and lgw_scorecard_admin_edited.
 *
 * @param  int $post_id  lgw_scorecard CPT post ID.
 */
function lgw_cache_merge_result( $post_id ) {
    $sc = get_post_meta( $post_id, 'lgw_scorecard_data', true );
    if ( ! is_array( $sc ) ) return;

    $home     = trim( $sc['home_team']  ?? '' );
    $away     = trim( $sc['away_team']  ?? '' );
    $division = trim( $sc['division']   ?? '' );
    $date_raw = trim( $sc['date']       ?? '' ); // played date dd/mm/yyyy

    if ( ! $home || ! $away || ! $division ) return;

    // Determine season
    $season_id = get_post_meta( $post_id, 'lgw_sc_season', true );
    if ( ! $season_id && function_exists( 'lgw_get_active_season_id' ) ) {
        $season_id = lgw_get_active_season_id();
    }
    if ( ! $season_id ) return;

    $cached = lgw_cache_get_division( $season_id, $division );
    if ( ! $cached || empty( $cached['fixtures'] ) ) return;

    // Find fixture date from lgw_fixture_date meta, or fall back to played date
    $fixture_date_raw = trim( get_post_meta( $post_id, 'lgw_fixture_date', true ) );
    if ( ! $fixture_date_raw ) $fixture_date_raw = $date_raw;

    // Normalise both sides to a comparable string for matching
    $fixture_ts = lgw_parse_any_date( $fixture_date_raw );

    $matched = false;
    foreach ( $cached['fixtures'] as &$fx ) {
        $fx_home = trim( $fx['homeTeam'] ?? '' );
        $fx_away = trim( $fx['awayTeam'] ?? '' );

        // Match by home/away name (case-insensitive) and date
        if ( strcasecmp( $fx_home, $home ) !== 0 || strcasecmp( $fx_away, $away ) !== 0 ) continue;

        // Date match — compare timestamps if available, otherwise skip date check
        if ( $fixture_ts ) {
            $fx_ts = lgw_parse_any_date( $fx['date'] ?? '' );
            if ( $fx_ts && abs( $fx_ts - $fixture_ts ) > DAY_IN_SECONDS ) continue;
        }

        // Write scores from scorecard into cached fixture
        $fx['shotsHome'] = (string) ( $sc['home_total']  ?? '' );
        $fx['shotsAway'] = (string) ( $sc['away_total']  ?? '' );
        $fx['ptsHome']   = (string) ( $sc['home_points'] ?? '' );
        $fx['ptsAway']   = (string) ( $sc['away_points'] ?? '' );
        $fx['played']    = true;
        $fx['status']    = 'confirmed';
        $matched = true;
        break;
    }
    unset( $fx );

    if ( $matched ) {
        // Persist updated cache (preserves synced_at from original)
        $key = lgw_cache_option_key( $season_id, $division );
        update_option( $key, $cached, false );
        lgw_cache_log( 'info', "Merged result into cache: {$home} v {$away} ({$division})" );
    } else {
        lgw_cache_log( 'warn', "lgw_cache_merge_result: no matching fixture found for {$home} v {$away} in {$division} ({$season_id})" );
    }
}

/**
 * Return status info for the Settings health panel.
 *
 * @return array[]  One entry per active-season division.
 */
function lgw_cache_get_all_status() {
    if ( ! function_exists( 'lgw_get_active_season_id' ) ) return [];

    $season_id = lgw_get_active_season_id();
    if ( ! $season_id ) return [];

    $seasons = get_option( 'lgw_seasons', [] );
    $season  = null;
    foreach ( $seasons as $s ) {
        if ( ( $s['id'] ?? '' ) === $season_id ) { $season = $s; break; }
    }
    if ( ! $season ) return [];

    $divisions = $season['divisions'] ?? [];
    $result    = [];

    foreach ( $divisions as $div_entry ) {
        if ( is_array( $div_entry ) ) {
            $div_name = trim( $div_entry['division'] ?? '' );
        } else {
            $div_name = trim( (string) $div_entry );
        }
        if ( ! $div_name ) continue;
        $key    = lgw_cache_option_key( $season_id, $div_name );
        $cached = get_option( $key, null );

        if ( ! is_array( $cached ) ) {
            $result[] = [
                'division'       => $div_name,
                'synced_at'      => 0,
                'fixture_count'  => 0,
                'team_count'     => 0,
                'status'         => 'empty',
            ];
            continue;
        }

        $synced_at = intval( $cached['synced_at'] ?? 0 );
        $age       = $synced_at ? ( time() - $synced_at ) : PHP_INT_MAX;

        if ( $age > LGW_CACHE_HARD_TTL ) {
            $status = 'expired';
        } elseif ( $age > 2 * HOUR_IN_SECONDS ) {
            $status = 'stale';
        } else {
            $status = 'ok';
        }

        $result[] = [
            'division'      => $div_name,
            'synced_at'     => $synced_at,
            'fixture_count' => count( $cached['fixtures'] ?? [] ),
            'team_count'    => count( $cached['teams']    ?? [] ),
            'status'        => $status,
        ];
    }

    return $result;
}

// ─────────────────────────────────────────────────────────────────────────────
// INTERNAL HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * WP option key for a division cache entry.
 */
function lgw_cache_option_key( $season_id, $division ) {
    return 'lgw_div_cache_' . sanitize_key( $season_id ) . '_' . sanitize_key( $division );
}

/**
 * Parse the LEAGUE TABLE section of a CSV body into an array of team rows.
 * Mirrors the column detection logic in lgw-widget.js renderTable().
 *
 * @return array[]  Each row: pos, team, pl, w, d, l, f, a, pts, diff
 */
function lgw_cache_parse_teams( $csv_body ) {
    $rows = lgw_cache_parse_csv_rows( $csv_body );
    $n    = count( $rows );
    $i    = 0;

    // Find LEAGUE TABLE header
    while ( $i < $n && strpos( implode( '', $rows[$i] ), 'LEAGUE TABLE' ) === false ) $i++;
    $i++;
    while ( $i < $n && implode( '', $rows[$i] ) === '' ) $i++;

    // Find POS header row
    while ( $i < $n && ( $rows[$i][0] ?? '' ) !== 'POS' ) $i++;
    if ( $i >= $n ) return [];

    // Detect columns from header
    $hdr     = $rows[$i];
    $colPos  = -1; $colTeam = -1; $colPl = -1; $colPts = -1;
    $colDiff = -1; $colW = -1;    $colL  = -1; $colD   = -1;
    $colFor  = -1; $colAgn = -1;
    foreach ( $hdr as $c => $val ) {
        $v = strtoupper( trim( preg_replace( '/\s+/', '', $val ) ) );
        if ( $v === 'POS' )                      $colPos  = $c;
        elseif ( $v === 'TEAM' )                 $colTeam = $c;
        elseif ( $v === 'PL' || $v === 'PLAYED' ) $colPl  = $c;
        elseif ( $v === 'PTS' || $v === 'POINTS' ) $colPts = $c;
        elseif ( $v === '+/-' || $v === 'DIFF' )  $colDiff = $c;
        elseif ( $v === 'W' || $v === 'WON' )     $colW   = $c;
        elseif ( $v === 'L' || $v === 'LOST' )    $colL   = $c;
        elseif ( $v === 'D' || $v === 'DRAWN' )   $colD   = $c;
        elseif ( $v === 'FOR' )                   $colFor  = $c;
        elseif ( $v === 'AGAINST' || $v === 'AGN' ) $colAgn = $c;
    }
    // Positional fallbacks (mirror JS defaults)
    if ( $colPos  < 0 ) $colPos  = 0;
    if ( $colTeam < 0 ) $colTeam = 1;
    if ( $colPl   < 0 ) $colPl   = 5;
    if ( $colPts  < 0 ) $colPts  = 7;
    if ( $colDiff < 0 ) $colDiff = 8;
    if ( $colW    < 0 ) $colW    = 9;
    if ( $colL    < 0 ) $colL    = 10;
    if ( $colD    < 0 ) $colD    = 11;
    if ( $colFor  < 0 ) $colFor  = 12;
    if ( $colAgn  < 0 ) $colAgn  = 14;

    $i++;
    $teams = [];
    while ( $i < $n ) {
        $r   = $rows[$i];
        $pos = trim( $r[$colPos]  ?? '' );
        $team = trim( $r[$colTeam] ?? '' );
        if ( $pos === '' || $team === '' ) break;
        if ( is_numeric( $pos ) ) {
            $teams[] = [
                'pos'  => intval( $pos ),
                'team' => $team,
                'pl'   => intval( $r[$colPl]   ?? 0 ),
                'pts'  => floatval( $r[$colPts] ?? 0 ),
                'diff' => trim( $r[$colDiff]   ?? '0' ),
                'w'    => trim( $r[$colW]      ?? '0' ),
                'l'    => trim( $r[$colL]      ?? '0' ),
                'd'    => trim( $r[$colD]      ?? '0' ),
                'f'    => trim( $r[$colFor]    ?? '0' ),
                'a'    => trim( $r[$colAgn]    ?? '0' ),
            ];
        }
        $i++;
    }

    return $teams;
}

/**
 * Parse the FIXTURES section of a CSV body.
 * Mirrors the column detection in lgw-widget.js parseFixtureGroups().
 *
 * @return array[]  Each row: date, homeTeam, awayTeam, shotsHome, shotsAway,
 *                            ptsHome, ptsAway, timeNote, played, status
 */
function lgw_cache_parse_fixtures( $csv_body ) {
    $rows = lgw_cache_parse_csv_rows( $csv_body );
    $n    = count( $rows );
    $i    = 0;

    while ( $i < $n && strpos( implode( '', $rows[$i] ), 'FIXTURES' ) === false ) $i++;
    $i++;
    if ( $i >= $n ) return [];

    // Detect column positions — support both new-style (homepts/home) and legacy (HPts/HTeam)
    $colPtsH  = 0;  $colHTeam = 2; $colHScore = 7;
    $colAScore = 9; $colATeam = 10; $colPtsA  = 15; $colTime = -1;

    for ( $h = $i; $h < min( $i + 5, $n ); $h++ ) {
        $joined = strtolower( implode( '', $rows[$h] ) );
        if ( strpos( $joined, 'homepts' ) !== false ) {
            foreach ( $rows[$h] as $c => $val ) {
                $hv = strtolower( trim( $val ) );
                if ( $hv === 'homepts' )    $colPtsH  = $c;
                if ( $hv === 'home' )       $colHTeam = $c;
                if ( $hv === 'home shots' ) $colHScore = $c;
                if ( $hv === 'away shots' ) $colAScore = $c;
                if ( $hv === 'away' )       $colATeam = $c;
                if ( $hv === 'awaypts' )    $colPtsA  = $c;
                if ( $hv === 'time' )       $colTime  = $c;
            }
            $i = $h + 1;
            break;
        }
        if ( strpos( $joined, 'hpts' ) !== false ) {
            foreach ( $rows[$h] as $c => $val ) {
                $hv = trim( $val );
                if ( $hv === 'HPts' )                    $colPtsH  = $c;
                if ( $hv === 'HTeam' )                   $colHTeam = $c;
                if ( $hv === 'HScore' )                  $colHScore = $c;
                if ( $hv === 'Ascore' || $hv === 'AScore' ) $colAScore = $c;
                if ( $hv === 'ATeam' )                   $colATeam = $c;
                if ( $hv === 'APts' )                    $colPtsA  = $c;
            }
            $i = $h + 1;
            break;
        }
    }

    $dateRe   = '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+\d{1,2}-[A-Za-z]+-\d{4}$/';
    $fixtures = [];
    $curDate  = '';

    while ( $i < $n ) {
        $r     = $rows[$i];
        $first = trim( $r[0] ?? ( $r[1] ?? '' ) );

        if ( preg_match( $dateRe, $first ) ) {
            $curDate = $first;
            $i++;
            continue;
        }

        $homeTeam  = trim( $r[$colHTeam]  ?? '' );
        $awayTeam  = trim( $r[$colATeam]  ?? '' );
        $shotsHome = trim( $r[$colHScore] ?? '' );
        $shotsAway = trim( $r[$colAScore] ?? '' );
        $ptsHome   = trim( $r[$colPtsH]   ?? '' );
        $ptsAway   = trim( $r[$colPtsA]   ?? '' );

        if ( ! $homeTeam || ! $awayTeam ) { $i++; continue; }

        // Time note detection (mirrors JS logic)
        $timeNote = '';
        if ( $colTime >= 0 ) {
            $tv = trim( $r[$colTime] ?? '' );
            if ( preg_match( '/^\d{1,2}:\d{2}(:\d{2})?$/', $tv ) ) {
                $timeNote = preg_replace( '/:\d{2}$/', '', $tv ); // strip seconds
            } elseif ( is_numeric( $tv ) ) {
                $fv = floatval( $tv );
                if ( $fv > 0 && $fv < 1 ) {
                    $mins     = round( $fv * 1440 );
                    $hh       = floor( $mins / 60 );
                    $mm       = $mins % 60;
                    $timeNote = sprintf( '%02d:%02d', $hh, $mm );
                }
            }
        } else {
            for ( $x = $colATeam + 1; $x < $colPtsA; $x++ ) {
                $tv = trim( $r[$x] ?? '' );
                if ( preg_match( '/^\d{1,2}:\d{2}(:\d{2})?$/', $tv ) ) {
                    $timeNote = preg_replace( '/:\d{2}$/', '', $tv );
                    break;
                }
                if ( is_numeric( $tv ) ) {
                    $fv = floatval( $tv );
                    if ( $fv >= 0.333 && $fv <= 0.938 ) {
                        $mins     = round( $fv * 1440 );
                        $hh       = floor( $mins / 60 );
                        $mm       = $mins % 60;
                        $timeNote = sprintf( '%02d:%02d', $hh, $mm );
                        break;
                    }
                }
            }
        }

        $played = ( $shotsHome !== '0' || $shotsAway !== '0' || $ptsHome !== '0' || $ptsAway !== '0' )
                  && ( $shotsHome !== '' || $shotsAway !== '' );

        $fixtures[] = [
            'date'      => $curDate,
            'homeTeam'  => $homeTeam,
            'awayTeam'  => $awayTeam,
            'shotsHome' => $shotsHome,
            'shotsAway' => $shotsAway,
            'ptsHome'   => $ptsHome,
            'ptsAway'   => $ptsAway,
            'timeNote'  => $timeNote,
            'played'    => $played,
            'status'    => $played ? 'csv_played' : 'unplayed', // overlaid below
        ];

        $i++;
    }

    return $fixtures;
}

/**
 * Overlay confirmed/pending/disputed scorecard statuses onto parsed fixtures.
 *
 * @param  array  $fixtures  Output of lgw_cache_parse_fixtures().
 * @param  string $division
 * @param  string $season_id
 * @return array  Fixtures with status field updated.
 */
function lgw_cache_overlay_scorecard_statuses( $fixtures, $division, $season_id ) {
    if ( empty( $fixtures ) ) return $fixtures;

    // Query all scorecards for this division + season
    $args = [
        'post_type'      => 'lgw_scorecard',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'   => 'lgw_sc_season',
                'value' => $season_id,
            ],
        ],
    ];

    $posts = get_posts( $args );
    if ( empty( $posts ) ) return $fixtures;

    // Build a lookup keyed by lowercased "home||away"
    $sc_lookup = [];
    foreach ( $posts as $post ) {
        $sc = get_post_meta( $post->ID, 'lgw_scorecard_data', true );
        if ( ! is_array( $sc ) ) continue;
        $sc_div = trim( $sc['division'] ?? '' );
        if ( $sc_div && strcasecmp( $sc_div, $division ) !== 0 ) continue;

        $home   = strtolower( trim( $sc['home_team'] ?? '' ) );
        $away   = strtolower( trim( $sc['away_team'] ?? '' ) );
        $status = get_post_meta( $post->ID, 'lgw_sc_status', true );
        if ( $home && $away ) {
            $sc_lookup[ $home . '||' . $away ] = $status ?: 'pending';
        }
    }

    foreach ( $fixtures as &$fx ) {
        $key = strtolower( $fx['homeTeam'] ) . '||' . strtolower( $fx['awayTeam'] );
        if ( isset( $sc_lookup[ $key ] ) ) {
            $sc_status = $sc_lookup[ $key ];
            if ( $sc_status === 'confirmed' ) {
                $fx['status'] = 'confirmed';
                $fx['played'] = true;
            } elseif ( in_array( $sc_status, [ 'pending_second_club', 'submitted' ], true ) ) {
                $fx['status'] = 'pending';
            } elseif ( $sc_status === 'disputed' ) {
                $fx['status'] = 'disputed';
            }
        }
    }
    unset( $fx );

    return $fixtures;
}

/**
 * Parse raw CSV text into a 2D array of rows.
 *
 * @param  string $csv_body
 * @return array[]
 */
function lgw_cache_parse_csv_rows( $csv_body ) {
    $rows = [];
    foreach ( explode( "\n", $csv_body ) as $line ) {
        $line = rtrim( $line, "\r" );
        if ( $line === '' ) continue;
        $rows[] = str_getcsv( $line );
    }
    return $rows;
}

/**
 * Simple internal logger — writes to PHP error log prefixed with [LGW-Cache].
 */
function lgw_cache_log( $level, $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( "[LGW-Cache][{$level}] {$message}" );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX HANDLERS
// ─────────────────────────────────────────────────────────────────────────────

function lgw_ajax_cache_sync_now() {
    check_ajax_referer( 'lgw_cache_sync', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    lgw_cache_sync_all();

    $status = lgw_cache_get_all_status();
    wp_send_json_success( [ 'status' => $status ] );
}

function lgw_ajax_cache_sync_division() {
    check_ajax_referer( 'lgw_cache_sync', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $division  = sanitize_text_field( wp_unslash( $_POST['division']  ?? '' ) );
    $season_id = sanitize_text_field( wp_unslash( $_POST['season_id'] ?? '' ) );

    if ( ! $division || ! $season_id ) wp_send_json_error( 'Missing division or season_id' );

    // Find CSV URL — check season divisions array first (authoritative source)
    $csv_url = '';
    $seasons = get_option( 'lgw_seasons', [] );
    foreach ( $seasons as $s ) {
        if ( ( $s['id'] ?? '' ) !== $season_id ) continue;
        foreach ( $s['divisions'] ?? [] as $d ) {
            if ( is_array( $d ) && strcasecmp( trim( $d['division'] ?? '' ), $division ) === 0 ) {
                $csv_url = trim( $d['csv_url'] ?? '' );
                break 2;
            }
        }
    }
    // Fallback: sheets_tabs mapping
    if ( ! $csv_url ) {
        $opts = get_option( 'lgw_drive', [] );
        if ( function_exists( 'lgw_sheets_entry_for_division' ) ) {
            $entry   = lgw_sheets_entry_for_division( $division, $opts );
            $csv_url = trim( $entry['csv_url'] ?? '' );
        }
    }

    if ( ! $csv_url ) wp_send_json_error( 'No CSV URL found for division: ' . $division );

    $ok = lgw_cache_sync_from_csv( $csv_url, $season_id, $division );
    if ( ! $ok ) {
        wp_send_json_error( 'Sync failed — check the CSV URL is accessible' );
    }

    $all_status = lgw_cache_get_all_status();
    wp_send_json_success( [ 'status' => $all_status ] );
}

// ─────────────────────────────────────────────────────────────────────────────
// SETTINGS HTML — cache health panel
// Called from the main plugin's Settings page renderer.
// ─────────────────────────────────────────────────────────────────────────────
function lgw_cache_settings_html() {
    $nonce      = wp_create_nonce( 'lgw_cache_sync' );
    $interval   = get_option( 'lgw_cache_cron_interval', 'hourly' );
    $all_status = lgw_cache_get_all_status();

    $intervals = [
        'lgw_15min' => '15 minutes',
        'lgw_30min' => '30 minutes',
        'hourly'    => '1 hour (recommended)',
        'lgw_4hour' => '4 hours',
    ];

    $next_run = wp_next_scheduled( 'lgw_div_cache_cron' );
    $next_str = $next_run ? human_time_diff( $next_run ) . ' from now' : 'not scheduled';
    ?>
    <hr>
    <h2>🗄️ Division Cache</h2>
    <p>Pre-built standings and fixtures are stored in the WordPress database so the widget renders instantly without fetching from Google Sheets on each page load. The cache is refreshed automatically by a background task and immediately when a scorecard is confirmed.</p>

    <table class="form-table">
        <tr>
            <th scope="row"><label for="lgw_cache_cron_interval">Sync interval</label></th>
            <td>
                <select id="lgw_cache_cron_interval" name="lgw_cache_cron_interval">
                    <?php foreach ( $intervals as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $interval, $val ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">How often the background task syncs division data from Google Sheets. Next scheduled run: <strong><?php echo esc_html( $next_str ); ?></strong></p>
            </td>
        </tr>
    </table>

    <div style="margin-top:16px">
        <button type="button" class="button button-primary" id="lgw-cache-sync-all"
            data-nonce="<?php echo esc_attr( $nonce ); ?>">
            🔄 Sync all divisions now
        </button>
        <span id="lgw-cache-sync-status" style="margin-left:10px;font-size:13px"></span>
    </div>

    <div id="lgw-cache-health-wrap" style="margin-top:16px">
        <?php lgw_cache_health_table_html( $all_status, $nonce ); ?>
    </div>

    <script>
    (function(){
        var syncNonce = <?php echo json_encode( $nonce ); ?>;

        function refreshTable(status) {
            var wrap = document.getElementById('lgw-cache-health-wrap');
            if (!wrap || !status) return;
            var rows = status.map(function(s) {
                var age = s.synced_at ? Math.floor((Date.now()/1000 - s.synced_at)) : null;
                var ageStr = age === null ? '—' : (age < 60 ? age + 's ago' : age < 3600 ? Math.floor(age/60) + 'm ago' : Math.floor(age/3600) + 'h ago');
                var badge = s.status === 'ok' ? '<span style="color:#1a6e1a;font-weight:700">✅ OK</span>'
                          : s.status === 'stale' ? '<span style="color:#8a5a00;font-weight:700">⚠️ Stale</span>'
                          : s.status === 'expired' ? '<span style="color:#8a0000;font-weight:700">🔴 Expired</span>'
                          : '<span style="color:#555">— Empty</span>';
                var enc = encodeURIComponent(s.division);
                return '<tr>'
                     + '<td style="padding:6px 10px">' + escHtml(s.division) + '</td>'
                     + '<td style="padding:6px 10px">' + ageStr + '</td>'
                     + '<td style="padding:6px 10px;text-align:center">' + s.fixture_count + '</td>'
                     + '<td style="padding:6px 10px;text-align:center">' + s.team_count + '</td>'
                     + '<td style="padding:6px 10px">' + badge + '</td>'
                     + '<td style="padding:6px 10px"><button type="button" class="button button-small lgw-cache-sync-one" data-div="' + escHtml(s.division) + '" data-nonce="' + escHtml(syncNonce) + '">Sync</button></td>'
                     + '</tr>';
            }).join('');
            var tbl = wrap.querySelector('tbody');
            if (tbl) tbl.innerHTML = rows || '<tr><td colspan="6" style="padding:8px 10px;color:#888">No active season divisions found.</td></tr>';
            bindSyncButtons();
        }

        function escHtml(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function doSync(action, extra, btn, statusEl) {
            btn.disabled = true;
            statusEl.textContent = 'Syncing…';
            statusEl.style.color = '#333';
            var fd = new FormData();
            fd.append('action', action);
            fd.append('nonce',  syncNonce);
            for (var k in extra) fd.append(k, extra[k]);
            fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                .then(function(r){return r.json();})
                .then(function(r){
                    btn.disabled = false;
                    if (r.success) {
                        statusEl.textContent = '✅ Done';
                        statusEl.style.color = 'green';
                        refreshTable(r.data.status);
                    } else {
                        statusEl.textContent = '❌ ' + (r.data || 'Sync failed');
                        statusEl.style.color = 'red';
                    }
                    setTimeout(function(){ statusEl.textContent=''; }, 5000);
                })
                .catch(function(){
                    btn.disabled = false;
                    statusEl.textContent = '❌ Request failed';
                    statusEl.style.color = 'red';
                });
        }

        document.getElementById('lgw-cache-sync-all').addEventListener('click', function(){
            doSync('lgw_cache_sync_now', {}, this, document.getElementById('lgw-cache-sync-status'));
        });

        function bindSyncButtons(){
            document.querySelectorAll('.lgw-cache-sync-one').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var seasonId = <?php echo json_encode( function_exists('lgw_get_active_season_id') ? lgw_get_active_season_id() : '' ); ?>;
                    doSync('lgw_cache_sync_division',
                        {division: this.dataset.div, season_id: seasonId},
                        this,
                        document.getElementById('lgw-cache-sync-status'));
                });
            });
        }
        bindSyncButtons();
    })();
    </script>
    <?php
}

/**
 * Render the health table rows (also called via JS to refresh without page reload).
 */
function lgw_cache_health_table_html( $all_status, $nonce ) {
    ?>
    <table class="widefat" style="max-width:760px">
        <thead>
            <tr>
                <th>Division</th>
                <th>Last synced</th>
                <th style="text-align:center">Fixtures</th>
                <th style="text-align:center">Teams</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $all_status ) ) : ?>
            <tr><td colspan="6" style="padding:8px 10px;color:#888">No active season divisions found.</td></tr>
        <?php else : ?>
            <?php foreach ( $all_status as $s ) :
                $age    = $s['synced_at'] ? ( time() - $s['synced_at'] ) : null;
                $age_str = $age === null ? '—'
                         : ( $age < 60     ? $age . 's ago'
                         : ( $age < 3600   ? floor($age/60) . 'm ago'
                         :                   floor($age/3600) . 'h ago' ) );
                $badge  = $s['status'] === 'ok'      ? '<span style="color:#1a6e1a;font-weight:700">✅ OK</span>'
                        : ( $s['status'] === 'stale'   ? '<span style="color:#8a5a00;font-weight:700">⚠️ Stale</span>'
                        : ( $s['status'] === 'expired' ? '<span style="color:#8a0000;font-weight:700">🔴 Expired</span>'
                        :                                '<span style="color:#555">— Empty</span>' ) );
            ?>
            <tr>
                <td style="padding:6px 10px"><?php echo esc_html( $s['division'] ); ?></td>
                <td style="padding:6px 10px"><?php echo esc_html( $age_str ); ?></td>
                <td style="padding:6px 10px;text-align:center"><?php echo intval( $s['fixture_count'] ); ?></td>
                <td style="padding:6px 10px;text-align:center"><?php echo intval( $s['team_count'] ); ?></td>
                <td style="padding:6px 10px"><?php echo $badge; ?></td>
                <td style="padding:6px 10px">
                    <button type="button" class="button button-small lgw-cache-sync-one"
                        data-div="<?php echo esc_attr( $s['division'] ); ?>"
                        data-nonce="<?php echo esc_attr( $nonce ); ?>">Sync</button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// SERVER-SIDE RENDERING HELPERS
// Called by lgw_division_shortcode() when a warm cache entry is available.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Attempt to render the division widget panels from the DB cache.
 *
 * Returns an array with keys:
 *   'table_html'    — full HTML for the table panel (empty string = cache miss)
 *   'fixtures_html' — full HTML for the fixtures panel (with filter bar)
 *   'cached_json'   — JSON-encoded fixture groups for data-cached attr
 *   'hit'           — bool: true if cache was warm and rendering succeeded
 *
 * @param  string $csv_url     Shortcode csv= attribute (used for cache lookup + score overlays).
 * @param  string $division    Division title from shortcode.
 * @param  int    $promote     Number of promotion places.
 * @param  int    $relegate    Number of relegation places.
 * @return array
 */
function lgw_cache_render_division( $csv_url, $division, $promote = 0, $relegate = 0 ) {
    $empty = [ 'table_html' => '', 'fixtures_html' => '', 'cached_json' => '[]', 'hit' => false ];

    if ( ! function_exists( 'lgw_get_active_season_id' ) ) return $empty;
    $season_id = lgw_get_active_season_id();
    if ( ! $season_id ) return $empty;

    $cached = lgw_cache_get_division( $season_id, $division );
    if ( ! $cached || empty( $cached['teams'] ) ) return $empty;

    $teams    = $cached['teams']    ?? [];
    $fixtures = $cached['fixtures'] ?? [];

    if ( empty( $teams ) ) return $empty;

    // Apply score overrides from lgw_score_overrides (belt-and-braces)
    $overrides = get_option( 'lgw_score_overrides', [] );
    foreach ( $fixtures as &$fx ) {
        $key = $csv_url . '||' . ( $fx['date'] ?? '' ) . '||' . ( $fx['homeTeam'] ?? '' ) . '||' . ( $fx['awayTeam'] ?? '' );
        if ( isset( $overrides[ $key ] ) ) {
            $ov = $overrides[ $key ];
            $fx['shotsHome'] = $ov['sh'];
            $fx['shotsAway'] = $ov['sa'];
            $fx['ptsHome']   = $ov['ph'];
            $fx['ptsAway']   = $ov['pa'];
            $fx['played']    = true;
        }
    }
    unset( $fx );

    // Scorecard status map — for pill rendering on fixture rows
    $sc_status_map  = function_exists( 'lgw_build_scorecard_status_map' ) ? lgw_build_scorecard_status_map() : [];
    $played_dates   = function_exists( 'lgw_build_played_dates_map' )     ? lgw_build_played_dates_map()    : [];

    // Badge lookup for team cells
    $badges      = get_option( 'lgw_badges',       [] );
    $club_badges = get_option( 'lgw_club_badges',  [] );

    $table_html    = lgw_cache_render_table( $teams, $promote, $relegate, $fixtures, $badges, $club_badges );
    $postponements  = function_exists( 'lgw_get_postponements' ) ? lgw_get_postponements() : [];
    $fixtures_html = lgw_cache_render_fixtures( $fixtures, $sc_status_map, $played_dates, $badges, $club_badges, $postponements );

    // Build fixture groups array for data-cached — JS uses this for team modal + print
    $groups = [];
    $by_date = [];
    foreach ( $fixtures as $fx ) {
        $date = $fx['date'] ?? '';
        $by_date[ $date ][] = $fx;
    }
    foreach ( $by_date as $date => $matches ) {
        $groups[] = [ 'date' => $date, 'matches' => $matches ];
    }

    return [
        'table_html'    => $table_html,
        'fixtures_html' => $fixtures_html,
        'cached_json'   => wp_json_encode( $groups ),
        'hit'           => true,
    ];
}

/**
 * Render the league standings table from cached team rows.
 * Mirrors renderTable() in lgw-widget.js exactly.
 */
function lgw_cache_render_table( $teams, $promote, $relegate, $fixtures, $badges, $club_badges ) {
    if ( empty( $teams ) ) return '<div class="lgw-status">Could not find league table in data.</div>';

    // Sort: pts desc, then diff desc
    usort( $teams, function( $a, $b ) {
        $pd = floatval( $b['pts'] ) - floatval( $a['pts'] );
        if ( $pd !== 0.0 ) return $pd > 0 ? 1 : -1;
        return floatval( $b['diff'] ) - floatval( $a['diff'] ) > 0 ? 1 : -1;
    } );
    foreach ( $teams as $i => &$t ) { $t['pos'] = $i + 1; }
    unset( $t );

    // Games remaining per team (for clinched logic)
    $games_left = [];
    foreach ( $teams as $t ) {
        $games_left[ strtoupper( $t['team'] ) ] = 0;
    }
    foreach ( $fixtures as $fx ) {
        if ( empty( $fx['played'] ) ) {
            $hu = strtoupper( $fx['homeTeam'] ?? '' );
            $au = strtoupper( $fx['awayTeam'] ?? '' );
            if ( isset( $games_left[ $hu ] ) ) $games_left[ $hu ]++;
            if ( isset( $games_left[ $au ] ) ) $games_left[ $au ]++;
        }
    }

    $total    = count( $teams );
    $MAX_PTS  = 7;
    $promote  = intval( $promote );
    $relegate = intval( $relegate );

    $h  = '<div class="tbl-wrap"><table class="lg"><thead><tr>';
    $h .= '<th class="cp">Pos</th><th class="ct">Team</th>';
    $h .= '<th>Pl</th><th>Pts</th><th>+/-</th><th>W</th><th>L</th><th>D</th><th>For</th><th>Agn</th>';
    $h .= '</tr></thead><tbody>';

    foreach ( $teams as $idx => $t ) {
        // Zone
        $zone = '';
        if ( $promote > 0 && $idx < $promote )            $zone = 'promote';
        if ( $relegate > 0 && $idx >= $total - $relegate ) $zone = 'relegate';

        // Clinched
        $clinched = false;
        if ( $zone === 'promote' && $promote > 0 ) {
            $challenger = $teams[ $promote ] ?? null;
            if ( ! $challenger ) {
                $clinched = true;
            } else {
                $clinched = ( floatval( $t['pts'] ) - floatval( $challenger['pts'] ) )
                            > ( ( $games_left[ strtoupper( $challenger['team'] ) ] ?? 0 ) * $MAX_PTS );
            }
        } elseif ( $zone === 'relegate' && $relegate > 0 ) {
            $safe = $teams[ $total - $relegate - 1 ] ?? null;
            if ( ! $safe ) {
                $clinched = true;
            } else {
                $clinched = ( floatval( $safe['pts'] ) - floatval( $t['pts'] ) )
                            > ( ( $games_left[ strtoupper( $t['team'] ) ] ?? 0 ) * $MAX_PTS );
            }
        }

        $border_top = '';
        if ( $promote > 0 && $idx === $promote )            $border_top = ' zone-border-top';
        if ( $relegate > 0 && $idx === $total - $relegate ) $border_top = ' zone-border-top';

        $row_class = '';
        if ( $zone === 'promote' )  $row_class = $clinched ? ' row-promoted'  : ' row-promote-zone';
        if ( $zone === 'relegate' ) $row_class = $clinched ? ' row-relegated' : ' row-relegate-zone';
        $row_class .= $border_top;

        $badge_html = lgw_cache_badge_img( $t['team'], $badges, $club_badges );

        $h .= '<tr class="' . trim( $row_class ) . ' lgw-team-row" data-team="' . esc_attr( $t['team'] ) . '">';
        $h .= '<td class="cp">' . intval( $t['pos'] ) . '</td>';
        $h .= '<td class="ct"><span class="lgw-team-link">' . $badge_html . esc_html( $t['team'] ) . '</span></td>';
        $h .= '<td>' . esc_html( $t['pl'] )   . '</td>';
        $h .= '<td class="ck">' . esc_html( $t['pts'] )  . '</td>';
        $h .= '<td>' . esc_html( $t['diff'] ) . '</td>';
        $h .= '<td>' . esc_html( $t['w'] )    . '</td>';
        $h .= '<td>' . esc_html( $t['l'] )    . '</td>';
        $h .= '<td>' . esc_html( $t['d'] )    . '</td>';
        $h .= '<td>' . esc_html( $t['f'] )    . '</td>';
        $h .= '<td>' . esc_html( $t['a'] )    . '</td>';
        $h .= '</tr>';
    }

    $h .= '</tbody></table></div>';

    if ( $promote > 0 || $relegate > 0 ) {
        $h .= '<div class="lg-legend">';
        if ( $promote > 0 )  $h .= '<span class="lg-key lg-key-promote"></span>▲ Promotion&nbsp;&nbsp;';
        if ( $relegate > 0 ) $h .= '<span class="lg-key lg-key-relegate"></span>▼ Relegation';
        $h .= '</div>';
    }

    return $h;
}

/**
 * Render the fixtures list from cached fixture rows.
 * Mirrors renderFixtures() in lgw-widget.js.
 * Always renders the full "All" filter — JS re-filters client-side via the filter bar.
 */
function lgw_cache_render_fixtures( $fixtures, $sc_status_map, $played_dates, $badges, $club_badges, $postponements = [] ) {
    if ( empty( $fixtures ) ) return '<div class="lgw-status">No fixtures to display.</div>';

    // Group by date
    $by_date = [];
    foreach ( $fixtures as $fx ) {
        $date = $fx['date'] ?? '';
        $by_date[ $date ][] = $fx;
    }

    if ( empty( $by_date ) ) return '<div class="lgw-status">No fixtures to display.</div>';

    // Filter bar (mirrors filterBar() in JS) — JS attaches click handlers
    $h  = '<div class="fix-filter">';
    $h .= '<button data-f="all" class="active">All</button>';
    $h .= '<button data-f="results">Results</button>';
    $h .= '<button data-f="upcoming">Upcoming</button>';
    $h .= '</div>';

    foreach ( $by_date as $date => $matches ) {
        if ( ! $date ) continue;
        $h .= '<div class="date-group">';
        $h .= '<div class="date-hdr"><span>' . esc_html( $date ) . '</span><span class="date-hdr-notes">Notes</span></div>';

        foreach ( $matches as $fx ) {
            $home = $fx['homeTeam'] ?? '';
            $away = $fx['awayTeam'] ?? '';
            if ( ! $home || ! $away ) continue;

            $played     = ! empty( $fx['played'] );
            $shots_home = $fx['shotsHome'] ?? '';
            $shots_away = $fx['shotsAway'] ?? '';
            $pts_home   = $fx['ptsHome']   ?? '';
            $pts_away   = $fx['ptsAway']   ?? '';
            $time_note  = $fx['timeNote']  ?? '';

            $pc        = $played ? ' played' : '';
            $fx_attrs  = ' data-home="' . esc_attr( $home ) . '"'
                       . ' data-away="' . esc_attr( $away ) . '"'
                       . ' data-date="' . esc_attr( $date ) . '"';
            if ( $played ) $fx_attrs .= ' title="Click to view full scorecard"';

            // Annotation lookup key (matches JS pdKey format)
            $pd_key = strtolower( $home . '||' . $away . '||' . $date );

            // Played-on-different-date pill
            $played_on   = $played_dates[ $pd_key ] ?? '';
            $played_pill = $played_on
                ? '<span class="fx-played-pill">📅 Played ' . esc_html( $played_on ) . '</span>'
                : '';

            // Scorecard status pill
            $sc_raw    = $sc_status_map[ $pd_key ] ?? '';
            $sc_status = strpos( $sc_raw, ':' ) !== false ? explode( ':', $sc_raw )[0] : $sc_raw;
            $sc_side   = strpos( $sc_raw, ':' ) !== false ? explode( ':', $sc_raw )[1] : '';
            $sc_pending_label = 'Pending';
            if ( $sc_status === 'pending' && $sc_side && $sc_side !== 'both' ) {
                $awaiting_team    = $sc_side === 'home' ? $away : $home;
                $sc_pending_label = 'Pending (' . esc_html( $awaiting_team ) . ')';
            }
            $sc_icon  = $sc_status === 'confirmed' ? '✅'
                      : ( $sc_status === 'disputed' ? '⚠️' : '📋' );
            $sc_label = $sc_status === 'pending'
                ? $sc_pending_label
                : ( $sc_status ? ucfirst( $sc_status ) : '' );
            $sc_pill  = $sc_status
                ? '<span class="fx-sc-status fx-sc-' . esc_attr( $sc_status ) . '">' . $sc_icon . ' ' . $sc_label . '</span>'
                : '';

            // Postponed pill (mirrors JS postEntry/postponedPill logic)
            $post_entry        = $postponements[ $pd_key ] ?? null;
            $reschedule_for    = $post_entry ? ( $post_entry['rescheduled_for'] ?? '' ) : '';
            $postponed_pill    = $post_entry
                ? '<span class="fx-postponed-pill">🚫 Postponed'
                  . ( $reschedule_for ? ' &bull; 📅 Rescheduled ' . esc_html( $reschedule_for ) : '' )
                  . '</span>'
                : '';
            // Notes column: postponed splits into two stacked pills (mirrors JS notesInner)
            $postponed_note_pill = $post_entry
                ? '<span class="fx-postponed-pill">🚫 Postponed</span>'
                  . ( $reschedule_for ? '<span class="fx-reschedule-pill">📅 Rescheduled ' . esc_html( $reschedule_for ) . '</span>' : '' )
                : '';

            $notes_inner = $postponed_note_pill . $played_pill . $sc_pill;
            $fx_notes    = '<div class="fx-notes">' . $notes_inner . '</div>';
            $fx_pills    = ( $postponed_pill || $played_pill || $sc_pill )
                ? '<div class="fx-pills">' . $postponed_pill . $played_pill . $sc_pill . '</div>'
                : '';

            $home_badge = lgw_cache_badge_img( $home, $badges, $club_badges );
            $away_badge = lgw_cache_badge_img( $away, $badges, $club_badges );

            $h .= '<div class="fx-row' . $pc . '"' . $fx_attrs . '>';
            $h .= '<div class="fx-ph">' . ( $played ? esc_html( $pts_home ) : '' ) . '</div>';
            $h .= '<div class="fx-h"><span class="lgw-team-link" data-team="' . esc_attr( $home ) . '">' . $home_badge . esc_html( $home ) . '</span></div>';
            $h .= '<div class="fx-sc"><span class="fx-sb">' . esc_html( $shots_home ) . '</span><span class="fx-sep">v</span><span class="fx-sb">' . esc_html( $shots_away ) . '</span></div>';
            $h .= '<div class="fx-a"><span class="lgw-team-link" data-team="' . esc_attr( $away ) . '">' . $away_badge . esc_html( $away ) . '</span></div>';
            $h .= '<div class="fx-pa">' . ( $played ? esc_html( $pts_away ) : '' ) . '</div>';
            $h .= $fx_notes;
            $h .= $time_note ? '<div class="fx-time"><span>⏰ ' . esc_html( $time_note ) . '</span></div>' : '';
            $h .= $fx_pills;
            $h .= '</div>';
        }

        $h .= '</div>'; // .date-group
    }

    return $h;
}

/**
 * Build a badge <img> tag for a team name, matching badgeImg() logic in lgw-widget.js.
 */
function lgw_cache_badge_img( $team, $badges, $club_badges ) {
    $team_lc = strtolower( trim( $team ) );
    $url     = '';

    // Club badge lookup (keyed by lowercase club name fragment)
    foreach ( $club_badges as $key => $badge_url ) {
        if ( strpos( $team_lc, strtolower( trim( $key ) ) ) !== false ) {
            $url = $badge_url;
            break;
        }
    }

    // Exact badge lookup
    if ( ! $url && isset( $badges[ $team ] ) ) {
        $url = $badges[ $team ];
    }

    if ( ! $url ) return '';
    return '<img src="' . esc_url( $url ) . '" alt="" class="lgw-badge" aria-hidden="true">';
}
