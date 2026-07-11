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

	// ── Handle POST actions (all nonce + capability guarded) ──────────────────
	if ( isset( $_POST['lgw_club_access_save'] ) && check_admin_referer( 'lgw_club_access' ) ) {
		$mode = sanitize_text_field( wp_unslash( $_POST['lgw_auth_mode'] ?? 'both' ) );
		if ( ! in_array( $mode, array( 'passphrase', 'both', 'login' ), true ) ) $mode = 'both';
		update_option( 'lgw_auth_mode', $mode );
		update_option( 'lgw_admin_notify_emails', sanitize_text_field( wp_unslash( $_POST['lgw_admin_notify_emails'] ?? '' ) ) );
		echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
	}

	if ( isset( $_POST['lgw_ca_action'] ) && check_admin_referer( 'lgw_ca_decision' ) ) {
		$uid    = intval( $_POST['lgw_ca_uid'] ?? 0 );
		$action = sanitize_key( $_POST['lgw_ca_action'] );
		$reason = sanitize_text_field( wp_unslash( $_POST['lgw_ca_reason'] ?? '' ) );
		if ( $uid && get_userdata( $uid ) ) {
			if ( 'approve' === $action ) {
				$configured = wp_list_pluck( lgw_get_clubs(), 'name' );
				$grant = array_values( array_intersect(
					$configured,
					array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['lgw_ca_clubs'] ?? array() ) ) )
				) );
				if ( empty( $grant ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>Select at least one club to approve.</p></div>';
				} else {
					lgw_ca_approve( $uid, $grant );
					echo '<div class="notice notice-success is-dismissible"><p>Approved.</p></div>';
				}
			} elseif ( 'reject' === $action ) {
				lgw_ca_reject( $uid, $reason );
				echo '<div class="notice notice-success is-dismissible"><p>Request rejected.</p></div>';
			} elseif ( 'revoke' === $action ) {
				lgw_ca_revoke( $uid, $reason );
				echo '<div class="notice notice-success is-dismissible"><p>Access revoked.</p></div>';
			}
		}
	}

	$tabs = array( 'requests' => 'Requests', 'members' => 'Members', 'settings' => 'Settings' );
	$tab  = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? $_GET['tab'] : 'requests';

	$pending  = get_users( array( 'meta_key' => LGW_META_STATUS, 'meta_value' => 'pending',  'orderby' => 'display_name' ) );
	$approved = get_users( array( 'meta_key' => LGW_META_STATUS, 'meta_value' => 'approved', 'orderby' => 'display_name' ) );

	echo '<div class="wrap"><h1>🔐 Club Access</h1>';
	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $label ) {
		$count = 'requests' === $slug ? count( $pending ) : ( 'members' === $slug ? count( $approved ) : 0 );
		printf(
			'<a href="%s" class="nav-tab %s">%s%s</a>',
			esc_url( admin_url( 'admin.php?page=lgw-club-access&tab=' . $slug ) ),
			$tab === $slug ? 'nav-tab-active' : '',
			esc_html( $label ),
			$count ? ' <span class="count">(' . (int) $count . ')</span>' : ''
		);
	}
	echo '</h2>';

	if ( 'requests' === $tab )      lgw_ca_render_requests( $pending );
	elseif ( 'members' === $tab )   lgw_ca_render_members( $approved );
	else                            lgw_ca_render_settings();

	echo '</div>';
}

// ── Tab: pending requests ─────────────────────────────────────────────────────
function lgw_ca_render_requests( $pending ) {
	if ( empty( $pending ) ) { echo '<p>No pending requests.</p>'; return; }
	$configured = wp_list_pluck( lgw_get_clubs(), 'name' );
	echo '<p>Approve maps a verified account to the clubs it may submit for. Confirm the person’s identity with the club before approving.</p>';
	foreach ( $pending as $u ) {
		$req  = (array) get_user_meta( $u->ID, LGW_META_REQUESTED, true );
		$note = get_user_meta( $u->ID, LGW_META_NOTE, true );
		echo '<div class="card" style="max-width:640px;margin:0 0 16px;padding:12px 16px;">';
		printf( '<h3 style="margin-top:0;">%s <span style="font-weight:400;color:#666;">&lt;%s&gt;</span></h3>',
			esc_html( $u->display_name ), esc_html( $u->user_email ) );
		echo '<p><strong>Requested:</strong> ' . ( $req ? esc_html( implode( ', ', $req ) ) : '<em>none specified</em>' ) . '</p>';
		if ( $note ) echo '<p><strong>Note:</strong> ' . esc_html( $note ) . '</p>';

		echo '<form method="post" style="margin-top:8px;">';
		wp_nonce_field( 'lgw_ca_decision' );
		printf( '<input type="hidden" name="lgw_ca_uid" value="%d">', (int) $u->ID );
		echo '<p><strong>Grant clubs:</strong></p><div style="columns:2;max-width:520px;">';
		foreach ( $configured as $club ) {
			printf(
				'<label style="display:block;"><input type="checkbox" name="lgw_ca_clubs[]" value="%s" %s> %s</label>',
				esc_attr( $club ), checked( in_array( $club, $req, true ), true, false ), esc_html( $club )
			);
		}
		echo '</div>';
		echo '<p><input type="text" name="lgw_ca_reason" class="regular-text" placeholder="Reason (optional, shown to user if rejecting)"></p>';
		echo '<button class="button button-primary" name="lgw_ca_action" value="approve">Approve</button> ';
		echo '<button class="button" name="lgw_ca_action" value="reject" onclick="return confirm(\'Reject this request?\');">Reject</button>';
		echo '</form></div>';
	}
}

