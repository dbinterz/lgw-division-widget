<?php
/**
 * lgw-club-access.php — Slice A of OAuth-backed club-admin access.
 *
 * Backend authorization primitives:
 *   - custom role `lgw_club_admin` + capability `lgw_submit_scorecard`
 *   - user-meta model for approval status and approved clubs (by NAME string)
 *   - `lgw_auth_mode` control (passphrase | both | login)
 *   - helpers consumed by the scorecard/cup submission gates
 *   - a self-contained "Club Access" admin settings page
 *
 * Slice A deliberately contains NO OAuth code. Google login is added later by
 * installing a social-login plugin; nothing here changes when it is.
 *
 * See SPEC-club-access.md for the full design.
 *
 * @package LGW
 * @since 2026.27.25
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants ────────────────────────────────────────────────────────────────
const LGW_ROLE_CLUB_ADMIN = 'lgw_club_admin';
const LGW_CAP_SUBMIT      = 'lgw_submit_scorecard';

// User-meta keys
const LGW_META_STATUS     = 'lgw_approval_status';   // pending|approved|rejected|revoked
const LGW_META_CLUBS      = 'lgw_clubs';             // array of approved club-name strings
const LGW_META_REQUESTED  = 'lgw_requested_clubs';   // array of requested club-name strings
const LGW_META_NOTE       = 'lgw_request_note';
const LGW_META_APPROVED_BY = 'lgw_approved_by';
const LGW_META_APPROVED_AT = 'lgw_approved_at';
const LGW_META_REASON     = 'lgw_decision_reason';

// ── Install: register role + capability (idempotent, version-guarded) ─────────
add_action( 'init', 'lgw_club_access_install' );
function lgw_club_access_install() {
	// Only run the role/cap sync once per plugin version.
	if ( get_option( 'lgw_club_access_ver' ) === LGW_VERSION ) return;

	// Club-admin role: submission only, plus `read` so they are not blocked from
	// the front end. Deliberately NO edit_posts / dashboard access (least privilege).
	remove_role( LGW_ROLE_CLUB_ADMIN ); // re-create cleanly to pick up cap changes
	add_role(
		LGW_ROLE_CLUB_ADMIN,
		'Club Admin (LGW)',
		array(
			'read'            => true,
			LGW_CAP_SUBMIT    => true,
		)
	);

	// Administrators can already submit for any club — grant them the cap too so
	// capability checks are uniform.
	$admin = get_role( 'administrator' );
	if ( $admin && ! $admin->has_cap( LGW_CAP_SUBMIT ) ) {
		$admin->add_cap( LGW_CAP_SUBMIT );
	}

	update_option( 'lgw_club_access_ver', LGW_VERSION );
}

// ── Auth-mode helpers ─────────────────────────────────────────────────────────
/** Current submission auth mode: passphrase | both | login. */
function lgw_auth_mode() {
	$mode = get_option( 'lgw_auth_mode', 'both' );
	return in_array( $mode, array( 'passphrase', 'both', 'login' ), true ) ? $mode : 'both';
}
/** Is the shared-passphrase path currently offered? */
function lgw_passphrase_enabled() {
	return lgw_auth_mode() !== 'login';
}
/** Is the logged-in (identity) submission path currently offered? */
function lgw_login_submit_enabled() {
	return lgw_auth_mode() !== 'passphrase';
}

// ── Authorization primitives ──────────────────────────────────────────────────
/**
 * Club NAMES a user is authorised to submit for.
 * - Administrators → every configured club (they may submit for any).
 * - Approved club admins → their approved `lgw_clubs` list.
 * - Everyone else → empty array.
 *
 * @param int|null $uid  Defaults to current user.
 * @return string[]
 */
function lgw_user_submit_clubs( $uid = null ) {
	$uid = $uid ?: get_current_user_id();
	if ( ! $uid ) return array();

	if ( user_can( $uid, 'manage_options' ) ) {
		// Admins: all clubs by name.
		return array_values( array_filter( array_map(
			function ( $c ) { return isset( $c['name'] ) ? $c['name'] : ''; },
			lgw_get_clubs()
		) ) );
	}

	if ( ! user_can( $uid, LGW_CAP_SUBMIT ) ) return array();
	if ( get_user_meta( $uid, LGW_META_STATUS, true ) !== 'approved' ) return array();

	$clubs = get_user_meta( $uid, LGW_META_CLUBS, true );
	return is_array( $clubs ) ? array_values( $clubs ) : array();
}

