<?php
/**
 * LGW Setup Wizard — first-run guided configuration.
 *
 * Prompts a fresh install to pick a data source and bootstrap it:
 *   • Google CSV        → data_source = google_sheets, enter division CSV URLs.
 *   • Upload spreadsheet → parse a Division,Team CSV → seed WP cache (wordpress mode).
 *   • WordPress DB       → type divisions + team rosters → seed WP cache (wordpress mode).
 *
 * Runs once on activation (auto-redirect) but is re-runnable from LGW ▸ 🚀 Setup.
 *
 * @package LGW
 * @since   2026.27.12
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Activation: flag first-run redirect ───────────────────────────────────────
register_activation_hook( LGW_PLUGIN_FILE, 'lgw_setup_activate' );
function lgw_setup_activate() {
	if ( ! get_option( 'lgw_setup_complete' ) ) {
		set_transient( 'lgw_setup_redirect', 1, 60 );
	}
}

// ── First admin load after activation → wizard ────────────────────────────────
add_action( 'admin_init', 'lgw_setup_maybe_redirect' );
function lgw_setup_maybe_redirect() {
	if ( ! get_transient( 'lgw_setup_redirect' ) ) return;
	if ( isset( $_GET['activate-multi'] ) ) return; // bulk activation — don't hijack
	delete_transient( 'lgw_setup_redirect' );
	if ( ! current_user_can( 'manage_options' ) ) return;
	wp_safe_redirect( admin_url( 'admin.php?page=lgw-setup' ) );
	exit;
}

// ── Form submit handler — runs before admin headers, so redirects work ────────
add_action( 'admin_post_lgw_setup_wizard', 'lgw_setup_wizard_submit' );
function lgw_setup_wizard_submit() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
	check_admin_referer( 'lgw_setup_wizard' );
	$res  = lgw_setup_process_post();
	$step = is_wp_error( $res ) ? max( 1, intval( $_POST['lgw_setup_step'] ?? 1 ) ) : intval( $res );
	$url  = admin_url( 'admin.php?page=lgw-setup&step=' . $step );
	if ( is_wp_error( $res ) ) $url .= '&err=' . rawurlencode( $res->get_error_message() );
	wp_safe_redirect( $url );
	exit;
}

// ── Reset — wipe league config to start fresh ─────────────────────────────────
add_action( 'admin_post_lgw_setup_reset', 'lgw_setup_reset' );
function lgw_setup_reset() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
	check_admin_referer( 'lgw_setup_reset' );

	// Drop seeded division caches for the active season.
	$season = function_exists( 'lgw_get_active_season' ) ? lgw_get_active_season() : null;
	$sid    = $season['id'] ?? '';
	if ( $sid && function_exists( 'lgw_cache_option_key' ) ) {
		foreach ( (array) ( $season['divisions'] ?? array() ) as $d ) {
			if ( ! empty( $d['division'] ) ) delete_option( lgw_cache_option_key( $sid, $d['division'] ) );
		}
	}
	// Clear the active season's division list.
	lgw_setup_set_divisions( array() );

	// Reset source + wizard state.
	delete_option( 'lgw_data_source' );   // back to default (google_sheets)
	delete_option( 'lgw_setup_complete' );
	delete_option( 'lgw_setup_choice' );
	delete_option( 'lgw_setup_pending' );
	delete_option( 'lgw_setup_fx' );
	delete_option( 'lgw_setup_fx_div' );

	wp_safe_redirect( admin_url( 'admin.php?page=lgw-setup&step=1&reset=1' ) );
	exit;
}

// ── Menu (hidden-ish, appended last) ──────────────────────────────────────────
add_action( 'admin_menu', 'lgw_setup_menu', 99 );
function lgw_setup_menu() {
	add_submenu_page(
		'lgw-scorecards',
		'LGW Setup Wizard',
		'🚀 Setup',
		'manage_options',
		'lgw-setup',
		'lgw_setup_wizard_page'
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/** True if a league already exists (setup done or active season has divisions). */
function lgw_setup_is_configured() {
	if ( get_option( 'lgw_setup_complete' ) ) return true;
	$s = function_exists( 'lgw_get_active_season' ) ? lgw_get_active_season() : null;
	return ! empty( $s['divisions'] );
}

/** Ensure an active season exists and return its id. */
function lgw_setup_ensure_season() {
	$id = function_exists( 'lgw_get_active_season_id' ) ? lgw_get_active_season_id() : '';
	if ( $id ) return $id;
	$seasons = function_exists( 'lgw_get_seasons' ) ? lgw_get_seasons() : array();
	$year    = date( 'Y' );
	$seasons[] = array(
		'id'        => $year,
		'label'     => $year . ' Season',
		'active'    => true,
		'divisions' => array(),
	);
	update_option( 'lgw_seasons', $seasons );
	return $year;
}

/**
 * Write the active season's division list.
 * @param array $divisions  Array of ['division'=>name, 'csv_url'=>url].
 */
function lgw_setup_set_divisions( $divisions ) {
	$seasons = function_exists( 'lgw_get_seasons' ) ? lgw_get_seasons() : array();
	$found   = false;
	foreach ( $seasons as &$s ) {
		if ( ! empty( $s['active'] ) ) { $s['divisions'] = $divisions; $found = true; break; }
	}
	unset( $s );
	if ( ! $found ) {
		$year = date( 'Y' );
		$seasons[] = array( 'id' => $year, 'label' => $year . ' Season', 'active' => true, 'divisions' => $divisions );
	}
	update_option( 'lgw_seasons', $seasons );
}