// ── Tab: approved members ─────────────────────────────────────────────────────
function lgw_ca_render_members( $approved ) {
	if ( empty( $approved ) ) { echo '<p>No approved club admins yet.</p>'; return; }
	echo '<table class="wp-list-table widefat striped"><thead><tr>';
	echo '<th>User</th><th>Email</th><th>Clubs</th><th>Approved</th><th>Action</th></tr></thead><tbody>';
	foreach ( $approved as $u ) {
		$clubs = (array) get_user_meta( $u->ID, LGW_META_CLUBS, true );
		$when  = get_user_meta( $u->ID, LGW_META_APPROVED_AT, true );
		echo '<tr>';
		printf( '<td>%s</td><td>%s</td><td>%s</td><td>%s</td>',
			esc_html( $u->display_name ),
			esc_html( $u->user_email ),
			esc_html( implode( ', ', $clubs ) ),
			$when ? esc_html( date_i18n( 'j M Y', (int) $when ) ) : '—'
		);
		echo '<td><form method="post" style="margin:0;">';
		wp_nonce_field( 'lgw_ca_decision' );
		printf( '<input type="hidden" name="lgw_ca_uid" value="%d">', (int) $u->ID );
		echo '<button class="button button-small" name="lgw_ca_action" value="revoke" onclick="return confirm(\'Revoke this user\\\'s access?\');">Revoke</button>';
		echo '</form></td></tr>';
	}
	echo '</tbody></table>';
}

