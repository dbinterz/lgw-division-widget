<?php
/**
 * lgw-entry.php — Championship entry form, ledger and payments.
 *
 * Lets players self-enter championships (or club admins enter on their behalf,
 * per club policy) instead of an admin hand-pasting "Name(s), Club" lines into
 * the gchamp textarea. Every entry becomes a structured `lgw_entry` post (the
 * ledger: status / amount / payment ref / audit), and a confirmed/paid entry is
 * PROJECTED into the existing `lgw_gchamp_<id>.entries[]` so the draw/bracket
 * engine is completely untouched.
 *
 * Slices:
 *   1. `lgw_entry` CPT + per-champ config + admin list + projection helper.
 *   2. Front-end [lgw_champ_entry] form, login gate, per-club entry policy, free path.
 *   3. Stripe Checkout (raw REST, no SDK) + webhook → flips pending_payment→paid.
 *
 * Reuses: lgw_get_clubs(), lgw_user_can_submit_for() (lgw-club-access.php),
 * lgw_gchamp_norm_entry()/short_name()/entry_club() (lgw-gchamp.php),
 * lgw_audit_log() (lgw-sc-admin.php).
 *
 * @package LGW
 * @since 2026.31.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants ────────────────────────────────────────────────────────────────
const LGW_ENTRY_CPT = 'lgw_entry';

// Ledger meta keys
const LGW_ENTRY_META_CHAMP      = 'lgw_entry_champ';
// v2026.31.7 — bulk entry supports multiple competitions in one basket / payment.
const LGW_ENTRY_META_DISCIPLINE = 'lgw_entry_discipline';
const LGW_ENTRY_META_PLAYERS    = 'lgw_entry_players';
const LGW_ENTRY_META_CLUB       = 'lgw_entry_club';
const LGW_ENTRY_META_STRING     = 'lgw_entry_string';
const LGW_ENTRY_META_CONTACT    = 'lgw_entry_contact';
const LGW_ENTRY_META_PREFS      = 'lgw_entry_prefs';
const LGW_ENTRY_META_STATUS     = 'lgw_entry_status';     // pending_payment|paid|confirmed|withdrawn|refunded
const LGW_ENTRY_META_AMOUNT     = 'lgw_entry_amount';     // pence
const LGW_ENTRY_META_CURRENCY   = 'lgw_entry_currency';
const LGW_ENTRY_META_PAYREF     = 'lgw_entry_payment_ref';
const LGW_ENTRY_META_BY         = 'lgw_entry_submitted_by';
const LGW_ENTRY_META_CREATED    = 'lgw_entry_created';
const LGW_ENTRY_META_UPDATED    = 'lgw_entry_updated';
const LGW_ENTRY_META_PROJECTED  = 'lgw_entry_projected';  // '1' once written into the championship entries[]
const LGW_ENTRY_META_BATCH      = 'lgw_entry_batch';      // bulk-submission batch id (combined Stripe checkout)

// Hard dependency: the championship-engine abstraction (lgw-champ-engine.php).
// The module loader loads it before this file; require defensively so the
// projection layer is always available regardless of load context (and so unit
// tests can load lgw-entry.php standalone).
if ( ! function_exists( 'lgw_champ_engine' ) && file_exists( __DIR__ . '/lgw-champ-engine.php' ) ) {
	require_once __DIR__ . '/lgw-champ-engine.php';
}

// ── CPT: the ledger (mirrors lgw_scorecard) ──────────────────────────────────
add_action( 'init', 'lgw_entry_register_cpt' );
function lgw_entry_register_cpt() {
	register_post_type( LGW_ENTRY_CPT, array(
		'labels'       => array( 'name' => 'Entries', 'singular_name' => 'Entry' ),
		'public'       => false,
		'show_ui'      => true,
		'show_in_menu' => false,
		'supports'     => array( 'title' ),
		'capabilities' => array( 'create_posts' => 'manage_options' ),
		'map_meta_cap' => true,
	) );
}

// ── Discipline → player-count map ─────────────────────────────────────────────
/** Ordered discipline labels — mirrors the gchamp discipline enum. */
function lgw_entry_disciplines() {
	return array( 'singles' => 'Singles', 'pairs' => 'Pairs', 'triples' => 'Triples', 'fours' => 'Fours' );
}
/** Number of named players an entry of this discipline carries (1..4). */
function lgw_entry_player_count( $discipline ) {
	switch ( $discipline ) {
		case 'pairs':   return 2;
		case 'triples': return 3;
		case 'fours':   return 4;
		default:        return 1; // singles / unknown
	}
}

// ── Championship lookup ───────────────────────────────────────────────────────
/** Load a gchamp option by id, or false if it does not exist / is malformed. */
function lgw_entry_get_champ( $champ_id ) {
	$champ_id = sanitize_key( $champ_id );
	if ( $champ_id === '' ) return false;
	// Engine-agnostic: resolves gchamp or legacy champ (lgw-champ-engine.php).
	if ( function_exists( 'lgw_champ_engine' ) ) {
		$engine = lgw_champ_engine( $champ_id );
		return $engine ? ( $engine->get( $champ_id ) ?: false ) : false;
	}
	// Fallback if the engine module is absent — gchamp only.
	$champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
	return ( is_array( $champ ) && isset( $champ['title'] ) ) ? $champ : false;
}

// ── Per-champ entry config (separate option — keeps the gchamp option lean) ───
/** Config defaults merged over the stored `lgw_entry_cfg_{champ}` option. */
function lgw_entry_cfg( $champ_id ) {
	$champ_id = sanitize_key( $champ_id );
	$stored   = get_option( 'lgw_entry_cfg_' . $champ_id, array() );
	if ( ! is_array( $stored ) ) $stored = array();
	return array_merge( array(
		'fee'             => 0,      // pence; 0 = free
		'currency'        => 'gbp',
		'open'            => false,  // master on/off for the form
		'deadline'        => '',     // unix timestamp or ''
		'capacity'        => 0,      // 0 = unlimited
		'policy_override' => '',     // ''|'open'|'club_admin' — overrides per-club policy for this champ
	), $stored );
}

/** Persist a sanitised config array for a champ. */
function lgw_entry_save_cfg( $champ_id, array $in ) {
	$champ_id = sanitize_key( $champ_id );
	if ( $champ_id === '' ) return false;
	$cfg = array(
		'fee'             => max( 0, intval( $in['fee'] ?? 0 ) ),
		'currency'        => sanitize_key( $in['currency'] ?? 'gbp' ) ?: 'gbp',
		'open'            => ! empty( $in['open'] ),
		'deadline'        => $in['deadline'] !== '' ? intval( $in['deadline'] ) : '',
		'capacity'        => max( 0, intval( $in['capacity'] ?? 0 ) ),
		'policy_override' => in_array( $in['policy_override'] ?? '', array( 'open', 'club_admin' ), true ) ? $in['policy_override'] : '',
	);
	return update_option( 'lgw_entry_cfg_' . $champ_id, $cfg );
}

/** Count ledger entries for a champ in the given statuses (default: everything live). */
function lgw_entry_count( $champ_id, array $statuses = array( 'pending_payment', 'paid', 'confirmed' ) ) {
	$posts = get_posts( array(
		'post_type'      => LGW_ENTRY_CPT,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array( 'key' => LGW_ENTRY_META_CHAMP, 'value' => sanitize_key( $champ_id ) ),
			array( 'key' => LGW_ENTRY_META_STATUS, 'value' => $statuses, 'compare' => 'IN' ),
		),
	) );
	return count( $posts );
}

/**
 * Is the entry window open for this champ right now?
 * Returns array( bool $open, string $reason ) — reason is a user-facing note when closed.
 */
function lgw_entry_window( $champ_id ) {
	$cfg = lgw_entry_cfg( $champ_id );
	if ( empty( $cfg['open'] ) )                                   return array( false, 'Entries are not open for this championship.' );
	if ( $cfg['deadline'] !== '' && time() > (int) $cfg['deadline'] ) return array( false, 'The entry deadline has passed.' );
	if ( $cfg['capacity'] > 0 && lgw_entry_count( $champ_id ) >= $cfg['capacity'] ) return array( false, 'This championship is full.' );
	return array( true, '' );
}

// ── Entry-string builder ──────────────────────────────────────────────────────
/**
 * Build the canonical "Name(s), Club" string the draw consumes. Commas are
 * stripped from names so the first comma reliably delimits the club (the draw's
 * lgw_gchamp_entry_club() splits on the first comma).
 *
 * @param string[] $players
 * @param string   $club
 */
function lgw_entry_build_string( array $players, $club ) {
	$clean = array();
	foreach ( $players as $p ) {
		$p = trim( preg_replace( '/\s+/', ' ', str_replace( ',', ' ', (string) $p ) ) );
		if ( $p !== '' ) $clean[] = $p;
	}
	$names = implode( ' & ', $clean );
	$club  = trim( preg_replace( '/\s+/', ' ', (string) $club ) );
	return $names . ', ' . $club;
}

// ── Per-club entry policy ─────────────────────────────────────────────────────
/**
 * Effective entry policy for a club within a champ: 'open' or 'club_admin'.
 * Precedence: champ policy_override → per-club record → global default → 'open'.
 */
function lgw_entry_policy_for_club( $club_name, $champ_id = '' ) {
	if ( $champ_id !== '' ) {
		$cfg = lgw_entry_cfg( $champ_id );
		if ( $cfg['policy_override'] !== '' ) return $cfg['policy_override'];
	}
	if ( function_exists( 'lgw_get_clubs' ) ) {
		foreach ( lgw_get_clubs() as $c ) {
			if ( isset( $c['name'] ) && strcasecmp( trim( $c['name'] ), trim( (string) $club_name ) ) === 0 ) {
				$p = $c['entry_policy'] ?? '';
				if ( in_array( $p, array( 'open', 'club_admin' ), true ) ) return $p;
				break;
			}
		}
	}
	$default = get_option( 'lgw_entry_default_policy', 'open' );
	return in_array( $default, array( 'open', 'club_admin' ), true ) ? $default : 'open';
}

/**
 * May $uid submit an entry naming $club_name in this champ?
 * - WP admins: always.
 * - policy 'open':       any logged-in user.
 * - policy 'club_admin': only an approved club admin for that club
 *   (reuses lgw_user_can_submit_for(), which handles the "Ards"↔"Ards A" match).
 */
function lgw_entry_user_may_submit( $club_name, $champ_id = '', $uid = null ) {
	$uid = $uid ?: get_current_user_id();
	if ( ! $uid ) return false;
	if ( user_can( $uid, 'manage_options' ) ) return true;
	$policy = lgw_entry_policy_for_club( $club_name, $champ_id );
	if ( 'club_admin' === $policy ) {
		return function_exists( 'lgw_user_can_submit_for' ) && lgw_user_can_submit_for( $club_name, $uid );
	}
	return true; // 'open' → any logged-in user
}