/**
 * Case-insensitive duplicate team names within one list.
 * @return string[]  Distinct names that appear more than once (first-seen casing).
 */
function lgw_setup_dupes( $teams ) {
	$seen = array();
	$dupe = array();
	foreach ( $teams as $t ) {
		$t = trim( $t );
		if ( $t === '' ) continue;
		$k = strtolower( $t );
		if ( isset( $seen[ $k ] ) ) $dupe[ $k ] = $seen[ $k ];
		else $seen[ $k ] = $t;
	}
	return array_values( $dupe );
}

/**
 * Round-robin pairing via the circle method.
 * @return array  List of rounds; each round a list of [home, away] pairs.
 */
function lgw_setup_round_robin( $teams ) {
	$arr = array_values( $teams );
	$n   = count( $arr );
	if ( $n < 2 ) return array();
	if ( $n % 2 ) { $arr[] = null; $n++; } // odd → bye marker
	$rounds = array();
	$half   = $n / 2;
	for ( $r = 0; $r < $n - 1; $r++ ) {
		$pairs = array();
		for ( $i = 0; $i < $half; $i++ ) {
			$h = $arr[ $i ];
			$a = $arr[ $n - 1 - $i ];
			if ( $h === null || $a === null ) continue;    // bye
			$pairs[] = ( $r % 2 ) ? array( $a, $h ) : array( $h, $a ); // alternate home/away
		}
		$rounds[] = $pairs;
		// Rotate all but the first entry.
		$fixed = $arr[0];
		$rest  = array_slice( $arr, 1 );
		array_unshift( $rest, array_pop( $rest ) );
		$arr = array_merge( array( $fixed ), $rest );
	}
	return $rounds;
}

/**
 * Derive a club key from a team name so sibling teams share it.
 * Strips a trailing team designator — a single letter or digit ("DUNBARTON A",
 * "PICKIE 2") — then normalises case/whitespace. Teams with no suffix map to
 * themselves ("BELFAST").
 */
function lgw_setup_club_of( $team ) {
	$c = preg_replace( '/\s+[A-Za-z0-9]$/u', '', trim( (string) $team ) );
	$c = trim( $c ) !== '' ? trim( $c ) : trim( (string) $team );
	return strtoupper( preg_replace( '/\s+/', ' ', $c ) );
}

/**
 * Re-orient pairs within each round so no club hosts more than one match on the
 * same day. Flipping a pair swaps home/away only — always a valid fixture.
 * Sibling teams (e.g. DUNBARTON A & B) sharing a green can't both be at home.
 * @param array $rounds  List of rounds; each a list of [home, away].
 * @return array  Same shape, venues balanced per round where possible.
 */
function lgw_setup_balance_home_clubs( $rounds ) {
	foreach ( $rounds as &$pairs ) {
		$hosting = array(); // club keys already at home this round
		foreach ( $pairs as &$pair ) {
			$hc = lgw_setup_club_of( $pair[0] );
			$ac = lgw_setup_club_of( $pair[1] );
			if ( isset( $hosting[ $hc ] ) && ! isset( $hosting[ $ac ] ) ) {
				$pair = array( $pair[1], $pair[0] ); // flip venue
				$hc   = $ac;
			}
			$hosting[ $hc ] = true; // may double up only when both clubs already host (unavoidable)
		}
		unset( $pair );
	}
	unset( $pairs );
	return $rounds;
}

/**
 * Build fixture records for a team list.
 * @param array $teams
 * @param bool  $double         Double round (reverse legs appended).
 * @param int   $start_ts       Unix timestamp of round 1.
 * @param int   $interval_days  Days between rounds.
 * @return array  Fixture records in lgw_cache shape.
 */
function lgw_setup_generate_fixtures( $teams, $double, $start_ts, $interval_days ) {
	$rounds = lgw_setup_round_robin( $teams );
	if ( $double ) {
		$rev = array();
		foreach ( $rounds as $rd ) {
			$p = array();
			foreach ( $rd as $pair ) $p[] = array( $pair[1], $pair[0] );
			$rev[] = $p;
		}
		$rounds = array_merge( $rounds, $rev );
	}
	$rounds = lgw_setup_balance_home_clubs( $rounds );
	$fx = array();
	foreach ( $rounds as $ri => $pairs ) {
		$ts   = $start_ts + $ri * $interval_days * DAY_IN_SECONDS;
		$date = date( 'D j-M-Y', $ts );
		foreach ( $pairs as $pair ) {
			$fx[] = array(
				'date' => $date, 'homeTeam' => $pair[0], 'awayTeam' => $pair[1],
				'shotsHome' => '', 'shotsAway' => '', 'ptsHome' => '', 'ptsAway' => '',
				'timeNote' => '', 'played' => false, 'status' => 'unplayed',
			);
		}
	}
	return $fx;
}

/** Write a fixture list into a division's cache, preserving teams/csv_url. */
function lgw_setup_store_fixtures( $season_id, $division, $fixtures ) {
	$data = get_option( lgw_cache_option_key( $season_id, $division ), null );
	if ( ! is_array( $data ) ) $data = array( 'teams' => array(), 'fixtures' => array(), 'csv_url' => '' );
	$data['fixtures'] = $fixtures;
	lgw_cache_set_division( $season_id, $division, $data );
}

