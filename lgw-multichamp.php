<?php
/**
 * LGW Multi-Discipline Championship
 *
 * Provides the [lgw_multichamp] shortcode for team-vs-team multi-discipline
 * championship events (singles, pairs, triples, fours on a single day).
 *
 * Data stored in WP options:
 *   lgw_multichamp_{id}         — championship config
 *   lgw_multichamp_{id}_scores  — game results keyed by fixture+discipline
 *
 * Scorecards use the existing lgw_scorecard CPT with context='multichamp'
 * and lgw_multichamp_game_id meta = "{champ_id}:{fix_id}:{disc_id}:{index}"
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin menu registration ───────────────────────────────────────────────────

function lgw_multichamp_register_submenu() {
    add_submenu_page(
        'lgw-scorecards',
        'Multi-Discipline Championships',
        '🏆 Multi-Champ',
        'manage_options',
        'lgw-multichamp',
        'lgw_multichamp_admin_router'
    );
}

// ── Admin: handle saves / deletes (admin_init — before headers sent) ─────────

add_action( 'admin_init', 'lgw_multichamp_handle_admin_actions' );
function lgw_multichamp_handle_admin_actions() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // ── Save championship ──
    if ( isset( $_POST['lgw_mc_save_nonce'] )
        && wp_verify_nonce( $_POST['lgw_mc_save_nonce'], 'lgw_mc_save' ) ) {

        $champ_id = sanitize_key( $_POST['mc_id'] ?? '' );
        if ( ! $champ_id ) {
            $champ_id = 'mc_' . time();
        }

        // Disciplines: build from parallel posted arrays
        $disc_ids     = array_map( 'sanitize_key',  (array)( $_POST['disc_id']       ?? [] ) );
        $disc_labels  = array_map( 'sanitize_text_field', (array)( $_POST['disc_label']    ?? [] ) );
        $disc_counts  = array_map( 'intval',               (array)( $_POST['disc_count']    ?? [] ) );
        $disc_ends         = array_map( 'intval',               (array)( $_POST['disc_ends']          ?? [] ) );
        $disc_scoring_mode = array_map( 'sanitize_key',         (array)( $_POST['disc_scoring_mode']  ?? [] ) );
        $disc_time_limit   = array_map( 'sanitize_text_field',  (array)( $_POST['disc_time_limit']    ?? [] ) );
        $disc_pts_win = array_map( 'intval',               (array)( $_POST['disc_pts_win']  ?? [] ) );
        $disc_pts_drw = array_map( 'intval',               (array)( $_POST['disc_pts_draw'] ?? [] ) );
        $disc_pts_los = array_map( 'intval',               (array)( $_POST['disc_pts_loss'] ?? [] ) );

        $disciplines = [];
        foreach ( $disc_ids as $i => $did ) {
            if ( ! $did ) continue;
            $disciplines[] = [
                'id'         => $did,
                'label'      => $disc_labels[$i]  ?? $did,
                'count'      => max( 1, $disc_counts[$i]  ?? 1 ),
                'ends'         => max( 1, $disc_ends[$i]    ?? 21 ),
                'scoring_mode' => in_array( $disc_scoring_mode[$i] ?? '', [ 'ends', 'target' ] ) ? $disc_scoring_mode[$i] : 'ends',
                'time_limit'   => $disc_time_limit[$i] ?? '',
                'pts_win'    => max( 0, $disc_pts_win[$i] ?? 2 ),
                'pts_draw'   => max( 0, $disc_pts_drw[$i] ?? 1 ),
                'pts_loss'   => max( 0, $disc_pts_los[$i] ?? 0 ),
            ];
        }

        // Entries come from club_name[] array in the club table
        $raw_entries = (array)( $_POST['club_name'] ?? [] );
        $entries = array_values( array_filter(
            array_map( 'sanitize_text_field', $raw_entries )
        ) );

        $existing = get_option( 'lgw_multichamp_' . $champ_id, [] );

        // Club colours and badges (parallel arrays indexed by entry position)
        $club_colours = array_map( 'sanitize_hex_color', (array)( $_POST['club_colour'] ?? [] ) );
        $club_badges  = array_map( 'esc_url_raw',        (array)( $_POST['club_badge']  ?? [] ) );

        $champ = array_merge( $existing, [
            'id'            => $champ_id,
            'title'         => sanitize_text_field( $_POST['mc_title']  ?? '' ),
            'date'          => sanitize_text_field( $_POST['mc_date']   ?? '' ),
            'venue'         => sanitize_text_field( $_POST['mc_venue']  ?? '' ),
            'status'        => sanitize_key( $_POST['mc_status'] ?? ( $existing['status'] ?? 'pending' ) ),
            'entries'       => $entries,
            'disciplines'   => $disciplines,
            'bonus_win'     => max( 0, (int)( $_POST['mc_bonus_win']  ?? 0 ) ),
            'bonus_draw'    => max( 0, (int)( $_POST['mc_bonus_draw'] ?? 0 ) ),
            'bonus_loss'    => max( 0, (int)( $_POST['mc_bonus_loss'] ?? 0 ) ),
            'colour_primary'=> sanitize_hex_color( $_POST['mc_colour_primary'] ?? '' ) ?: '',
            'score_entry'   => isset( $_POST['mc_score_entry'] ) ? 1 : 0,
            'passphrase'    => ! empty( $_POST['mc_passphrase'] )
                ? hash( 'sha256', trim( $_POST['mc_passphrase'] ) )
                : ( $existing['passphrase'] ?? '' ),
            'club_colours'  => $club_colours,
            'club_badges'   => $club_badges,
            // preserve fixtures if already drawn
            'fixtures'      => $existing['fixtures']    ?? [],
            'draw_version'  => $existing['draw_version'] ?? 0,
        ] );

        update_option( 'lgw_multichamp_' . $champ_id, $champ );
        wp_redirect( admin_url( 'admin.php?page=lgw-multichamp&action=edit&id=' . $champ_id . '&saved=1' ) );
        exit;
    }

    // ── Save fixtures (manual or auto-draw result) ──
    if ( isset( $_POST['lgw_mc_fixtures_nonce'] )
        && wp_verify_nonce( $_POST['lgw_mc_fixtures_nonce'], 'lgw_mc_fixtures' ) ) {

        $champ_id = sanitize_key( $_POST['mc_id'] ?? '' );
        if ( ! $champ_id ) return;
        $champ = get_option( 'lgw_multichamp_' . $champ_id, [] );
        if ( ! $champ ) return;

        $homes = array_map( 'sanitize_text_field', (array)( $_POST['fix_home'] ?? [] ) );
        $aways = array_map( 'sanitize_text_field', (array)( $_POST['fix_away'] ?? [] ) );
        $fixtures = [];
        foreach ( $homes as $i => $home ) {
            $away = $aways[$i] ?? '';
            if ( ! $home || ! $away || $home === $away ) continue;
            $fixtures[] = [
                'id'   => 'fix_' . ( $i + 1 ),
                'home' => $home,
                'away' => $away,
            ];
        }
        $champ['fixtures']     = $fixtures;
        $champ['draw_version'] = ( (int)( $champ['draw_version'] ?? 0 ) ) + 1;
        update_option( 'lgw_multichamp_' . $champ_id, $champ );
        wp_redirect( admin_url( 'admin.php?page=lgw-multichamp&action=edit&id=' . $champ_id . '&tab=fixtures&saved=1' ) );
        exit;
    }

    // ── Delete championship ──
    if ( isset( $_POST['lgw_mc_delete_nonce'] )
        && wp_verify_nonce( $_POST['lgw_mc_delete_nonce'], 'lgw_mc_delete' ) ) {

        $champ_id = sanitize_key( $_POST['mc_id'] ?? '' );
        if ( $champ_id ) {
            delete_option( 'lgw_multichamp_' . $champ_id );
            delete_option( 'lgw_multichamp_' . $champ_id . '_scores' );
        }
        wp_redirect( admin_url( 'admin.php?page=lgw-multichamp&deleted=1' ) );
        exit;
    }
}

// ── Admin: AJAX — auto-draw (round-robin) ────────────────────────────────────

add_action( 'wp_ajax_lgw_mc_auto_draw', 'lgw_mc_ajax_auto_draw' );
function lgw_mc_ajax_auto_draw() {
    check_ajax_referer( 'lgw_mc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $champ    = get_option( 'lgw_multichamp_' . $champ_id, [] );
    if ( ! $champ ) wp_send_json_error( 'Championship not found' );

    $entries = $champ['entries'] ?? [];
    if ( count( $entries ) < 2 ) wp_send_json_error( 'At least 2 clubs required' );

    // Round-robin: each club plays each other club once
    $fixtures = [];
    $idx = 1;
    for ( $i = 0; $i < count( $entries ); $i++ ) {
        for ( $j = $i + 1; $j < count( $entries ); $j++ ) {
            $fixtures[] = [
                'id'   => 'fix_' . $idx,
                'home' => $entries[$i],
                'away' => $entries[$j],
            ];
            $idx++;
        }
    }

    wp_send_json_success( [ 'fixtures' => $fixtures ] );
}

// ── Admin: AJAX — save game scores ───────────────────────────────────────────

add_action( 'wp_ajax_lgw_mc_save_scores', 'lgw_mc_ajax_save_scores' );
function lgw_mc_ajax_save_scores() {
    check_ajax_referer( 'lgw_mc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $champ_id  = sanitize_key( $_POST['champ_id']  ?? '' );
    $fix_id    = sanitize_key( $_POST['fix_id']     ?? '' );
    $disc_id   = sanitize_key( $_POST['disc_id']    ?? '' );
    $game_idx  = (int)( $_POST['game_idx'] ?? 0 );

    $champ = get_option( 'lgw_multichamp_' . $champ_id, [] );
    if ( ! $champ ) wp_send_json_error( 'Championship not found' );

    // Find discipline config for pts calculation
    $disc_cfg = null;
    foreach ( $champ['disciplines'] as $d ) {
        if ( $d['id'] === $disc_id ) { $disc_cfg = $d; break; }
    }
    if ( ! $disc_cfg ) wp_send_json_error( 'Discipline not found' );

    $shots_home = (int)( $_POST['shots_home'] ?? 0 );
    $shots_away = (int)( $_POST['shots_away'] ?? 0 );

    if ( $shots_home > $shots_away ) {
        $pts_home = $disc_cfg['pts_win'];
        $pts_away = $disc_cfg['pts_loss'];
    } elseif ( $shots_home < $shots_away ) {
        $pts_home = $disc_cfg['pts_loss'];
        $pts_away = $disc_cfg['pts_win'];
    } else {
        $pts_home = $disc_cfg['pts_draw'];
        $pts_away = $disc_cfg['pts_draw'];
    }

    $players_home = array_filter( array_map( 'sanitize_text_field',
        explode( ',', $_POST['players_home'] ?? '' ) ) );
    $players_away = array_filter( array_map( 'sanitize_text_field',
        explode( ',', $_POST['players_away'] ?? '' ) ) );

    $game_key   = $disc_id . '_' . $game_idx;
    $game_status= sanitize_key( $_POST['game_status'] ?? 'not_started' );
    if ( ! in_array( $game_status, [ 'not_started', 'in_progress', 'complete' ] ) ) $game_status = 'not_started';
    $scores   = get_option( 'lgw_multichamp_' . $champ_id . '_scores', [] );

    $scores[ $fix_id ][ $game_key ] = [
        'shots_home'   => $shots_home,
        'shots_away'   => $shots_away,
        'pts_home'     => $pts_home,
        'pts_away'     => $pts_away,
        'players_home' => array_values( $players_home ),
        'players_away' => array_values( $players_away ),
        'status'       => $game_status,
        'scorecard_id' => (int)( $scores[ $fix_id ][ $game_key ]['scorecard_id'] ?? 0 ),
    ];

    update_option( 'lgw_multichamp_' . $champ_id . '_scores', $scores );

    // Log appearances if players provided
    if ( function_exists( 'lgw_log_appearances' ) ) {
        $fix = null;
        foreach ( $champ['fixtures'] as $f ) {
            if ( $f['id'] === $fix_id ) { $fix = $f; break; }
        }
        if ( $fix ) {
            $context = 'multichamp:' . $champ_id . ':' . $disc_id;
            lgw_log_appearances( $players_home, $fix['home'], $context );
            lgw_log_appearances( $players_away, $fix['away'], $context );
        }
    }

    wp_send_json_success( [
        'pts_home'   => $pts_home,
        'pts_away'   => $pts_away,
        'game_key'   => $game_key,
        'game_status'=> $game_status,
    ] );
}

// ── Admin: AJAX — clear a single game's scores ────────────────────────────────

add_action( 'wp_ajax_lgw_mc_clear_game', 'lgw_mc_ajax_clear_game' );
function lgw_mc_ajax_clear_game() {
    check_ajax_referer( 'lgw_mc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $fix_id   = sanitize_key( $_POST['fix_id']   ?? '' );
    $disc_id  = sanitize_key( $_POST['disc_id']  ?? '' );
    $game_idx = (int)( $_POST['game_idx'] ?? 0 );

    $scores   = get_option( 'lgw_multichamp_' . $champ_id . '_scores', [] );
    $game_key = $disc_id . '_' . $game_idx;

    // Preserve scorecard_id link even after clearing scores
    $sc_id = $scores[ $fix_id ][ $game_key ]['scorecard_id'] ?? 0;
    unset( $scores[ $fix_id ][ $game_key ] );
    if ( $sc_id ) {
        $scores[ $fix_id ][ $game_key ] = [ 'scorecard_id' => (int)$sc_id ];
    }

    update_option( 'lgw_multichamp_' . $champ_id . '_scores', $scores );
    wp_send_json_success();
}

// ── Admin: AJAX — clear all games for a fixture ───────────────────────────────

add_action( 'wp_ajax_lgw_mc_clear_fixture', 'lgw_mc_ajax_clear_fixture' );
function lgw_mc_ajax_clear_fixture() {
    check_ajax_referer( 'lgw_mc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $fix_id   = sanitize_key( $_POST['fix_id']   ?? '' );

    $scores = get_option( 'lgw_multichamp_' . $champ_id . '_scores', [] );

    // Preserve any scorecard_id links
    $preserved = [];
    foreach ( $scores[ $fix_id ] ?? [] as $gk => $g ) {
        if ( ! empty( $g['scorecard_id'] ) ) {
            $preserved[ $gk ] = [ 'scorecard_id' => (int)$g['scorecard_id'] ];
        }
    }
    $scores[ $fix_id ] = $preserved;

    update_option( 'lgw_multichamp_' . $champ_id . '_scores', $scores );
    wp_send_json_success();
}


// ── Admin+Frontend: AJAX — reorder fixtures ──────────────────────────────────

add_action( 'wp_ajax_lgw_mc_reorder_fixtures', 'lgw_mc_ajax_reorder_fixtures' );
function lgw_mc_ajax_reorder_fixtures() {
    check_ajax_referer( 'lgw_mc_frontend_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $order    = (array)( $_POST['order'] ?? [] ); // array of fix IDs in new order

    $champ = get_option( 'lgw_multichamp_' . $champ_id, [] );
    if ( ! $champ ) wp_send_json_error( 'Championship not found' );

    $fixtures     = $champ['fixtures'] ?? [];
    $fix_by_id    = [];
    foreach ( $fixtures as $fix ) { $fix_by_id[ $fix['id'] ] = $fix; }

    $reordered = [];
    foreach ( $order as $fix_id ) {
        $fix_id = sanitize_key( $fix_id );
        if ( isset( $fix_by_id[ $fix_id ] ) ) {
            $reordered[] = $fix_by_id[ $fix_id ];
        }
    }
    // Append any fixtures not in the order array (safety net)
    foreach ( $fixtures as $fix ) {
        if ( ! in_array( $fix['id'], $order ) ) $reordered[] = $fix;
    }

    $champ['fixtures'] = $reordered;
    update_option( 'lgw_multichamp_' . $champ_id, $champ );
    wp_send_json_success();
}

// ── Frontend: AJAX — verify passphrase ───────────────────────────────────────

add_action( 'wp_ajax_lgw_mc_verify_passphrase',        'lgw_mc_ajax_verify_passphrase' );
add_action( 'wp_ajax_nopriv_lgw_mc_verify_passphrase', 'lgw_mc_ajax_verify_passphrase' );
function lgw_mc_ajax_verify_passphrase() {
    check_ajax_referer( 'lgw_mc_frontend_nonce', 'nonce' );
    $champ_id   = sanitize_key( $_POST['champ_id'] ?? '' );
    $passphrase = sanitize_text_field( $_POST['passphrase'] ?? '' );
    $champ      = get_option( 'lgw_multichamp_' . $champ_id, [] );
    if ( ! $champ || empty( $champ['score_entry'] ) ) wp_send_json_error( 'Not enabled' );
    $stored = $champ['passphrase'] ?? '';
    if ( $stored && hash( 'sha256', trim( $passphrase ) ) === $stored ) {
        wp_send_json_success();
    }
    wp_send_json_error( 'Incorrect passphrase' );
}

// ── Frontend: AJAX — submit score from public widget ─────────────────────────

add_action( 'wp_ajax_lgw_mc_frontend_score',        'lgw_mc_ajax_frontend_score' );
add_action( 'wp_ajax_nopriv_lgw_mc_frontend_score', 'lgw_mc_ajax_frontend_score' );
function lgw_mc_ajax_frontend_score() {
    check_ajax_referer( 'lgw_mc_frontend_nonce', 'nonce' );

    $champ_id  = sanitize_key( $_POST['champ_id']  ?? '' );
    $fix_id    = sanitize_key( $_POST['fix_id']    ?? '' );
    $disc_id   = sanitize_key( $_POST['disc_id']   ?? '' );
    $game_idx  = (int)( $_POST['game_idx'] ?? 0 );
    $passphrase= sanitize_text_field( $_POST['passphrase'] ?? '' );

    $champ = get_option( 'lgw_multichamp_' . $champ_id, [] );
    if ( ! $champ ) wp_send_json_error( 'Championship not found' );
    if ( empty( $champ['score_entry'] ) ) wp_send_json_error( 'Score entry not enabled' );

    $stored_hash = $champ['passphrase'] ?? '';
    if ( ! $stored_hash || hash( 'sha256', trim( $passphrase ) ) !== $stored_hash ) {
        wp_send_json_error( 'Incorrect passphrase' );
    }

    $disc_cfg = null;
    foreach ( $champ['disciplines'] as $d ) {
        if ( $d['id'] === $disc_id ) { $disc_cfg = $d; break; }
    }
    if ( ! $disc_cfg ) wp_send_json_error( 'Discipline not found' );

    $shots_home = (int)( $_POST['shots_home'] ?? 0 );
    $shots_away = (int)( $_POST['shots_away'] ?? 0 );
    $status     = sanitize_key( $_POST['game_status'] ?? 'not_started' );
    if ( ! in_array( $status, [ 'not_started', 'in_progress', 'complete' ] ) ) $status = 'not_started';

    if ( $shots_home > $shots_away ) {
        $pts_home = $disc_cfg['pts_win'];  $pts_away = $disc_cfg['pts_loss'];
    } elseif ( $shots_home < $shots_away ) {
        $pts_home = $disc_cfg['pts_loss']; $pts_away = $disc_cfg['pts_win'];
    } else {
        $pts_home = $disc_cfg['pts_draw']; $pts_away = $disc_cfg['pts_draw'];
    }

    $game_key = $disc_id . '_' . $game_idx;
    $scores   = get_option( 'lgw_multichamp_' . $champ_id . '_scores', [] );
    $fe_players_home = array_filter( array_map( 'sanitize_text_field',
        explode( ',', $_POST['players_home'] ?? '' ) ) );
    $fe_players_away = array_filter( array_map( 'sanitize_text_field',
        explode( ',', $_POST['players_away'] ?? '' ) ) );

    $existing_game = $scores[ $fix_id ][ $game_key ] ?? [];
    $scores[ $fix_id ][ $game_key ] = array_merge( $existing_game, [
        'shots_home'   => $shots_home,
        'shots_away'   => $shots_away,
        'pts_home'     => $pts_home,
        'pts_away'     => $pts_away,
        'status'       => $status,
        'players_home' => $fe_players_home ? array_values( $fe_players_home ) : ( $existing_game['players_home'] ?? [] ),
        'players_away' => $fe_players_away ? array_values( $fe_players_away ) : ( $existing_game['players_away'] ?? [] ),
    ] );
    update_option( 'lgw_multichamp_' . $champ_id . '_scores', $scores );

    // Log appearances
    if ( ! empty( $fe_players_home ) || ! empty( $fe_players_away ) ) {
        $fix_obj = null;
        foreach ( $champ['fixtures'] as $f ) { if ( $f['id'] === $fix_id ) { $fix_obj = $f; break; } }
        if ( $fix_obj && function_exists( 'lgw_log_appearances' ) ) {
            $ctx = 'multichamp:' . $champ_id . ':' . $disc_id;
            if ( $fe_players_home ) lgw_log_appearances( $fe_players_home, $fix_obj['home'], $ctx );
            if ( $fe_players_away ) lgw_log_appearances( $fe_players_away, $fix_obj['away'], $ctx );
        }
    }

    $standings = lgw_mc_compute_standings( $champ, $scores );
    wp_send_json_success( [ 'pts_home' => $pts_home, 'pts_away' => $pts_away,
        'game_key' => $game_key, 'standings' => $standings ] );
}




add_action( 'wp_ajax_lgw_mc_create_scorecard', 'lgw_mc_ajax_create_scorecard' );
function lgw_mc_ajax_create_scorecard() {
    check_ajax_referer( 'lgw_mc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $fix_id   = sanitize_key( $_POST['fix_id']   ?? '' );
    $disc_id  = sanitize_key( $_POST['disc_id']  ?? '' );
    $game_idx = (int)( $_POST['game_idx'] ?? 0 );

    $champ = get_option( 'lgw_multichamp_' . $champ_id, [] );
    if ( ! $champ ) wp_send_json_error( 'Championship not found' );

    // Locate fixture
    $fix = null;
    foreach ( $champ['fixtures'] as $f ) {
        if ( $f['id'] === $fix_id ) { $fix = $f; break; }
    }
    if ( ! $fix ) wp_send_json_error( 'Fixture not found' );

    // Locate discipline label
    $disc_label = $disc_id;
    foreach ( $champ['disciplines'] as $d ) {
        if ( $d['id'] === $disc_id ) { $disc_label = $d['label']; break; }
    }

    $game_key   = $disc_id . '_' . $game_idx;
    $game_ref   = $champ_id . ':' . $fix_id . ':' . $disc_id . ':' . $game_idx;
    $scores     = get_option( 'lgw_multichamp_' . $champ_id . '_scores', [] );

    // If scorecard already exists, return it
    $existing_id = (int)( $scores[ $fix_id ][ $game_key ]['scorecard_id'] ?? 0 );
    if ( $existing_id && get_post( $existing_id ) ) {
        wp_send_json_success( [
            'post_id'  => $existing_id,
            'edit_url' => admin_url( 'post.php?post=' . $existing_id . '&action=edit' ),
            'created'  => false,
        ] );
    }

    // Build a minimal scorecard data array so the edit screen has something to work with
    $game_label = $disc_label . ( ( $champ['disciplines'][ array_search( $disc_id, array_column( $champ['disciplines'], 'id' ) ) ]['count'] ?? 1 ) > 1
        ? ' (Game ' . ( $game_idx + 1 ) . ')' : '' );

    $sc_data = [
        'home_team'    => $fix['home'],
        'away_team'    => $fix['away'],
        'date'         => $champ['date'] ?? '',
        'fixture_date' => $champ['date'] ?? '',
        'venue'        => $champ['venue'] ?? '',
        'division'     => $champ['title'] . ' — ' . $game_label,
        'competition'  => $champ['title'],
        'rinks'        => [],
        'home_total'   => $scores[ $fix_id ][ $game_key ]['shots_home'] ?? '',
        'away_total'   => $scores[ $fix_id ][ $game_key ]['shots_away'] ?? '',
        'home_points'  => $scores[ $fix_id ][ $game_key ]['pts_home']   ?? '',
        'away_points'  => $scores[ $fix_id ][ $game_key ]['pts_away']   ?? '',
    ];

    $title   = $fix['home'] . ' v ' . $fix['away'] . ' — ' . $game_label . ' (' . ( $champ['date'] ?? '' ) . ')';
    $post_id = wp_insert_post( [
        'post_type'   => 'lgw_scorecard',
        'post_title'  => $title,
        'post_status' => 'publish',
    ] );

    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( 'Could not create scorecard: ' . $post_id->get_error_message() );
    }

    update_post_meta( $post_id, 'lgw_scorecard_data',       $sc_data );
    update_post_meta( $post_id, 'lgw_match_key',            sanitize_title( $fix['home'] . '-' . $fix['away'] . '-' . $disc_id . '-' . $game_idx ) );
    update_post_meta( $post_id, 'lgw_sc_context',           'multichamp' );
    update_post_meta( $post_id, 'lgw_sc_status',            'pending' );
    update_post_meta( $post_id, 'lgw_submitted_by',         'admin' );
    update_post_meta( $post_id, 'lgw_multichamp_game_id',   $game_ref );

    // Tag with active season
    if ( function_exists( 'lgw_get_active_season_id' ) ) {
        $season_id = lgw_get_active_season_id();
        if ( $season_id ) update_post_meta( $post_id, 'lgw_sc_season', $season_id );
    }

    // Store the scorecard ID back in the scores option
    $scores[ $fix_id ][ $game_key ]['scorecard_id'] = $post_id;
    update_option( 'lgw_multichamp_' . $champ_id . '_scores', $scores );

    if ( function_exists( 'lgw_audit_log' ) ) {
        lgw_audit_log( $post_id, 'submitted', 'Created by admin for multi-discipline championship: ' . $game_ref );
    }

    wp_send_json_success( [
        'post_id'  => $post_id,
        'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
        'created'  => true,
    ] );
}

// ── Admin: AJAX — get all championships (for list page) ──────────────────────

add_action( 'wp_ajax_lgw_mc_list', 'lgw_mc_ajax_list' );
function lgw_mc_ajax_list() {
    check_ajax_referer( 'lgw_mc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );
    wp_send_json_success( lgw_mc_get_all() );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function lgw_mc_get_all() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'lgw_multichamp_%'
           AND option_name NOT LIKE '%_scores'
         ORDER BY option_name ASC"
    );
    $out = [];
    foreach ( $rows as $row ) {
        $data = maybe_unserialize( $row->option_value );
        if ( is_array( $data ) && isset( $data['id'] ) ) {
            $out[] = $data;
        }
    }
    return $out;
}

function lgw_mc_compute_standings( array $champ, array $scores ) {
    $entries     = $champ['entries']     ?? [];
    $disciplines = $champ['disciplines'] ?? [];
    $fixtures    = $champ['fixtures']    ?? [];

    // Initialise standings per club (overall + per discipline)
    $overall = [];
    $by_disc = [];
    foreach ( $entries as $club ) {
        $overall[ $club ] = [ 'pts' => 0, 'shots_for' => 0, 'shots_against' => 0, 'w' => 0, 'd' => 0, 'l' => 0 ];
        foreach ( $disciplines as $d ) {
            $by_disc[ $d['id'] ][ $club ] = [ 'pts' => 0, 'shots_for' => 0, 'shots_against' => 0, 'w' => 0, 'd' => 0, 'l' => 0 ];
        }
    }

    $bonus_win  = (int)($champ['bonus_win']  ?? 0);
    $bonus_draw = (int)($champ['bonus_draw'] ?? 0);
    $bonus_loss = (int)($champ['bonus_loss'] ?? 0);

    foreach ( $fixtures as $fix ) {
        $home = $fix['home'];
        $away = $fix['away'];
        $fix_scores = $scores[ $fix['id'] ] ?? [];

        // Track fixture-level totals for bonus points
        $fix_ph = 0; $fix_pa = 0; $fix_has_score = false;

        foreach ( $disciplines as $d ) {
            for ( $i = 0; $i < $d['count']; $i++ ) {
                $gk = $d['id'] . '_' . $i;
                if ( ! isset( $fix_scores[ $gk ] ) ) continue;
                $g = $fix_scores[ $gk ];

                // Skip cleared games (only scorecard_id preserved, no score data)
                if ( ! isset( $g['shots_home'] ) ) continue;

                $fix_has_score = true;
                $sh = (int)($g['shots_home'] ?? 0);
                $sa = (int)($g['shots_away'] ?? 0);
                $ph = (int)($g['pts_home']   ?? 0);
                $pa = (int)($g['pts_away']   ?? 0);

                $fix_ph += $ph;
                $fix_pa += $pa;

                // Overall: accumulate pts and shots (W/D/L recorded per-fixture after loop)
                if ( isset( $overall[ $home ] ) ) {
                    $overall[$home]['pts']           += $ph;
                    $overall[$home]['shots_for']     += $sh;
                    $overall[$home]['shots_against'] += $sa;
                }
                if ( isset( $overall[ $away ] ) ) {
                    $overall[$away]['pts']           += $pa;
                    $overall[$away]['shots_for']     += $sa;
                    $overall[$away]['shots_against'] += $sh;
                }

                // Per discipline
                if ( isset( $by_disc[ $d['id'] ][ $home ] ) ) {
                    $by_disc[$d['id']][$home]['pts']           += $ph;
                    $by_disc[$d['id']][$home]['shots_for']     += $sh;
                    $by_disc[$d['id']][$home]['shots_against'] += $sa;
                    if ( $ph > $pa )       $by_disc[$d['id']][$home]['w']++;
                    elseif ( $ph === $pa ) $by_disc[$d['id']][$home]['d']++;
                    else                   $by_disc[$d['id']][$home]['l']++;
                }
                if ( isset( $by_disc[ $d['id'] ][ $away ] ) ) {
                    $by_disc[$d['id']][$away]['pts']           += $pa;
                    $by_disc[$d['id']][$away]['shots_for']     += $sa;
                    $by_disc[$d['id']][$away]['shots_against'] += $sh;
                    if ( $pa > $ph )       $by_disc[$d['id']][$away]['w']++;
                    elseif ( $pa === $ph ) $by_disc[$d['id']][$away]['d']++;
                    else                   $by_disc[$d['id']][$away]['l']++;
                }
            }
        }

        // Fixture-level overall W/D/L based on aggregate shots
        if ( $fix_has_score ) {
            $fix_sh = 0; $fix_sa = 0;
            foreach ( $fix_scores as $g ) {
                if ( ! isset($g['shots_home']) ) continue;
                $fix_sh += (int)$g['shots_home'];
                $fix_sa += (int)$g['shots_away'];
            }
            if ( isset( $overall[$home] ) ) {
                if ( $fix_sh > $fix_sa )      $overall[$home]['w']++;
                elseif ( $fix_sh === $fix_sa ) $overall[$home]['d']++;
                else                           $overall[$home]['l']++;
            }
            if ( isset( $overall[$away] ) ) {
                if ( $fix_sa > $fix_sh )      $overall[$away]['w']++;
                elseif ( $fix_sa === $fix_sh ) $overall[$away]['d']++;
                else                           $overall[$away]['l']++;
            }
        }

        // Fixture-level bonus points (overall win/draw/loss based on aggregate pts)
        if ( $fix_has_score && ( $bonus_win || $bonus_draw || $bonus_loss ) ) {
            if ( $fix_ph > $fix_pa ) {
                if ( isset( $overall[$home] ) ) $overall[$home]['pts'] += $bonus_win;
                if ( isset( $overall[$away] ) ) $overall[$away]['pts'] += $bonus_loss;
            } elseif ( $fix_ph < $fix_pa ) {
                if ( isset( $overall[$home] ) ) $overall[$home]['pts'] += $bonus_loss;
                if ( isset( $overall[$away] ) ) $overall[$away]['pts'] += $bonus_win;
            } else {
                if ( isset( $overall[$home] ) ) $overall[$home]['pts'] += $bonus_draw;
                if ( isset( $overall[$away] ) ) $overall[$away]['pts'] += $bonus_draw;
            }
        }
    }

    // Sort: pts desc, shots_for-shots_against desc
    $sort_fn = function( $a, $b ) {
        if ( $a['pts'] !== $b['pts'] ) return $b['pts'] - $a['pts'];
        $da = $a['shots_for'] - $a['shots_against'];
        $db = $b['shots_for'] - $b['shots_against'];
        return $db - $da;
    };

    uasort( $overall, $sort_fn );
    foreach ( $by_disc as &$dt ) { uasort( $dt, $sort_fn ); }
    unset($dt);

    return [ 'overall' => $overall, 'by_discipline' => $by_disc ];
}

// ── Admin page router ─────────────────────────────────────────────────────────

function lgw_multichamp_admin_router() {
    $action   = sanitize_key( $_GET['action'] ?? 'list' );
    $champ_id = sanitize_key( $_GET['id']     ?? '' );

    if ( $action === 'edit' && $champ_id ) {
        lgw_mc_edit_page( $champ_id );
    } elseif ( $action === 'new' ) {
        lgw_mc_edit_page( '' );
    } else {
        lgw_mc_list_page();
    }
}

// ── Admin: list page ──────────────────────────────────────────────────────────

function lgw_mc_list_page() {
    $all     = lgw_mc_get_all();
    $deleted = isset( $_GET['deleted'] );
    lgw_page_header( 'Multi-Discipline Championships' );
    if ( $deleted ) echo '<div class="notice notice-success is-dismissible"><p>Championship deleted.</p></div>';
    echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=lgw-multichamp&action=new' ) ) . '" class="button button-primary">+ New Championship</a></p>';

    if ( empty( $all ) ) {
        echo '<p>No multi-discipline championships yet.</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>Title</th><th>Date</th><th>Venue</th><th>Clubs</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach ( $all as $c ) {
            $edit_url = admin_url( 'admin.php?page=lgw-multichamp&action=edit&id=' . esc_attr( $c['id'] ) );
            echo '<tr>';
            echo '<td><a href="' . esc_url( $edit_url ) . '"><strong>' . esc_html( $c['title'] ?: '(untitled)' ) . '</strong></a></td>';
            echo '<td>' . esc_html( $c['date']  ?? '' ) . '</td>';
            echo '<td>' . esc_html( $c['venue'] ?? '' ) . '</td>';
            echo '<td>' . count( $c['entries'] ?? [] ) . '</td>';
            echo '<td>' . esc_html( ucfirst( $c['status'] ?? 'pending' ) ) . '</td>';
            echo '<td><a href="' . esc_url( $edit_url ) . '">Edit</a>';
            echo ' | <form method="post" style="display:inline" onsubmit="return confirm(\'Delete this championship and all its scores?\');">';
            wp_nonce_field( 'lgw_mc_delete', 'lgw_mc_delete_nonce' );
            echo '<input type="hidden" name="mc_id" value="' . esc_attr( $c['id'] ) . '">';
            echo '<button type="submit" class="button-link" style="color:#a00">Delete</button></form></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

// ── Admin: edit page (setup / fixtures / scores tabs) ────────────────────────

function lgw_mc_edit_page( $champ_id ) {
    $is_new = ( $champ_id === '' );
    $champ  = $is_new ? [] : ( get_option( 'lgw_multichamp_' . $champ_id, [] ) ?: [] );
    $scores = $is_new ? [] : ( get_option( 'lgw_multichamp_' . $champ_id . '_scores', [] ) ?: [] );
    $tab    = sanitize_key( $_GET['tab'] ?? 'setup' );
    $saved  = isset( $_GET['saved'] );

    $title = $is_new ? 'New Multi-Discipline Championship' : ( $champ['title'] ?: 'Edit Championship' );
    lgw_page_header( $title );

    if ( $saved ) echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';

    // Back link
    echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=lgw-multichamp' ) ) . '">&larr; All Championships</a></p>';

    // Shortcode reference (only once an ID exists)
    if ( ! $is_new ) {
        echo '<div style="background:#f0f6fc;border:1px solid #c8d8ea;border-radius:4px;padding:10px 14px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:12px;">';
        echo '<span>📋 <strong>Shortcode:</strong></span>';
        echo '<code style="background:#e8f0f8;padding:3px 10px;border-radius:3px;font-size:13px;user-select:all;">[lgw_multichamp id="' . esc_attr($champ_id) . '"]</code>';
        echo '<span style="color:#666;font-size:12px;">Copy this onto any page to display the widget.</span>';
        echo '</div>';
    }

    // Tab nav
    $tabs = [ 'setup' => '⚙️ Setup', 'fixtures' => '📋 Fixtures', 'scores' => '🎯 Scores' ];
    if ( $is_new ) $tabs = [ 'setup' => '⚙️ Setup' ];
    echo '<nav class="nav-tab-wrapper lgw-mc-tabs">';
    foreach ( $tabs as $t_key => $t_label ) {
        $url    = admin_url( 'admin.php?page=lgw-multichamp&action=edit&id=' . $champ_id . '&tab=' . $t_key );
        $active = ( $tab === $t_key ) ? ' nav-tab-active' : '';
        echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . $active . '">' . esc_html( $t_label ) . '</a>';
    }
    echo '</nav>';
    echo '<div class="lgw-mc-tab-content">';

    if ( $tab === 'setup' )    lgw_mc_tab_setup( $champ, $champ_id, $is_new );
    if ( $tab === 'fixtures' ) lgw_mc_tab_fixtures( $champ, $champ_id );
    if ( $tab === 'scores' )   lgw_mc_tab_scores( $champ, $champ_id, $scores );

    echo '</div></div>'; // tab-content + page wrap from lgw_page_header
}

// ── Tab: Setup ────────────────────────────────────────────────────────────────

function lgw_mc_tab_setup( $champ, $champ_id, $is_new ) {
    $disciplines = $champ['disciplines'] ?? [
        [ 'id' => 'singles', 'label' => 'Singles', 'count' => 1, 'scoring_mode' => 'target', 'ends' => 21, 'time_limit' => '', 'pts_win' => 2, 'pts_draw' => 1, 'pts_loss' => 0 ],
        [ 'id' => 'pairs',   'label' => 'Pairs',   'count' => 1, 'scoring_mode' => 'ends',   'ends' => 21, 'time_limit' => '', 'pts_win' => 2, 'pts_draw' => 1, 'pts_loss' => 0 ],
        [ 'id' => 'triples', 'label' => 'Triples', 'count' => 1, 'scoring_mode' => 'ends',   'ends' => 21, 'time_limit' => '', 'pts_win' => 2, 'pts_draw' => 1, 'pts_loss' => 0 ],
        [ 'id' => 'fours',   'label' => 'Fours',   'count' => 1, 'scoring_mode' => 'ends',   'ends' => 21, 'time_limit' => '', 'pts_win' => 2, 'pts_draw' => 1, 'pts_loss' => 0 ],
    ];
    $entries = implode( "\n", $champ['entries'] ?? [] );
    ?>
    <form method="post" action="">
        <?php wp_nonce_field( 'lgw_mc_save', 'lgw_mc_save_nonce' ); ?>
        <input type="hidden" name="mc_id" value="<?php echo esc_attr( $champ_id ); ?>">

        <table class="form-table">
            <tr><th><label for="mc_title">Title</label></th>
                <td><input type="text" id="mc_title" name="mc_title" class="regular-text" value="<?php echo esc_attr( $champ['title'] ?? '' ); ?>"></td></tr>
            <tr><th><label for="mc_date">Date</label></th>
                <td><input type="text" id="mc_date" name="mc_date" class="regular-text" placeholder="DD/MM/YYYY" value="<?php echo esc_attr( $champ['date'] ?? '' ); ?>"></td></tr>
            <tr><th><label for="mc_venue">Venue</label></th>
                <td><input type="text" id="mc_venue" name="mc_venue" class="regular-text" value="<?php echo esc_attr( $champ['venue'] ?? '' ); ?>"></td></tr>
            <tr><th><label for="mc_status">Status</label></th>
                <td><select name="mc_status" id="mc_status">
                    <?php foreach ( [ 'pending' => 'Pending', 'active' => 'Active', 'complete' => 'Complete' ] as $v => $l ) {
                        $sel = ( ( $champ['status'] ?? 'pending' ) === $v ) ? ' selected' : '';
                        echo '<option value="' . esc_attr($v) . '"' . $sel . '>' . esc_html($l) . '</option>';
                    } ?>
                </select></td></tr>
            <tr><th><label>Widget colour<br><small>Primary accent colour</small></label></th>
                <td><input type="color" name="mc_colour_primary" value="<?php echo esc_attr( $champ['colour_primary'] ?? '#072a82' ); ?>"></td></tr>
            <tr><th><label>Frontend score entry</label></th>
                <td>
                    <label><input type="checkbox" name="mc_score_entry" value="1" <?php checked( !empty($champ['score_entry']) ); ?>> Allow scores to be entered from the public widget</label>
                    <div style="margin-top:8px">
                        <label for="mc_passphrase">Passphrase <small>(leave blank to keep existing)</small></label><br>
                        <input type="text" id="mc_passphrase" name="mc_passphrase" class="regular-text" placeholder="Enter new passphrase to change">
                        <?php if ( !empty($champ['passphrase']) ) echo '<span style="color:#2e7d32;font-size:12px;margin-left:8px">✓ Passphrase set</span>'; ?>
                    </div>
                </td></tr>
            <tr><th><label>Participating clubs<br><small>Add colour and badge per club</small></label></th>
                <td>
                    <table class="widefat" id="lgw-mc-clubs-table" style="max-width:700px">
                        <thead><tr><th>Club name</th><th>Colour</th><th>Badge</th><th></th></tr></thead>
                        <tbody id="lgw-mc-clubs-body">
                        <?php
                        $club_entries  = $champ['entries']      ?? [];
                        $club_colours  = $champ['club_colours'] ?? [];
                        $club_badges   = $champ['club_badges']  ?? [];
                        foreach ( $club_entries as $ci => $club_name ) :
                            $col = $club_colours[$ci] ?? '#072a82';
                            $bdg = $club_badges[$ci]  ?? '';
                        ?>
                        <tr class="lgw-mc-club-row">
                            <td><input type="text" name="club_name[]" value="<?php echo esc_attr($club_name); ?>" class="regular-text" placeholder="Club name"></td>
                            <td><input type="color" name="club_colour[]" value="<?php echo esc_attr($col); ?>"></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <?php if ($bdg) echo '<img src="' . esc_url($bdg) . '" style="width:32px;height:32px;object-fit:contain;">'; ?>
                                    <input type="hidden" name="club_badge[]" class="lgw-mc-badge-url" value="<?php echo esc_url($bdg); ?>">
                                    <button type="button" class="button button-small lgw-mc-choose-badge">Choose badge</button>
                                    <?php if ($bdg) echo '<button type="button" class="button button-small lgw-mc-remove-badge">Remove</button>'; ?>
                                </div>
                            </td>
                            <td><button type="button" class="button button-small lgw-mc-remove-club">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:8px"><button type="button" class="button" id="lgw-mc-add-club">+ Add club</button></p>
                </td></tr>
            <tr><th><label>Match bonus points<br><small>Awarded for the overall fixture result</small></label></th>
                <td>
                    <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
                        <label style="font-weight:normal">Overall win:
                            <input type="number" name="mc_bonus_win" value="<?php echo esc_attr( $champ['bonus_win'] ?? 0 ); ?>" min="0" max="99" style="width:60px;margin-left:6px">
                        </label>
                        <label style="font-weight:normal">Overall draw:
                            <input type="number" name="mc_bonus_draw" value="<?php echo esc_attr( $champ['bonus_draw'] ?? 0 ); ?>" min="0" max="99" style="width:60px;margin-left:6px">
                        </label>
                        <label style="font-weight:normal">Overall loss:
                            <input type="number" name="mc_bonus_loss" value="<?php echo esc_attr( $champ['bonus_loss'] ?? 0 ); ?>" min="0" max="99" style="width:60px;margin-left:6px">
                        </label>
                    </div>
                    <p class="description" style="margin-top:6px">Added on top of individual game points based on the combined fixture result. Set all to 0 to disable.</p>
                </td></tr>
        </table>

        <h3>Disciplines</h3>
        <p class="description">Configure each discipline played on the day. Add as many rows as needed.</p>
        <table class="lgw-mc-disc-table" id="lgw-mc-disc-table" style="border-collapse:collapse;">
            <thead><tr>
                <th style="padding:4px 8px;white-space:nowrap">Label</th>
                <th style="padding:4px 8px;white-space:nowrap">ID</th>
                <th style="padding:4px 8px;white-space:nowrap">Count</th>
                <th style="padding:4px 8px;white-space:nowrap">Scoring</th>
                <th style="padding:4px 8px;white-space:nowrap">Ends/Target</th>
                <th style="padding:4px 8px;white-space:nowrap">Time limit</th>
                <th style="padding:4px 8px;white-space:nowrap">Win</th>
                <th style="padding:4px 8px;white-space:nowrap">Draw</th>
                <th style="padding:4px 8px;white-space:nowrap">Loss</th>
                <th></th>
            </tr></thead>
            <tbody id="lgw-mc-disc-body">
            <?php foreach ( $disciplines as $d ) : ?>
                <tr class="lgw-mc-disc-row">
                    <td><input type="text" name="disc_label[]" value="<?php echo esc_attr( $d['label'] ); ?>" class="regular-text"></td>
                    <td><input type="text" name="disc_id[]"    value="<?php echo esc_attr( $d['id']    ); ?>" class="small-text" placeholder="e.g. singles"></td>
                    <td><input type="number" name="disc_count[]" value="<?php echo esc_attr( $d['count'] ); ?>" min="1" max="20" style="width:60px"></td>
                    <td>
                        <select name="disc_scoring_mode[]" class="lgw-mc-scoring-mode">
                            <option value="ends"   <?php selected( ($d['scoring_mode'] ?? 'ends'), 'ends'   ); ?>>Ends</option>
                            <option value="target" <?php selected( ($d['scoring_mode'] ?? 'ends'), 'target' ); ?>>Target score</option>
                        </select>
                    </td>
                    <td>
                        <input type="number" name="disc_ends[]" value="<?php echo esc_attr( $d['ends'] ); ?>" min="1" max="999" style="width:65px">
                        <span class="lgw-mc-ends-label description"><?php echo ( ($d['scoring_mode'] ?? 'ends') === 'target' ) ? 'shots' : 'ends'; ?></span>
                    </td>
                    <td><input type="text" name="disc_time_limit[]" value="<?php echo esc_attr( $d['time_limit'] ?? '' ); ?>" placeholder="e.g. 75 mins" style="width:90px"></td>
                    <td><input type="number" name="disc_pts_win[]"  value="<?php echo esc_attr( $d['pts_win']  ); ?>" min="0" max="99" style="width:55px"></td>
                    <td><input type="number" name="disc_pts_draw[]" value="<?php echo esc_attr( $d['pts_draw'] ); ?>" min="0" max="99" style="width:55px"></td>
                    <td><input type="number" name="disc_pts_loss[]" value="<?php echo esc_attr( $d['pts_loss'] ); ?>" min="0" max="99" style="width:55px"></td>
                    <td><button type="button" class="button lgw-mc-remove-disc">Remove</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button" id="lgw-mc-add-disc">+ Add discipline</button></p>

        <p class="submit"><input type="submit" class="button button-primary" value="Save Championship"></p>
    </form>
    <?php
}

// ── Tab: Fixtures ─────────────────────────────────────────────────────────────

function lgw_mc_tab_fixtures( $champ, $champ_id ) {
    $entries  = $champ['entries']  ?? [];
    $fixtures = $champ['fixtures'] ?? [];
    ?>
    <div style="margin:16px 0;display:flex;gap:12px;align-items:center;">
        <button type="button" class="button button-primary" id="lgw-mc-auto-draw"
            data-champ="<?php echo esc_attr( $champ_id ); ?>"
            data-nonce="<?php echo esc_attr( wp_create_nonce( 'lgw_mc_nonce' ) ); ?>">
            ⚡ Auto-draw (round-robin)
        </button>
        <span class="description">or build pairings manually below, then save.</span>
    </div>

    <form method="post" action="" id="lgw-mc-fixtures-form">
        <?php wp_nonce_field( 'lgw_mc_fixtures', 'lgw_mc_fixtures_nonce' ); ?>
        <input type="hidden" name="mc_id" value="<?php echo esc_attr( $champ_id ); ?>">

        <table class="widefat" id="lgw-mc-fix-table">
            <thead><tr><th></th><th>#</th><th>Home club</th><th>Away club</th><th></th></tr></thead>
            <tbody id="lgw-mc-fix-body">
            <?php foreach ( $fixtures as $i => $fix ) : ?>
                <tr class="lgw-mc-fix-row" draggable="true" data-fix-id="<?php echo esc_attr($fix['id']); ?>">
                    <td class="lgw-mc-drag-handle" title="Drag to reorder">⠿</td>
                    <td class="lgw-mc-fix-num"><?php echo $i + 1; ?></td>
                    <td><?php lgw_mc_club_select( 'fix_home[]', $fix['home'], $entries ); ?></td>
                    <td><?php lgw_mc_club_select( 'fix_away[]', $fix['away'], $entries ); ?></td>
                    <td><button type="button" class="button lgw-mc-remove-fix">Remove</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <button type="button" class="button" id="lgw-mc-add-fix"
                data-entries='<?php echo esc_attr( json_encode( $entries, JSON_HEX_APOS ) ); ?>'>
                + Add pairing
            </button>
        </p>
        <p class="submit"><input type="submit" class="button button-primary" value="Save fixtures"></p>
    </form>
    <?php
}

function lgw_mc_club_select( $name, $selected, $entries ) {
    echo '<select name="' . esc_attr( $name ) . '" class="lgw-mc-club-select">';
    foreach ( $entries as $e ) {
        $sel = ( $e === $selected ) ? ' selected' : '';
        echo '<option value="' . esc_attr($e) . '"' . $sel . '>' . esc_html($e) . '</option>';
    }
    echo '</select>';
}

// ── Tab: Scores ───────────────────────────────────────────────────────────────

function lgw_mc_tab_scores( $champ, $champ_id, $scores ) {
    $disciplines = $champ['disciplines'] ?? [];
    $fixtures    = $champ['fixtures']    ?? [];

    if ( empty( $fixtures ) ) {
        echo '<p>No fixtures yet — generate or add pairings in the <strong>Fixtures</strong> tab first.</p>';
        return;
    }

    $nonce = wp_create_nonce( 'lgw_mc_nonce' );
    echo '<div id="lgw-mc-scores" data-champ="' . esc_attr($champ_id) . '" data-nonce="' . esc_attr($nonce) . '">';

    foreach ( $fixtures as $fix ) {
        $fix_scores = $scores[ $fix['id'] ] ?? [];
        $tot_ph = 0; $tot_pa = 0; $tot_sh = 0; $tot_sa = 0;
        foreach ( $fix_scores as $g ) { $tot_ph += (int)($g['pts_home']??0); $tot_pa += (int)($g['pts_away']??0); $tot_sh += (int)($g['shots_home']??0); $tot_sa += (int)($g['shots_away']??0); }

        echo '<div class="lgw-mc-fix-card postbox" style="margin-bottom:16px;">';
        echo '<div class="postbox-header" style="cursor:pointer;display:flex;align-items:center;" onclick="if(event.target.closest(\'.lgw-mc-clear-fix\')){return;}this.nextElementSibling.style.display=this.nextElementSibling.style.display===\'none\'?\'block\':\'none\'">';
        echo '<h2 class="hndle" style="flex:1"><span>' . esc_html( $fix['home'] ) . ' v ' . esc_html( $fix['away'] );
        if ( $tot_ph || $tot_pa ) {
            echo ' &nbsp;—&nbsp; <strong>' . $tot_ph . ' pts / ' . $tot_sh . ' shots</strong> v <strong>' . $tot_pa . ' pts / ' . $tot_sa . ' shots</strong>';
        }
        echo '</span></h2>';
        echo '<button type="button" class="button button-small lgw-mc-clear-fix" style="margin-right:12px;color:#a00;"'
            . ' data-champ="' . esc_attr($champ_id) . '"'
            . ' data-fix="' . esc_attr($fix['id']) . '"'
            . ' title="Clear all scores for this fixture">✕ Clear all</button>';
        echo '</div>';
        echo '<div class="inside lgw-mc-fix-inside">';

        echo '<div class="lgw-mc-games-wrap">';

        foreach ( $disciplines as $d ) {
            for ( $i = 0; $i < $d['count']; $i++ ) {
                $gk    = $d['id'] . '_' . $i;
                $g     = $fix_scores[ $gk ] ?? [];
                $sc_id = (int)( $g['scorecard_id'] ?? 0 );
                $label = $d['label'] . ( $d['count'] > 1 ? ' — Game ' . ( $i + 1 ) : '' );
                $mode  = ( $d['scoring_mode'] ?? 'ends' ) === 'target'
                    ? 'First to ' . $d['ends'] . ' shots'
                    : $d['ends'] . ' ends';
                $time  = ! empty( $d['time_limit'] ) ? ' · ' . $d['time_limit'] : '';

                echo '<div class="lgw-mc-game-card" data-fix="' . esc_attr($fix['id']) . '" data-disc="' . esc_attr($d['id']) . '" data-idx="' . $i . '">';

                // Game header
                $g_status_val = $g['status'] ?? 'not_started';
                $status_labels_admin = [ 'not_started' => '', 'in_progress' => '🟡 In progress', 'complete' => '✅ Complete' ];
                echo '<div class="lgw-mc-game-card-hdr">';
                echo '<strong>' . esc_html($label) . '</strong>';
                echo '<span class="lgw-mc-game-meta">' . esc_html($mode . $time) . '</span>';
                if ( $status_labels_admin[$g_status_val] ?? '' ) echo '<span class="lgw-mc-status-badge-admin">' . $status_labels_admin[$g_status_val] . '</span>';
                if ( isset( $g['pts_home'] ) ) {
                    echo '<span class="lgw-mc-game-result">' . esc_html($g['pts_home'] ?? 0) . ' pts / ' . esc_html($g['pts_away'] ?? 0) . ' pts</span>';
                }
                echo '</div>';

                // Score inputs row
                echo '<div class="lgw-mc-game-inputs">';

                echo '<div class="lgw-mc-side">';
                echo '<label>' . esc_html($fix['home']) . '</label>';
                echo '<input type="number" class="mc-shots-home" min="0" placeholder="Shots" value="' . esc_attr($g['shots_home'] ?? '') . '">';
                echo '</div>';

                echo '<div class="lgw-mc-score-mid">';
                echo '<span class="mc-pts-display">';
                if ( isset($g['pts_home']) ) echo esc_html($g['pts_home']) . '<br>v<br>' . esc_html($g['pts_away']);
                else echo 'v';
                echo '</span>';
                echo '</div>';

                echo '<div class="lgw-mc-side lgw-mc-side-away">';
                echo '<label>' . esc_html($fix['away']) . '</label>';
                echo '<input type="number" class="mc-shots-away" min="0" placeholder="Shots" value="' . esc_attr($g['shots_away'] ?? '') . '">';
                echo '</div>';

                echo '</div>'; // lgw-mc-game-inputs

                // Players row — full width, below the score grid
                echo '<div class="lgw-mc-players-row">';
                echo '<div class="lgw-mc-players-side">';
                echo '<label class="lgw-mc-players-label">👤 ' . esc_html($fix['home']) . ' players</label>';
                echo '<input type="text" class="mc-players-home" placeholder="Comma-separated names" value="' . esc_attr(implode(', ', $g['players_home'] ?? [])) . '">';
                echo '</div>';
                echo '<div class="lgw-mc-players-side">';
                echo '<label class="lgw-mc-players-label">👤 ' . esc_html($fix['away']) . ' players</label>';
                echo '<input type="text" class="mc-players-away" placeholder="Comma-separated names" value="' . esc_attr(implode(', ', $g['players_away'] ?? [])) . '">';
                echo '</div>';
                echo '</div>'; // lgw-mc-players-row

                // Actions row
                echo '<div class="lgw-mc-game-actions">';
                echo '<select class="mc-game-status" style="font-size:12px">';
                foreach ( [ 'not_started' => 'Not started', 'in_progress' => '🟡 In progress', 'complete' => '✅ Complete' ] as $sv => $sl ) {
                    $sel = ( $g_status_val === $sv ) ? ' selected' : '';
                    echo '<option value="' . esc_attr($sv) . '"' . $sel . '>' . esc_html($sl) . '</option>';
                }
                echo '</select>';
                echo '<button type="button" class="button button-primary button-small mc-save-game">Save</button>';
                echo '<button type="button" class="button button-small mc-clear-game" style="color:#a00;"'
                    . ' title="Clear this game\'s scores">✕ Clear</button>';
                echo '<div class="mc-sc-cell">';
                if ( $sc_id ) {
                    echo '<a href="' . esc_url(admin_url('post.php?post=' . $sc_id . '&action=edit')) . '" class="button button-small" target="_blank">✏️ Edit scorecard</a>';
                } else {
                    echo '<button type="button" class="button button-small mc-create-sc"'
                        . ' data-champ="' . esc_attr($champ_id) . '"'
                        . ' data-fix="'   . esc_attr($fix['id']) . '"'
                        . ' data-disc="'  . esc_attr($d['id'])   . '"'
                        . ' data-idx="'   . $i . '">'
                        . '+ Full scorecard</button>';
                }
                echo '</div>';
                echo '</div>'; // lgw-mc-game-actions

                echo '</div>'; // lgw-mc-game-card
            }
        }

        echo '</div>'; // lgw-mc-games-wrap
        echo '</div></div>'; // inside, fix-card
    }

    echo '</div>'; // lgw-mc-scores
}

// ── Shortcode ─────────────────────────────────────────────────────────────────

add_shortcode( 'lgw_multichamp', 'lgw_multichamp_shortcode' );
function lgw_multichamp_shortcode( $atts ) {
    $atts = shortcode_atts( [ 'id' => '' ], $atts, 'lgw_multichamp' );
    $champ_id = sanitize_key( $atts['id'] );
    if ( ! $champ_id ) return '<p class="lgw-error">lgw_multichamp: no id specified.</p>';

    $champ = get_option( 'lgw_multichamp_' . $champ_id, [] );
    if ( ! $champ ) return '<p class="lgw-error">lgw_multichamp: championship not found.</p>';

    $scores      = get_option( 'lgw_multichamp_' . $champ_id . '_scores', [] ) ?: [];
    $standings   = lgw_mc_compute_standings( $champ, $scores );
    $disciplines = $champ['disciplines'] ?? [];
    $fixtures    = $champ['fixtures']    ?? [];
    $bonus_win   = (int)($champ['bonus_win']  ?? 0);
    $bonus_draw  = (int)($champ['bonus_draw'] ?? 0);
    $bonus_loss  = (int)($champ['bonus_loss'] ?? 0);

    // Club colour/badge maps keyed by club name
    $club_entries  = $champ['entries']      ?? [];
    $club_colours  = $champ['club_colours'] ?? [];
    $club_badges   = $champ['club_badges']  ?? [];
    $club_colour_map = [];
    $club_badge_map  = [];
    foreach ( $club_entries as $ci => $cname ) {
        $club_colour_map[ $cname ] = $club_colours[$ci] ?? '';
        $club_badge_map[ $cname ]  = $club_badges[$ci]  ?? '';
    }
    $colour_primary = $champ['colour_primary'] ?? '';
    $score_entry    = ! empty( $champ['score_entry'] );
    $frontend_nonce = wp_create_nonce( 'lgw_mc_frontend_nonce' );

    ob_start();
    ?>
    <div class="lgw-w lgw-mc-widget" id="lgw-mc-<?php echo esc_attr($champ_id); ?>"
         data-champ="<?php echo esc_attr($champ_id); ?>"
         data-score-entry="<?php echo $score_entry ? '1' : '0'; ?>"
         data-nonce="<?php echo esc_attr($frontend_nonce); ?>"
         data-bonus-win="<?php echo (int)$bonus_win; ?>"
         data-bonus-draw="<?php echo (int)$bonus_draw; ?>"
         data-bonus-loss="<?php echo (int)$bonus_loss; ?>"
         <?php if ($colour_primary): ?>style="--lgw-mc-primary:<?php echo esc_attr($colour_primary); ?>"<?php endif; ?>>

        <?php if ($score_entry): ?>
        <div class="lgw-mc-unlock-bar" id="lgw-mc-unlock-<?php echo esc_attr($champ_id); ?>">
            <span class="lgw-mc-unlock-label">🔑 Score entry:</span>
            <input type="text" class="lgw-mc-passphrase-input" placeholder="Enter passphrase" autocomplete="off" spellcheck="false">
            <button type="button" class="lgw-mc-unlock-btn">Unlock</button>
            <span class="lgw-mc-unlock-status"></span>
        </div>
        <?php endif; ?>

        <div class="lgw-mc-header">
            <h2 class="lgw-mc-title"><?php echo esc_html( $champ['title'] ); ?></h2>
            <?php if ( $champ['date'] || $champ['venue'] ) : ?>
            <div class="lgw-mc-meta">
                <?php if ( $champ['date']  ) echo '<span>📅 ' . esc_html($champ['date'])  . '</span>'; ?>
                <?php if ( $champ['venue'] ) echo '<span>📍 ' . esc_html($champ['venue']) . '</span>'; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab nav -->
        <div class="lgw-mc-tabnav">
            <div class="lgw-mc-tab active" data-tab="overall" role="button" tabindex="0">Overall</div>
            <?php foreach ( $disciplines as $d ) : ?>
            <div class="lgw-mc-tab" data-tab="disc-<?php echo esc_attr($d['id']); ?>" role="button" tabindex="0"><?php echo esc_html($d['label']); ?></div>
            <?php endforeach; ?>
            <div class="lgw-mc-tab" data-tab="fixtures" role="button" tabindex="0">Fixtures</div>
        </div>

        <!-- Overall standings -->
        <div class="lgw-mc-panel active" data-panel="overall">
            <?php
            $bonus_note = '';
            if ( !empty($champ['bonus_win']) || !empty($champ['bonus_draw']) ) {
                $bonus_note = 'Points include match bonus: win +'.$champ['bonus_win'].', draw +'.$champ['bonus_draw'].', loss +'.($champ['bonus_loss']??0);
            }
            echo lgw_mc_render_standings_table( $standings['overall'], 'Overall standings', $bonus_note, $club_colour_map, $club_badge_map );
            ?>
        </div>

        <!-- Per-discipline standings -->
        <?php foreach ( $disciplines as $d ) : ?>
        <div class="lgw-mc-panel" data-panel="disc-<?php echo esc_attr($d['id']); ?>">
            <?php echo lgw_mc_render_standings_table( $standings['by_discipline'][$d['id']] ?? [], $d['label'] . ' standings', '', $club_colour_map, $club_badge_map ); ?>
        </div>
        <?php endforeach; ?>

        <!-- Fixtures -->
        <div class="lgw-mc-panel" data-panel="fixtures">
            <?php if ( empty($fixtures) ) : ?>
                <p class="lgw-status">Fixtures not yet available.</p>
            <?php else : ?>
            <?php foreach ( $fixtures as $fix ) :
                $fix_scores = $scores[ $fix['id'] ] ?? [];
                $tot_ph = 0; $tot_pa = 0; $tot_sh = 0; $tot_sa = 0;
                $fix_has_score = false;
                foreach ( $fix_scores as $g ) {
                    if ( ! isset($g['shots_home']) ) continue;
                    $fix_has_score = true;
                    $tot_ph += (int)($g['pts_home']??0);
                    $tot_pa += (int)($g['pts_away']??0);
                    $tot_sh += (int)($g['shots_home']??0);
                    $tot_sa += (int)($g['shots_away']??0);
                }
                // Apply bonus to display total
                $disp_ph = $tot_ph; $disp_pa = $tot_pa;
                if ( $fix_has_score ) {
                    if ( $tot_ph > $tot_pa ) { $disp_ph += $bonus_win; $disp_pa += $bonus_loss; }
                    elseif ( $tot_ph < $tot_pa ) { $disp_ph += $bonus_loss; $disp_pa += $bonus_win; }
                    else { $disp_ph += $bonus_draw; $disp_pa += $bonus_draw; }
                }
                $has_result = $fix_has_score;
                $home_wins  = $has_result && $disp_ph > $disp_pa;
                $away_wins  = $has_result && $disp_pa > $disp_ph;
                $home_colour = $club_colour_map[ $fix['home'] ] ?? '';
                $away_colour = $club_colour_map[ $fix['away'] ] ?? '';
                $home_badge  = $club_badge_map[ $fix['home'] ]  ?? '';
                $away_badge  = $club_badge_map[ $fix['away'] ]  ?? '';
                $home_badge_html = $home_badge ? '<img src="' . esc_url($home_badge) . '" class="lgw-badge lgw-mc-club-badge" alt=""> ' : '';
                $away_badge_html = $away_badge ? '<img src="' . esc_url($away_badge) . '" class="lgw-badge lgw-mc-club-badge" alt=""> ' : '';
            ?>
            <div class="lgw-mc-fixture-card" data-fix="<?php echo esc_attr($fix['id']); ?>">
                <div class="lgw-mc-match-header">
                    <span class="lgw-mc-club <?php echo $home_wins ? 'winner' : ''; ?>"
                          <?php if ($home_colour) echo 'style="color:' . esc_attr($home_colour) . '"'; ?>>
                        <?php echo $home_badge_html; ?><?php echo esc_html($fix['home']); ?>
                    </span>
                    <div class="lgw-mc-match-score">
                        <?php if ( $has_result ) : ?>
                            <span class="lgw-mc-score-pts" data-ph="<?php echo $disp_ph; ?>" data-pa="<?php echo $disp_pa; ?>"><?php echo $disp_ph . ' – ' . $disp_pa; ?></span>
                            <span class="lgw-mc-score-shots" data-sh="<?php echo $tot_sh; ?>" data-sa="<?php echo $tot_sa; ?>"><?php echo $tot_sh . ' – ' . $tot_sa . ' shots'; ?></span>
                        <?php else : ?>
                            <span class="lgw-mc-vs lgw-mc-vs-placeholder">v</span>
                            <span class="lgw-mc-score-pts" data-ph="0" data-pa="0" style="display:none"></span>
                            <span class="lgw-mc-score-shots" data-sh="0" data-sa="0" style="display:none"></span>
                        <?php endif; ?>
                    </div>
                    <span class="lgw-mc-club <?php echo $away_wins ? 'winner' : ''; ?>"
                          <?php if ($away_colour) echo 'style="color:' . esc_attr($away_colour) . '"'; ?>>
                        <?php echo $away_badge_html; ?><?php echo esc_html($fix['away']); ?>
                    </span>
                </div>

                <!-- Per-game breakdown -->
                <?php if ( $has_result ) : ?>
                <div class="lgw-mc-games-grid">
                    <?php foreach ( $disciplines as $d ) :
                        for ( $i = 0; $i < $d['count']; $i++ ) :
                            $gk     = $d['id'] . '_' . $i;
                            $g      = $fix_scores[ $gk ] ?? null;
                            $g_status = isset($g['status']) ? $g['status'] : 'not_started';
                            $has_g  = $g && isset($g['shots_home']);
                            $label  = $d['label'] . ( $d['count'] > 1 ? ' ' . ($i+1) : '' );
                            $mode   = ( $d['scoring_mode'] ?? 'ends' ) === 'target'
                                ? 'First to ' . $d['ends'] . ' shots'
                                : $d['ends'] . ' ends';
                            $time   = ! empty( $d['time_limit'] ) ? ' · ' . $d['time_limit'] : '';
                            // Only show rows for games that have started or have a score
                            // Hide not-started rows unless score entry is enabled (scorer needs to see all games)
                            if ( $g_status === 'not_started' && ! $has_g && ! $score_entry ) continue;
                            $ph = (int)($g['pts_home'] ?? 0);
                            $pa = (int)($g['pts_away'] ?? 0);
                            $sh = $g['shots_home'] ?? '';
                            $sa = $g['shots_away'] ?? '';
                            $result_class = $ph > $pa ? 'home-win' : ( $pa > $ph ? 'away-win' : 'draw' );
                            $players_h = implode(', ', $g['players_home'] ?? []);
                            $players_a = implode(', ', $g['players_away'] ?? []);
                            $status_labels = [ 'in_progress' => '🟡 In progress', 'complete' => '✅ Complete', 'not_started' => '' ];
                            $status_label = $status_labels[ $g_status ] ?? '';
                    ?>
                    <div class="lgw-mc-game-row <?php echo $result_class; ?>"
                         data-fix="<?php echo esc_attr($fix['id']); ?>"
                         data-disc="<?php echo esc_attr($d['id']); ?>"
                         data-idx="<?php echo $i; ?>"
                         data-champ="<?php echo esc_attr($champ_id); ?>"
                         data-sh="<?php echo (int)($g['shots_home'] ?? 0); ?>"
                         data-sa="<?php echo (int)($g['shots_away'] ?? 0); ?>"
                         data-ph="<?php echo (int)($g['pts_home'] ?? 0); ?>"
                         data-pa="<?php echo (int)($g['pts_away'] ?? 0); ?>">
                        <div class="lgw-mc-disc-label">
                            <?php echo esc_html($label); ?>
                            <span class="lgw-mc-disc-meta"><?php echo esc_html($mode . $time); ?></span>
                            <?php if ($status_label) echo '<span class="lgw-mc-status-badge">' . $status_label . '</span>'; ?>
                        </div>
                        <div class="lgw-mc-score-col">
                            <span class="lgw-mc-game-shots <?php echo $ph > $pa ? 'bold-winner' : ''; ?>">
                                <?php echo $has_g ? esc_html($sh) : ( $score_entry ? '<span class="lgw-mc-pending">—</span>' : '' ); ?>
                            </span>
                            <?php if ($players_h) echo '<span class="lgw-mc-players">' . esc_html($players_h) . '</span>'; ?>
                        </div>
                        <div class="lgw-mc-score-col lgw-mc-score-col-away">
                            <span class="lgw-mc-game-shots <?php echo $pa > $ph ? 'bold-winner' : ''; ?>">
                                <?php echo $has_g ? esc_html($sa) : ( $score_entry ? '<span class="lgw-mc-pending">—</span>' : '' ); ?>
                            </span>
                            <?php if ($players_a) echo '<span class="lgw-mc-players">' . esc_html($players_a) . '</span>'; ?>
                        </div>
                        <?php if ( $score_entry ) : ?>
                        <div class="lgw-mc-fe-inputs" style="display:none">
                            <div class="lgw-mc-fe-row">
                                <div class="lgw-mc-fe-side">
                                    <label><?php echo esc_html($fix['home']); ?></label>
                                    <input type="number" class="lgw-mc-fe-home" min="0" placeholder="Shots" value="<?php echo esc_attr($sh); ?>">
                                    <input type="text" class="lgw-mc-fe-players-home" placeholder="Players (comma-separated)" value="<?php echo esc_attr($players_h); ?>">
                                </div>
                                <div class="lgw-mc-fe-mid">
                                    <select class="lgw-mc-fe-status">
                                        <?php foreach ( [ 'in_progress' => '🟡 In progress', 'complete' => '✅ Complete' ] as $sv => $sl ) : ?>
                                        <option value="<?php echo $sv; ?>" <?php selected($g_status, $sv); ?>><?php echo $sl; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="lgw-mc-fe-save">Save</button>
                                </div>
                                <div class="lgw-mc-fe-side lgw-mc-fe-side-away">
                                    <label><?php echo esc_html($fix['away']); ?></label>
                                    <input type="number" class="lgw-mc-fe-away" min="0" placeholder="Shots" value="<?php echo esc_attr($sa); ?>">
                                    <input type="text" class="lgw-mc-fe-players-away" placeholder="Players (comma-separated)" value="<?php echo esc_attr($players_a); ?>">
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function lgw_mc_render_standings_table( array $rows, string $title, string $note = '', array $colour_map = [], array $badge_map = [] ) {
    if ( empty( $rows ) ) return '<p class="lgw-status">No results yet.</p>';
    $html  = '<h3 class="lgw-mc-standings-title">' . esc_html($title) . '</h3>';
    if ( $note ) $html .= '<p class="lgw-mc-standings-note">' . esc_html($note) . '</p>';
    $html .= '<div class="tbl-wrap"><table class="lg lgw-mc-standings">';
    $html .= '<thead><tr><th>#</th><th>Club</th><th>Pts</th><th>+/-</th><th>W</th><th>D</th><th>L</th><th>For</th><th>Agst</th></tr></thead><tbody>';
    $pos = 1;
    foreach ( $rows as $club => $r ) {
        $diff   = $r['shots_for'] - $r['shots_against'];
        $colour = $colour_map[ $club ] ?? '';
        $badge  = $badge_map[ $club ]  ?? '';
        $bg_style  = $colour ? ' style="background:' . esc_attr($colour) . '20;border-left:4px solid ' . esc_attr($colour) . '"' : '';
        $name_style= $colour ? ' style="color:' . esc_attr($colour) . '"' : '';
        $badge_html= $badge  ? '<img src="' . esc_url($badge) . '" class="lgw-badge lgw-mc-club-badge" alt=""> ' : '';
        // esc_attr converts apostrophes in club names; use esc_html for display text only
        $html .= '<tr' . $bg_style . '>';
        $html .= '<td class="cp">' . $pos++ . '</td>';
        $html .= '<td class="ct"' . ( $colour ? ' style="font-weight:700;color:' . esc_attr($colour) . '"' : '' ) . '>' . $badge_html . esc_html($club) . '</td>';
        $html .= '<td class="ck">' . esc_html($r['pts'])          . '</td>';
        $html .= '<td>'            . ( $diff >= 0 ? '+' : '' ) . esc_html($diff) . '</td>';
        $html .= '<td>'            . esc_html($r['w'])            . '</td>';
        $html .= '<td>'            . esc_html($r['d'])            . '</td>';
        $html .= '<td>'            . esc_html($r['l'])            . '</td>';
        $html .= '<td>'            . esc_html($r['shots_for'])     . '</td>';
        $html .= '<td>'            . esc_html($r['shots_against']) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

// ── Enqueue frontend assets ───────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', 'lgw_multichamp_enqueue' );
function lgw_multichamp_enqueue() {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'lgw_multichamp' ) ) return;
    wp_enqueue_style( 'lgw-multichamp', plugin_dir_url(__FILE__) . 'lgw-multichamp.css', [], LGW_VERSION );
    wp_enqueue_script( 'lgw-multichamp', plugin_dir_url(__FILE__) . 'lgw-multichamp.js', [], LGW_VERSION, true );
    wp_localize_script( 'lgw-multichamp', 'lgwMcData', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'isAdmin' => current_user_can( 'manage_options' ) ? '1' : '0',
    ] );
}

// ── Enqueue admin assets ──────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'lgw_multichamp_admin_enqueue' );
function lgw_multichamp_admin_enqueue( $hook ) {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'lgw-multichamp' ) return;
    wp_enqueue_media();
    wp_enqueue_style(  'lgw-multichamp', plugin_dir_url(__FILE__) . 'lgw-multichamp.css', [], LGW_VERSION );
    wp_enqueue_script( 'lgw-multichamp', plugin_dir_url(__FILE__) . 'lgw-multichamp.js',  [], LGW_VERSION, true );
    // wp-admin already defines window.ajaxurl globally — no localize needed
}