// ── Duplicate detection ───────────────────────────────────────────────────────
/** True if this entry-string already exists (live ledger row OR already in the draw). */
function lgw_entry_is_duplicate( $champ_id, $entry_string ) {
	$norm = lgw_entry_norm( $entry_string );

	$rows = get_posts( array(
		'post_type'      => LGW_ENTRY_CPT,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array( 'key' => LGW_ENTRY_META_CHAMP, 'value' => sanitize_key( $champ_id ) ),
			array( 'key' => LGW_ENTRY_META_STATUS, 'value' => array( 'withdrawn', 'refunded' ), 'compare' => 'NOT IN' ),
		),
	) );
	foreach ( $rows as $rid ) {
		if ( lgw_entry_norm( get_post_meta( $rid, LGW_ENTRY_META_STRING, true ) ) === $norm ) return true;
	}

	$champ = lgw_entry_get_champ( $champ_id );
	if ( $champ ) {
		foreach ( $champ['entries'] ?? array() as $e ) {
			if ( lgw_entry_norm( $e ) === $norm ) return true;
		}
	}
	return false;
}

/** Normalise an entry string for comparison (shared with the champ-engine layer). */
function lgw_entry_norm( $s ) {
	if ( function_exists( 'lgw_champ_engine_norm' ) ) return lgw_champ_engine_norm( $s );
	if ( function_exists( 'lgw_gchamp_norm_entry' ) ) return strtolower( lgw_gchamp_norm_entry( $s ) );
	return strtolower( preg_replace( '/\s+/', ' ', trim( (string) $s ) ) );
}

// ── Projection: ledger → gchamp entries[] (the ONLY writer of gchamp entries) ─
/**
 * Append a confirmed/paid entry into its championship's entries[] (+ preferences).
 * Pre-draw only: if the draw is already complete we do NOT reshape brackets from
 * here — the entry is flagged for manual placement on the admin list instead.
 *
 * @return true|WP_Error
 */
function lgw_entry_project( $entry_id ) {
	$champ_id = get_post_meta( $entry_id, LGW_ENTRY_META_CHAMP, true );
	$string   = get_post_meta( $entry_id, LGW_ENTRY_META_STRING, true );
	if ( ! $champ_id || ! $string ) return new WP_Error( 'lgw_entry', 'Entry is missing champ or string.' );

	$engine = lgw_champ_engine( $champ_id );
	if ( ! $engine ) return new WP_Error( 'lgw_entry', 'Championship not found.' );

	$prefs = get_post_meta( $entry_id, LGW_ENTRY_META_PREFS, true );
	$res   = $engine->append_entry( $champ_id, $string, is_array( $prefs ) ? $prefs : array() );

	if ( is_wp_error( $res ) ) {
		if ( $res->get_error_code() === 'draw_started' ) {
			// Draw already run — appending would require a re-draw (wipes scores).
			update_post_meta( $entry_id, LGW_ENTRY_META_PROJECTED, 'needs_placement' );
			lgw_audit_log( $entry_id, 'project_deferred', 'Draw already started — needs manual placement by an admin.' );
			return true;
		}
		return $res;
	}

	update_post_meta( $entry_id, LGW_ENTRY_META_PROJECTED, '1' );
	lgw_audit_log( $entry_id, 'projected', 'Added "' . $string . '" to ' . $champ_id . ' (' . $engine->name() . ') entries.' );
	return true;
}

/**
 * Remove an entry-string from its championship's entries[] (+ preferences).
 * Pre-draw only; post-draw removal must go through the championship's own tool.
 */
function lgw_entry_unproject( $entry_id ) {
	$champ_id = get_post_meta( $entry_id, LGW_ENTRY_META_CHAMP, true );
	$string   = get_post_meta( $entry_id, LGW_ENTRY_META_STRING, true );
	if ( ! $champ_id || ! $string ) return;

	$engine = lgw_champ_engine( $champ_id );
	if ( ! $engine ) return;

	$res = $engine->remove_entry( $champ_id, $string );
	update_post_meta( $entry_id, LGW_ENTRY_META_PROJECTED, '0' );

	if ( is_wp_error( $res ) ) {
		lgw_audit_log( $entry_id, 'unproject_deferred', 'Draw started — remove via the championship’s withdraw tool.' );
		return;
	}
	lgw_audit_log( $entry_id, 'unprojected', 'Removed "' . $string . '" from ' . $champ_id . ' entries.' );
}

// ── Ledger creation + status transition ───────────────────────────────────────
/**
 * Create a pending ledger row. Returns the new post id or WP_Error.
 *
 * @param array $d keys: champ, discipline, players[], club, string, contact, prefs[], amount, currency, status, uid
 */
function lgw_entry_create( array $d ) {
	$pid = wp_insert_post( array(
		'post_type'   => LGW_ENTRY_CPT,
		'post_status' => 'publish',
		'post_title'  => wp_strip_all_tags( $d['string'] . ' — ' . ( $d['champ'] ?? '' ) ),
	), true );
	if ( is_wp_error( $pid ) ) return $pid;

	update_post_meta( $pid, LGW_ENTRY_META_CHAMP,      sanitize_key( $d['champ'] ) );
	update_post_meta( $pid, LGW_ENTRY_META_DISCIPLINE, sanitize_key( $d['discipline'] ?? '' ) );
	update_post_meta( $pid, LGW_ENTRY_META_PLAYERS,    array_values( array_map( 'sanitize_text_field', (array) ( $d['players'] ?? array() ) ) ) );
	update_post_meta( $pid, LGW_ENTRY_META_CLUB,       sanitize_text_field( $d['club'] ?? '' ) );
	update_post_meta( $pid, LGW_ENTRY_META_STRING,     $d['string'] );
	update_post_meta( $pid, LGW_ENTRY_META_CONTACT,    sanitize_text_field( $d['contact'] ?? '' ) );
	update_post_meta( $pid, LGW_ENTRY_META_PREFS,      is_array( $d['prefs'] ?? null ) ? $d['prefs'] : array() );
	update_post_meta( $pid, LGW_ENTRY_META_STATUS,     $d['status'] );
	update_post_meta( $pid, LGW_ENTRY_META_AMOUNT,     max( 0, intval( $d['amount'] ?? 0 ) ) );
	update_post_meta( $pid, LGW_ENTRY_META_CURRENCY,   sanitize_key( $d['currency'] ?? 'gbp' ) );
	update_post_meta( $pid, LGW_ENTRY_META_BY,         intval( $d['uid'] ?? get_current_user_id() ) );
	update_post_meta( $pid, LGW_ENTRY_META_CREATED,    time() );
	update_post_meta( $pid, LGW_ENTRY_META_UPDATED,    time() );
	lgw_audit_log( $pid, 'created', 'Status ' . $d['status'] . '.' );
	return $pid;
}

/** Transition an entry to a new status, projecting/unprojecting as needed. */
function lgw_entry_set_status( $entry_id, $status ) {
	$old = get_post_meta( $entry_id, LGW_ENTRY_META_STATUS, true );
	if ( $old === $status ) return;
	update_post_meta( $entry_id, LGW_ENTRY_META_STATUS, $status );
	update_post_meta( $entry_id, LGW_ENTRY_META_UPDATED, time() );
	lgw_audit_log( $entry_id, 'status', $old . ' → ' . $status );

	if ( in_array( $status, array( 'paid', 'confirmed' ), true ) ) {
		lgw_entry_project( $entry_id );
	} elseif ( in_array( $status, array( 'withdrawn', 'refunded' ), true ) ) {
		lgw_entry_unproject( $entry_id );
	}
}

// ── Email ─────────────────────────────────────────────────────────────────────
/** Recipients for new-entry admin notifications (reuses the club-access option). */
function lgw_entry_admin_recipients() {
	$raw  = get_option( 'lgw_admin_notify_emails', get_option( 'admin_email' ) );
	$list = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ), 'is_email' );
	return $list ? array_values( $list ) : array( get_option( 'admin_email' ) );
}

/** Email the entrant that their entry is confirmed/paid. */
function lgw_entry_email_entrant( $entry_id ) {
	$contact = get_post_meta( $entry_id, LGW_ENTRY_META_CONTACT, true );
	$to      = is_email( $contact ) ? $contact : '';
	if ( ! $to ) {
		$uid  = intval( get_post_meta( $entry_id, LGW_ENTRY_META_BY, true ) );
		$user = $uid ? get_userdata( $uid ) : false;
		if ( $user && is_email( $user->user_email ) ) $to = $user->user_email;
	}
	if ( ! $to ) return;
	$champ_id = get_post_meta( $entry_id, LGW_ENTRY_META_CHAMP, true );
	$champ    = lgw_entry_get_champ( $champ_id );
	$title    = $champ ? $champ['title'] : $champ_id;
	$string   = get_post_meta( $entry_id, LGW_ENTRY_META_STRING, true );
	$body     = "Your championship entry has been confirmed.\n\n"
		. "Championship: {$title}\nEntry: {$string}\n\n"
		. "If you did not make this entry, please contact a league administrator.";
	wp_mail( $to, '[LGW] Entry confirmed — ' . $title, $body );
}

/** Notify admins of a new entry. */
function lgw_entry_notify_admins( $entry_id ) {
	$champ_id = get_post_meta( $entry_id, LGW_ENTRY_META_CHAMP, true );
	$champ    = lgw_entry_get_champ( $champ_id );
	$title    = $champ ? $champ['title'] : $champ_id;
	$string   = get_post_meta( $entry_id, LGW_ENTRY_META_STRING, true );
	$status   = get_post_meta( $entry_id, LGW_ENTRY_META_STATUS, true );
	$body     = "A new championship entry has been submitted.\n\n"
		. "Championship: {$title}\nEntry: {$string}\nStatus: {$status}\n";
	wp_mail( lgw_entry_admin_recipients(), '[LGW] New entry — ' . $title, $body );
}

// ─────────────────────────────────────────────────────────────────────────────
// SLICE 2 — Front-end form: [lgw_champ_entry champ="..."]
// ─────────────────────────────────────────────────────────────────────────────