// ── Tab: settings ─────────────────────────────────────────────────────────────
function lgw_ca_render_settings() {
	$mode   = lgw_auth_mode();
	$emails = get_option( 'lgw_admin_notify_emails', get_option( 'admin_email' ) );
	?>
	<form method="post">
		<?php wp_nonce_field( 'lgw_club_access' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Submission auth mode</th>
				<td><fieldset>
					<label><input type="radio" name="lgw_auth_mode" value="passphrase" <?php checked( $mode, 'passphrase' ); ?>>
						<strong>Passphrase only</strong> — current behaviour; login hidden.</label><br>
					<label><input type="radio" name="lgw_auth_mode" value="both" <?php checked( $mode, 'both' ); ?>>
						<strong>Both</strong> — clubs may log in <em>or</em> use the passphrase (recommended during rollout).</label><br>
					<label><input type="radio" name="lgw_auth_mode" value="login" <?php checked( $mode, 'login' ); ?>>
						<strong>Login only</strong> — passphrase disabled; approved accounts required.</label>
				</fieldset></td>
			</tr>
			<tr>
				<th scope="row"><label for="lgw_admin_notify_emails">Approval notification emails</label></th>
				<td>
					<input name="lgw_admin_notify_emails" id="lgw_admin_notify_emails" type="text"
						class="regular-text" value="<?php echo esc_attr( $emails ); ?>">
					<p class="description">Comma-separated. Notified when a club requests access.</p>
				</td>
			</tr>
		</table>
		<?php submit_button( 'Save', 'primary', 'lgw_club_access_save' ); ?>
	</form>
	<?php
}

// ── Decision handlers ─────────────────────────────────────────────────────────
function lgw_ca_approve( $uid, array $clubs ) {
	$u = new WP_User( $uid );
	$u->add_role( LGW_ROLE_CLUB_ADMIN ); // add, don't strip any existing role
	update_user_meta( $uid, LGW_META_STATUS, 'approved' );
	update_user_meta( $uid, LGW_META_CLUBS, array_values( $clubs ) );
	update_user_meta( $uid, LGW_META_APPROVED_BY, get_current_user_id() );
	update_user_meta( $uid, LGW_META_APPROVED_AT, time() );
	delete_user_meta( $uid, LGW_META_REASON );
	lgw_ca_notify_user( $uid, 'approved', '', $clubs );
}
function lgw_ca_reject( $uid, $reason = '' ) {
	update_user_meta( $uid, LGW_META_STATUS, 'rejected' );
	update_user_meta( $uid, LGW_META_REASON, $reason );
	( new WP_User( $uid ) )->remove_role( LGW_ROLE_CLUB_ADMIN );
	delete_user_meta( $uid, LGW_META_CLUBS );
	lgw_ca_notify_user( $uid, 'rejected', $reason );
}
function lgw_ca_revoke( $uid, $reason = '' ) {
	update_user_meta( $uid, LGW_META_STATUS, 'revoked' );
	update_user_meta( $uid, LGW_META_REASON, $reason );
	delete_user_meta( $uid, LGW_META_CLUBS );
	( new WP_User( $uid ) )->remove_role( LGW_ROLE_CLUB_ADMIN );
	lgw_ca_notify_user( $uid, 'revoked', $reason );
}

// ── Email helpers ─────────────────────────────────────────────────────────────
function lgw_ca_admin_recipients() {
	$raw = get_option( 'lgw_admin_notify_emails', get_option( 'admin_email' ) );
	$list = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ), 'is_email' );
	return $list ?: array( get_option( 'admin_email' ) );
}
function lgw_ca_notify_admins( $uid ) {
	$u = get_userdata( $uid );
	if ( ! $u ) return;
	$req  = implode( ', ', (array) get_user_meta( $uid, LGW_META_REQUESTED, true ) );
	$note = get_user_meta( $uid, LGW_META_NOTE, true );
	$body = "New club-admin access request.\n\n"
		. "Name:  {$u->display_name}\nEmail: {$u->user_email}\n"
		. "Clubs requested: " . ( $req ?: '(none specified)' ) . "\n"
		. ( $note ? "Note: {$note}\n" : '' )
		. "\nReview: " . admin_url( 'admin.php?page=lgw-club-access&tab=requests' ) . "\n";
	wp_mail( lgw_ca_admin_recipients(), '[LGW] Club-admin access request — ' . $u->display_name, $body );
}
function lgw_ca_notify_user( $uid, $decision, $reason = '', $clubs = array() ) {
	$u = get_userdata( $uid );
	if ( ! $u || ! is_email( $u->user_email ) ) return;
	$site = get_bloginfo( 'name' );
	if ( 'approved' === $decision ) {
		$subject = "[LGW] Your club-admin access is approved";
		$body = "Hello {$u->display_name},\n\nYour request to submit scorecards has been approved for: "
			. implode( ', ', (array) $clubs ) . ".\n\nLog in at " . wp_login_url() . " to submit.\n\n{$site}\n";
	} else {
		$word = 'rejected' === $decision ? 'has not been approved' : 'has been revoked';
		$subject = "[LGW] Your club-admin access {$word}";
		$body = "Hello {$u->display_name},\n\nYour club-admin access {$word}.\n"
			. ( $reason ? "Reason: {$reason}\n" : '' )
			. "\nIf you believe this is a mistake, please contact a league administrator.\n\n{$site}\n";
	}
	wp_mail( $u->user_email, $subject, $body );
}