/** Pull fixture-gen options from POST → [enabled, double, start_ts, interval_days]. */
function lgw_setup_fixture_opts() {
	$enabled  = ! empty( $_POST['lgw_gen_fixtures'] );
	$double   = ! empty( $_POST['lgw_gen_double'] );
	$start_ts = ! empty( $_POST['lgw_gen_start'] ) ? strtotime( sanitize_text_field( $_POST['lgw_gen_start'] ) ) : 0;
	if ( ! $start_ts ) $start_ts = time();
	$weeks    = max( 1, intval( $_POST['lgw_gen_interval'] ?? 1 ) );
	return array( $enabled, $double, $start_ts, $weeks * 7 );
}

/** Save step-2 fixture choices as the review-step defaults; clear per-division overrides. */
function lgw_setup_save_fx_defaults() {
	list( $on, $dbl, $ts, $idays ) = lgw_setup_fixture_opts();
	update_option( 'lgw_setup_fx', array(
		'on'     => $on ? 1 : 0,
		'double' => $dbl ? 1 : 0,
		'start'  => date( 'Y-m-d', $ts ),
		'weeks'  => max( 1, (int) round( $idays / 7 ) ),
	) );
	delete_option( 'lgw_setup_fx_div' );
}

/** Global fixture defaults set at step 2. */
function lgw_setup_fx_get() {
	$d = get_option( 'lgw_setup_fx', array() );
	return array(
		'on'     => ! empty( $d['on'] ),
		'double' => ! empty( $d['double'] ),
		'start'  => $d['start'] ?? date( 'Y-m-d', strtotime( 'next saturday' ) ),
		'weeks'  => max( 1, intval( $d['weeks'] ?? 1 ) ),
	);
}

/** Per-division overrides: [ division => ['start'=>Y-m-d,'weeks'=>int,'double'=>0|1] ]. */
function lgw_setup_fx_div_get() {
	$d = get_option( 'lgw_setup_fx_div', array() );
	return is_array( $d ) ? $d : array();
}

/**
 * Resolve fixture settings for one division (override → global default).
 * @return array [ 'start'=>Y-m-d, 'weeks'=>int, 'double'=>bool ]
 */
function lgw_setup_fx_for_division( $division ) {
	$def = lgw_setup_fx_get();
	$ov  = lgw_setup_fx_div_get();
	$o   = $ov[ $division ] ?? array();
	return array(
		'start'  => ! empty( $o['start'] ) ? $o['start'] : $def['start'],
		'weeks'  => isset( $o['weeks'] ) ? max( 1, intval( $o['weeks'] ) ) : $def['weeks'],
		'double' => isset( $o['double'] ) ? ! empty( $o['double'] ) : $def['double'],
	);
}

/** Read per-division fixture controls from a step-3 POST into the override shape. */
function lgw_setup_read_fx_div_post( $divisions ) {
	$out    = array();
	$starts = (array) ( $_POST['lgw_fx_start'] ?? array() );
	$weeks  = (array) ( $_POST['lgw_fx_weeks'] ?? array() );
	$dbls   = (array) ( $_POST['lgw_fx_double'] ?? array() );
	foreach ( $divisions as $d ) {
		$name = $d['division'];
		$out[ $name ] = array(
			'start'  => isset( $starts[ $name ] ) ? sanitize_text_field( $starts[ $name ] ) : '',
			'weeks'  => isset( $weeks[ $name ] ) ? max( 1, intval( $weeks[ $name ] ) ) : 1,
			'double' => ! empty( $dbls[ $name ] ) ? 1 : 0,
		);
	}
	return $out;
}

/**
 * Parse an uploaded 2-column CSV (Division,Team) into [division => [team,…]].
 * A leading header row (division/team) is skipped if detected.
 */
function lgw_setup_parse_upload_csv( $raw ) {
	$out   = array();
	$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
	$first = true;
	foreach ( $lines as $line ) {
		if ( trim( $line ) === '' ) continue;
		$cols = str_getcsv( $line );
		$div  = isset( $cols[0] ) ? trim( $cols[0] ) : '';
		$team = isset( $cols[1] ) ? trim( $cols[1] ) : '';
		if ( $first ) {
			$first = false;
			if ( preg_match( '/^div/i', $div ) && preg_match( '/team|club/i', $team ) ) continue; // header
		}
		if ( $div === '' || $team === '' ) continue;
		$out[ $div ][] = $team;
	}
	return $out;
}

/** Staged wizard config — held here until the user hits Finish, so nothing
 *  overwrites the live league until the review step is confirmed. */
function lgw_setup_pending_get() {
	$p = get_option( 'lgw_setup_pending', array() );
	return is_array( $p ) ? $p : array();
}