add_shortcode( 'lgw_champ_entry', 'lgw_entry_shortcode' );
function lgw_entry_shortcode( $atts ) {
	$atts     = shortcode_atts( array( 'champ' => '' ), $atts, 'lgw_champ_entry' );
	$champ_id = sanitize_key( $atts['champ'] );

	// Reuse the scorecard palette (this page may carry only this shortcode).
	if ( ! wp_style_is( 'lgw-scorecard', 'registered' ) ) {
		wp_register_style( 'lgw-scorecard', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-scorecard.css', array( 'lgw-saira' ), LGW_VERSION );
	}
	wp_enqueue_style( 'lgw-saira', 'https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700&display=swap', array(), null );
	wp_enqueue_style( 'lgw-scorecard' );

	$open  = '<div class="lgw-submit-card lgw-entry-card" style="max-width:520px">';
	$champ = lgw_entry_get_champ( $champ_id );
	if ( ! $champ ) {
		return $open . '<div class="lgw-notice lgw-notice-error">Championship not found.</div></div>';
	}
	$title = esc_html( $champ['title'] );

	if ( ! is_user_logged_in() ) {
		return $open . '<h3>Enter ' . $title . '</h3>'
			. '<p>Please log in to enter this championship.</p>'
			. '<p><a class="lgw-btn lgw-btn-primary" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a></p></div>';
	}

	list( $win_open, $win_reason ) = lgw_entry_window( $champ_id );
	if ( ! $win_open ) {
		return $open . '<h3>Enter ' . $title . '</h3>'
			. '<div class="lgw-notice lgw-notice-info">' . esc_html( $win_reason ) . '</div></div>';
	}

	$cfg        = lgw_entry_cfg( $champ_id );
	$discipline = $champ['discipline'] ?? 'singles';
	$n_players  = lgw_entry_player_count( $discipline );
	$pref_fields = $champ['preference_fields'] ?? array();
	$clubs      = wp_list_pluck( lgw_get_clubs(), 'name' );
	$nonce      = wp_create_nonce( 'lgw_entry_submit' );
	$fee_note   = $cfg['fee'] > 0
		? '<div class="lgw-notice lgw-notice-info">Entry fee: <strong>' . esc_html( lgw_entry_format_money( $cfg['fee'], $cfg['currency'] ) ) . '</strong> — you will be taken to secure checkout after submitting.</div>'
		: '';

	ob_start(); ?>
	<div class="lgw-submit-card lgw-entry-card" style="max-width:520px">
		<h3>Enter <?php echo $title; ?></h3>
		<p style="font-size:13px;color:#555;margin:0 0 12px"><?php echo esc_html( ucfirst( $discipline ) ); ?> — enter <?php echo $n_players === 1 ? 'your name' : 'all ' . (int) $n_players . ' player names'; ?>, your club and contact email.</p>
		<?php echo $fee_note; // phpcs:ignore ?>
		<form id="lgw-entry-form">
			<?php for ( $i = 0; $i < $n_players; $i++ ) : ?>
				<div class="lgw-form-row">
					<label for="lgw-entry-p<?php echo $i; ?>"><?php echo $n_players === 1 ? 'Player name' : 'Player ' . ( $i + 1 ); ?></label>
					<input type="text" id="lgw-entry-p<?php echo $i; ?>" name="players[]" required>
				</div>
			<?php endfor; ?>
			<div class="lgw-form-row">
				<label for="lgw-entry-club">Club</label>
				<select id="lgw-entry-club" name="club" required>
					<option value="">— select your club —</option>
					<?php foreach ( $clubs as $c ) printf( '<option value="%s">%s</option>', esc_attr( $c ), esc_html( $c ) ); ?>
				</select>
			</div>
			<div class="lgw-form-row">
				<label for="lgw-entry-contact">Contact email</label>
				<input type="email" id="lgw-entry-contact" name="contact" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
			</div>
			<?php if ( in_array( 'date', $pref_fields, true ) ) : ?>
				<div class="lgw-form-row">
					<label for="lgw-entry-pref-date">Preferred date (optional)</label>
					<input type="text" id="lgw-entry-pref-date" name="pref_date" placeholder="e.g. 12/07/26">
				</div>
			<?php endif; ?>
			<?php if ( in_array( 'location', $pref_fields, true ) ) : ?>
				<div class="lgw-form-row">
					<label for="lgw-entry-pref-loc">Preferred location (optional)</label>
					<input type="text" id="lgw-entry-pref-loc" name="pref_location">
				</div>
			<?php endif; ?>
			<p style="margin:0"><button type="submit" class="lgw-btn lgw-btn-primary"><?php echo $cfg['fee'] > 0 ? 'Enter &amp; pay' : 'Submit entry'; ?></button></p>
			<p id="lgw-entry-msg" role="status" aria-live="polite" style="margin:12px 0 0"></p>
		</form>
	</div>
	<script>
	(function(){
		var f=document.getElementById('lgw-entry-form'); if(!f) return;
		f.addEventListener('submit',function(e){
			e.preventDefault();
			var msg=document.getElementById('lgw-entry-msg');
			msg.className='lgw-notice lgw-notice-info'; msg.textContent='Submitting…';
			var btn=f.querySelector('button[type=submit]'); if(btn) btn.disabled=true;
			var data=new FormData(f);
			data.append('action','lgw_entry_submit');
			data.append('champ',<?php echo wp_json_encode( $champ_id ); ?>);
			data.append('nonce',<?php echo wp_json_encode( $nonce ); ?>);
			data.append('return_url', window.location.href);
			fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,{method:'POST',credentials:'same-origin',body:data})
			.then(function(r){return r.json();})
			.then(function(j){
				if(j.success){
					if(j.data && j.data.checkout_url){
						msg.className='lgw-notice lgw-notice-info'; msg.textContent='Redirecting to secure checkout…';
						window.location.href=j.data.checkout_url; return;
					}
					f.style.display='none';
					msg.className='lgw-notice lgw-notice-ok';
					msg.textContent='✅ '+((j.data&&j.data.message)||'Entry received.');
				} else {
					if(btn) btn.disabled=false;
					msg.className='lgw-notice lgw-notice-error';
					msg.textContent='⚠️ '+(j.data||'Could not submit your entry.');
				}
			}).catch(function(){
				if(btn) btn.disabled=false;
				msg.className='lgw-notice lgw-notice-error'; msg.textContent='⚠️ Network error — please try again.';
			});
		});
	})();
	</script>
	<?php
	return ob_get_clean();
}

/** Format pence into a currency string (e.g. 500 → "£5.00"). */
function lgw_entry_format_money( $pence, $currency = 'gbp' ) {
	$symbols = array( 'gbp' => '£', 'eur' => '€', 'usd' => '$' );
	$sym     = $symbols[ strtolower( $currency ) ] ?? strtoupper( $currency ) . ' ';
	return $sym . number_format( $pence / 100, 2 );
}

// ── AJAX: submit an entry (logged-in only) ────────────────────────────────────
add_action( 'wp_ajax_lgw_entry_submit', 'lgw_ajax_entry_submit' );
add_action( 'wp_ajax_nopriv_lgw_entry_submit', 'lgw_ajax_entry_submit_nopriv' );
function lgw_ajax_entry_submit_nopriv() { wp_send_json_error( 'Please log in to enter.' ); }

