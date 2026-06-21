<?php
/**
 * lgw-clubs.php — Club directory admin page
 *
 * Adds an "🏢 Clubs" sub-menu that shows an index card grid.
 * Clicking a club opens an inline edit panel (AJAX, no page reload)
 * where admins can update contact details alongside the existing
 * passphrase and badge settings.
 *
 * Data is stored as additional keys on each club record inside the
 * existing lgw_clubs WP option:
 *   name, pin, address, website,
 *   contacts: [ { role, name, phone, email }, … ]
 *
 * Roles shipped by default: Secretary, President, Green Keeper.
 * Admins may add freeform extra contacts.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Menu registration — called from lgw_admin_menu() in lgw-division-widget.php ──

function lgw_clubs_register_submenu() {
    add_submenu_page(
        'lgw-scorecards',
        'Club Directory',
        '🏢 Clubs',
        'manage_options',
        'lgw-clubs',
        'lgw_clubs_admin_page'
    );
}

// ── Enqueue inline styles / scripts only on our page ─────────────────────────

add_action( 'admin_enqueue_scripts', 'lgw_clubs_enqueue' );
function lgw_clubs_enqueue( $hook ) {
    if ( strpos( $hook, 'lgw-clubs' ) === false ) return;
    // Media uploader (for badge images)
    wp_enqueue_media();
}

// ── AJAX: save a single club ──────────────────────────────────────────────────

add_action( 'wp_ajax_lgw_save_club', 'lgw_ajax_save_club' );
function lgw_ajax_save_club() {
    check_ajax_referer( 'lgw_clubs_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $slug        = sanitize_key( wp_unslash( $_POST['club_slug']    ?? '' ) );
    $name        = sanitize_text_field( wp_unslash( $_POST['club_name']   ?? '' ) );
    $address     = sanitize_textarea_field( wp_unslash( $_POST['club_address'] ?? '' ) );
    $website     = esc_url_raw( wp_unslash( $_POST['club_website']  ?? '' ) );
    $badge_url   = esc_url_raw( wp_unslash( $_POST['club_badge']    ?? '' ) );
    $badge_type  = in_array( wp_unslash( $_POST['club_badge_type'] ?? '' ), array( 'club', 'exact' ), true )
                    ? wp_unslash( $_POST['club_badge_type'] ) : 'club';
    $pin_raw     = trim( wp_unslash( $_POST['club_pin'] ?? '' ) );
    $is_new      = ! empty( $_POST['club_is_new'] );

    // Contacts array
    $contact_roles   = isset( $_POST['contact_role'] )  ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['contact_role']  ) ) : array();
    $contact_names   = isset( $_POST['contact_name'] )  ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['contact_name']  ) ) : array();
    $contact_phones  = isset( $_POST['contact_phone'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['contact_phone'] ) ) : array();
    $contact_emails  = isset( $_POST['contact_email'] ) ? array_map( 'sanitize_email',      (array) wp_unslash( $_POST['contact_email'] ) ) : array();

    $contacts = array();
    foreach ( $contact_roles as $i => $role ) {
        $contacts[] = array(
            'role'  => $role,
            'name'  => $contact_names[ $i ]  ?? '',
            'phone' => $contact_phones[ $i ] ?? '',
            'email' => $contact_emails[ $i ] ?? '',
        );
    }

    // Facilities
    $fac_greens      = max( 0, intval( wp_unslash( $_POST['fac_greens']      ?? 0 ) ) );
    $fac_rinks       = max( 0, intval( wp_unslash( $_POST['fac_rinks']       ?? 0 ) ) );
    $fac_floodlights = ! empty( $_POST['fac_floodlights'] );
    $fac_parking_raw = wp_unslash( $_POST['fac_parking'] ?? 'none' );
    $fac_parking     = in_array( $fac_parking_raw, array( 'none', 'street', 'private' ), true ) ? $fac_parking_raw : 'none';
    $fac_bar         = ! empty( $_POST['fac_bar'] );
    $fac_changing    = ! empty( $_POST['fac_changing'] );
    $can_submit      = ! empty( $_POST['can_submit'] );

    if ( $name === '' ) wp_send_json_error( 'Club name cannot be empty.' );

    $clubs = get_option( 'lgw_clubs', array() );

    // Locate existing record by slug (sanitize_title of original name)
    $idx = -1;
    foreach ( $clubs as $i => $c ) {
        if ( sanitize_key( sanitize_title( $c['name'] ) ) === $slug ) { $idx = $i; break; }
    }

    // Resolve passphrase hash
    $existing_hash = ( $idx >= 0 ) ? ( $clubs[ $idx ]['pin'] ?? '' ) : '';
    $pin_normalised = strtolower( preg_replace( '/\s+/', ' ', $pin_raw ) );
    $new_hash = $pin_normalised !== '' ? hash( 'sha256', $pin_normalised ) : $existing_hash;

    $record = array(
        'name'        => $name,
        'pin'         => $new_hash,
        'address'     => $address,
        'website'     => $website,
        'can_submit'  => $can_submit,
        'contacts'    => $contacts,
        'facilities'  => array(
            'greens'      => $fac_greens,
            'rinks'       => $fac_rinks,
            'floodlights' => $fac_floodlights,
            'parking'     => $fac_parking,
            'bar'         => $fac_bar,
            'changing'    => $fac_changing,
        ),
    );

    if ( $idx >= 0 ) {
        $clubs[ $idx ] = $record;
    } else {
        // New club — only allowed via the new-club form
        if ( ! $is_new ) wp_send_json_error( 'Club not found.' );
        $clubs[] = $record;
        $idx = count( $clubs ) - 1;
    }

    update_option( 'lgw_clubs', $clubs );

    // Badge / club-badge tables (mirrors Settings page logic)
    lgw_clubs_update_badge( $name, $badge_url, $badge_type );

    $new_slug = sanitize_key( sanitize_title( $name ) );
    wp_send_json_success( array( 'slug' => $new_slug, 'name' => $name ) );
}

// ── AJAX: delete a single club ────────────────────────────────────────────────

add_action( 'wp_ajax_lgw_delete_club', 'lgw_ajax_delete_club' );
function lgw_ajax_delete_club() {
    check_ajax_referer( 'lgw_clubs_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $slug  = sanitize_key( wp_unslash( $_POST['club_slug'] ?? '' ) );
    $clubs = get_option( 'lgw_clubs', array() );
    $clubs = array_values( array_filter( $clubs, function( $c ) use ( $slug ) {
        return sanitize_key( sanitize_title( $c['name'] ) ) !== $slug;
    } ) );
    update_option( 'lgw_clubs', $clubs );
    wp_send_json_success();
}

// ── Helper: update badge options ──────────────────────────────────────────────

function lgw_clubs_update_badge( $name, $badge_url, $badge_type ) {
    $badges      = get_option( 'lgw_badges',      array() );
    $club_badges = get_option( 'lgw_club_badges', array() );

    // Remove from both tables first
    unset( $badges[ $name ], $club_badges[ $name ] );
    // Case-insensitive cleanup
    foreach ( $badges as $k => $v ) {
        if ( strtolower( $k ) === strtolower( $name ) ) unset( $badges[ $k ] );
    }
    foreach ( $club_badges as $k => $v ) {
        if ( strtolower( $k ) === strtolower( $name ) ) unset( $club_badges[ $k ] );
    }

    if ( $badge_url !== '' ) {
        if ( $badge_type === 'exact' ) {
            $badges[ $name ] = $badge_url;
        } else {
            $club_badges[ $name ] = $badge_url;
        }
    }

    update_option( 'lgw_badges',      $badges );
    update_option( 'lgw_club_badges', $club_badges );
}

// ── Admin page ────────────────────────────────────────────────────────────────

function lgw_clubs_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $clubs       = get_option( 'lgw_clubs',       array() );
    $badges      = get_option( 'lgw_badges',      array() );
    $club_badges = get_option( 'lgw_club_badges', array() );
    $nonce       = wp_create_nonce( 'lgw_clubs_nonce' );

    // Build badge lookup
    $badge_lookup = array();
    foreach ( $badges as $k => $v )      $badge_lookup[ strtolower( $k ) ] = array( 'url' => $v, 'type' => 'exact' );
    foreach ( $club_badges as $k => $v ) $badge_lookup[ strtolower( $k ) ] = array( 'url' => $v, 'type' => 'club' );

    ?>
    <div class="wrap lgw-admin-wrap">
        <?php lgw_page_header( 'Club Directory' ); ?>

        <p>Click a club card to edit its contact details, passphrase, and badge. Changes are saved per-club without affecting others.</p>
        <p><button type="button" class="button button-primary" id="lgw-clubs-add-new">+ Add New Club</button></p>

        <!-- Index grid -->
        <div class="lgw-clubs-grid" id="lgw-clubs-grid">
        <?php foreach ( $clubs as $club ) :
            $slug    = sanitize_key( sanitize_title( $club['name'] ) );
            $bl      = $badge_lookup[ strtolower( $club['name'] ) ] ?? null;
            $badge   = $bl ? $bl['url'] : '';
            $btype   = $bl ? $bl['type'] : 'club';
            $has_pin = ! empty( $club['pin'] );
            $contacts= $club['contacts'] ?? array();
            $sec     = '';
            foreach ( $contacts as $ct ) { if ( strtolower($ct['role']) === 'secretary' ) { $sec = $ct['name']; break; } }
            ?>
            <div class="lgw-club-card" data-slug="<?php echo esc_attr( $slug ); ?>" tabindex="0" role="button" aria-expanded="false">
                <div class="lgw-club-card-inner">
                    <?php if ( $badge ) : ?>
                        <img class="lgw-club-card-badge" src="<?php echo esc_url( $badge ); ?>" alt="">
                    <?php else : ?>
                        <span class="lgw-club-card-initials"><?php echo esc_html( strtoupper( substr( $club['name'], 0, 2 ) ) ); ?></span>
                    <?php endif; ?>
                    <div class="lgw-club-card-info">
                        <strong><?php echo esc_html( $club['name'] ); ?></strong>
                        <?php if ( $sec ) echo '<span class="lgw-club-card-meta">Sec: ' . esc_html( $sec ) . '</span>'; ?>
                        <?php if ( ! empty( $club['address'] ) ) echo '<span class="lgw-club-card-meta">' . esc_html( wp_trim_words( $club['address'], 5, '…' ) ) . '</span>'; ?>
                    </div>
                    <span class="lgw-club-card-pin-badge <?php echo $has_pin ? 'set' : 'unset'; ?>" title="<?php echo $has_pin ? 'Passphrase set' : 'No passphrase'; ?>">
                        <?php echo $has_pin ? '🔑' : '🔓'; ?>
                    </span>
                    <?php if ( ! empty( $club['can_submit'] ) ) : ?>
                    <span title="Scorecard submission enabled" style="font-size:14px;flex-shrink:0">📋</span>
                    <?php endif; ?>

                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <!-- Detail panel (hidden until a card is clicked) -->
        <div id="lgw-club-detail-panel" class="lgw-club-detail-panel" style="display:none">
            <div class="lgw-club-detail-header">
                <h2 id="lgw-club-detail-title">Club</h2>
                <button type="button" class="lgw-club-detail-close" id="lgw-club-detail-close" title="Close">✕</button>
            </div>
            <div id="lgw-club-detail-body"><!-- injected by JS --></div>
        </div>

        <!-- New-club panel (hidden) -->
        <div id="lgw-club-new-panel" class="lgw-club-detail-panel" style="display:none">
            <div class="lgw-club-detail-header">
                <h2>Add New Club</h2>
                <button type="button" class="lgw-club-detail-close" id="lgw-club-new-close" title="Close">✕</button>
            </div>
            <div id="lgw-club-new-body">
                <?php lgw_clubs_render_form( null, null, array(), true ); ?>
            </div>
        </div>

    </div><!-- .wrap -->

    <!-- Pass data to JS -->
    <script>
    var lgwClubsData = <?php echo json_encode( array(
        'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
        'nonce'    => $nonce,
        'clubs'    => array_map( function($c) use ($badge_lookup) {
            $slug = sanitize_key( sanitize_title( $c['name'] ) );
            $bl   = $badge_lookup[ strtolower($c['name']) ] ?? null;
            return array(
                'slug'       => $slug,
                'name'       => $c['name'],
                'pin_set'    => ! empty( $c['pin'] ),
                'can_submit' => ! empty( $c['can_submit'] ),
                'address'    => $c['address']  ?? '',
                'website'    => $c['website']  ?? '',
                'badge_url'  => $bl ? $bl['url']  : '',
                'badge_type' => $bl ? $bl['type'] : 'club',
                'contacts'   => $c['contacts']   ?? array(),
                'facilities' => $c['facilities'] ?? array(),
            );
        }, $clubs ),
    ) ); ?>;
    </script>

    <?php lgw_clubs_inline_styles(); ?>
    <?php lgw_clubs_inline_script(); ?>
    <?php
}

// ── Reusable form renderer ────────────────────────────────────────────────────

function lgw_clubs_render_form( $club, $slug, $badge_info, $is_new = false ) {
    $name        = $club ? $club['name']      : '';
    $address     = $club ? ( $club['address'] ?? '' ) : '';
    $website     = $club ? ( $club['website'] ?? '' ) : '';
    $has_pin     = $club && ! empty( $club['pin'] );
    $can_submit  = $club ? ! empty( $club['can_submit'] ) : false;
    $badge_url   = $badge_info ? ( $badge_info['url']  ?? '' ) : '';
    $badge_type  = $badge_info ? ( $badge_info['type'] ?? 'club' ) : 'club';
    $contacts    = $club ? ( $club['contacts'] ?? array() ) : array();
    $fac         = $club ? ( $club['facilities'] ?? array() ) : array();
    $fac_greens      = intval( $fac['greens']      ?? 0 );
    $fac_rinks_val   = intval( $fac['rinks']       ?? 0 );
    $fac_floodlights = ! empty( $fac['floodlights'] );
    $fac_parking     = $fac['parking']  ?? 'none';
    $fac_bar         = ! empty( $fac['bar'] );
    $fac_changing    = ! empty( $fac['changing'] );

    // Ensure the three standard roles always appear
    $standard_roles = array( 'Secretary', 'President', 'Green Keeper' );
    $existing_roles = array_map( function($c){ return $c['role']; }, $contacts );
    foreach ( $standard_roles as $role ) {
        if ( ! in_array( $role, $existing_roles, true ) ) {
            $contacts[] = array( 'role' => $role, 'name' => '', 'phone' => '', 'email' => '' );
        }
    }
    // Sort so standard roles come first
    usort( $contacts, function($a, $b) use ($standard_roles) {
        $ai = array_search( $a['role'], $standard_roles );
        $bi = array_search( $b['role'], $standard_roles );
        $ai = $ai === false ? 99 : $ai;
        $bi = $bi === false ? 99 : $bi;
        return $ai - $bi;
    });

    ?>
    <form class="lgw-club-edit-form" data-slug="<?php echo esc_attr( $slug ?? '' ); ?>" data-is-new="<?php echo $is_new ? '1' : '0'; ?>">

        <div class="lgw-club-form-section">
            <h3>Club Details</h3>
            <table class="form-table lgw-club-form-table">
                <tr>
                    <th><label>Club Name</label></th>
                    <td><input type="text" name="club_name" class="regular-text" value="<?php echo esc_attr( $name ); ?>" required></td>
                </tr>
                <tr>
                    <th><label>Address</label></th>
                    <td><textarea name="club_address" class="regular-text" rows="3" placeholder="Clubhouse address"><?php echo esc_textarea( $address ); ?></textarea></td>
                </tr>
                <tr>
                    <th><label>Website</label></th>
                    <td><input type="url" name="club_website" class="regular-text" value="<?php echo esc_attr( $website ); ?>" placeholder="https://"></td>
                </tr>
                <tr>
                    <th><label>Passphrase<br><small style="font-weight:400;color:#666">Leave blank to keep existing</small></label></th>
                    <td>
                        <input type="text" name="club_pin" class="regular-text" value="" autocomplete="off" autocapitalize="none" spellcheck="false"
                               placeholder="<?php echo $has_pin ? '(set — enter new to change)' : 'word.word.word'; ?>">
                        <?php if ( $has_pin ) echo '<span style="color:#2e7d32;font-size:12px;margin-left:8px">🔑 Passphrase set</span>'; ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="lgw-club-form-section">
            <h3>Badge</h3>
            <table class="form-table lgw-club-form-table">
                <tr>
                    <th><label>Badge Image</label></th>
                    <td>
                        <input type="hidden" name="club_badge" class="lgw-club-badge-url" value="<?php echo esc_attr( $badge_url ); ?>">
                        <button type="button" class="button lgw-club-pick-badge">Choose Image</button>
                        <span class="lgw-club-badge-preview-wrap">
                            <?php if ( $badge_url ) : ?>
                                <img class="lgw-club-badge-preview" src="<?php echo esc_url( $badge_url ); ?>">
                            <?php else : ?>
                                <img class="lgw-club-badge-preview" src="" style="display:none">
                            <?php endif; ?>
                        </span>
                        <button type="button" class="button-link lgw-club-clear-badge" <?php echo $badge_url ? '' : 'style="display:none"'; ?>>Remove</button>
                    </td>
                </tr>
                <tr>
                    <th><label>Match Type</label></th>
                    <td>
                        <select name="club_badge_type">
                            <option value="club"  <?php selected( $badge_type, 'club' );  ?>>Club prefix (e.g. ARDS matches ARDS A, ARDS B)</option>
                            <option value="exact" <?php selected( $badge_type, 'exact' ); ?>>Exact team name</option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>

        <div class="lgw-club-form-section">
            <h3>Contacts</h3>
            <p class="description">Secretary, President, and Green Keeper are always shown. Add extra contacts as needed.</p>
            <div class="lgw-club-contacts-list" id="lgw-contacts-list">
            <?php foreach ( $contacts as $ct ) : ?>
                <?php lgw_clubs_contact_row( $ct['role'], $ct['name'], $ct['phone'], $ct['email'] ); ?>
            <?php endforeach; ?>
            </div>
            <button type="button" class="button lgw-club-add-contact" style="margin-top:8px">+ Add Contact</button>
        </div>

        <div class="lgw-club-form-section">
            <h3>Facilities</h3>
            <table class="form-table lgw-club-form-table">
                <tr>
                    <th><label>Greens</label></th>
                    <td>
                        <input type="number" name="fac_greens" id="fac_greens" class="small-text" value="<?php echo esc_attr( $fac_greens ); ?>" min="0" max="20">
                        <span style="margin-left:12px;color:#666;font-size:13px">Rinks:
                            <input type="number" name="fac_rinks" id="fac_rinks" class="small-text" value="<?php echo esc_attr( $fac_rinks_val ); ?>" min="0" max="200">
                            <span class="lgw-fac-rinks-hint" style="font-size:12px;color:#999;margin-left:6px">(default: greens × 6)</span>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Amenities</th>
                    <td>
                        <label style="margin-right:16px"><input type="checkbox" name="fac_floodlights" value="1" <?php checked( $fac_floodlights ); ?>> Floodlights</label>
                        <label style="margin-right:16px"><input type="checkbox" name="fac_bar"         value="1" <?php checked( $fac_bar );         ?>> Bar</label>
                        <label><input type="checkbox" name="fac_changing" value="1" <?php checked( $fac_changing ); ?>> Changing Rooms</label>
                    </td>
                </tr>
                <tr>
                    <th><label>Car Parking</label></th>
                    <td>
                        <select name="fac_parking">
                            <option value="none"    <?php selected( $fac_parking, 'none' );    ?>>None</option>
                            <option value="street"  <?php selected( $fac_parking, 'street' );  ?>>On Street</option>
                            <option value="private" <?php selected( $fac_parking, 'private' ); ?>>Private</option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>

        <div class="lgw-club-form-section">
            <h3>Scorecard Submission</h3>
            <table class="form-table lgw-club-form-table">
                <tr>
                    <th><label for="can_submit_<?php echo esc_attr( $slug ?? 'new' ); ?>">Allow submission</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="can_submit" id="can_submit_<?php echo esc_attr( $slug ?? 'new' ); ?>" value="1" <?php checked( $can_submit ); ?>>
                            This club can submit scorecards
                        </label>
                        <p class="description" style="margin-top:4px">When the global mode is <em>Admin Only</em>, this club can still submit. Has no effect when the global mode is <em>Disabled</em>.</p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="lgw-club-form-actions">
            <button type="submit" class="button button-primary lgw-club-save-btn">Save Club</button>
            <?php if ( ! $is_new ) : ?>
                <button type="button" class="button lgw-club-delete-btn" data-slug="<?php echo esc_attr( $slug ); ?>"
                        style="color:#b32d2e;border-color:#b32d2e;margin-left:auto">Delete Club</button>
            <?php endif; ?>
            <span class="lgw-club-save-msg" style="display:none;margin-left:12px;font-size:13px"></span>
        </div>

    </form>
    <?php
}

// ── Single contact row partial ────────────────────────────────────────────────

function lgw_clubs_contact_row( $role = '', $name = '', $phone = '', $email = '', $removable = null ) {
    $standard = array( 'Secretary', 'President', 'Green Keeper' );
    $is_std   = in_array( $role, $standard, true );
    // Standard roles cannot be removed; extra ones can
    $can_remove = $removable !== null ? $removable : ! $is_std;
    ?>
    <div class="lgw-contact-row">
        <?php if ( $is_std ) : ?>
            <input type="hidden" name="contact_role[]" value="<?php echo esc_attr( $role ); ?>">
            <span class="lgw-contact-role-label"><?php echo esc_html( $role ); ?></span>
        <?php else : ?>
            <input type="text" name="contact_role[]" class="lgw-contact-role-input" value="<?php echo esc_attr( $role ); ?>" placeholder="Role (e.g. Treasurer)">
        <?php endif; ?>
        <input type="text"  name="contact_name[]"  class="regular-text" value="<?php echo esc_attr( $name ); ?>"  placeholder="Full name">
        <input type="tel"   name="contact_phone[]" class="lgw-contact-phone" value="<?php echo esc_attr( $phone ); ?>" placeholder="Phone">
        <input type="email" name="contact_email[]" class="lgw-contact-email" value="<?php echo esc_attr( $email ); ?>" placeholder="Email">
        <?php if ( $can_remove ) : ?>
            <button type="button" class="button-link-delete lgw-contact-remove" title="Remove">✕</button>
        <?php else : ?>
            <span class="lgw-contact-remove-placeholder"></span>
        <?php endif; ?>
    </div>
    <?php
}

// ── Inline CSS ────────────────────────────────────────────────────────────────

function lgw_clubs_inline_styles() { ?>
<style>
/* Grid */
.lgw-clubs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 12px;
    margin: 16px 0 24px;
    max-width: 1100px;
}
.lgw-club-card {
    background: #fff;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    padding: 12px 14px;
    cursor: pointer;
    transition: border-color .15s, box-shadow .15s;
    outline: none;
}
.lgw-club-card:hover,
.lgw-club-card:focus { border-color: #072a82; box-shadow: 0 2px 8px rgba(7,42,130,.12); }
.lgw-club-card[aria-expanded="true"] { border-color: #072a82; background: #f5f7ff; }
.lgw-club-card-inner { display: flex; align-items: center; gap: 12px; }
.lgw-club-card-badge { width: 44px; height: 44px; object-fit: contain; flex-shrink: 0; }
.lgw-club-card-initials {
    width: 44px; height: 44px; flex-shrink: 0;
    background: #072a82; color: #fff;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 14px; letter-spacing: .5px;
}
.lgw-club-card-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.lgw-club-card-info strong { font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lgw-club-card-meta { font-size: 11px; color: #666; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lgw-club-card-pin-badge { font-size: 16px; flex-shrink: 0; }

/* Detail panel */
.lgw-club-detail-panel {
    background: #fff;
    border: 2px solid #072a82;
    border-radius: 8px;
    margin: 0 0 24px;
    max-width: 800px;
    overflow: hidden;
}
.lgw-club-detail-header {
    display: flex; align-items: center; justify-content: space-between;
    background: #072a82; color: #fff;
    padding: 12px 18px;
}
.lgw-club-detail-header h2 { margin: 0; color: #fff; font-size: 16px; }
.lgw-club-detail-close {
    background: none; border: none; color: #fff;
    font-size: 18px; cursor: pointer; padding: 0 4px; line-height: 1;
}
.lgw-club-detail-close:hover { opacity: .75; }
#lgw-club-detail-body,
#lgw-club-new-body { padding: 20px 22px; }

/* Form sections */
.lgw-club-form-section { margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #eee; }
.lgw-club-form-section:last-of-type { border-bottom: none; }
.lgw-club-form-section h3 { margin: 0 0 10px; font-size: 14px; text-transform: uppercase; letter-spacing: .5px; color: #072a82; }
.lgw-club-form-table { margin: 0 !important; }
.lgw-club-form-table th { width: 160px; font-weight: 600; font-size: 13px; padding: 8px 0; }
.lgw-club-form-table td { padding: 6px 0; }
.lgw-club-form-table textarea.regular-text { width: 340px; }

/* Badge preview */
.lgw-club-badge-preview-wrap { display: inline-flex; align-items: center; margin-left: 10px; }
.lgw-club-badge-preview { width: 48px; height: 48px; object-fit: contain; border: 1px solid #ddd; border-radius: 4px; margin-right: 8px; }

/* Contacts */
.lgw-club-contacts-list { display: flex; flex-direction: column; gap: 6px; max-width: 700px; }
.lgw-contact-row {
    display: grid;
    grid-template-columns: 130px 1fr 130px 180px 28px;
    gap: 6px;
    align-items: center;
}
.lgw-contact-role-label {
    font-weight: 600; font-size: 13px; color: #444;
    padding: 0 6px;
}
.lgw-contact-role-input { width: 100%; }
.lgw-contact-phone { width: 100%; }
.lgw-contact-email { width: 100%; }
.lgw-contact-remove { color: #b32d2e; font-size: 14px; }
.lgw-contact-remove-placeholder { display: block; width: 28px; }

/* Actions */
.lgw-club-form-actions {
    display: flex; align-items: center; gap: 10px;
    padding-top: 14px; border-top: 1px solid #eee; margin-top: 4px;
}
.lgw-club-save-msg.success { color: #2e7d32; font-weight: 600; }
.lgw-club-save-msg.error   { color: #b32d2e; font-weight: 600; }
</style>
<?php }

// ── Inline JavaScript ─────────────────────────────────────────────────────────

function lgw_clubs_inline_script() { ?>
<script>
(function(){
    var grid       = document.getElementById('lgw-clubs-grid');
    var detailPanel= document.getElementById('lgw-club-detail-panel');
    var detailBody = document.getElementById('lgw-club-detail-body');
    var detailTitle= document.getElementById('lgw-club-detail-title');
    var newPanel   = document.getElementById('lgw-club-new-panel');
    var nonce      = lgwClubsData.nonce;
    var ajaxUrl    = lgwClubsData.ajaxUrl;
    var clubs      = lgwClubsData.clubs;  // array indexed same as grid cards

    // ── Helpers ───────────────────────────────────────────────────────────────

    function findClub(slug) {
        for (var i = 0; i < clubs.length; i++) {
            if (clubs[i].slug === slug) return clubs[i];
        }
        return null;
    }

    function serializeForm(form) {
        var data = new FormData();
        // Regular inputs
        form.querySelectorAll('input, select, textarea').forEach(function(el) {
            if (!el.name) return;
            if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) return;
            data.append(el.name, el.value);
        });
        return data;
    }

    // ── Build contact rows HTML (reusable) ────────────────────────────────────

    var STANDARD_ROLES = ['Secretary', 'President', 'Green Keeper'];

    function buildContactRow(role, name, phone, email) {
        var isStd = STANDARD_ROLES.indexOf(role) !== -1;
        var roleCell = isStd
            ? '<input type="hidden" name="contact_role[]" value="' + esc(role) + '">' +
              '<span class="lgw-contact-role-label">' + esc(role) + '</span>'
            : '<input type="text" name="contact_role[]" class="lgw-contact-role-input" value="' + esc(role) + '" placeholder="Role">';
        var removeBtn = isStd
            ? '<span class="lgw-contact-remove-placeholder"></span>'
            : '<button type="button" class="button-link-delete lgw-contact-remove" title="Remove">✕</button>';
        return '<div class="lgw-contact-row">' +
            roleCell +
            '<input type="text"  name="contact_name[]"  class="regular-text" value="' + esc(name)  + '" placeholder="Full name">' +
            '<input type="tel"   name="contact_phone[]" class="lgw-contact-phone" value="' + esc(phone) + '" placeholder="Phone">' +
            '<input type="email" name="contact_email[]" class="lgw-contact-email" value="' + esc(email) + '" placeholder="Email">' +
            removeBtn +
            '</div>';
    }

    // ── Build form HTML from club data object ─────────────────────────────────

    function buildForm(club, isNew) {
        var slug     = club ? club.slug     : '';
        var name     = club ? club.name     : '';
        var address  = club ? club.address  : '';
        var website  = club ? club.website  : '';
        var pinSet    = club ? club.pin_set   : false;
        var canSubmit = club ? !!club.can_submit : false;
        var badgeUrl  = club ? club.badge_url : '';
        var badgeType= club ? club.badge_type : 'club';
        var contacts  = club ? (club.contacts   || []) : [];
        var fac       = club ? (club.facilities || {}) : {};
        var facGreens  = fac.greens      || 0;
        var facRinks   = fac.rinks       || 0;
        var facFlood   = fac.floodlights || false;
        var facParking = fac.parking     || 'none';
        var facBar     = fac.bar         || false;
        var facChange  = fac.changing    || false;

        // Ensure standard roles
        var existingRoles = contacts.map(function(c){ return c.role; });
        STANDARD_ROLES.forEach(function(role){
            if (existingRoles.indexOf(role) === -1) contacts.push({role:role,name:'',phone:'',email:''});
        });
        // Sort standard first
        contacts.sort(function(a,b){
            var ai = STANDARD_ROLES.indexOf(a.role); if (ai===-1) ai=99;
            var bi = STANDARD_ROLES.indexOf(b.role); if (bi===-1) bi=99;
            return ai-bi;
        });

        var contactRows = contacts.map(function(c){
            return buildContactRow(c.role, c.name, c.phone||'', c.email||'');
        }).join('');

        var pinPlaceholder = pinSet ? '(set — enter new to change)' : 'word.word.word';
        var pinIndicator   = pinSet ? '<span style="color:#2e7d32;font-size:12px;margin-left:8px">🔑 Passphrase set</span>' : '';
        var badgePreview   = badgeUrl
            ? '<img class="lgw-club-badge-preview" src="' + esc(badgeUrl) + '">'
            : '<img class="lgw-club-badge-preview" src="" style="display:none">';
        var clearBtn = badgeUrl ? '<button type="button" class="button-link lgw-club-clear-badge">Remove</button>'
                                : '<button type="button" class="button-link lgw-club-clear-badge" style="display:none">Remove</button>';
        var deleteBtn = isNew ? '' :
            '<button type="button" class="button lgw-club-delete-btn" data-slug="' + esc(slug) + '" style="color:#b32d2e;border-color:#b32d2e;margin-left:auto">Delete Club</button>';

        return '<form class="lgw-club-edit-form" data-slug="' + esc(slug) + '" data-is-new="' + (isNew?'1':'0') + '">' +

        '<div class="lgw-club-form-section"><h3>Club Details</h3>' +
        '<table class="form-table lgw-club-form-table">' +
        '<tr><th><label>Club Name</label></th><td>' +
            '<input type="text" name="club_name" class="regular-text" value="' + esc(name) + '" required></td></tr>' +
        '<tr><th><label>Address</label></th><td>' +
            '<textarea name="club_address" class="regular-text" rows="3" placeholder="Clubhouse address">' + esc(address) + '</textarea></td></tr>' +
        '<tr><th><label>Website</label></th><td>' +
            '<input type="url" name="club_website" class="regular-text" value="' + esc(website) + '" placeholder="https://"></td></tr>' +
        '<tr><th><label>Passphrase<br><small style="font-weight:400;color:#666">Leave blank to keep existing</small></label></th><td>' +
            '<input type="text" name="club_pin" class="regular-text" value="" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="' + esc(pinPlaceholder) + '">' +
            pinIndicator + '</td></tr>' +
        '</table></div>' +

        '<div class="lgw-club-form-section"><h3>Badge</h3>' +
        '<table class="form-table lgw-club-form-table">' +
        '<tr><th><label>Badge Image</label></th><td>' +
            '<input type="hidden" name="club_badge" class="lgw-club-badge-url" value="' + esc(badgeUrl) + '">' +
            '<button type="button" class="button lgw-club-pick-badge">Choose Image</button>' +
            '<span class="lgw-club-badge-preview-wrap">' + badgePreview + '</span>' +
            clearBtn + '</td></tr>' +
        '<tr><th><label>Match Type</label></th><td>' +
            '<select name="club_badge_type">' +
            '<option value="club"'  + (badgeType==='club'  ? ' selected':'') + '>Club prefix (e.g. ARDS matches ARDS A, ARDS B)</option>' +
            '<option value="exact"' + (badgeType==='exact' ? ' selected':'') + '>Exact team name</option>' +
            '</select></td></tr>' +
        '</table></div>' +

        '<div class="lgw-club-form-section"><h3>Contacts</h3>' +
        '<p class="description">Secretary, President, and Green Keeper are always shown. Add extra contacts as needed.</p>' +
        '<div class="lgw-club-contacts-list" id="lgw-contacts-list">' + contactRows + '</div>' +
        '<button type="button" class="button lgw-club-add-contact" style="margin-top:8px">+ Add Contact</button>' +
        '</div>' +

        '<div class="lgw-club-form-section"><h3>Scorecard Submission</h3>' +
        '<table class="form-table lgw-club-form-table">' +
        '<tr><th><label>Allow submission</label></th><td>' +
            '<label><input type="checkbox" name="can_submit" value="1"' + (canSubmit ? ' checked' : '') + '> This club can submit scorecards</label>' +
            '<p class="description" style="margin-top:4px">When the global mode is <em>Admin Only</em>, this club can still submit. Has no effect when the global mode is <em>Disabled</em>.</p>' +
        '</td></tr>' +
        '</table></div>' +

        '<div class="lgw-club-form-section"><h3>Facilities</h3>' +
        '<table class="form-table lgw-club-form-table">' +
        '<tr><th><label>Greens</label></th><td>' +
            '<input type="number" name="fac_greens" id="fac_greens" class="small-text lgw-fac-greens" value="' + facGreens + '" min="0" max="20">' +
            '<span style="margin-left:12px;color:#666;font-size:13px">Rinks: ' +
            '<input type="number" name="fac_rinks" id="fac_rinks" class="small-text lgw-fac-rinks" value="' + facRinks + '" min="0" max="200">' +
            '<span class="lgw-fac-rinks-hint" style="font-size:12px;color:#999;margin-left:6px">(default: greens × 6)</span>' +
            '</span></td></tr>' +
        '<tr><th>Amenities</th><td>' +
            '<label style="margin-right:16px"><input type="checkbox" name="fac_floodlights" value="1"' + (facFlood  ? ' checked' : '') + '> Floodlights</label>' +
            '<label style="margin-right:16px"><input type="checkbox" name="fac_bar"         value="1"' + (facBar    ? ' checked' : '') + '> Bar</label>' +
            '<label><input type="checkbox" name="fac_changing" value="1"'                              + (facChange ? ' checked' : '') + '> Changing Rooms</label>' +
        '</td></tr>' +
        '<tr><th><label>Car Parking</label></th><td>' +
            '<select name="fac_parking">' +
            '<option value="none"'    + (facParking==='none'    ? ' selected' : '') + '>None</option>' +
            '<option value="street"'  + (facParking==='street'  ? ' selected' : '') + '>On Street</option>' +
            '<option value="private"' + (facParking==='private' ? ' selected' : '') + '>Private</option>' +
            '</select></td></tr>' +
        '</table></div>' +

        '<div class="lgw-club-form-actions">' +
        '<button type="submit" class="button button-primary lgw-club-save-btn">Save Club</button>' +
        deleteBtn +
        '<span class="lgw-club-save-msg" style="display:none;margin-left:12px;font-size:13px"></span>' +
        '</div>' +
        '</form>';
    }

    // ── Open a club for editing ───────────────────────────────────────────────

    var activeCard = null;

    function openClub(slug) {
        var club = findClub(slug);
        if (!club) return;

        // Deactivate previous card
        if (activeCard) activeCard.setAttribute('aria-expanded','false');

        // Find and highlight new card
        activeCard = grid.querySelector('[data-slug="' + slug + '"]');
        if (activeCard) activeCard.setAttribute('aria-expanded','true');

        detailTitle.textContent = club.name;
        detailBody.innerHTML = buildForm(club, false);
        newPanel.style.display = 'none';
        detailPanel.style.display = 'block';
        detailPanel.scrollIntoView({behavior:'smooth', block:'nearest'});
        bindFormEvents(detailBody, detailPanel);
    }

    // ── Card click handler ────────────────────────────────────────────────────

    grid.addEventListener('click', function(e) {
        var card = e.target.closest('.lgw-club-card');
        if (!card) return;
        var slug = card.dataset.slug;
        // Toggle: clicking the open card closes the panel
        if (card === activeCard && detailPanel.style.display !== 'none') {
            closeDetail();
        } else {
            openClub(slug);
        }
    });
    grid.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            var card = e.target.closest('.lgw-club-card');
            if (card) { e.preventDefault(); card.click(); }
        }
    });

    function closeDetail() {
        detailPanel.style.display = 'none';
        if (activeCard) activeCard.setAttribute('aria-expanded','false');
        activeCard = null;
    }

    document.getElementById('lgw-club-detail-close').addEventListener('click', closeDetail);

    // ── New club ──────────────────────────────────────────────────────────────

    document.getElementById('lgw-clubs-add-new').addEventListener('click', function() {
        closeDetail();
        // Build fresh new-club form
        document.getElementById('lgw-club-new-body').innerHTML = buildForm(null, true);
        newPanel.style.display = 'block';
        newPanel.scrollIntoView({behavior:'smooth', block:'nearest'});
        bindFormEvents(document.getElementById('lgw-club-new-body'), newPanel);
    });

    document.getElementById('lgw-club-new-close').addEventListener('click', function() {
        newPanel.style.display = 'none';
    });

    // ── Bind events on a form container ──────────────────────────────────────

    function bindFormEvents(container, panel) {

        // Add contact row
        var addBtn = container.querySelector('.lgw-club-add-contact');
        if (addBtn) {
            addBtn.addEventListener('click', function() {
                var list = container.querySelector('.lgw-club-contacts-list');
                var div  = document.createElement('div');
                div.innerHTML = buildContactRow('', '', '', '');
                list.appendChild(div.firstChild);
                bindRemoveContacts(list);
            });
        }

        bindRemoveContacts(container);

        // Greens → rinks auto-calc
        var greensInput = container.querySelector('[name="fac_greens"]');
        var rinksInput  = container.querySelector('[name="fac_rinks"]');
        if (greensInput && rinksInput) {
            greensInput.addEventListener('input', function() {
                // Only auto-fill rinks if it hasn't been manually set
                var g = parseInt(greensInput.value) || 0;
                if (!rinksInput.dataset.manual) {
                    rinksInput.value = g * 6;
                }
            });
            rinksInput.addEventListener('input', function() {
                rinksInput.dataset.manual = '1';
            });
            // If rinks was already set (existing club), mark as manual
            if (parseInt(rinksInput.value) > 0) {
                rinksInput.dataset.manual = '1';
            }
        }

        // Badge picker
        var pickBtn = container.querySelector('.lgw-club-pick-badge');
        if (pickBtn) {
            pickBtn.addEventListener('click', function() {
                var frame = wp.media({title:'Choose Badge', button:{text:'Use as Badge'}, multiple:false});
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    container.querySelector('.lgw-club-badge-url').value = att.url;
                    var prev = container.querySelector('.lgw-club-badge-preview');
                    prev.src = att.url;
                    prev.style.display = '';
                    var clr = container.querySelector('.lgw-club-clear-badge');
                    if (clr) clr.style.display = '';
                });
                frame.open();
            });
        }

        var clearBtn = container.querySelector('.lgw-club-clear-badge');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                container.querySelector('.lgw-club-badge-url').value = '';
                var prev = container.querySelector('.lgw-club-badge-preview');
                prev.src = ''; prev.style.display = 'none';
                clearBtn.style.display = 'none';
            });
        }

        // Form submit
        var form = container.querySelector('.lgw-club-edit-form');
        if (form) form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitForm(form, panel);
        });

        // Delete
        var delBtn = container.querySelector('.lgw-club-delete-btn');
        if (delBtn) {
            delBtn.addEventListener('click', function() {
                var slug = delBtn.dataset.slug;
                var club = findClub(slug);
                if (!club) return;
                if (!confirm('Delete club "' + club.name + '"? This cannot be undone.')) return;
                deleteClub(slug, panel);
            });
        }
    }

    function bindRemoveContacts(container) {
        container.querySelectorAll('.lgw-contact-remove').forEach(function(btn) {
            // Prevent double-binding
            if (btn._lgwBound) return;
            btn._lgwBound = true;
            btn.addEventListener('click', function() {
                btn.closest('.lgw-contact-row').remove();
            });
        });
    }

    // ── Submit ────────────────────────────────────────────────────────────────

    function submitForm(form, panel) {
        var msg  = form.querySelector('.lgw-club-save-msg');
        var btn  = form.querySelector('.lgw-club-save-btn');
        var fd   = serializeForm(form);
        var slug = form.dataset.slug;
        var isNew= form.dataset.isNew === '1';

        fd.append('action',       'lgw_save_club');
        fd.append('nonce',        nonce);
        fd.append('club_slug',    slug);
        fd.append('club_is_new',  isNew ? '1' : '');

        btn.disabled = true;
        showMsg(msg, '', '');

        fetch(ajaxUrl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(res) {
                btn.disabled = false;
                if (res.success) {
                    showMsg(msg, '✓ Saved', 'success');
                    var newSlug = res.data.slug;
                    var newName = res.data.name;

                    if (isNew) {
                        // Add a new card to the grid
                        addCardToGrid(newSlug, newName);
                        panel.style.display = 'none';
                    } else {
                        // Update in-memory data and refresh card
                        updateClubData(slug, newSlug, newName, form);
                        refreshCard(slug, newSlug, newName);
                        // Update form slug for any subsequent saves
                        form.dataset.slug = newSlug;
                        detailTitle.textContent = newName;
                        // Refresh passphrase indicator if pin was changed
                        var pinField = form.querySelector('[name="club_pin"]');
                        if (pinField && pinField.value !== '') {
                            pinField.placeholder = '(set — enter new to change)';
                            pinField.value = '';
                            var ind = form.querySelector('.lgw-club-pin-indicator');
                            if (!ind) {
                                var span = document.createElement('span');
                                span.className = 'lgw-club-pin-indicator';
                                span.style.cssText = 'color:#2e7d32;font-size:12px;margin-left:8px';
                                span.textContent = '🔑 Passphrase set';
                                pinField.parentNode.appendChild(span);
                            }
                        }
                    }
                } else {
                    showMsg(msg, '✗ ' + (res.data || 'Save failed'), 'error');
                }
            })
            .catch(function() {
                btn.disabled = false;
                showMsg(msg, '✗ Network error', 'error');
            });
    }

    function updateClubData(oldSlug, newSlug, newName, form) {
        var club = findClub(oldSlug);
        if (!club) return;
        club.slug      = newSlug;
        club.name      = newName;
        club.address   = (form.querySelector('[name="club_address"]') || {}).value || '';
        club.website   = (form.querySelector('[name="club_website"]') || {}).value || '';
        club.badge_url = (form.querySelector('[name="club_badge"]')   || {}).value || '';
        club.badge_type= (form.querySelector('[name="club_badge_type"]') || {}).value || 'club';
        club.pin_set    = true; // conservative — might have been set
        club.can_submit = !!(form.querySelector('[name="can_submit"]') || {}).checked;
        club.facilities = {
            greens:      parseInt((form.querySelector('[name="fac_greens"]')  || {}).value || 0),
            rinks:       parseInt((form.querySelector('[name="fac_rinks"]')   || {}).value || 0),
            floodlights: !!(form.querySelector('[name="fac_floodlights"]') || {}).checked,
            parking:          (form.querySelector('[name="fac_parking"]')  || {}).value || 'none',
            bar:         !!(form.querySelector('[name="fac_bar"]')         || {}).checked,
            changing:    !!(form.querySelector('[name="fac_changing"]')    || {}).checked,
        };
        // Rebuild contacts
        var roles  = Array.from(form.querySelectorAll('[name="contact_role[]"]')).map(function(e){return e.value;});
        var names  = Array.from(form.querySelectorAll('[name="contact_name[]"]')).map(function(e){return e.value;});
        var phones = Array.from(form.querySelectorAll('[name="contact_phone[]"]')).map(function(e){return e.value;});
        var emails = Array.from(form.querySelectorAll('[name="contact_email[]"]')).map(function(e){return e.value;});
        club.contacts = roles.map(function(r,i){ return {role:r,name:names[i],phone:phones[i],email:emails[i]}; });
    }

    function refreshCard(oldSlug, newSlug, newName) {
        var card = grid.querySelector('[data-slug="' + oldSlug + '"]');
        if (!card) return;
        card.dataset.slug = newSlug;
        var strong = card.querySelector('strong');
        if (strong) strong.textContent = newName;
        // Update initials if no badge
        var initials = card.querySelector('.lgw-club-card-initials');
        if (initials) initials.textContent = newName.substring(0,2).toUpperCase();
    }

    function addCardToGrid(slug, name) {
        var card = document.createElement('div');
        card.className = 'lgw-club-card';
        card.dataset.slug = slug;
        card.setAttribute('tabindex','0');
        card.setAttribute('role','button');
        card.setAttribute('aria-expanded','false');
        card.innerHTML = '<div class="lgw-club-card-inner">' +
            '<span class="lgw-club-card-initials">' + esc(name.substring(0,2).toUpperCase()) + '</span>' +
            '<div class="lgw-club-card-info"><strong>' + esc(name) + '</strong></div>' +
            '<span class="lgw-club-card-pin-badge unset" title="No passphrase">🔓</span>' +
            '</div>';
        grid.appendChild(card);
        // Add to in-memory list
        clubs.push({slug:slug, name:name, pin_set:false, address:'', website:'', badge_url:'', badge_type:'club', contacts:[]});
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    function deleteClub(slug, panel) {
        var fd = new FormData();
        fd.append('action',    'lgw_delete_club');
        fd.append('nonce',     nonce);
        fd.append('club_slug', slug);

        fetch(ajaxUrl, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res.success) {
                    // Remove card from grid
                    var card = grid.querySelector('[data-slug="' + slug + '"]');
                    if (card) card.remove();
                    // Remove from in-memory list
                    clubs = clubs.filter(function(c){ return c.slug !== slug; });
                    panel.style.display = 'none';
                    activeCard = null;
                } else {
                    alert('Delete failed: ' + (res.data || 'unknown error'));
                }
            });
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    function showMsg(el, text, cls) {
        el.textContent = text;
        el.className   = 'lgw-club-save-msg ' + cls;
        el.style.display = text ? '' : 'none';
        if (text && cls === 'success') {
            setTimeout(function(){ el.style.display='none'; }, 3000);
        }
    }

    function esc(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

}());
</script>
<?php }