// ── Front-end registration: [lgw_club_access_request] ─────────────────────────
add_shortcode( 'lgw_club_access_request', 'lgw_ca_request_shortcode' );
function lgw_ca_request_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<div class="lgw-ca-box"><p>Please log in to request scorecard-submission access.</p><p><a class="button" href="'
			. esc_url( wp_login_url( get_permalink() ) ) . '">Log in</a></p></div>';
	}

	$uid    = get_current_user_id();
	$status = get_user_meta( $uid, LGW_META_STATUS, true );

	if ( 'approved' === $status ) {
		$clubs = (array) get_user_meta( $uid, LGW_META_CLUBS, true );
		return '<div class="lgw-ca-box"><p>✅ You are approved to submit scorecards for: <strong>'
			. esc_html( implode( ', ', $clubs ) ) . '</strong>.</p>'
			. '<p>To change the clubs you administer, please contact a league administrator.</p></div>';
	}
	if ( 'pending' === $status ) {
		$req = (array) get_user_meta( $uid, LGW_META_REQUESTED, true );
		return '<div class="lgw-ca-box"><p>⏳ Your access request is pending review'
			. ( $req ? ' for: <strong>' . esc_html( implode( ', ', $req ) ) . '</strong>' : '' ) . '.</p>'
			. '<p>Please contact a league administrator to confirm your details.</p></div>';
	}

	// none | rejected | revoked → show the request form
	$clubs = wp_list_pluck( lgw_get_clubs(), 'name' );
	$nonce = wp_create_nonce( 'lgw_request_access' );
	$prev_reason = get_user_meta( $uid, LGW_META_REASON, true );
	ob_start(); ?>
	<div class="lgw-ca-box">
		<?php if ( in_array( $status, array( 'rejected', 'revoked' ), true ) ) : ?>
			<p><em>Your previous request was <?php echo esc_html( $status ); ?><?php echo $prev_reason ? ' — ' . esc_html( $prev_reason ) : ''; ?>. You may request again below.</em></p>
		<?php endif; ?>
		<p>Request permission to submit scorecards on behalf of your club(s). A league
		administrator will confirm your details before approving.</p>
		<form id="lgw-ca-form">
			<p><label for="lgw-ca-clubs"><strong>Club(s) you administer</strong></label><br>
			<select id="lgw-ca-clubs" name="clubs[]" multiple size="6" style="min-width:240px;">
				<?php foreach ( $clubs as $c ) printf( '<option value="%s">%s</option>', esc_attr( $c ), esc_html( $c ) ); ?>
			</select>
			<br><small>Hold Ctrl/⌘ to select more than one.</small></p>
			<p><label for="lgw-ca-note"><strong>Note</strong> (your role / contact)</label><br>
			<textarea id="lgw-ca-note" name="note" rows="3" style="width:100%;max-width:420px;"></textarea></p>
			<p><button type="submit" class="button button-primary">Submit request</button></p>
			<p id="lgw-ca-msg" role="status" aria-live="polite"></p>
		</form>
	</div>
	<script>
	(function(){
		var f=document.getElementById('lgw-ca-form'); if(!f) return;
		f.addEventListener('submit',function(e){
			e.preventDefault();
			var msg=document.getElementById('lgw-ca-msg'); msg.textContent='Submitting…';
			var sel=f.querySelector('#lgw-ca-clubs'), clubs=[];
			for(var i=0;i<sel.options.length;i++){ if(sel.options[i].selected) clubs.push(sel.options[i].value); }
			var data=new FormData();
			data.append('action','lgw_request_access');
			data.append('nonce',<?php echo wp_json_encode( $nonce ); ?>);
			data.append('note',f.querySelector('#lgw-ca-note').value);
			clubs.forEach(function(c){ data.append('clubs[]',c); });
			fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,{method:'POST',credentials:'same-origin',body:data})
			.then(function(r){return r.json();})
			.then(function(j){
				if(j.success){ f.style.display='none'; msg.textContent='✅ '+j.data; }
				else { msg.textContent='⚠️ '+(j.data||'Could not submit your request.'); }
			}).catch(function(){ msg.textContent='⚠️ Network error — please try again.'; });
		});
	})();
	</script>
	<?php
	return ob_get_clean();
}

// ── AJAX: submit an access request (logged-in only) ───────────────────────────
add_action( 'wp_ajax_lgw_request_access', 'lgw_ajax_request_access' );
function lgw_ajax_request_access() {
	if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in first.' );
	check_ajax_referer( 'lgw_request_access', 'nonce' );

	$uid = get_current_user_id();

	// Already-approved users manage clubs via an admin, not by re-requesting.
	if ( get_user_meta( $uid, LGW_META_STATUS, true ) === 'approved' ) {
		wp_send_json_error( 'You are already approved. Contact an administrator to change your clubs.' );
	}
	// Light rate-limit: one submission per 30s per user.
	if ( get_transient( 'lgw_ca_req_' . $uid ) ) {
		wp_send_json_error( 'Please wait a moment before submitting again.' );
	}

	$configured = wp_list_pluck( lgw_get_clubs(), 'name' );
	$clubs = array_values( array_intersect(
		$configured,
		array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['clubs'] ?? array() ) ) )
	) );
	if ( empty( $clubs ) ) wp_send_json_error( 'Please select at least one club.' );

	$note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

	update_user_meta( $uid, LGW_META_STATUS, 'pending' );
	update_user_meta( $uid, LGW_META_REQUESTED, $clubs );
	update_user_meta( $uid, LGW_META_NOTE, $note );
	set_transient( 'lgw_ca_req_' . $uid, 1, 30 );

	lgw_ca_notify_admins( $uid );
	wp_send_json_success( 'Request received. Please contact a league administrator to confirm your details.' );
}