function lgw_ajax_entry_submit() {
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in to enter.' );
	check_ajax_referer( 'lgw_entry_submit', 'nonce' );

	$uid = get_current_user_id();
	if ( get_transient( 'lgw_entry_rl_' . $uid ) ) wp_send_json_error( 'Please wait a moment before submitting again.' );

	$champ_id = sanitize_key( $_POST['champ'] ?? '' );
	$champ    = lgw_entry_get_champ( $champ_id );
	if ( ! $champ ) wp_send_json_error( 'Championship not found.' );

	list( $win_open, $win_reason ) = lgw_entry_window( $champ_id );
	if ( ! $win_open ) wp_send_json_error( $win_reason );

	$discipline = $champ['discipline'] ?? 'singles';
	$need       = lgw_entry_player_count( $discipline );

	$players_raw = (array) ( $_POST['players'] ?? array() );
	$players     = array();
	foreach ( $players_raw as $p ) {
		$p = sanitize_text_field( wp_unslash( $p ) );
		if ( $p !== '' ) $players[] = $p;
	}
	if ( count( $players ) !== $need ) {
		wp_send_json_error( sprintf( 'Please enter %d player name%s.', $need, $need === 1 ? '' : 's' ) );
	}

	// Club must be one of the configured clubs.
	$configured = wp_list_pluck( lgw_get_clubs(), 'name' );
	$club       = sanitize_text_field( wp_unslash( $_POST['club'] ?? '' ) );
	$match      = '';
	foreach ( $configured as $c ) { if ( strcasecmp( $c, $club ) === 0 ) { $match = $c; break; } }
	if ( $match === '' ) wp_send_json_error( 'Please select your club.' );
	$club = $match;

	// Per-club policy re-check (server-side authority).
	if ( ! lgw_entry_user_may_submit( $club, $champ_id, $uid ) ) {
		wp_send_json_error( 'Entries for ' . esc_html( $club ) . ' must be made by an approved club administrator.' );
	}

	$contact = sanitize_text_field( wp_unslash( $_POST['contact'] ?? '' ) );
	if ( ! is_email( $contact ) ) wp_send_json_error( 'Please enter a valid contact email.' );

	$string = lgw_entry_build_string( $players, $club );
	if ( lgw_entry_is_duplicate( $champ_id, $string ) ) {
		wp_send_json_error( 'That entry (' . esc_html( $string ) . ') has already been entered.' );
	}

	// Non-blocking capitation warning: names not in the club's tracked player list.
	$capitation = lgw_entry_unknown_players( $club, $players );

	$prefs = array();
	if ( ! empty( $_POST['pref_date'] ) )     $prefs['date']     = sanitize_text_field( wp_unslash( $_POST['pref_date'] ) );
	if ( ! empty( $_POST['pref_location'] ) ) $prefs['location'] = sanitize_text_field( wp_unslash( $_POST['pref_location'] ) );

	$cfg    = lgw_entry_cfg( $champ_id );
	$paid   = $cfg['fee'] > 0;
	$status = $paid ? 'pending_payment' : 'confirmed';

	$entry_id = lgw_entry_create( array(
		'champ'      => $champ_id,
		'discipline' => $discipline,
		'players'    => $players,
		'club'       => $club,
		'string'     => $string,
		'contact'    => $contact,
		'prefs'      => $prefs,
		'amount'     => $paid ? $cfg['fee'] : 0,
		'currency'   => $cfg['currency'],
		'status'     => $status,
		'uid'        => $uid,
	) );
	if ( is_wp_error( $entry_id ) ) wp_send_json_error( 'Could not save your entry. Please try again.' );

	if ( $capitation ) lgw_audit_log( $entry_id, 'capitation_warning', 'Not in club player list: ' . implode( '; ', $capitation ) );
	$warn = lgw_entry_capitation_message( $club, $capitation );

	set_transient( 'lgw_entry_rl_' . $uid, 1, 30 );

	if ( ! $paid ) {
		lgw_entry_project( $entry_id );
		lgw_entry_email_entrant( $entry_id );
		lgw_entry_notify_admins( $entry_id );
		wp_send_json_success( array( 'message' => 'Your entry has been received.', 'warning' => $warn ) );
	}

	// Paid path — create a Stripe Checkout Session and hand back its URL.
	$return_url = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_url'] ?? '' ) ), home_url( '/' ) );
	$url        = lgw_entry_stripe_create_session( $entry_id, $return_url );
	if ( is_wp_error( $url ) ) {
		// Keep the ledger row as pending_payment so an admin can follow up / mark paid.
		lgw_entry_notify_admins( $entry_id );
		wp_send_json_error( 'Payment could not be started. Your entry is saved as pending — please contact a league administrator. (' . esc_html( $url->get_error_message() ) . ')' );
	}
	lgw_entry_notify_admins( $entry_id );
	wp_send_json_success( array( 'checkout_url' => $url, 'warning' => $warn ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// SLICE 4 — Player validation (capitation warning) + bulk club entry
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Names among $players NOT found in the club's tracked player list.
 * Advisory only — used to warn about possible capitation impact, never to block.
 * Returns [] when the players module is absent (so entry is never gated on it).
 *
 * @return string[] the raw names that did not match a tracked player
 */
function lgw_entry_unknown_players( $club, array $players ) {
	if ( ! function_exists( 'lgw_players_table' ) || ! function_exists( 'lgw_clean_player_name' ) ) return array();
	global $wpdb;
	$tbl     = lgw_players_table();
	$club    = (string) $club;
	$unknown = array();
	foreach ( $players as $p ) {
		$name = lgw_clean_player_name( (string) $p );
		if ( $name === '' ) continue;
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE club = %s AND name = %s", $club, $name ) );
		if ( ! $id ) $unknown[] = trim( (string) $p );
	}
	return $unknown;
}

/** Human capitation warning for a set of unknown names (empty string if none). */
function lgw_entry_capitation_message( $club, array $unknown ) {
	if ( empty( $unknown ) ) return '';
	return sprintf(
		'%s %s not in the %s player list. Please check the spelling — unregistered players may affect your club\'s capitation fees.',
		implode( ', ', $unknown ),
		count( $unknown ) === 1 ? 'is' : 'are',
		$club
	);
}

/**
 * Parse a bulk-entry textarea into entries for a discipline.
 *  - Entries are newline-separated. For singles, commas on a line ALSO separate entries.
 *  - Team events: the members of one entry are separated by "/" (e.g. "A Smith / B Jones").
 *
 * @return array{entries: array<int, array{players: string[], raw: string}>, errors: string[]}
 */
function lgw_entry_parse_bulk( $text, $discipline ) {
	$need    = lgw_entry_player_count( $discipline );
	$entries = array();
	$errors  = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( $line === '' ) continue;
		$units = ( $need === 1 ) ? array_map( 'trim', explode( ',', $line ) ) : array( $line );
		foreach ( $units as $unit ) {
			$unit = trim( $unit );
			if ( $unit === '' ) continue;
			$members = array_values( array_filter( array_map( 'trim', explode( '/', $unit ) ), 'strlen' ) );
			if ( count( $members ) !== $need ) {
				$errors[] = sprintf(
					'"%s" — expected %d player%s%s, got %d.',
					$unit, $need, $need === 1 ? '' : 's',
					$need === 1 ? '' : ' separated by "/"', count( $members )
				);
				continue;
			}
			$entries[] = array( 'players' => $members, 'raw' => $unit );
		}
	}
	return array( 'entries' => $entries, 'errors' => $errors );
}

/** May $uid bulk-enter for $club? Bulk is a club-admin tool: approved club admin OR site admin. */
function lgw_entry_user_may_bulk( $club, $uid = null ) {
	$uid = $uid ?: get_current_user_id();
	if ( ! $uid ) return false;
	if ( user_can( $uid, 'manage_options' ) ) return true;
	return function_exists( 'lgw_user_can_submit_for' ) && lgw_user_can_submit_for( $club, $uid );
}

/** Clubs $uid may bulk-enter for (all configured clubs for site admins). */
function lgw_entry_bulk_clubs_for_user( $uid = null ) {
	$uid   = $uid ?: get_current_user_id();
	$names = wp_list_pluck( lgw_get_clubs(), 'name' );
	if ( user_can( $uid, 'manage_options' ) ) return array_values( $names );
	return array_values( array_filter( $names, static function ( $c ) use ( $uid ) {
		return lgw_entry_user_may_bulk( $c, $uid );
	} ) );
}

add_shortcode( 'lgw_champ_bulk_entry', 'lgw_entry_bulk_shortcode' );
function lgw_entry_bulk_shortcode( $atts ) {
	// `champ` accepts a single id OR a comma/space-separated list of competitions.
	$atts = shortcode_atts( array( 'champ' => '' ), $atts, 'lgw_champ_bulk_entry' );

	if ( ! wp_style_is( 'lgw-scorecard', 'registered' ) ) {
		wp_register_style( 'lgw-scorecard', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-scorecard.css', array( 'lgw-saira' ), LGW_VERSION );
	}
	wp_enqueue_style( 'lgw-saira', 'https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700&display=swap', array(), null );
	wp_enqueue_style( 'lgw-scorecard' );

	$open = '<div class="lgw-submit-card lgw-entry-card" style="max-width:560px">';

	// Resolve the requested competitions (de-duped, order preserved).
	$champs = array();
	foreach ( preg_split( '/[,\s]+/', (string) $atts['champ'] ) as $raw ) {
		$id = sanitize_key( $raw );
		if ( $id === '' || isset( $champs[ $id ] ) ) continue;
		$c = lgw_entry_get_champ( $id );
		if ( $c ) $champs[ $id ] = $c;
	}
	if ( empty( $champs ) ) return $open . '<div class="lgw-notice lgw-notice-error">Championship not found.</div></div>';

	$first   = reset( $champs );
	$heading = count( $champs ) === 1 ? 'Bulk entry — ' . esc_html( $first['title'] ) : 'Bulk entry';

	if ( ! is_user_logged_in() ) {
		return $open . '<h3>' . $heading . '</h3><p>Please log in as a club administrator to enter.</p>'
			. '<p><a class="lgw-btn lgw-btn-primary" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a></p></div>';
	}

	$clubs = lgw_entry_bulk_clubs_for_user();
	if ( empty( $clubs ) ) {
		return $open . '<h3>' . $heading . '</h3>'
			. '<div class="lgw-notice lgw-notice-info">Bulk entry is for approved club administrators. Your account is not an administrator for any club — please use the standard entry form, or contact the league office.</div></div>';
	}

	// Keep only competitions whose entry window is open; remember why the rest are closed.
	$openable = array();
	$closed   = array();
	foreach ( $champs as $id => $c ) {
		list( $wo, $wr ) = lgw_entry_window( $id );
		if ( $wo ) $openable[ $id ] = $c; else $closed[ $id ] = $wr;
	}
	if ( empty( $openable ) ) {
		$reason = count( $champs ) === 1 ? reset( $closed ) : 'Entries are not open for these championships.';
		return $open . '<h3>' . $heading . '</h3><div class="lgw-notice lgw-notice-info">' . esc_html( $reason ) . '</div></div>';
	}

	$nonce   = wp_create_nonce( 'lgw_entry_submit' );
	$any_fee = false;
	foreach ( $openable as $id => $c ) { if ( lgw_entry_cfg( $id )['fee'] > 0 ) { $any_fee = true; break; } }

	ob_start(); ?>
	<div class="lgw-submit-card lgw-entry-card" style="max-width:560px">
		<h3><?php echo $heading; ?></h3>
		<p style="font-size:13px;color:#555;margin:0 0 12px">Enter your club's entrants for <?php echo count( $openable ) === 1 ? 'this competition' : 'each competition'; ?>, one per line. Leave a competition blank to skip it.</p>
		<?php if ( $any_fee ) : ?>
			<div class="lgw-notice lgw-notice-info">Fees are shown per competition below. You'll be taken to a <strong>single secure checkout</strong> covering every paid entry in one payment.</div>
		<?php endif; ?>
		<form id="lgw-bulk-form">
			<div class="lgw-form-row">
				<label for="lgw-bulk-club">Club</label>
				<select id="lgw-bulk-club" name="club" required>
					<?php foreach ( $clubs as $c ) printf( '<option value="%s">%s</option>', esc_attr( $c ), esc_html( $c ) ); ?>
				</select>
			</div>
			<?php foreach ( $openable as $id => $c ) :
				$disc   = $c['discipline'] ?? 'singles';
				$need   = lgw_entry_player_count( $disc );
				$fcfg   = lgw_entry_cfg( $id );
				$feetxt = $fcfg['fee'] > 0 ? lgw_entry_format_money( $fcfg['fee'], $fcfg['currency'] ) . ' per entry' : 'Free';
				$ph     = $need === 1
					? "One name per line (or comma-separated):\nA Smith\nB Jones\nC McKee"
					: "One team per line, players separated by \"/\":\nA Smith / B Jones\nC McKee / D Watt";
			?>
			<fieldset class="lgw-bulk-comp" style="border:1px solid #e2e2e2;border-radius:6px;padding:10px 14px 12px;margin:0 0 12px">
				<legend style="font-weight:600;padding:0 6px;font-size:13px"><?php echo esc_html( $c['title'] ); ?> <span style="font-weight:400;color:#777"> — <?php echo esc_html( ucfirst( $disc ) ); ?> · <?php echo esc_html( $feetxt ); ?></span></legend>
				<?php if ( $need > 1 ) : ?><p style="font-size:12px;color:#777;margin:0 0 6px">Separate the <?php echo (int) $need; ?> players in each entry with "/".</p><?php endif; ?>
				<textarea name="entries[<?php echo esc_attr( $id ); ?>]" rows="5" placeholder="<?php echo esc_attr( $ph ); ?>" style="width:100%;font-family:inherit"
					data-champ="<?php echo esc_attr( $id ); ?>" data-fee="<?php echo (int) $fcfg['fee']; ?>" data-cur="<?php echo esc_attr( $fcfg['currency'] ); ?>" data-need="<?php echo (int) $need; ?>"></textarea>
				<div class="lgw-bulk-sub" data-champ="<?php echo esc_attr( $id ); ?>" style="font-size:12px;color:#555;margin-top:4px;text-align:right">0 entries</div>
			</fieldset>
			<?php endforeach; ?>
			<?php if ( ! empty( $closed ) ) : ?>
				<p style="font-size:12px;color:#999;margin:-4px 0 12px">Not open for entry: <?php echo esc_html( implode( ', ', array_map( function( $id ) use ( $champs ) { return $champs[ $id ]['title']; }, array_keys( $closed ) ) ) ); ?>.</p>
			<?php endif; ?>
			<div class="lgw-form-row">
				<label for="lgw-bulk-contact">Contact email</label>
				<input type="email" id="lgw-bulk-contact" name="contact" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required>
			</div>
			<?php if ( $any_fee ) : ?>
			<div id="lgw-bulk-basket" style="border-top:1px solid #e2e2e2;margin:4px 0 12px;padding-top:10px;display:flex;justify-content:space-between;align-items:baseline">
				<span style="color:#555;font-size:14px"><span id="lgw-bulk-count">0</span> paid entr<span id="lgw-bulk-count-suffix">ies</span> · total</span>
				<strong id="lgw-bulk-total" style="font-size:17px;color:#1a2e5a"><?php echo esc_html( lgw_entry_format_money( 0, 'gbp' ) ); ?></strong>
			</div>
			<?php endif; ?>
			<p style="margin:0"><button type="submit" class="lgw-btn lgw-btn-primary"><span id="lgw-bulk-btnlabel"><?php echo $any_fee ? 'Enter &amp; pay' : 'Enter entries'; ?></span></button></p>
			<p id="lgw-bulk-msg" role="status" aria-live="polite" style="margin:12px 0 0"></p>
		</form>
	</div>
	<script>
	(function(){
		var f=document.getElementById('lgw-bulk-form'); if(!f) return;

		// ── Live basket: count valid entries per competition and total the fees ──
		var SYM={gbp:'£',eur:'€',usd:'$'};
		var DEFLABEL=<?php echo wp_json_encode( $any_fee ? 'Enter & pay' : 'Enter entries' ); ?>;
		function money(p,cur){cur=(cur||'gbp').toLowerCase();var s=SYM[cur]||(cur.toUpperCase()+' ');return s+(p/100).toFixed(2);}
		// Mirror lgw_entry_parse_bulk(): newline units; singles also comma-split; teams need N "/"-separated members.
		function countEntries(txt,need){
			var n=0;
			txt.split(/\n/).forEach(function(line){
				line=line.trim(); if(!line) return;
				(need===1 ? line.split(',') : [line]).forEach(function(u){
					if(need===1){ if(u.trim()!=='') n++; return; }
					var mem=u.split('/').map(function(x){return x.trim();}).filter(Boolean);
					if(mem.length===need) n++;
				});
			});
			return n;
		}
		function recalc(){
			var totals={}, paidCount=0;
			f.querySelectorAll('textarea[data-champ]').forEach(function(t){
				var fee=parseInt(t.getAttribute('data-fee'),10)||0;
				var need=parseInt(t.getAttribute('data-need'),10)||1;
				var cur=t.getAttribute('data-cur')||'gbp';
				var n=countEntries(t.value,need);
				var el=f.querySelector('.lgw-bulk-sub[data-champ="'+t.getAttribute('data-champ')+'"]');
				if(el){ el.textContent = n+(n===1?' entry':' entries')+(fee>0?' · '+money(fee*n,cur):''); }
				if(fee>0){ totals[cur]=(totals[cur]||0)+fee*n; paidCount+=n; }
			});
			var curs=Object.keys(totals);
			var totEl=document.getElementById('lgw-bulk-total');
			if(totEl){ totEl.textContent = curs.length ? curs.map(function(c){return money(totals[c],c);}).join(' + ') : money(0,'gbp'); }
			var cEl=document.getElementById('lgw-bulk-count'); if(cEl) cEl.textContent=paidCount;
			var sEl=document.getElementById('lgw-bulk-count-suffix'); if(sEl) sEl.textContent=(paidCount===1?'y':'ies');
			var lbl=document.getElementById('lgw-bulk-btnlabel');
			if(lbl){ lbl.textContent = (curs.length===1) ? (DEFLABEL+' '+money(totals[curs[0]],curs[0])) : DEFLABEL; }
		}
		f.querySelectorAll('textarea[data-champ]').forEach(function(t){ t.addEventListener('input',recalc); });
		recalc();

		f.addEventListener('submit',function(e){
			e.preventDefault();
			var msg=document.getElementById('lgw-bulk-msg');
			var hasText=false;
			f.querySelectorAll('textarea[name^="entries["]').forEach(function(t){ if(t.value.trim()!=='') hasText=true; });
			if(!hasText){ msg.className='lgw-notice lgw-notice-error'; msg.textContent='⚠️ Please enter at least one entrant in a competition above.'; return; }
			msg.className='lgw-notice lgw-notice-info'; msg.textContent='Submitting…';
			var btn=f.querySelector('button[type=submit]'); if(btn) btn.disabled=true;
			var data=new FormData(f);
			data.append('action','lgw_entry_bulk_submit');
			data.append('champs',<?php echo wp_json_encode( implode( ',', array_keys( $openable ) ) ); ?>);
			data.append('nonce',<?php echo wp_json_encode( $nonce ); ?>);
			data.append('return_url', window.location.href);
			fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,{method:'POST',credentials:'same-origin',body:data})
			.then(function(r){return r.json();})
			.then(function(j){
				if(j.success){
					if(j.data && j.data.checkout_url){
						msg.className='lgw-notice lgw-notice-info'; msg.textContent='Redirecting to secure checkout…';
						window.location.href=j.data.checkout_url; return;
					}
					f.style.display='none';
					msg.className='lgw-notice lgw-notice-ok';
					msg.innerHTML='✅ '+((j.data&&j.data.message)||'Entries received.')+((j.data&&j.data.detail_html)||'');
				} else {
					if(btn) btn.disabled=false;
					msg.className='lgw-notice lgw-notice-error';
					msg.innerHTML='⚠️ '+((j.data&&j.data.message)||j.data||'Could not submit.')+((j.data&&j.data.detail_html)||'');
				}
			}).catch(function(){
				if(btn) btn.disabled=false;
				msg.className='lgw-notice lgw-notice-error'; msg.textContent='⚠️ Network error — please try again.';
			});
		});
	})();
	</script>
	<?php
	return ob_get_clean();
}

// ── AJAX: bulk submit (club admins only) ──────────────────────────────────────
add_action( 'wp_ajax_lgw_entry_bulk_submit', 'lgw_ajax_entry_bulk_submit' );
add_action( 'wp_ajax_nopriv_lgw_entry_bulk_submit', function () { wp_send_json_error( 'Please log in to enter.' ); } );

function lgw_ajax_entry_bulk_submit() {
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in to enter.' );
	check_ajax_referer( 'lgw_entry_submit', 'nonce' );

	$uid = get_current_user_id();
	if ( get_transient( 'lgw_entry_rl_' . $uid ) ) wp_send_json_error( 'Please wait a moment before submitting again.' );

	// Competition list: new `champs` (comma/space list) or legacy single `champ`.
	$raw_list  = wp_unslash( $_POST['champs'] ?? ( $_POST['champ'] ?? '' ) );
	$champ_ids = array();
	foreach ( preg_split( '/[,\s]+/', (string) $raw_list ) as $r ) {
		$id = sanitize_key( $r );
		if ( $id !== '' && ! in_array( $id, $champ_ids, true ) ) $champ_ids[] = $id;
	}
	if ( empty( $champ_ids ) ) wp_send_json_error( 'No championship specified.' );

	// Per-competition entrant text: new assoc array entries[<champ>], or legacy scalar.
	$entries_in = $_POST['entries'] ?? '';
	if ( ! is_array( $entries_in ) ) $entries_in = array( $champ_ids[0] => $entries_in );

	// Club selected once, and the user must be an approved admin for it.
	$configured = wp_list_pluck( lgw_get_clubs(), 'name' );
	$club_in    = sanitize_text_field( wp_unslash( $_POST['club'] ?? '' ) );
	$club       = '';
	foreach ( $configured as $c ) { if ( strcasecmp( $c, $club_in ) === 0 ) { $club = $c; break; } }
	if ( $club === '' ) wp_send_json_error( 'Please select your club.' );
	if ( ! lgw_entry_user_may_bulk( $club, $uid ) ) {
		wp_send_json_error( 'Bulk entry for ' . esc_html( $club ) . ' is restricted to approved club administrators.' );
	}

	$contact = sanitize_text_field( wp_unslash( $_POST['contact'] ?? '' ) );
	if ( ! is_email( $contact ) ) wp_send_json_error( 'Please enter a valid contact email.' );

	$batch      = 'b' . wp_generate_password( 16, false );
	$created    = array(); // all created entry ids (free + paid)
	$paid_ids   = array(); // ids needing payment → the single checkout basket
	$currencies = array(); // distinct currencies among paid entries
	$by_champ   = array(); // champ_id => [ids] for the admin notification
	$dupes      = array();
	$warns      = array();
	$errors     = array();

	foreach ( $champ_ids as $cid ) {
		$champ = lgw_entry_get_champ( $cid );
		if ( ! $champ ) continue;
		list( $win_open ) = lgw_entry_window( $cid );
		if ( ! $win_open ) continue; // silently skip closed comps (form only offered open ones)

		$text = trim( (string) wp_unslash( $entries_in[ $cid ] ?? '' ) );
		if ( $text === '' ) continue; // competition left blank

		$disc   = $champ['discipline'] ?? 'singles';
		$parsed = lgw_entry_parse_bulk( $text, $disc );
		foreach ( $parsed['errors'] as $er ) $errors[] = $champ['title'] . ' — ' . $er;
		if ( empty( $parsed['entries'] ) ) continue;

		$cfg  = lgw_entry_cfg( $cid );
		$paid = $cfg['fee'] > 0;

		foreach ( $parsed['entries'] as $ent ) {
			$string = lgw_entry_build_string( $ent['players'], $club );
			if ( lgw_entry_is_duplicate( $cid, $string ) ) { $dupes[] = $string; continue; }

			$entry_id = lgw_entry_create( array(
				'champ'      => $cid,
				'discipline' => $disc,
				'players'    => $ent['players'],
				'club'       => $club,
				'string'     => $string,
				'contact'    => $contact,
				'prefs'      => array(),
				'amount'     => $paid ? $cfg['fee'] : 0,
				'currency'   => $cfg['currency'],
				'status'     => $paid ? 'pending_payment' : 'confirmed',
				'uid'        => $uid,
			) );
			if ( is_wp_error( $entry_id ) ) continue;
			update_post_meta( $entry_id, LGW_ENTRY_META_BATCH, $batch );

			$unknown = lgw_entry_unknown_players( $club, $ent['players'] );
			if ( $unknown ) {
				lgw_audit_log( $entry_id, 'capitation_warning', 'Not in club player list: ' . implode( '; ', $unknown ) );
				$warns[ $string ] = $unknown;
			}
			if ( $paid ) {
				$paid_ids[] = $entry_id;
				$currencies[ strtolower( $cfg['currency'] ) ] = 1;
			} else {
				lgw_entry_project( $entry_id );
				lgw_entry_email_entrant( $entry_id );
			}
			$created[]           = $entry_id;
			$by_champ[ $cid ][]  = $entry_id;
		}
	}

	if ( empty( $created ) ) {
		wp_send_json_error( array(
			'message'     => 'No new entries were added.',
			'detail_html' => lgw_entry_bulk_detail_html( array( 'dupes' => $dupes, 'errors' => $errors ) ),
		) );
	}

	set_transient( 'lgw_entry_rl_' . $uid, 1, 30 );
	$detail_html = lgw_entry_bulk_detail_html( array( 'warns' => $warns, 'dupes' => $dupes, 'errors' => $errors ) );
	lgw_entry_notify_admins_bulk_multi( $club, $by_champ, $batch );

	if ( ! empty( $paid_ids ) ) {
		// One Stripe basket requires one currency across the paid entries.
		if ( count( $currencies ) > 1 ) {
			wp_send_json_error( array(
				'message'     => 'These competitions use different currencies and cannot be combined in one payment — please enter them separately.',
				'detail_html' => $detail_html,
			) );
		}
		$return_url = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['return_url'] ?? '' ) ), home_url( '/' ) );
		$url        = lgw_entry_stripe_create_batch_session( $batch, $champ_ids[0], $paid_ids, $return_url );
		if ( is_wp_error( $url ) ) {
			wp_send_json_error( array(
				'message'     => count( $paid_ids ) . ' ' . ( count( $paid_ids ) === 1 ? 'entry' : 'entries' ) . ' saved as pending payment, but checkout could not start — please contact the league office. (' . esc_html( $url->get_error_message() ) . ')',
				'detail_html' => $detail_html,
			) );
		}
		wp_send_json_success( array( 'checkout_url' => $url, 'detail_html' => $detail_html ) );
	}

	wp_send_json_success( array(
		'message'     => count( $created ) . ' ' . ( count( $created ) === 1 ? 'entry' : 'entries' ) . ' received for ' . esc_html( $club ) . '.',
		'detail_html' => $detail_html,
	) );
}