/**
 * Can the user submit for a given team/club label? Uses the existing
 * club↔team matcher so "Ards" authorises "Ards A"/"Ards B".
 *
 * @param string   $team  Team or club label from a fixture.
 * @param int|null $uid   Defaults to current user.
 */
function lgw_user_can_submit_for( $team, $uid = null ) {
	if ( $team === '' ) return false;
	foreach ( lgw_user_submit_clubs( $uid ) as $club ) {
		if ( lgw_club_matches_team( $club, $team ) ) return true;
	}
	return false;
}

/** True when the current user is a logged-in, approved club admin (not an admin). */
function lgw_is_club_admin_submitter() {
	if ( ! is_user_logged_in() ) return false;
	if ( current_user_can( 'manage_options' ) ) return false;
	return ! empty( lgw_user_submit_clubs() );
}

// ── Admin settings page: LGW → Club Access ────────────────────────────────────
// Priority 99: the parent menu `lgw-scorecards` is registered inside
// lgw_admin_menu() on the default-priority admin_menu hook. Register after it so
// the submenu attaches to an existing parent (otherwise WP drops it → 403).
add_action( 'admin_menu', 'lgw_club_access_menu', 99 );
function lgw_club_access_menu() {
	add_submenu_page(
		'lgw-scorecards',
		'Club Access',
		'🔐 Club Access',
		'manage_options',
		'lgw-club-access',
		'lgw_club_access_page'
	);
}

function lgw_club_access_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	// Save
	if ( isset( $_POST['lgw_club_access_save'] ) && check_admin_referer( 'lgw_club_access' ) ) {
		$mode = sanitize_text_field( wp_unslash( $_POST['lgw_auth_mode'] ?? 'both' ) );
		if ( ! in_array( $mode, array( 'passphrase', 'both', 'login' ), true ) ) $mode = 'both';
		update_option( 'lgw_auth_mode', $mode );

		$emails = sanitize_text_field( wp_unslash( $_POST['lgw_admin_notify_emails'] ?? '' ) );
		update_option( 'lgw_admin_notify_emails', $emails );

		echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
	}

	$mode   = lgw_auth_mode();
	$emails = get_option( 'lgw_admin_notify_emails', get_option( 'admin_email' ) );

	// Counts for a quick at-a-glance panel.
	$pending  = count( get_users( array( 'meta_key' => LGW_META_STATUS, 'meta_value' => 'pending',  'fields' => 'ID' ) ) );
	$approved = count( get_users( array( 'meta_key' => LGW_META_STATUS, 'meta_value' => 'approved', 'fields' => 'ID' ) ) );
	?>
	<div class="wrap">
		<h1>🔐 Club Access</h1>
		<p>Controls how clubs authenticate when submitting scorecards. Registration &amp; approval
		screens arrive in the next slice — this page manages the submission mode and notifications.</p>

		<p><strong>Approved club admins:</strong> <?php echo (int) $approved; ?>
		&nbsp;|&nbsp; <strong>Pending requests:</strong> <?php echo (int) $pending; ?></p>

		<form method="post">
			<?php wp_nonce_field( 'lgw_club_access' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Submission auth mode</th>
					<td>
						<fieldset>
							<label><input type="radio" name="lgw_auth_mode" value="passphrase" <?php checked( $mode, 'passphrase' ); ?>>
								<strong>Passphrase only</strong> — current behaviour; login hidden.</label><br>
							<label><input type="radio" name="lgw_auth_mode" value="both" <?php checked( $mode, 'both' ); ?>>
								<strong>Both</strong> — clubs may log in <em>or</em> use the passphrase (recommended during rollout).</label><br>
							<label><input type="radio" name="lgw_auth_mode" value="login" <?php checked( $mode, 'login' ); ?>>
								<strong>Login only</strong> — passphrase disabled; approved accounts required.</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="lgw_admin_notify_emails">Approval notification emails</label></th>
					<td>
						<input name="lgw_admin_notify_emails" id="lgw_admin_notify_emails" type="text"
							class="regular-text" value="<?php echo esc_attr( $emails ); ?>">
						<p class="description">Comma-separated. Notified when a club requests access (used by the next slice).</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save', 'primary', 'lgw_club_access_save' ); ?>
		</form>
	</div>
	<?php
}