// ─────────────────────────────────────────────────────────────────────────────
// POST processing — returns next step number or WP_Error
// ─────────────────────────────────────────────────────────────────────────────
function lgw_setup_process_post() {
	$step   = intval( $_POST['lgw_setup_step'] ?? 0 );
	$choice = get_option( 'lgw_setup_choice', '' );

	// Step 1 — pick source
	if ( $step === 1 ) {
		$src = $_POST['lgw_setup_source'] ?? '';
		if ( ! in_array( $src, array( 'google_sheets', 'upload', 'wordpress' ), true ) ) {
			return new WP_Error( 'bad_source', 'Please choose a data source.' );
		}
		if ( lgw_setup_is_configured() && empty( $_POST['lgw_setup_confirm_overwrite'] ) ) {
			return new WP_Error( 'confirm', 'A league is already set up. Tick the confirm box to overwrite it.' );
		}
		update_option( 'lgw_setup_choice', $src );
		return 2;
	}

	// Step 2 — configure
	if ( $step === 2 ) {
		// Nothing here touches the live league — everything is staged in
		// 'lgw_setup_pending' and only applied when the user hits Finish (step 3).

		// -- Google CSV --------------------------------------------------------
		if ( $choice === 'google_sheets' ) {
			$names = array_map( 'sanitize_text_field', (array) ( $_POST['lgw_div_name'] ?? array() ) );
			$urls  = array_map( 'esc_url_raw',         (array) ( $_POST['lgw_div_csv_url'] ?? array() ) );
			$divs  = array();
			$tabs  = array();
			foreach ( $names as $i => $n ) {
				$n = trim( $n ); $u = trim( $urls[ $i ] ?? '' );
				if ( $n !== '' && $u !== '' ) {
					$divs[] = array( 'division' => $n, 'csv_url' => $u );
					$tabs[] = array( 'division' => $n, 'csv_url' => $u, 'tab' => '', 'spreadsheet_id' => '' );
				}
			}
			if ( empty( $divs ) ) return new WP_Error( 'no_divs', 'Add at least one division with a CSV URL.' );
			update_option( 'lgw_setup_pending', array(
				'choice'      => 'google_sheets',
				'data_source' => 'google_sheets',
				'divisions'   => $divs,
				'teams'       => array(),
				'sheets_tabs' => $tabs,
				'sheets_id'   => sanitize_text_field( $_POST['lgw_sheets_id'] ?? '' ),
			) );
			update_option( 'lgw_setup_fx', array( 'on' => 0 ) ); // fixtures come from the sheet
			delete_option( 'lgw_setup_fx_div' );
			return 3;
		}

		// -- Upload spreadsheet (CSV) ------------------------------------------
		if ( $choice === 'upload' ) {
			if ( empty( $_FILES['lgw_upload']['tmp_name'] ) || ! is_uploaded_file( $_FILES['lgw_upload']['tmp_name'] ) ) {
				return new WP_Error( 'no_file', 'Choose a CSV file to upload.' );
			}
			$raw = file_get_contents( $_FILES['lgw_upload']['tmp_name'] );
			$map = lgw_setup_parse_upload_csv( $raw );
			if ( empty( $map ) ) return new WP_Error( 'empty_csv', 'No Division,Team rows found in that file.' );
			foreach ( $map as $div => $teams ) {
				$d = lgw_setup_dupes( $teams );
				if ( $d ) return new WP_Error( 'dupe', 'Duplicate team in "' . $div . '": ' . implode( ', ', $d ) . '. Each team must be unique within its division — rename or remove the duplicate in your CSV and upload again.' );
			}
			$divs = array();
			foreach ( $map as $div => $teams ) $divs[] = array( 'division' => $div, 'csv_url' => '' );
			update_option( 'lgw_setup_pending', array(
				'choice'      => 'upload',
				'data_source' => 'wordpress',
				'divisions'   => $divs,
				'teams'       => $map,
			) );
			lgw_setup_save_fx_defaults(); // fixtures generated + dated on the review step
			return 3;
		}

		// -- WordPress DB (manual rosters) -------------------------------------
		if ( $choice === 'wordpress' ) {
			$names   = (array) ( $_POST['lgw_wp_div_name'] ?? array() );
			$rosters = (array) ( $_POST['lgw_wp_div_teams'] ?? array() );

			// Collect + validate first (no partial writes on error).
			$plan = array();
			foreach ( $names as $i => $n ) {
				$n = sanitize_text_field( trim( $n ) );
				if ( $n === '' ) continue;
				$teams = array_map( 'sanitize_text_field', preg_split( '/\r\n|\r|\n/', (string) ( $rosters[ $i ] ?? '' ) ) );
				$teams = array_values( array_filter( array_map( 'trim', $teams ) ) );
				if ( empty( $teams ) ) continue;
				$d = lgw_setup_dupes( $teams );
				if ( $d ) return new WP_Error( 'dupe', 'Duplicate team in "' . $n . '": ' . implode( ', ', $d ) . '. Each team must be unique within its division — rename or remove the duplicate, then continue.' );
				$plan[ $n ] = $teams;
			}
			if ( empty( $plan ) ) return new WP_Error( 'no_divs', 'Add at least one division with team names.' );

			$divs = array();
			foreach ( $plan as $n => $teams ) $divs[] = array( 'division' => $n, 'csv_url' => '' );
			update_option( 'lgw_setup_pending', array(
				'choice'      => 'wordpress',
				'data_source' => 'wordpress',
				'divisions'   => $divs,
				'teams'       => $plan,
			) );
			lgw_setup_save_fx_defaults(); // fixtures generated + dated on the review step
			return 3;
		}

		return new WP_Error( 'no_choice', 'Session expired — start again.' );
	}

	// Step 3 — review: regenerate fixture preview, or finish + commit
	if ( $step === 3 ) {
		$pending = lgw_setup_pending_get();
		if ( empty( $pending['divisions'] ) ) return new WP_Error( 'no_pending', 'Session expired — start again.' );
		$divs  = $pending['divisions'];
		$fx_on = ! in_array( $choice, array( 'google_sheets', '' ), true ) && lgw_setup_fx_get()['on'];

		// Save per-division fixture settings (used by both actions).
		if ( $fx_on && $divs ) {
			update_option( 'lgw_setup_fx_div', lgw_setup_read_fx_div_post( $divs ) );
		}

		// "Regenerate preview" — stay on step 3 with updated dates (no commit).
		if ( ( $_POST['lgw_setup_action'] ?? '' ) === 'regen' ) {
			return 3;
		}

		// Finish — NOW apply the staged config to the live league.
		$sid = lgw_setup_ensure_season();

		// Seed team rosters (upload / WP-DB sources).
		foreach ( (array) ( $pending['teams'] ?? array() ) as $div => $teams ) {
			lgw_cache_seed_teams( $sid, $div, $teams );
		}
		lgw_setup_set_divisions( $divs );
		update_option( 'lgw_data_source', $pending['data_source'] ?? 'google_sheets' );

		// Google CSV: merge sheet config into lgw_drive without clobbering other keys.
		if ( ( $pending['choice'] ?? '' ) === 'google_sheets' ) {
			$drive = function_exists( 'lgw_get_option_array' ) ? lgw_get_option_array( 'lgw_drive' ) : (array) get_option( 'lgw_drive', array() );
			$drive['sheets_tabs'] = $pending['sheets_tabs'] ?? array();
			$drive['sheets_id']   = $pending['sheets_id'] ?: ( $drive['sheets_id'] ?? '' );
			update_option( 'lgw_drive', $drive );
		}

		// Generate + store fixtures per division.
		if ( $fx_on ) {
			foreach ( $divs as $d ) {
				$name  = $d['division'];
				$teams = $pending['teams'][ $name ] ?? array();
				if ( count( $teams ) < 2 ) continue;
				$s   = lgw_setup_fx_for_division( $name );
				$ts  = strtotime( $s['start'] ) ?: time();
				$fix = lgw_setup_generate_fixtures( $teams, $s['double'], $ts, $s['weeks'] * 7 );
				lgw_setup_store_fixtures( $sid, $name, $fix );
			}
		}

		update_option( 'lgw_setup_complete', 1 );
		delete_option( 'lgw_setup_choice' );
		delete_option( 'lgw_setup_pending' );
		delete_option( 'lgw_setup_fx' );
		delete_option( 'lgw_setup_fx_div' );
		return 4;
	}

	return new WP_Error( 'bad_step', 'Unknown step.' );
}