/** Build the HTML detail block (warnings / duplicates / parse errors) for a bulk response. */
function lgw_entry_bulk_detail_html( array $parts ) {
	$html = '';
	if ( ! empty( $parts['warns'] ) ) {
		$rows = array();
		foreach ( $parts['warns'] as $string => $names ) {
			$rows[] = '<li>' . esc_html( $string ) . ' — <em>' . esc_html( implode( ', ', $names ) ) . '</em> not in the club player list</li>';
		}
		$html .= '<p style="margin:10px 0 4px"><strong>⚠ Capitation check:</strong> unregistered players may affect your club\'s capitation fees — please check spelling.</p><ul style="margin:0 0 6px 18px">' . implode( '', $rows ) . '</ul>';
	}
	if ( ! empty( $parts['dupes'] ) ) {
		$html .= '<p style="margin:10px 0 4px"><strong>Already entered (skipped):</strong></p><ul style="margin:0 0 6px 18px"><li>' . implode( '</li><li>', array_map( 'esc_html', $parts['dupes'] ) ) . '</li></ul>';
	}
	if ( ! empty( $parts['errors'] ) ) {
		$html .= '<p style="margin:10px 0 4px"><strong>Could not read (skipped):</strong></p><ul style="margin:0 0 6px 18px"><li>' . implode( '</li><li>', array_map( 'esc_html', $parts['errors'] ) ) . '</li></ul>';
	}
	return $html;
}

/**
 * Notify admins of a bulk submission (one email for the whole batch), grouped by
 * competition. $by_champ maps champ_id => [entry ids].
 */
function lgw_entry_notify_admins_bulk_multi( $club, array $by_champ, $batch ) {
	if ( empty( $by_champ ) ) return;
	$total    = 0;
	$sections = array();
	$titles   = array();
	foreach ( $by_champ as $cid => $ids ) {
		$champ    = lgw_entry_get_champ( $cid );
		$title    = $champ ? $champ['title'] : $cid;
		$titles[] = $title;
		$status   = lgw_entry_cfg( $cid )['fee'] > 0 ? 'pending payment' : 'confirmed';
		$lines    = array();
		foreach ( $ids as $id ) $lines[] = ' - ' . get_post_meta( $id, LGW_ENTRY_META_STRING, true );
		$total     += count( $ids );
		$sections[] = "{$title} ({$status}):\n" . implode( "\n", $lines );
	}
	$subject = count( $titles ) === 1
		? '[LGW] Bulk entries — ' . $titles[0]
		: '[LGW] Bulk entries — ' . count( $titles ) . ' competitions';
	$body = "{$total} bulk entries submitted by {$club} (batch {$batch}):\n\n" . implode( "\n\n", $sections ) . "\n";
	wp_mail( lgw_entry_admin_recipients(), $subject, $body );
}

// ─────────────────────────────────────────────────────────────────────────────
// SLICE 3 — Stripe Checkout via raw REST (no SDK) + webhook
// ─────────────────────────────────────────────────────────────────────────────

/** Stripe secret key: wp-config constant wins over the stored option. */
function lgw_entry_stripe_secret() {
	if ( defined( 'LGW_STRIPE_SECRET_KEY' ) && LGW_STRIPE_SECRET_KEY ) return LGW_STRIPE_SECRET_KEY;
	return (string) get_option( 'lgw_stripe_secret_key', '' );
}
/** Stripe webhook signing secret: wp-config constant wins over the stored option. */
function lgw_entry_stripe_webhook_secret() {
	if ( defined( 'LGW_STRIPE_WEBHOOK_SECRET' ) && LGW_STRIPE_WEBHOOK_SECRET ) return LGW_STRIPE_WEBHOOK_SECRET;
	return (string) get_option( 'lgw_stripe_webhook_secret', '' );
}

/**
 * Create a Stripe Checkout Session for an entry. Returns the redirect URL or WP_Error.
 * Uses wp_remote_post with a nested body array (WP http_build_query yields Stripe's
 * bracketed field syntax) — no SDK, no vendored dependency.
 */
function lgw_entry_stripe_create_session( $entry_id, $return_url ) {
	$secret = lgw_entry_stripe_secret();
	if ( ! $secret ) return new WP_Error( 'lgw_stripe', 'Stripe is not configured.' );

	$champ_id = get_post_meta( $entry_id, LGW_ENTRY_META_CHAMP, true );
	$champ    = lgw_entry_get_champ( $champ_id );
	$title    = $champ ? $champ['title'] : $champ_id;
	$string   = get_post_meta( $entry_id, LGW_ENTRY_META_STRING, true );
	$amount   = intval( get_post_meta( $entry_id, LGW_ENTRY_META_AMOUNT, true ) );
	$currency = get_post_meta( $entry_id, LGW_ENTRY_META_CURRENCY, true ) ?: 'gbp';
	if ( $amount <= 0 ) return new WP_Error( 'lgw_stripe', 'Entry has no fee.' );

	$base    = $return_url ?: home_url( '/' );
	$success = add_query_arg( array( 'lgw_entry_paid' => 1, 'session_id' => '{CHECKOUT_SESSION_ID}' ), $base );
	$cancel  = add_query_arg( array( 'lgw_entry_cancelled' => 1 ), $base );

	$body = array(
		'mode'                => 'payment',
		'success_url'         => $success,
		'cancel_url'          => $cancel,
		'client_reference_id' => (string) $entry_id,
		'customer_email'      => get_post_meta( $entry_id, LGW_ENTRY_META_CONTACT, true ),
		'metadata'            => array( 'entry_id' => (string) $entry_id, 'champ' => (string) $champ_id ),
		'line_items'          => array(
			array(
				'quantity'   => 1,
				'price_data' => array(
					'currency'     => $currency,
					'unit_amount'  => $amount,
					'product_data' => array( 'name' => $title . ' — ' . $string ),
				),
			),
		),
	);

	$res = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
		'timeout' => 20,
		'headers' => array(
			'Authorization' => 'Bearer ' . $secret,
			'Content-Type'  => 'application/x-www-form-urlencoded',
		),
		'body'    => $body, // WP encodes nested arrays as line_items[0][price_data][...] — Stripe's format.
	) );
	if ( is_wp_error( $res ) ) return $res;

	$code = wp_remote_retrieve_response_code( $res );
	$json = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 || empty( $json['id'] ) ) {
		$msg = $json['error']['message'] ?? ( 'Stripe error (HTTP ' . $code . ')' );
		return new WP_Error( 'lgw_stripe', $msg );
	}

	update_post_meta( $entry_id, LGW_ENTRY_META_PAYREF, sanitize_text_field( $json['id'] ) );
	lgw_audit_log( $entry_id, 'checkout_created', 'Stripe session ' . $json['id'] );
	return isset( $json['url'] ) ? esc_url_raw( $json['url'] ) : new WP_Error( 'lgw_stripe', 'Stripe returned no checkout URL.' );
}

/**
 * Create ONE Checkout Session covering a whole bulk batch (N entries × fee).
 * One line-item per entry (so the Stripe receipt itemises the entrants).
 * Returns the redirect URL or WP_Error. The webhook confirms every row in the
 * batch together, keyed on the batch id in session metadata.
 */
function lgw_entry_stripe_create_batch_session( $batch, $champ_id, array $entry_ids, $return_url ) {
	$secret = lgw_entry_stripe_secret();
	if ( ! $secret ) return new WP_Error( 'lgw_stripe', 'Stripe is not configured.' );

	$currency = get_post_meta( $entry_ids[0], LGW_ENTRY_META_CURRENCY, true ) ?: 'gbp';

	// A batch may span several competitions — title each line item by the entry's
	// own championship so the Stripe receipt itemises across competitions.
	$title_cache = array();
	$line_items  = array();
	$total       = 0;
	foreach ( $entry_ids as $id ) {
		$amount = intval( get_post_meta( $id, LGW_ENTRY_META_AMOUNT, true ) );
		if ( $amount <= 0 ) continue;
		$cid = get_post_meta( $id, LGW_ENTRY_META_CHAMP, true );
		if ( ! isset( $title_cache[ $cid ] ) ) {
			$c = lgw_entry_get_champ( $cid );
			$title_cache[ $cid ] = $c ? $c['title'] : ( $cid ?: $champ_id );
		}
		$total       += $amount;
		$line_items[] = array(
			'quantity'   => 1,
			'price_data' => array(
				'currency'     => $currency,
				'unit_amount'  => $amount,
				'product_data' => array( 'name' => $title_cache[ $cid ] . ' — ' . get_post_meta( $id, LGW_ENTRY_META_STRING, true ) ),
			),
		);
	}
	if ( $total <= 0 || empty( $line_items ) ) return new WP_Error( 'lgw_stripe', 'Batch has no payable entries.' );

	$base    = $return_url ?: home_url( '/' );
	$success = add_query_arg( array( 'lgw_entry_paid' => 1, 'session_id' => '{CHECKOUT_SESSION_ID}' ), $base );
	$cancel  = add_query_arg( array( 'lgw_entry_cancelled' => 1 ), $base );

	$body = array(
		'mode'                => 'payment',
		'success_url'         => $success,
		'cancel_url'          => $cancel,
		'client_reference_id' => (string) $batch,
		'customer_email'      => get_post_meta( $entry_ids[0], LGW_ENTRY_META_CONTACT, true ),
		'metadata'            => array( 'batch_id' => (string) $batch, 'champ' => (string) $champ_id ),
		'line_items'          => $line_items,
	);

	$res = wp_remote_post( 'https://api.stripe.com/v1/checkout/sessions', array(
		'timeout' => 20,
		'headers' => array(
			'Authorization' => 'Bearer ' . $secret,
			'Content-Type'  => 'application/x-www-form-urlencoded',
		),
		'body'    => $body,
	) );
	if ( is_wp_error( $res ) ) return $res;

	$code = wp_remote_retrieve_response_code( $res );
	$json = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 || empty( $json['id'] ) ) {
		$msg = $json['error']['message'] ?? ( 'Stripe error (HTTP ' . $code . ')' );
		return new WP_Error( 'lgw_stripe', $msg );
	}
	foreach ( $entry_ids as $id ) {
		update_post_meta( $id, LGW_ENTRY_META_PAYREF, sanitize_text_field( $json['id'] ) );
		lgw_audit_log( $id, 'checkout_created', 'Stripe batch session ' . $json['id'] . ' (' . $batch . ')' );
	}
	return isset( $json['url'] ) ? esc_url_raw( $json['url'] ) : new WP_Error( 'lgw_stripe', 'Stripe returned no checkout URL.' );
}