// ─────────────────────────────────────────────────────────────────────────────
// Render
// ─────────────────────────────────────────────────────────────────────────────
function lgw_setup_wizard_page() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

	$err    = isset( $_GET['err'] ) ? sanitize_text_field( wp_unslash( $_GET['err'] ) ) : '';
	$step   = isset( $_GET['step'] ) ? max( 1, min( 4, intval( $_GET['step'] ) ) ) : 1;
	$choice = get_option( 'lgw_setup_choice', '' );
	$labels = array( 1 => 'Data source', 2 => 'Configure', 3 => 'Review', 4 => 'Done' );
	?>
	<div class="wrap lgw-setup">
		<h1>🚀 LGW Setup</h1>

		<ol class="lgw-steps">
			<?php foreach ( $labels as $n => $lab ) : ?>
				<li class="<?php echo $n === $step ? 'active' : ( $n < $step ? 'done' : '' ); ?>">
					<span class="num"><?php echo $n < $step ? '✓' : $n; ?></span> <?php echo esc_html( $lab ); ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<?php if ( $err ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['reset'] ) ) : ?>
			<div class="notice notice-success"><p>League config wiped. Starting fresh.</p></div>
		<?php endif; ?>

		<?php
		if ( $step === 1 )      lgw_setup_render_step1();
		elseif ( $step === 2 )  lgw_setup_render_step2( $choice );
		elseif ( $step === 3 )  lgw_setup_render_step3( $choice );
		else                    lgw_setup_render_done();
		?>
	</div>

	<style>
		.lgw-setup .lgw-steps{display:flex;gap:8px;list-style:none;margin:16px 0 24px;padding:0}
		.lgw-setup .lgw-steps li{flex:1;padding:10px 12px;background:#f0f0f1;border-radius:6px;color:#666;font-weight:600}
		.lgw-setup .lgw-steps li.active{background:#072a82;color:#fff}
		.lgw-setup .lgw-steps li.done{background:#138211;color:#fff}
		.lgw-setup .lgw-steps .num{display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;background:rgba(0,0,0,.12);border-radius:50%;margin-right:6px}
		.lgw-setup .card{max-width:760px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px;margin-bottom:16px}
		.lgw-setup .src-opt{display:block;border:2px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:10px 0;cursor:pointer}
		.lgw-setup .src-opt:hover{border-color:#072a82}
		.lgw-setup .src-opt input{margin-right:8px}
		.lgw-setup .src-opt strong{font-size:15px}
		.lgw-setup .src-opt .desc{color:#666;margin:4px 0 0 26px}
		.lgw-setup table.divs{width:100%;border-collapse:collapse;margin:8px 0}
		.lgw-setup table.divs th,.lgw-setup table.divs td{text-align:left;padding:6px 8px}
		.lgw-setup table.divs input[type=text],.lgw-setup table.divs input[type=url]{width:100%}
		.lgw-setup textarea{width:100%;min-height:90px}
		.lgw-setup .actions{margin-top:18px}
	</style>
	<?php
}

function lgw_setup_render_step1() {
	$cur        = get_option( 'lgw_setup_choice', '' );
	$configured = lgw_setup_is_configured();
	$season     = function_exists( 'lgw_get_active_season' ) ? lgw_get_active_season() : null;
	$cur_src    = get_option( 'lgw_data_source', 'google_sheets' );
	$div_count  = count( $season['divisions'] ?? array() );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card">
		<?php wp_nonce_field( 'lgw_setup_wizard' ); ?>
		<input type="hidden" name="action" value="lgw_setup_wizard">
		<input type="hidden" name="lgw_setup_step" value="1">
		<?php if ( $configured ) : ?>
			<div class="notice notice-warning inline" style="margin:0 0 16px">
				<p><strong>⚠️ A league is already set up.</strong> Current source: <code><?php echo esc_html( $cur_src ); ?></code>, <?php echo (int) $div_count; ?> division(s) in season “<?php echo esc_html( $season['label'] ?? '—' ); ?>”.</p>
				<p>Re-running the wizard <strong>overwrites</strong> the active season’s division list and data source. Team standings for divisions you re-enter get reset. This cannot be undone. To tweak an existing league instead, use <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LGW_SETUP_PAGE ) ); ?>">League Setup</a>.</p>
				<p><label><input type="checkbox" name="lgw_setup_confirm_overwrite" value="1"> I understand — overwrite the existing league.</label></p>
			</div>
		<?php endif; ?>
		<h2>Where do your league tables come from?</h2>
		<label class="src-opt">
			<input type="radio" name="lgw_setup_source" value="google_sheets" <?php checked( $cur ?: 'google_sheets', 'google_sheets' ); ?>>
			<strong>Google published CSV</strong>
			<div class="desc">Tables live in Google Sheets, published to the web. LGW reads the CSV. Best if you already run standings in Sheets.</div>
		</label>
		<label class="src-opt">
			<input type="radio" name="lgw_setup_source" value="upload" <?php checked( $cur, 'upload' ); ?>>
			<strong>Upload a spreadsheet</strong>
			<div class="desc">Upload a one-off <code>Division,Team</code> CSV. Teams are seeded into the WordPress database — no Google account needed.</div>
		</label>
		<label class="src-opt">
			<input type="radio" name="lgw_setup_source" value="wordpress" <?php checked( $cur, 'wordpress' ); ?>>
			<strong>WordPress database</strong>
			<div class="desc">Type your divisions and team rosters here. Standings build from submitted scorecards. Fully self-contained.</div>
		</label>
		<div class="actions"><button class="button button-primary button-hero">Continue →</button></div>
	</form>
	<?php if ( $configured ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card" style="border-color:#d63638"
	      onsubmit="return confirm('Wipe the current league config and start fresh? This deletes the active season\'s divisions and seeded standings. Cannot be undone.');">
		<?php wp_nonce_field( 'lgw_setup_reset' ); ?>
		<input type="hidden" name="action" value="lgw_setup_reset">
		<h2 style="color:#d63638;margin-top:0">Start fresh</h2>
		<p>Wipe the active season’s divisions, seeded standings, and data-source setting, then begin from a clean slate. Sponsors, theme, players, and scorecards are left untouched.</p>
		<button class="button button-secondary" style="color:#d63638;border-color:#d63638">Wipe &amp; start fresh</button>
	</form>
	<?php endif; ?>
	<?php
}

/** Optional round-robin fixture-generation fields, shared by upload + WP-DB steps. */
function lgw_setup_fixture_fields_html() {
	$next_sat = date( 'Y-m-d', strtotime( 'next saturday' ) );
	?>
	<fieldset style="border:1px solid #dcdcde;border-radius:8px;padding:12px 16px;margin:12px 0">
		<label style="font-weight:600"><input type="checkbox" name="lgw_gen_fixtures" value="1" onchange="var d=this.checked;this.closest('fieldset').querySelectorAll('.lgw-gen-opt').forEach(function(e){e.style.display=d?'':'none'})"> Generate round-robin fixtures</label>
		<div class="lgw-gen-opt" style="display:none;margin-top:10px">
			<p><label><input type="checkbox" name="lgw_gen_double" value="1"> Double round (home &amp; away — each pair plays twice)</label></p>
			<p>
				<label>First round date <input type="date" name="lgw_gen_start" value="<?php echo esc_attr( $next_sat ); ?>"></label>
				&nbsp;&nbsp;
				<label>Weeks between rounds <input type="number" name="lgw_gen_interval" value="1" min="1" style="width:70px"></label>
			</p>
			<p style="color:#666;margin:0">Defaults for all divisions — you can adjust the date/interval per division (or bulk-apply to a group) and preview on the next step. Every team plays every other once (twice if double); odd team count → a bye each round.</p>
		</div>
	</fieldset>
	<?php
}

function lgw_setup_render_step2( $choice ) {
	if ( $choice === 'google_sheets' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card">
			<?php wp_nonce_field( 'lgw_setup_wizard' ); ?>
		<input type="hidden" name="action" value="lgw_setup_wizard">
			<input type="hidden" name="lgw_setup_step" value="2">
			<h2>Google CSV — divisions</h2>
			<p>Paste the <em>published-to-web CSV</em> link for each division (File ▸ Share ▸ Publish to web ▸ CSV).</p>
			<table class="divs" id="lgw-div-rows">
				<tr><th style="width:35%">Division name</th><th>Published CSV URL</th></tr>
				<?php for ( $i = 0; $i < 3; $i++ ) : ?>
				<tr>
					<td><input type="text" name="lgw_div_name[]" placeholder="Division 1"></td>
					<td><input type="url" name="lgw_div_csv_url[]" placeholder="https://docs.google.com/…/pub?output=csv"></td>
				</tr>
				<?php endfor; ?>
			</table>
			<p><button type="button" class="button" onclick="lgwAddDivRow()">+ Add division</button></p>
			<p><label>Optional Sheet ID (for writeback): <input type="text" name="lgw_sheets_id" style="width:340px" placeholder="Google Sheet ID"></label></p>
			<div class="actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-setup&step=1' ) ); ?>" class="button">← Back</a>
				<button class="button button-primary">Continue →</button>
			</div>
		</form>
		<script>
		function lgwAddDivRow(){
			var t=document.getElementById('lgw-div-rows'),r=t.insertRow(-1);
			r.insertCell(0).innerHTML='<input type="text" name="lgw_div_name[]" placeholder="Division">';
			r.insertCell(1).innerHTML='<input type="url" name="lgw_div_csv_url[]" placeholder="https://…/pub?output=csv">';
		}
		</script>
		<?php
	} elseif ( $choice === 'upload' ) {
		?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card">
			<?php wp_nonce_field( 'lgw_setup_wizard' ); ?>
		<input type="hidden" name="action" value="lgw_setup_wizard">
			<input type="hidden" name="lgw_setup_step" value="2">
			<h2>Upload a spreadsheet</h2>
			<p>Provide a <strong>CSV</strong> with two columns — <code>Division</code>, <code>Team</code> — one team per row. Export from Excel/Sheets as CSV first.</p>
			<pre style="background:#f6f7f7;padding:10px;border-radius:6px">Division,Team
Division 1,Ballymena
Division 1,Belmont
Division 2,Cliftonville</pre>
			<p><input type="file" name="lgw_upload" accept=".csv,text/csv" required></p>
			<?php lgw_setup_fixture_fields_html(); ?>
			<div class="actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-setup&step=1' ) ); ?>" class="button">← Back</a>
				<button class="button button-primary">Upload & seed →</button>
			</div>
		</form>
		<?php
	} elseif ( $choice === 'wordpress' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card">
			<?php wp_nonce_field( 'lgw_setup_wizard' ); ?>
		<input type="hidden" name="action" value="lgw_setup_wizard">
			<input type="hidden" name="lgw_setup_step" value="2">
			<h2>WordPress database — divisions & teams</h2>
			<p>Name each division and list its teams, one per line.</p>
			<div id="lgw-wp-divs">
				<?php for ( $i = 0; $i < 2; $i++ ) : ?>
				<div class="card" style="border-style:dashed">
					<p><label>Division name <input type="text" name="lgw_wp_div_name[]" placeholder="Division <?php echo $i + 1; ?>" style="width:280px"></label></p>
					<textarea name="lgw_wp_div_teams[]" placeholder="Ballymena&#10;Belmont&#10;Cliftonville"></textarea>
				</div>
				<?php endfor; ?>
			</div>
			<p><button type="button" class="button" onclick="lgwAddWpDiv()">+ Add division</button></p>
			<?php lgw_setup_fixture_fields_html(); ?>
			<div class="actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-setup&step=1' ) ); ?>" class="button">← Back</a>
				<button class="button button-primary">Seed teams →</button>
			</div>
		</form>
		<script>
		function lgwAddWpDiv(){
			var w=document.getElementById('lgw-wp-divs'),d=document.createElement('div');
			d.className='card';d.style.borderStyle='dashed';
			d.innerHTML='<p><label>Division name <input type="text" name="lgw_wp_div_name[]" placeholder="Division" style="width:280px"></label></p>'+
				'<textarea name="lgw_wp_div_teams[]" placeholder="Team one\nTeam two"></textarea>';
			w.appendChild(d);
		}
		</script>
		<?php
	} else {
		?>
		<div class="card"><p>Session expired. <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-setup&step=1' ) ); ?>">Start again →</a></p></div>
		<?php
	}
}

function lgw_setup_render_step3( $choice ) {
	$src_label = array(
		'google_sheets' => 'Google published CSV',
		'upload'        => 'Uploaded spreadsheet → WordPress DB',
		'wordpress'     => 'WordPress database',
	);
	$pending = lgw_setup_pending_get();
	$divs    = $pending['divisions'] ?? array();
	$season  = function_exists( 'lgw_get_active_season' ) ? lgw_get_active_season() : null;
	$label   = $season['label'] ?? ( date( 'Y' ) . ' Season' );
	$fx_on   = ! in_array( $choice, array( 'google_sheets', '' ), true ) && lgw_setup_fx_get()['on'];
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card">
		<?php wp_nonce_field( 'lgw_setup_wizard' ); ?>
		<input type="hidden" name="action" value="lgw_setup_wizard">
		<input type="hidden" name="lgw_setup_step" value="3">
		<h2>Review</h2>
		<p style="color:#666">Nothing is saved until you hit <strong>Finish</strong> — your current league stays intact if you go back.</p>
		<p><strong>Data source:</strong> <?php echo esc_html( $src_label[ $choice ] ?? $choice ); ?></p>
		<p><strong>Season:</strong> <?php echo esc_html( $label ); ?></p>
		<p><strong>Divisions (<?php echo count( $divs ); ?>):</strong></p>

		<?php if ( ! $fx_on ) : ?>
			<ul style="list-style:disc;margin-left:22px">
				<?php foreach ( $divs as $d ) : ?>
					<li><?php echo esc_html( $d['division'] ); ?><?php echo ! empty( $d['csv_url'] ) ? ' <span style="color:#666">(fixtures from CSV)</span>' : ''; ?></li>
				<?php endforeach; ?>
				<?php if ( empty( $divs ) ) echo '<li>None configured.</li>'; ?>
			</ul>
		<?php else : ?>
			<p>Set the first-round date and interval per division, or tick divisions and bulk-apply. “Regenerate” refreshes the preview; “Finish” writes the fixtures.</p>

			<div class="card" style="background:#f6f7f7;padding:12px 16px">
				<strong>Bulk apply to ticked divisions:</strong>
				<label style="margin-left:8px">Date <input type="date" id="lgw-bulk-date"></label>
				<label style="margin-left:8px">Weeks <input type="number" id="lgw-bulk-weeks" min="1" value="1" style="width:64px"></label>
				<label style="margin-left:8px"><input type="checkbox" id="lgw-bulk-double"> Double</label>
				<button type="button" class="button" style="margin-left:8px" onclick="lgwBulkApply()">Apply to ticked</button>
				<label style="margin-left:16px"><input type="checkbox" id="lgw-check-all" onchange="lgwCheckAll(this.checked)"> Tick all</label>
			</div>

			<table class="divs" style="margin-top:12px">
				<tr><th style="width:28px"></th><th>Division</th><th>Teams</th><th>First round</th><th>Weeks</th><th>Double</th><th>Fixtures</th></tr>
				<?php foreach ( $divs as $d ) :
					$name  = $d['division'];
					$teams = $pending['teams'][ $name ] ?? array();
					$s     = lgw_setup_fx_for_division( $name );
					$ts    = strtotime( $s['start'] ) ?: time();
					$prev  = count( $teams ) >= 2 ? lgw_setup_generate_fixtures( $teams, $s['double'], $ts, $s['weeks'] * 7 ) : array();
					$rounds = array();
					foreach ( $prev as $f ) $rounds[ $f['date'] ][] = $f;
				?>
				<tr>
					<td><input type="checkbox" class="lgw-div-check" data-div="<?php echo esc_attr( $name ); ?>"></td>
					<td><strong><?php echo esc_html( $name ); ?></strong></td>
					<td><?php echo count( $teams ); ?></td>
					<td><input type="date" name="lgw_fx_start[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( $s['start'] ); ?>" data-div="<?php echo esc_attr( $name ); ?>" class="lgw-fx-start"></td>
					<td><input type="number" name="lgw_fx_weeks[<?php echo esc_attr( $name ); ?>]" value="<?php echo (int) $s['weeks']; ?>" min="1" style="width:64px" class="lgw-fx-weeks" data-div="<?php echo esc_attr( $name ); ?>"></td>
					<td style="text-align:center"><input type="checkbox" name="lgw_fx_double[<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( $s['double'] ); ?> class="lgw-fx-double" data-div="<?php echo esc_attr( $name ); ?>"></td>
					<td><?php echo count( $prev ); ?></td>
				</tr>
				<tr>
					<td></td>
					<td colspan="6">
						<?php if ( $prev ) : ?>
						<details>
							<summary style="cursor:pointer;color:#072a82"><?php echo count( $prev ); ?> fixtures over <?php echo count( $rounds ); ?> rounds — preview</summary>
							<div style="max-height:220px;overflow:auto;margin-top:6px">
								<?php foreach ( $rounds as $date => $games ) : ?>
									<p style="margin:6px 0 2px"><strong><?php echo esc_html( $date ); ?></strong></p>
									<ul style="list-style:none;margin:0 0 6px;padding:0;color:#333">
										<?php foreach ( $games as $g ) : ?>
											<li><?php echo esc_html( $g['homeTeam'] ); ?> <span style="color:#999">v</span> <?php echo esc_html( $g['awayTeam'] ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endforeach; ?>
							</div>
						</details>
						<?php else : ?>
							<span style="color:#999">Need ≥ 2 teams to generate fixtures.</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<div class="actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-setup&step=2' ) ); ?>" class="button">← Back</a>
			<?php if ( $fx_on ) : ?>
				<button class="button" name="lgw_setup_action" value="regen">↻ Regenerate preview</button>
			<?php endif; ?>
			<button class="button button-primary button-hero" name="lgw_setup_action" value="finish">Finish setup ✓</button>
		</div>
	</form>
	<?php if ( $fx_on ) : ?>
	<script>
	function lgwCheckAll(v){document.querySelectorAll('.lgw-div-check').forEach(function(c){c.checked=v;});}
	function lgwBulkApply(){
		var d=document.getElementById('lgw-bulk-date').value,
		    w=document.getElementById('lgw-bulk-weeks').value,
		    db=document.getElementById('lgw-bulk-double').checked;
		document.querySelectorAll('.lgw-div-check').forEach(function(c){
			if(!c.checked)return;
			var dv=c.getAttribute('data-div'),q=function(cl){return document.querySelector(cl+'[data-div="'+CSS.escape(dv)+'"]');};
			if(d)q('.lgw-fx-start').value=d;
			if(w)q('.lgw-fx-weeks').value=w;
			q('.lgw-fx-double').checked=db;
		});
	}
	</script>
	<?php endif; ?>
	<?php
}

function lgw_setup_render_done() {
	?>
	<div class="card">
		<h2>✅ Setup complete</h2>
		<p>LGW is configured. Drop a division table into any page or post with a shortcode:</p>
		<pre style="background:#f6f7f7;padding:10px;border-radius:6px">[lgw_division division="Division 1"]</pre>
		<p>Next steps:</p>
		<ul style="list-style:disc;margin-left:22px">
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . LGW_SETUP_PAGE ) ); ?>">League Setup</a> — fine-tune divisions, cache, API keys, Drive/Sheets.</li>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-settings' ) ); ?>">Settings</a> — theme, sponsors, club badges.</li>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-table-compare' ) ); ?>">Table Compare</a> — verify WP vs Sheets data.</li>
		</ul>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-setup&step=1' ) ); ?>" class="button">Re-run wizard</a></p>
	</div>
	<?php
}