// Webhook endpoint: POST {site}/wp-json/lgw/v1/stripe-webhook
add_action( 'rest_api_init', function () {
	register_rest_route( 'lgw/v1', '/stripe-webhook', array(
		'methods'             => 'POST',
		'callback'            => 'lgw_entry_stripe_webhook',
		'permission_callback' => '__return_true',
	) );
} );

function lgw_entry_stripe_webhook( WP_REST_Request $request ) {
	$secret = lgw_entry_stripe_webhook_secret();
	if ( ! $secret ) return new WP_REST_Response( array( 'error' => 'not configured' ), 500 );

	$payload = $request->get_body();
	$sig      = $request->get_header( 'stripe_signature' );
	if ( ! lgw_entry_stripe_verify_sig( $payload, (string) $sig, $secret ) ) {
		return new WP_REST_Response( array( 'error' => 'bad signature' ), 400 );
	}

	$event = json_decode( $payload, true );
	if ( ( $event['type'] ?? '' ) !== 'checkout.session.completed' ) {
		return new WP_REST_Response( array( 'ignored' => true ), 200 );
	}

	$session = $event['data']['object'] ?? array();

	// Bulk batch: one session covering N entries, keyed by batch id in metadata.
	$batch_id = sanitize_text_field( $session['metadata']['batch_id'] ?? '' );
	if ( $batch_id !== '' ) {
		return lgw_entry_stripe_webhook_finish_batch( $batch_id, $session );
	}

	$entry_id = intval( $session['metadata']['entry_id'] ?? ( $session['client_reference_id'] ?? 0 ) );
	if ( ! $entry_id || get_post_type( $entry_id ) !== LGW_ENTRY_CPT ) {
		return new WP_REST_Response( array( 'error' => 'unknown entry' ), 200 );
	}

	// Idempotent: ignore if already paid/confirmed.
	$status = get_post_meta( $entry_id, LGW_ENTRY_META_STATUS, true );
	if ( in_array( $status, array( 'paid', 'confirmed' ), true ) ) {
		return new WP_REST_Response( array( 'ok' => 'already paid' ), 200 );
	}

	// Re-verify amount + currency against the ledger — never trust the client.
	$exp_amount   = intval( get_post_meta( $entry_id, LGW_ENTRY_META_AMOUNT, true ) );
	$exp_currency = get_post_meta( $entry_id, LGW_ENTRY_META_CURRENCY, true ) ?: 'gbp';
	$got_amount   = intval( $session['amount_total'] ?? 0 );
	$got_currency = strtolower( $session['currency'] ?? '' );
	$paid_ok      = ( $session['payment_status'] ?? '' ) === 'paid';
	if ( ! $paid_ok || $got_amount !== $exp_amount || $got_currency !== strtolower( $exp_currency ) ) {
		lgw_audit_log( $entry_id, 'webhook_mismatch', "payment_status={$session['payment_status']}, amount={$got_amount}/{$exp_amount}, currency={$got_currency}/{$exp_currency}" );
		return new WP_REST_Response( array( 'error' => 'amount/currency/status mismatch' ), 200 );
	}

	if ( ! empty( $session['payment_intent'] ) ) {
		update_post_meta( $entry_id, LGW_ENTRY_META_PAYREF, sanitize_text_field( $session['payment_intent'] ) );
	}
	lgw_entry_set_status( $entry_id, 'paid' ); // projects into gchamp
	lgw_entry_email_entrant( $entry_id );
	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * Confirm every pending row in a bulk batch from one Checkout Session.
 * Re-verifies the paid total against the SUM of the batch's ledger amounts
 * (never trusts the client), is idempotent, and confirms/projects/emails each
 * still-pending row.
 */
function lgw_entry_stripe_webhook_finish_batch( $batch_id, array $session ) {
	$rows = get_posts( array(
		'post_type'      => LGW_ENTRY_CPT,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array( array( 'key' => LGW_ENTRY_META_BATCH, 'value' => $batch_id ) ),
	) );
	if ( empty( $rows ) ) return new WP_REST_Response( array( 'error' => 'unknown batch' ), 200 );

	// Expected total = sum of the batch's own ledger amounts. Currency from the batch.
	$exp_total = 0;
	$currency  = '';
	$pending   = array();
	foreach ( $rows as $id ) {
		$exp_total += intval( get_post_meta( $id, LGW_ENTRY_META_AMOUNT, true ) );
		if ( $currency === '' ) $currency = strtolower( get_post_meta( $id, LGW_ENTRY_META_CURRENCY, true ) ?: 'gbp' );
		if ( ! in_array( get_post_meta( $id, LGW_ENTRY_META_STATUS, true ), array( 'paid', 'confirmed' ), true ) ) {
			$pending[] = $id;
		}
	}
	if ( empty( $pending ) ) return new WP_REST_Response( array( 'ok' => 'already paid' ), 200 );

	$got_amount   = intval( $session['amount_total'] ?? 0 );
	$got_currency = strtolower( $session['currency'] ?? '' );
	$paid_ok      = ( $session['payment_status'] ?? '' ) === 'paid';
	if ( ! $paid_ok || $got_amount !== $exp_total || $got_currency !== $currency ) {
		foreach ( $pending as $id ) {
			lgw_audit_log( $id, 'webhook_mismatch', "batch={$batch_id} status={$session['payment_status']} amount={$got_amount}/{$exp_total} currency={$got_currency}/{$currency}" );
		}
		return new WP_REST_Response( array( 'error' => 'amount/currency/status mismatch' ), 200 );
	}

	foreach ( $pending as $id ) {
		if ( ! empty( $session['payment_intent'] ) ) {
			update_post_meta( $id, LGW_ENTRY_META_PAYREF, sanitize_text_field( $session['payment_intent'] ) );
		}
		lgw_entry_set_status( $id, 'paid' ); // projects
		lgw_entry_email_entrant( $id );
	}
	return new WP_REST_Response( array( 'ok' => true, 'confirmed' => count( $pending ) ), 200 );
}

/** Verify a Stripe webhook signature header (t=…,v1=…) against the signing secret. */
function lgw_entry_stripe_verify_sig( $payload, $sig_header, $secret, $tolerance = 300 ) {
	if ( $sig_header === '' ) return false;
	$ts = ''; $v1 = array();
	foreach ( explode( ',', $sig_header ) as $part ) {
		$kv = explode( '=', trim( $part ), 2 );
		if ( count( $kv ) !== 2 ) continue;
		if ( $kv[0] === 't' )  $ts   = $kv[1];
		if ( $kv[0] === 'v1' ) $v1[] = $kv[1];
	}
	if ( $ts === '' || empty( $v1 ) ) return false;
	if ( $tolerance > 0 && abs( time() - intval( $ts ) ) > $tolerance ) return false;

	$expected = hash_hmac( 'sha256', $ts . '.' . $payload, $secret );
	foreach ( $v1 as $candidate ) {
		if ( hash_equals( $expected, $candidate ) ) return true;
	}
	return false;
}

// ─────────────────────────────────────────────────────────────────────────────
// SLICE 1 — Admin: LGW → Entries
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'lgw_entry_register_submenu', 99 );
function lgw_entry_register_submenu() {
	add_submenu_page( 'lgw-scorecards', 'Entries', '📝 Entries', 'manage_options', 'lgw-entries', 'lgw_entry_admin_page' );
}

/** Handle admin POST actions (cfg save, settings save, row actions) before render. */
add_action( 'admin_init', 'lgw_entry_admin_actions' );
function lgw_entry_admin_actions() {
	if ( ! is_admin() || empty( $_POST['lgw_entry_action'] ) ) return;
	if ( ! current_user_can( 'manage_options' ) ) return;

	$action = sanitize_key( $_POST['lgw_entry_action'] );

	if ( $action === 'save_cfg' ) {
		check_admin_referer( 'lgw_entry_cfg' );
		$champ_id = sanitize_key( $_POST['champ'] ?? '' );
		$deadline = '';
		if ( ! empty( $_POST['deadline'] ) ) {
			$t = strtotime( wp_unslash( $_POST['deadline'] ) );
			if ( $t ) $deadline = $t;
		}
		lgw_entry_save_cfg( $champ_id, array(
			'fee'             => round( floatval( $_POST['fee'] ?? 0 ) * 100 ),
			'currency'        => $_POST['currency'] ?? 'gbp',
			'open'            => ! empty( $_POST['open'] ),
			'deadline'        => $deadline,
			'capacity'        => intval( $_POST['capacity'] ?? 0 ),
			'policy_override' => $_POST['policy_override'] ?? '',
		) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lgw-entries', 'champ' => $champ_id, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	if ( $action === 'save_settings' ) {
		check_admin_referer( 'lgw_entry_settings' );
		if ( ! defined( 'LGW_STRIPE_SECRET_KEY' ) )     update_option( 'lgw_stripe_secret_key', sanitize_text_field( wp_unslash( $_POST['stripe_secret'] ?? '' ) ) );
		if ( ! defined( 'LGW_STRIPE_WEBHOOK_SECRET' ) ) update_option( 'lgw_stripe_webhook_secret', sanitize_text_field( wp_unslash( $_POST['stripe_webhook'] ?? '' ) ) );
		$def = $_POST['default_policy'] ?? 'open';
		update_option( 'lgw_entry_default_policy', in_array( $def, array( 'open', 'club_admin' ), true ) ? $def : 'open' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lgw-entries', 'settings' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	// Row actions
	if ( in_array( $action, array( 'confirm', 'withdraw', 'mark_paid', 'refund' ), true ) ) {
		check_admin_referer( 'lgw_entry_row' );
		$entry_id = intval( $_POST['entry_id'] ?? 0 );
		$champ_id = get_post_meta( $entry_id, LGW_ENTRY_META_CHAMP, true );
		if ( $entry_id && get_post_type( $entry_id ) === LGW_ENTRY_CPT ) {
			switch ( $action ) {
				case 'confirm':   lgw_entry_set_status( $entry_id, 'confirmed' ); break;
				case 'withdraw':  lgw_entry_set_status( $entry_id, 'withdrawn' ); break;
				case 'mark_paid':
					update_post_meta( $entry_id, LGW_ENTRY_META_PAYREF, 'offline' );
					lgw_audit_log( $entry_id, 'mark_paid_offline', 'Marked paid offline by admin.' );
					lgw_entry_set_status( $entry_id, 'paid' );
					break;
				case 'refund':
					$note = sanitize_text_field( wp_unslash( $_POST['refund_note'] ?? '' ) );
					lgw_audit_log( $entry_id, 'refund_note', $note );
					lgw_entry_set_status( $entry_id, 'refunded' );
					break;
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'lgw-entries', 'champ' => $champ_id, 'done' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}

function lgw_entry_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised' );
	$champ_id = sanitize_key( $_GET['champ'] ?? '' );
	echo '<div class="wrap"><h1>Championship Entries</h1>';

	// Championship picker — every engine (gchamp + legacy champ), via the adapter.
	$champs = array();
	foreach ( lgw_champ_engine_list() as $row ) $champs[ $row['id'] ] = $row['title'];

	echo '<p><label>Championship: <select onchange="location.href=this.value">';
	echo '<option value="' . esc_url( admin_url( 'admin.php?page=lgw-entries' ) ) . '">— choose —</option>';
	foreach ( $champs as $id => $t ) {
		$url = admin_url( 'admin.php?page=lgw-entries&champ=' . urlencode( $id ) );
		printf( '<option value="%s"%s>%s</option>', esc_url( $url ), selected( $id, $champ_id, false ), esc_html( $t ) );
	}
	echo '</select></label></p>';

	lgw_entry_admin_settings_box();

	if ( $champ_id === '' || ! isset( $champs[ $champ_id ] ) ) {
		echo '<p>Select a championship above to manage its entries and fee/deadline settings.</p></div>';
		return;
	}

	lgw_entry_admin_cfg_box( $champ_id );
	lgw_entry_admin_list_box( $champ_id );
	echo '</div>';
}

/** Stripe + default-policy settings box. */
function lgw_entry_admin_settings_box() {
	$secret_const  = defined( 'LGW_STRIPE_SECRET_KEY' );
	$webhook_const = defined( 'LGW_STRIPE_WEBHOOK_SECRET' );
	$default       = get_option( 'lgw_entry_default_policy', 'open' );
	$webhook_url   = rest_url( 'lgw/v1/stripe-webhook' );
	?>
	<details style="margin:14px 0;padding:10px 14px;border:1px solid #ccd0d4;background:#fff">
		<summary style="cursor:pointer;font-weight:600">⚙️ Payment &amp; policy settings</summary>
		<form method="post" style="margin-top:12px">
			<?php wp_nonce_field( 'lgw_entry_settings' ); ?>
			<input type="hidden" name="lgw_entry_action" value="save_settings">
			<table class="form-table">
				<tr><th scope="row">Default entry policy</th><td>
					<select name="default_policy">
						<option value="open"<?php selected( $default, 'open' ); ?>>Open — any logged-in user may enter</option>
						<option value="club_admin"<?php selected( $default, 'club_admin' ); ?>>Club admin only — approved club admins enter on behalf</option>
					</select>
					<p class="description">Per-club overrides (set on the Clubs screen) and per-champ overrides take precedence.</p>
				</td></tr>
				<tr><th scope="row">Stripe secret key</th><td>
					<?php if ( $secret_const ) : ?>
						<em>Set via <code>LGW_STRIPE_SECRET_KEY</code> in wp-config.php.</em>
					<?php else : ?>
						<input type="password" name="stripe_secret" value="<?php echo esc_attr( get_option( 'lgw_stripe_secret_key', '' ) ); ?>" class="regular-text" autocomplete="off">
						<p class="description">Use a test key (<code>sk_test_…</code>) until you go live.</p>
					<?php endif; ?>
				</td></tr>
				<tr><th scope="row">Stripe webhook signing secret</th><td>
					<?php if ( $webhook_const ) : ?>
						<em>Set via <code>LGW_STRIPE_WEBHOOK_SECRET</code> in wp-config.php.</em>
					<?php else : ?>
						<input type="password" name="stripe_webhook" value="<?php echo esc_attr( get_option( 'lgw_stripe_webhook_secret', '' ) ); ?>" class="regular-text" autocomplete="off">
					<?php endif; ?>
					<p class="description">Point your Stripe webhook at <code><?php echo esc_html( $webhook_url ); ?></code> (event <code>checkout.session.completed</code>).</p>
				</td></tr>
			</table>
			<p><button class="button button-primary">Save settings</button></p>
		</form>
	</details>
	<?php
}

/** Per-champ fee / deadline / capacity / policy config box. */
function lgw_entry_admin_cfg_box( $champ_id ) {
	$cfg      = lgw_entry_cfg( $champ_id );
	$deadline = $cfg['deadline'] !== '' ? date( 'Y-m-d\TH:i', (int) $cfg['deadline'] ) : '';
	?>
	<h2>Entry settings</h2>
	<form method="post" style="margin-bottom:20px;padding:10px 14px;border:1px solid #ccd0d4;background:#fff;max-width:640px">
		<?php wp_nonce_field( 'lgw_entry_cfg' ); ?>
		<input type="hidden" name="lgw_entry_action" value="save_cfg">
		<input type="hidden" name="champ" value="<?php echo esc_attr( $champ_id ); ?>">
		<table class="form-table">
			<tr><th scope="row">Open for entries</th><td><label><input type="checkbox" name="open" value="1"<?php checked( $cfg['open'] ); ?>> Accept entries via <code>[lgw_champ_entry champ="<?php echo esc_attr( $champ_id ); ?>"]</code></label></td></tr>
			<tr><th scope="row">Entry fee</th><td><?php echo esc_html( ( array( 'gbp' => '£', 'eur' => '€', 'usd' => '$' )[ $cfg['currency'] ] ?? '' ) ); ?><input type="number" name="fee" min="0" step="0.01" value="<?php echo esc_attr( number_format( $cfg['fee'] / 100, 2, '.', '' ) ); ?>" style="width:100px">
				<select name="currency"><?php foreach ( array( 'gbp', 'eur', 'usd' ) as $cur ) printf( '<option value="%s"%s>%s</option>', $cur, selected( $cfg['currency'], $cur, false ), strtoupper( $cur ) ); ?></select>
				<p class="description">Set 0 for a free entry (goes straight to confirmed, no payment).</p></td></tr>
			<tr><th scope="row">Deadline</th><td><input type="datetime-local" name="deadline" value="<?php echo esc_attr( $deadline ); ?>"><p class="description">Leave blank for no deadline.</p></td></tr>
			<tr><th scope="row">Capacity</th><td><input type="number" name="capacity" min="0" value="<?php echo esc_attr( (int) $cfg['capacity'] ); ?>" style="width:100px"><p class="description">0 = unlimited.</p></td></tr>
			<tr><th scope="row">Access policy override</th><td>
				<select name="policy_override">
					<option value="">Use per-club / default policy</option>
					<option value="open"<?php selected( $cfg['policy_override'], 'open' ); ?>>Force open (any logged-in user)</option>
					<option value="club_admin"<?php selected( $cfg['policy_override'], 'club_admin' ); ?>>Force club-admin only</option>
				</select>
			</td></tr>
		</table>
		<p><button class="button button-primary">Save entry settings</button></p>
	</form>
	<?php
}

/** The entries list for a champ, with row actions. */
function lgw_entry_admin_list_box( $champ_id ) {
	$posts = get_posts( array(
		'post_type'      => LGW_ENTRY_CPT,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'ASC',
		'meta_key'       => LGW_ENTRY_META_CHAMP,
		'meta_value'     => $champ_id,
	) );

	echo '<h2>Entries (' . count( $posts ) . ')</h2>';
	if ( ! $posts ) { echo '<p>No entries yet.</p>'; return; }

	echo '<table class="widefat striped"><thead><tr><th>Entry</th><th>Club</th><th>Status</th><th>Amount</th><th>Payment ref</th><th>Submitted by</th><th>Actions</th></tr></thead><tbody>';
	foreach ( $posts as $p ) {
		$id       = $p->ID;
		$string   = get_post_meta( $id, LGW_ENTRY_META_STRING, true );
		$club     = get_post_meta( $id, LGW_ENTRY_META_CLUB, true );
		$status   = get_post_meta( $id, LGW_ENTRY_META_STATUS, true );
		$amount   = intval( get_post_meta( $id, LGW_ENTRY_META_AMOUNT, true ) );
		$currency = get_post_meta( $id, LGW_ENTRY_META_CURRENCY, true ) ?: 'gbp';
		$payref   = get_post_meta( $id, LGW_ENTRY_META_PAYREF, true );
		$by       = intval( get_post_meta( $id, LGW_ENTRY_META_BY, true ) );
		$user     = $by ? get_userdata( $by ) : false;
		$proj     = get_post_meta( $id, LGW_ENTRY_META_PROJECTED, true );

		$badge = $status;
		if ( $proj === 'needs_placement' ) $badge .= ' ⚠ needs placement';

		echo '<tr>';
		echo '<td>' . esc_html( $string ) . '</td>';
		echo '<td>' . esc_html( $club ) . '</td>';
		echo '<td>' . esc_html( $badge ) . '</td>';
		echo '<td>' . ( $amount > 0 ? esc_html( lgw_entry_format_money( $amount, $currency ) ) : '—' ) . '</td>';
		echo '<td>' . esc_html( $payref ?: '—' ) . '</td>';
		echo '<td>' . esc_html( $user ? $user->display_name : ( $by ?: 'system' ) ) . '</td>';
		echo '<td style="white-space:nowrap">';
		lgw_entry_row_button( $id, $champ_id, 'confirm',   'Confirm',      $status !== 'confirmed' && $status !== 'withdrawn' );
		lgw_entry_row_button( $id, $champ_id, 'mark_paid',  'Mark paid',    $status === 'pending_payment' );
		lgw_entry_row_button( $id, $champ_id, 'withdraw',  'Withdraw',     $status !== 'withdrawn' );
		lgw_entry_row_button( $id, $champ_id, 'refund',    'Refund',       $status === 'paid' );
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

/** Render a single nonce-protected row-action button (POST form). */
function lgw_entry_row_button( $entry_id, $champ_id, $action, $label, $show = true ) {
	if ( ! $show ) return;
	$confirm = in_array( $action, array( 'withdraw', 'refund' ), true )
		? ' onsubmit="return confirm(\'' . esc_js( $label . ' this entry?' ) . '\')"' : '';
	echo '<form method="post" style="display:inline"' . $confirm . '>';
	wp_nonce_field( 'lgw_entry_row' );
	echo '<input type="hidden" name="lgw_entry_action" value="' . esc_attr( $action ) . '">';
	echo '<input type="hidden" name="entry_id" value="' . intval( $entry_id ) . '">';
	if ( $action === 'refund' ) echo '<input type="hidden" name="refund_note" value="admin refund">';
	echo '<button class="button button-small" style="margin:1px">' . esc_html( $label ) . '</button>';
	echo '</form> ';
}
