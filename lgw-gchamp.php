<?php
/**
 * LGW Group Stage into Knockout Championships
 *
 * A parallel championship system to lgw-champ.php that supports a round-robin
 * group stage feeding into a single-elimination knockout bracket.
 *
 * Storage : WP option  lgw_gchamp_{id}
 * Shortcode: [lgw_gchamp id="..."]
 *
 * Build order (spec §Appendix B):
 *   Step 1 (v7.2.0) — Data model + admin config panel
 *   Step 2 (v7.2.1) — Date preference field + admin override
 *   Step 3 (v7.2.2) — Draw algorithm + group fixture generation
 *   Step 4 (v7.2.3) — Front-end group cards (static)
 *   Step 5 (v7.2.4) — Group score entry + standings recalculation
 *   Step 6 (v7.2.5) — Qualification logic + knockout seeding
 *   Revised (v7.2.7)  — Days-as-sections data model                   ← THIS FILE
 *   Step 7 (v7.2.6) — Player appearance logging for group games
 *   Step 8 (v7.2.7) — Live standings polling + qualification indicators
 *   Step 9 (v7.2.8) — Full integration test + documentation
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin menu registration ────────────────────────────────────────────────────
// Called from lgw_admin_menu() in lgw-division-widget.php — no direct hook here.
function lgw_gchamps_register_submenu() {
    // Group championships share the Championships admin page (lgw-champs).
    // They appear as a separate submenu only for create/edit; the list is merged.
    // Nothing to register as a separate page — the existing lgw-champs page
    // is extended to show both types (see lgw_gchamp_extend_champs_list_page()).
}

// ── Admin: handle saves, resets, deletes ──────────────────────────────────────
add_action( 'admin_init', 'lgw_gchamp_handle_admin_actions' );
function lgw_gchamp_handle_admin_actions() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // ── Save ──
    if ( isset( $_POST['lgw_gchamp_save_nonce'] )
        && wp_verify_nonce( $_POST['lgw_gchamp_save_nonce'], 'lgw_gchamp_save' ) ) {

        $champ_id = sanitize_key( $_POST['gchamp_id'] ?? '' );
        if ( ! $champ_id ) {
            wp_redirect( admin_url( 'admin.php?page=lgw-champs&gchamp_error=missing_id' ) );
            exit;
        }

        $existing = get_option( 'lgw_gchamp_' . $champ_id, array() );

        $entries_raw = sanitize_textarea_field( wp_unslash( $_POST['lgw_gchamp_entries'] ?? '' ) );
        $entries     = array_values( array_filter( array_map( 'trim', explode( "\n", $entries_raw ) ) ) );

        // Validate entry format — each entry must contain a comma (Player Name, Club)
        $bad_entries = array_filter( $entries, fn($e) => strpos($e, ',') === false );
        if ( ! empty( $bad_entries ) ) {
            $count = count( $bad_entries );
            $examples = implode( ', ', array_slice( $bad_entries, 0, 3 ) );
            wp_redirect( admin_url( 'admin.php?page=lgw-champs&gedit=' . $champ_id . '&entry_format_error=1&bad_count=' . $count ) );
            exit;
        }

        $dates_raw = sanitize_textarea_field( $_POST['lgw_gchamp_dates'] ?? '' );
        $dates     = array_values( array_filter( array_map( 'trim', explode( "\n", $dates_raw ) ) ) );

        $has_ko_bracket   = isset( $_POST['lgw_gchamp_has_ko'] ) ? 1 : 0;
        $ko_bracket_size  = intval( $_POST['lgw_gchamp_ko_bracket_size'] ?? 0 );
        $finals_q_per_day = max( 1, min( 4, intval( $_POST['lgw_gchamp_finals_q_per_day'] ?? 1 ) ) );
        // For 2-qualifier days: play a ranking day-final, or leave it as a
        // Finals-Week fixture (both finalists qualify without a day game).
        $ko_play_day_final = isset( $_POST['lgw_gchamp_ko_play_day_final'] ) ? 1 : 0;

        // ── Days config ───────────────────────────────────────────────────────
        $days_config    = array();
        $num_days       = max( 1, intval( $_POST['lgw_gchamp_num_days'] ?? 1 ) );
        $day_names_post = $_POST['lgw_gchamp_day_names'] ?? array();
        $day_dates_post = $_POST['lgw_gchamp_day_dates'] ?? array();
        $day_tgs_post   = $_POST['lgw_gchamp_day_tgs']   ?? array();
        $day_wpg_post   = $_POST['lgw_gchamp_day_wpg']   ?? array();
        $day_bru_post   = $_POST['lgw_gchamp_day_bru']   ?? array();

        $day_kobs_post      = $_POST['lgw_gchamp_day_kobs']      ?? array();
        $day_locations_post = $_POST['lgw_gchamp_day_locations'] ?? array();
        $day_fq_post        = $_POST['lgw_gchamp_day_fq']         ?? array();
        for ( $i = 0; $i < $num_days; $i++ ) {
            $days_config[] = array(
                'id'                => $i,
                'name'              => sanitize_text_field( wp_unslash( $day_names_post[$i] ?? ( 'Day ' . ( $i + 1 ) ) ) ),
                'date'              => sanitize_text_field( wp_unslash( $day_dates_post[$i] ?? '' ) ),
                'location'          => sanitize_text_field( wp_unslash( $day_locations_post[$i] ?? '' ) ),
                'target_group_size' => max( 2, intval( $day_tgs_post[$i]  ?? 4 ) ),
                'winners_per_group' => max( 1, intval( $day_wpg_post[$i]  ?? 1 ) ),
                'best_runners_up'   => max( 0, intval( $day_bru_post[$i]  ?? 0 ) ),
                'ko_bracket_size'   => max( 0, intval( $day_kobs_post[$i] ?? 0 ) ),
                'finals_qualifiers' => max( 1, min( 4, intval( $day_fq_post[$i] ?? $finals_q_per_day ) ) ),
            );
        }

        // ── Preference fields setting ─────────────────────────────────────────
        $preference_fields_raw = $_POST['lgw_gchamp_pref_fields'] ?? array();
        $allowed_pref_fields   = array( 'date', 'location' );
        $preference_fields     = array_values( array_intersect( (array) $preference_fields_raw, $allowed_pref_fields ) );

        // ── Entry preferences (multi-field) ───────────────────────────────────
        $pref_date_raw     = $_POST['lgw_gchamp_entry_pref_date']     ?? array();
        $pref_location_raw = $_POST['lgw_gchamp_entry_pref_location'] ?? array();
        $entry_preferences = array();
        foreach ( $entries as $entry ) {
            $key   = md5( $entry );
            $pdata = array();
            $pd    = sanitize_text_field( wp_unslash( $pref_date_raw[ $key ]     ?? '' ) );
            $pl    = sanitize_text_field( wp_unslash( $pref_location_raw[ $key ] ?? '' ) );
            if ( $pd !== '' ) $pdata['date']     = $pd;
            if ( $pl !== '' ) $pdata['location'] = $pl;
            if ( ! empty( $pdata ) ) $entry_preferences[ $entry ] = $pdata;
        }
        // Merge/preserve any existing preferences not overwritten (handles entries not in POST)
        foreach ( ( $existing['entry_preferences'] ?? array() ) as $e => $p ) {
            if ( in_array( $e, $entries, true ) && ! isset( $entry_preferences[ $e ] ) ) {
                // Migrate legacy string (old date-only) to new array format
                $entry_preferences[ $e ] = is_array( $p ) ? $p : array( 'date' => $p );
            }
        }

        // ── Preserve draw state ───────────────────────────────────────────────
        $champ_data = array(
            'id'                   => $champ_id,
            'format'               => 'group_knockout',
            'title'                => sanitize_text_field( wp_unslash( $_POST['lgw_gchamp_title']      ?? '' ) ),
            'discipline'           => sanitize_text_field( $_POST['lgw_gchamp_discipline']             ?? 'singles' ),
            'season'               => sanitize_text_field( wp_unslash( $_POST['lgw_gchamp_season']     ?? '' ) ),
            'entries'              => $entries,
            'preference_fields'    => $preference_fields,
            'entry_preferences'    => $entry_preferences,
            'dates'                => $dates,
            'stats_eligible'       => isset( $_POST['lgw_gchamp_stats_eligible'] ) ? 1 : 0,
            'colour_scheme'        => sanitize_hex_color( $_POST['lgw_gchamp_colour'] ?? '' ) ?: '',
            'tab_colour'           => sanitize_hex_color( $_POST['lgw_gchamp_tab_colour'] ?? '' ) ?: '',
            'has_ko_bracket'       => $has_ko_bracket,
            'ko_bracket_size'          => $ko_bracket_size,
            'finals_qualifiers_per_day' => $finals_q_per_day,
            'ko_play_day_final'    => $ko_play_day_final,
            'days_config'          => $days_config,
            'days'                 => ( function() use ( $existing, $days_config ) {
                $drawn_days = $existing['days'] ?? array();
                // Sync editable-post-draw fields from days_config back onto drawn days
                foreach ( $days_config as $i => $cfg ) {
                    if ( isset( $drawn_days[$i] ) ) {
                        $drawn_days[$i]['finals_qualifiers'] = $cfg['finals_qualifiers'];
                        $drawn_days[$i]['location']          = $cfg['location'];
                        $drawn_days[$i]['date']              = $cfg['date'];
                        $drawn_days[$i]['name']              = $cfg['name'];
                        $drawn_days[$i]['ko_bracket_size']   = $cfg['ko_bracket_size'];
                    }
                }
                return $drawn_days;
            } )(),
            'draw_complete'        => $existing['draw_complete']        ?? false,
            'group_stage_complete' => $existing['group_stage_complete'] ?? false,
            'ko_bracket'           => $existing['ko_bracket']           ?? null,
            'qualifiers'           => $existing['qualifiers']           ?? array(),
        );

        update_option( 'lgw_gchamp_' . $champ_id, $champ_data );
        wp_redirect( admin_url( 'admin.php?page=lgw-champs&gedit=' . $champ_id . '&saved=1' ) );
        exit;
    }
    // ── Reset draw ──
    if ( isset( $_GET['greset_draw'], $_GET['gedit'] ) ) {
        $champ_id = sanitize_key( $_GET['gedit'] );
        if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'lgw_gchamp_reset_' . $champ_id ) ) {
            $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
            // Wipe per-day KO data too
            foreach ( $champ['days'] as &$_day ) {
                $_day['ko_bracket']    = null;
                $_day['ko_qualifiers'] = array();
                $_day['ko_complete']   = false;
                $_day['day_complete']  = false;
                $_day['qualifiers']    = array();
            }
            unset( $_day );
            $champ['days']                 = array();
            $champ['draw_complete']        = false;
            $champ['group_stage_complete'] = false;
            $champ['ko_bracket']           = null;
            $champ['qualifiers']           = array();
            $champ['draw_warnings']        = array();
            update_option( 'lgw_gchamp_' . $champ_id, $champ );

            // Clear all player appearances for this championship
            if ( function_exists( 'lgw_clear_all_champ_appearances' ) ) {
                lgw_clear_all_champ_appearances( $champ_id );
            }

            wp_redirect( admin_url( 'admin.php?page=lgw-champs&gedit=' . $champ_id . '&saved=1' ) );
            exit;
        }
    }

    // ── Delete ──
    if ( isset( $_GET['action'], $_GET['gid'] ) && $_GET['action'] === 'gdelete' ) {
        $del_id = sanitize_key( $_GET['gid'] );
        if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'lgw_gchamp_delete_' . $del_id ) ) {
            if ( function_exists( 'lgw_clear_all_champ_appearances' ) ) {
                lgw_clear_all_champ_appearances( $del_id );
            }
            delete_option( 'lgw_gchamp_' . $del_id );
            wp_redirect( admin_url( 'admin.php?page=lgw-champs&deleted=1' ) );
            exit;
        }
    }
}

// ── Admin page router ─────────────────────────────────────────────────────────
// Hooked from lgw_champs_admin_page() in lgw-champ.php via action.
add_action( 'lgw_champs_admin_router', 'lgw_gchamp_admin_router' );
function lgw_gchamp_admin_router() {
    $gedit = sanitize_key( $_GET['gedit'] ?? '' );
    $gact  = $_GET['gaction'] ?? '';

    if ( $gedit && ( $gact === 'edit' || isset( $_GET['gedit'] ) ) ) {
        lgw_gchamp_edit_page( $gedit );
        return true;
    }
    if ( $gact === 'new' ) {
        lgw_gchamp_edit_page( '' );
        return true;
    }
    return false;
}

// ── Helper: extend the existing championships list page ───────────────────────
// Called at the bottom of lgw_champs_list_page() via action hook.
add_action( 'lgw_champs_list_after', 'lgw_gchamp_list_section' );
function lgw_gchamp_list_section() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'lgw_gchamp_%' ORDER BY option_name"
    );
    $gchamps = array();
    foreach ( $rows as $row ) {
        $id  = substr( $row->option_name, strlen( 'lgw_gchamp_' ) );
        $val = maybe_unserialize( $row->option_value );
        if ( is_array( $val ) && isset( $val['title'] ) ) {
            $gchamps[ $id ] = $val;
        }
    }

    ?>
    <hr>
    <h2 style="display:flex;align-items:center;gap:16px">
        Group Stage Championships
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-champs&gaction=new' ) ); ?>"
           class="button button-primary">+ New Group Championship</a>
    </h2>
    <p style="color:#555;font-size:13px">
        These championships run a round-robin group stage followed by a knockout bracket.
        Use the shortcode <code>[lgw_gchamp id="..."]</code> to display on the front end.
    </p>

    <?php if ( empty( $gchamps ) ): ?>
        <p>No group-stage championships yet.
           <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-champs&gaction=new' ) ); ?>">Create one →</a>
        </p>
    <?php else: ?>
    <table class="widefat striped" style="max-width:1000px">
        <thead>
            <tr>
                <th>Title</th>
                <th>Season</th>
                <th>Discipline</th>
                <th>Entries</th>
                <th>Groups</th>
                <th>Format</th>
                <th>Draw</th>
                <th>Shortcode</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $gchamps as $id => $champ ):
            $days_cfg    = $champ['days_config'] ?? array();
            $num_days_l  = count( $days_cfg );
            $drawn       = ! empty( $champ['draw_complete'] );
            $gs_complete = ! empty( $champ['group_stage_complete'] );
            $ko_drawn    = ! empty( $champ['ko_bracket'] );
            $has_ko      = ! empty( $champ['has_ko_bracket'] );
        ?>
        <tr>
            <td><strong><?php echo esc_html( $champ['title'] ?? $id ); ?></strong></td>
            <td><?php echo esc_html( $champ['season'] ?? '—' ); ?></td>
            <td><?php echo esc_html( ucfirst( $champ['discipline'] ?? 'singles' ) ); ?></td>
            <td><?php echo count( $champ['entries'] ?? array() ); ?></td>
            <td>
                <?php echo $num_days_l; ?> day<?php echo $num_days_l !== 1 ? 's' : ''; ?>
                <?php if($drawn): ?>
                <br><small style="color:#666">
                <?php foreach($champ['days']??array() as $dd): $ng=count($dd['groups']??array()); if($ng): ?>
                    <?php echo esc_html($dd['name'].': '.$ng.'g · '.$dd['winners_per_group'].'W'.($dd['best_runners_up']>0?'+'.$dd['best_runners_up'].'R':'')); ?><br>
                <?php endif; endforeach; ?>
                </small>
                <?php endif; ?>
            </td>
            <td>
                <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#e8f0fe;color:#072a82">
                    Group<?php echo $has_ko?' + KO':''; ?>
                </span>
            </td>
            <td>
                <?php if ( $ko_drawn ): ?>
                    <span style="color:#138211">✅ KO drawn</span>
                <?php elseif ( $gs_complete ): ?>
                    <span style="color:#e67e22">⚡ Group stage done</span>
                <?php elseif ( $drawn ): ?>
                    <span style="color:#1a4e6e">🎲 Groups drawn</span>
                <?php else: ?>
                    <span style="color:#999">⏳ Not drawn</span>
                <?php endif; ?>
            </td>
            <td><code>[lgw_gchamp id="<?php echo esc_html( $id ); ?>"]</code></td>
            <td style="white-space:nowrap">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-champs&gedit=' . urlencode( $id ) ) ); ?>"
                   class="button button-small">Edit</a>
                <a href="#"
                   class="button button-small lgw-gchamp-copy-btn"
                   data-id="<?php echo esc_attr( $id ); ?>"
                   data-title="<?php echo esc_attr( $champ['title'] ?? $id ); ?>">Copy</a>
                <a href="<?php echo esc_url( wp_nonce_url(
                    admin_url( 'admin.php?page=lgw-champs&action=gdelete&gid=' . urlencode( $id ) ),
                    'lgw_gchamp_delete_' . $id
                ) ); ?>"
                   class="button button-small button-link-delete"
                   onclick="return confirm('Delete this group championship? All data will be lost.')">
                    Delete
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif;
    ?>
    <script>
    (function(){
        var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        var nonce   = '<?php echo esc_js( wp_create_nonce( 'lgw_gchamp_draw' ) ); ?>';
        document.querySelectorAll( '.lgw-gchamp-copy-btn' ).forEach( function( btn ) {
            btn.addEventListener( 'click', function( e ) {
                e.preventDefault();
                var srcId    = btn.getAttribute( 'data-id' );
                var srcTitle = btn.getAttribute( 'data-title' );
                var newId = prompt( 'New championship ID (lowercase, hyphens only):', srcId + '-copy' );
                if ( ! newId ) return;
                newId = newId.trim().toLowerCase().replace( /[^a-z0-9-]/g, '-' ).replace( /^-+|-+$/g, '' );
                if ( ! newId ) { alert( 'Invalid ID.' ); return; }
                var newTitle = prompt( 'New championship title:', srcTitle + ' (Copy)' );
                if ( newTitle === null ) return;
                var fd = new FormData();
                fd.append( 'action',    'lgw_gchamp_copy' );
                fd.append( 'nonce',     nonce );
                fd.append( 'source_id', srcId );
                fd.append( 'new_id',    newId );
                fd.append( 'new_title', newTitle );
                fetch( ajaxUrl, { method: 'POST', body: fd } )
                    .then( function( r ) { return r.json(); } )
                    .then( function( data ) {
                        if ( ! data.success ) { alert( 'Error: ' + ( data.data || 'Unknown' ) ); return; }
                        window.location.href = data.data.edit_url;
                    } )
                    .catch( function( err ) { alert( 'Failed: ' + err.message ); } );
            } );
        } );
    })();
    </script>
    <?php
}

// ── Edit / New page ───────────────────────────────────────────────────────────
function lgw_gchamp_edit_page( $champ_id ) {
    $champ       = $champ_id ? get_option( 'lgw_gchamp_' . $champ_id, array() ) : array();
    $is_new      = ! $champ_id;
    $drawn       = ! empty( $champ['draw_complete'] );
    $gs_complete = ! empty( $champ['group_stage_complete'] );
    $has_scores  = $drawn && lgw_gchamp_has_any_scores( $champ );
    $disciplines = array( 'singles'=>'Singles','pairs'=>'Pairs','triples'=>'Triples','fours'=>'Fours' );
    $days_config = $champ['days_config'] ?? array( array(
        'id'=>0,'name'=>'Day 1','date'=>'','target_group_size'=>4,'winners_per_group'=>1,'best_runners_up'=>0
    ) );
    $num_days        = count( $days_config );
    $entries_str     = implode( "\n", $champ['entries'] ?? array() );
    $dates_str       = implode( "\n", $champ['dates']   ?? array() );
    $total_entries   = count( $champ['entries'] ?? array() );
    $has_ko          = ! empty( $champ['has_ko_bracket'] );
    $ko_bracket_size = intval( $champ['ko_bracket_size'] ?? 0 );
    $ko_play_day_final = ! empty( $champ['ko_play_day_final'] );
    ?>
    <div class="wrap">
    <?php lgw_page_header( $is_new ? 'New Group Championship' : 'Edit: ' . ( $champ['title'] ?? $champ_id ) ); ?>
    <p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=lgw-champs' ) ); ?>">← Back to championships</a>
        <?php if ( ! $is_new ): ?>
        &nbsp;|&nbsp;
        <a href="#" class="lgw-gchamp-copy-edit-btn"
           data-id="<?php echo esc_attr( $champ_id ); ?>"
           data-title="<?php echo esc_attr( $champ['title'] ?? $champ_id ); ?>"
           data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
           data-nonce="<?php echo esc_attr( wp_create_nonce( 'lgw_gchamp_draw' ) ); ?>">📋 Copy this championship</a>
        <?php endif; ?>
    </p>
    <?php if ( isset( $_GET['saved'] ) ): ?><div class="notice notice-success is-dismissible"><p>Saved.</p></div><?php endif; ?>
    <?php if ( isset( $_GET['entry_format_error'] ) ): ?>
    <div class="notice notice-error is-dismissible">
        <p><strong>⚠ Could not save — <?php echo intval($_GET['bad_count']??0); ?> entr<?php echo intval($_GET['bad_count']??0)===1?'y':'ies'; ?> missing a club name.</strong>
        Each entry must be in the format <code>Player Name(s), Club</code> (comma required).
        Please correct the entries textarea and save again.</p>
    </div>
    <?php endif; ?>

    <?php if ( $drawn ): ?>
    <div class="notice notice-warning" style="border-left-color:#e67e22">
        <p><strong>⚠ Draw already performed.</strong>
        <?php if ( ! $has_scores ): ?>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=lgw-champs&gedit=' . $champ_id . '&greset_draw=1' ), 'lgw_gchamp_reset_' . $champ_id ) ); ?>"
               onclick="return confirm('Reset the draw? All groups and fixtures will be cleared.')">Reset Draw</a>
        <?php else: ?>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=lgw-champs&gedit=' . $champ_id . '&greset_draw=1' ), 'lgw_gchamp_reset_' . $champ_id ) ); ?>"
               style="color:#c0202a;font-weight:600"
               onclick="return confirm('WARNING: Scores have been entered. Resetting will permanently delete all scores and fixtures.\n\nAre you sure?')">
                ⚠ Reset Draw (wipes all scores)
            </a>
        <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <form method="post">
    <?php wp_nonce_field( 'lgw_gchamp_save', 'lgw_gchamp_save_nonce' ); ?>
    <?php if ( $is_new ): ?>
        <input type="hidden" name="gchamp_id" value="">
    <?php else: ?>
        <input type="hidden" name="gchamp_id" value="<?php echo esc_attr( $champ_id ); ?>">
    <?php endif; ?>

    <table class="form-table" style="max-width:780px">
        <?php if ( $is_new ): ?>
        <tr><th><label>Championship ID</label></th>
        <td><input type="text" name="gchamp_id" value="<?php echo esc_attr($champ_id); ?>"
                   placeholder="e.g. seniors-2026" class="regular-text">
            <p class="description">Used in shortcode <code>[lgw_gchamp id="…"]</code>. Lowercase, hyphens only.</p></td></tr>
        <?php else: ?>
        <tr><th>Championship ID</th>
        <td><code><?php echo esc_html($champ_id); ?></code>
            <p class="description">Shortcode: <code>[lgw_gchamp id="<?php echo esc_attr($champ_id); ?>"]</code></p></td></tr>
        <?php endif; ?>
        <tr><th><label for="lgw_gchamp_title">Title</label></th>
        <td><input type="text" id="lgw_gchamp_title" name="lgw_gchamp_title"
                   value="<?php echo esc_attr($champ['title']??''); ?>" placeholder="e.g. Senior Singles 2026"
                   class="regular-text" style="width:360px"></td></tr>
        <tr><th><label for="lgw_gchamp_discipline">Discipline</label></th>
        <td><select id="lgw_gchamp_discipline" name="lgw_gchamp_discipline">
            <?php foreach($disciplines as $v=>$l): ?>
            <option value="<?php echo esc_attr($v);?>" <?php selected($champ['discipline']??'singles',$v);?>><?php echo esc_html($l);?></option>
            <?php endforeach; ?>
        </select></td></tr>
        <tr><th><label for="lgw_gchamp_season">Season</label></th>
        <td><input type="text" id="lgw_gchamp_season" name="lgw_gchamp_season"
                   value="<?php echo esc_attr($champ['season']??''); ?>" placeholder="e.g. 2026"
                   class="regular-text" style="width:160px"></td></tr>
        <tr><th><label for="lgw_gchamp_entries">Entries</label></th>
        <td>
            <textarea id="lgw_gchamp_entries" name="lgw_gchamp_entries" rows="16"
                      style="width:420px;font-family:monospace;font-size:13px"
                      placeholder="One entry per line: Player Name(s), Club"><?php echo esc_textarea($entries_str); ?></textarea>
            <p class="description">
                Format: <code>Player Name(s), Club</code><br>
                Currently: <strong id="lgw-gchamp-entry-count"><?php echo $total_entries; ?></strong> entries.
                <span id="lgw-gchamp-distribution" style="color:#555"></span>
            </p>
            <div id="lgw-gchamp-entry-format-warn"
                 style="display:none;margin-top:8px;padding:10px 14px;background:#fff8e1;border-left:4px solid #f0b429;border-radius:3px;font-size:13px;max-width:420px">
            </div>
        </td></tr>
        <?php if ( $drawn && ! $is_new ): ?>
        <tr>
          <th><label for="lgw_gchamp_rename_old">Rename Entry</label></th>
          <td>
            <div id="lgw-gchamp-rename-entry-wrap">
              <select id="lgw_gchamp_rename_old" style="width:400px;max-width:100%;font-size:13px">
                <option value="">— select entry to rename —</option>
                <?php foreach ( lgw_gchamp_collect_all_entries( $champ ) as $entry ): ?>
                <option value="<?php echo esc_attr($entry); ?>"><?php echo esc_html($entry); ?></option>
                <?php endforeach; ?>
              </select>
              <br style="margin-bottom:6px">
              <input type="text" id="lgw_gchamp_rename_new" placeholder="Corrected name" style="width:400px;max-width:100%;font-size:13px;margin-top:6px">
              <br>
              <button type="button" id="lgw_gchamp_rename_btn" class="button button-secondary" style="margin-top:8px">Rename Entry</button>
              <span id="lgw_gchamp_rename_msg" style="margin-left:10px;font-size:13px"></span>
            </div>
            <p class="description">Corrects spelling in the entries list and throughout all drawn groups, fixtures, knockout brackets and qualifiers. Does not affect scores or reset the draw.</p>
            <script>
            (function(){
              var btn = document.getElementById('lgw_gchamp_rename_btn');
              if (!btn) return;
              btn.addEventListener('click', function(){
                var oldVal = document.getElementById('lgw_gchamp_rename_old').value.trim();
                var newVal = document.getElementById('lgw_gchamp_rename_new').value.trim();
                var msg    = document.getElementById('lgw_gchamp_rename_msg');
                if (!oldVal) { msg.style.color='#c00'; msg.textContent='Please select an entry.'; return; }
                if (!newVal) { msg.style.color='#c00'; msg.textContent='Please enter the corrected name.'; return; }
                if (oldVal === newVal) { msg.style.color='#c00'; msg.textContent='Names are identical.'; return; }
                btn.disabled = true;
                msg.style.color='#555'; msg.textContent='Saving...';
                var fd = new FormData();
                fd.append('action',   'lgw_gchamp_rename_entry');
                fd.append('nonce',    '<?php echo esc_js(wp_create_nonce("lgw_gchamp_score")); ?>');
                fd.append('champ_id', '<?php echo esc_js($champ_id); ?>');
                fd.append('old_name', oldVal);
                fd.append('new_name', newVal);
                fetch('<?php echo esc_url(admin_url("admin-ajax.php")); ?>', {method:'POST', body:fd})
                  .then(function(r){ return r.json(); })
                  .then(function(data){
                    if (data.success) {
                      msg.style.color='#197319';
                      msg.textContent = data.data.message;
                      var sel = document.getElementById('lgw_gchamp_rename_old');
                      for (var i=0; i<sel.options.length; i++) {
                        if (sel.options[i].value === oldVal) {
                          sel.options[i].value = newVal;
                          sel.options[i].text  = newVal;
                          sel.options[i].selected = true;
                          break;
                        }
                      }
                      document.getElementById('lgw_gchamp_rename_new').value = '';
                      var ta = document.getElementById('lgw_gchamp_entries');
                      if (ta) {
                        ta.value = ta.value.split('\\n').map(function(line){
                          return line.trim() === oldVal ? newVal : line;
                        }).join('\\n');
                      }
                    } else {
                      msg.style.color='#c00';
                      msg.textContent = data.data || 'Error.';
                    }
                  })
                  .catch(function(){ msg.style.color='#c00'; msg.textContent='Network error.'; })
                  .finally(function(){ btn.disabled = false; });
              });
            })();
            </script>
          </td>
        </tr>
        <?php endif; ?>
        <tr><th>Stats eligible</th>
        <td><label>
            <input type="checkbox" name="lgw_gchamp_stats_eligible" value="1" <?php checked($champ['stats_eligible']??1,1);?>>
            Track player appearances and W/D/L for group games
        </label></td></tr>
        <tr><th>Colour scheme</th>
        <td>
            <?php
            $cur_colour = $champ['colour_scheme'] ?? '#c0202a';
            $presets = array(
                '#c0202a' => 'PGL Red',
                '#1a2e5a' => 'Navy',
                '#138211' => 'Green',
                '#e67e22' => 'Orange',
                '#7b2d8b' => 'Purple',
                '#1a6ea8' => 'Blue',
                '#2d2d2d' => 'Charcoal',
                'custom'  => 'Custom…',
            );
            ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px">
            <?php foreach($presets as $hex=>$label): if($hex==='custom') continue; ?>
            <label style="cursor:pointer;display:flex;align-items:center;gap:5px;font-size:13px">
                <input type="radio" name="lgw_gchamp_colour_preset" value="<?php echo esc_attr($hex); ?>"
                       <?php checked($cur_colour,$hex); ?>
                       onchange="document.getElementById('lgw_gchamp_colour').value='<?php echo esc_js($hex); ?>';document.getElementById('lgw_gchamp_colour_hidden').value='<?php echo esc_js($hex); ?>';document.getElementById('lgw-colour-preview').style.background='<?php echo esc_js($hex); ?>'">
                <span style="display:inline-block;width:18px;height:18px;border-radius:4px;background:<?php echo esc_attr($hex); ?>;border:1px solid rgba(0,0,0,.2)"></span>
                <?php echo esc_html($label); ?>
            </label>
            <?php endforeach; ?>
            <label style="cursor:pointer;display:flex;align-items:center;gap:5px;font-size:13px">
                <input type="radio" name="lgw_gchamp_colour_preset" value="custom"
                       <?php checked(!array_key_exists($cur_colour,$presets)||$cur_colour==='custom',true); ?>
                       onchange="document.getElementById('lgw-colour-custom-row').style.display='flex'">
                <span>Custom…</span>
            </label>
            </div>
            <div id="lgw-colour-custom-row" style="display:<?php echo array_key_exists($cur_colour,$presets)&&$cur_colour!=='custom'?'none':'flex'; ?>;align-items:center;gap:8px;margin-bottom:8px">
                <input type="color" id="lgw_gchamp_colour" value="<?php echo esc_attr($cur_colour); ?>"
                       oninput="document.getElementById('lgw_gchamp_colour_hidden').value=this.value;document.getElementById('lgw-colour-preview').style.background=this.value">
                <span id="lgw-colour-preview"
                      style="display:inline-block;width:80px;height:28px;border-radius:4px;background:<?php echo esc_attr($cur_colour); ?>;border:1px solid rgba(0,0,0,.2)"></span>
            </div>
            <input type="hidden" id="lgw_gchamp_colour_hidden" name="lgw_gchamp_colour" value="<?php echo esc_attr($cur_colour); ?>">
            <p class="description">Sets the header bar and interactive element colour on the front end.</p>
        </td></tr>
        <tr><th>Tab underline colour</th>
        <td>
            <?php
            $cur_tab_colour = $champ['tab_colour'] ?? '#e8b400';
            $tab_presets = array(
                '#e8b400' => 'Gold',
                '#c0202a' => 'Red',
                '#ffffff' => 'White',
                '#22c55e' => 'Bright Green',
                '#60a5fa' => 'Sky Blue',
                '#f97316' => 'Amber',
                'custom'  => 'Custom…',
            );
            ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px">
            <?php foreach($tab_presets as $hex=>$label): if($hex==='custom') continue; ?>
            <label style="cursor:pointer;display:flex;align-items:center;gap:5px;font-size:13px">
                <input type="radio" name="lgw_gchamp_tab_preset" value="<?php echo esc_attr($hex); ?>"
                       <?php checked($cur_tab_colour,$hex); ?>
                       onchange="document.getElementById('lgw_gchamp_tab_colour_hidden').value='<?php echo esc_js($hex); ?>';document.getElementById('lgw-tab-colour-preview').style.background='<?php echo esc_js($hex); ?>'">
                <span style="display:inline-block;width:18px;height:18px;border-radius:4px;background:<?php echo esc_attr($hex); ?>;border:1px solid rgba(0,0,0,.2)"></span>
                <?php echo esc_html($label); ?>
            </label>
            <?php endforeach; ?>
            <label style="cursor:pointer;display:flex;align-items:center;gap:5px;font-size:13px">
                <input type="radio" name="lgw_gchamp_tab_preset" value="custom"
                       <?php checked(!array_key_exists($cur_tab_colour,$tab_presets)||$cur_tab_colour==='custom',true); ?>
                       onchange="document.getElementById('lgw-tab-colour-custom-row').style.display='flex'">
                <span>Custom…</span>
            </label>
            </div>
            <div id="lgw-tab-colour-custom-row" style="display:<?php echo array_key_exists($cur_tab_colour,$tab_presets)&&$cur_tab_colour!=='custom'?'none':'flex';?>;align-items:center;gap:8px;margin-bottom:8px">
                <input type="color" id="lgw_gchamp_tab_colour" value="<?php echo esc_attr($cur_tab_colour); ?>"
                       oninput="document.getElementById('lgw_gchamp_tab_colour_hidden').value=this.value;document.getElementById('lgw-tab-colour-preview').style.background=this.value">
                <span id="lgw-tab-colour-preview" style="display:inline-block;width:80px;height:28px;border-radius:4px;background:<?php echo esc_attr($cur_tab_colour); ?>;border:1px solid rgba(0,0,0,.2)"></span>
            </div>
            <input type="hidden" id="lgw_gchamp_tab_colour_hidden" name="lgw_gchamp_tab_colour" value="<?php echo esc_attr($cur_tab_colour); ?>">
            <p class="description">The underline colour on active day tabs. Default is gold to match the PGL badge border.</p>
        </td></tr>
        <tr><th>Optional knockout bracket</th>
        <td><label>
            <input type="checkbox" name="lgw_gchamp_has_ko" value="1" <?php checked($has_ko,true);?> id="lgw_gchamp_has_ko">
            Include an internal knockout bracket after the group stage
        </label>
        <p class="description">Leave unchecked if Finals Week is run separately outside this system.</p>
        <label style="display:block;margin-top:10px">
            <input type="checkbox" name="lgw_gchamp_ko_play_day_final" value="1" <?php checked($ko_play_day_final,true);?> id="lgw_gchamp_ko_play_day_final">
            Play a day-final for 2-qualifier days
        </label>
        <p class="description">
            Only affects days set to <strong>2 finals qualifiers</strong>. Off (default): both
            finalists qualify and the final is played at Finals Week — no day game.
            On: the day final is played to rank the two qualifiers (winner seeded first).
        </p>
        </td></tr>
    </table>

    <hr style="margin:24px 0">
    <h2 style="color:#072a82;margin-bottom:4px">📅 Competition Days</h2>
    <p style="color:#555;font-size:13px;margin-top:0">
        Each day is an independent section with its own groups and qualifiers.
        The system auto-calculates the number of groups from the target group size.
    </p>
    <input type="hidden" name="lgw_gchamp_num_days" id="lgw_gchamp_num_days" value="<?php echo $num_days; ?>">
    <div style="max-width:960px;overflow-x:auto">
    <table class="widefat" id="lgw-gchamp-days-table" style="min-width:800px">
        <thead><tr>
            <th style="width:32px">#</th>
            <th style="min-width:120px">Day name</th>
            <th style="width:105px">Play date</th>
            <th style="min-width:120px">Location</th>
            <th style="width:80px">Target<br>group size</th>
            <th style="width:75px">Winners/<br>group</th>
            <th style="width:75px">Best<br>runners-up</th>
            <th style="width:70px">Finals<br>qualifiers</th>
            <th style="min-width:200px">Auto-calculated</th>
            <th style="width:32px"></th>
        </tr></thead>
        <tbody id="lgw-gchamp-days-tbody">
        <?php foreach ( $days_config as $di => $day ):
            $tgs  = intval($day['target_group_size']      ?? 4);
            $wpg  = intval($day['winners_per_group']      ?? 1);
            $bru  = intval($day['best_runners_up']        ?? 0);
            $fq   = intval($day['finals_qualifiers']      ?? ($champ['finals_qualifiers_per_day'] ?? 1));
        ?>
        <tr class="lgw-gchamp-day-row" data-day="<?php echo $di; ?>">
            <td style="color:#888;font-weight:700;text-align:center"><?php echo $di+1; ?></td>
            <td><input type="text" name="lgw_gchamp_day_names[<?php echo $di;?>]"
                       value="<?php echo esc_attr($day['name']??('Day '.($di+1))); ?>"
                       class="regular-text" style="width:100%" <?php echo $drawn?'readonly':''; ?>></td>
            <td><input type="text" name="lgw_gchamp_day_dates[<?php echo $di;?>]"
                       value="<?php echo esc_attr($day['date']??''); ?>"
                       placeholder="dd/mm/yyyy" class="small-text" style="width:96px"></td>
            <td><input type="text" name="lgw_gchamp_day_locations[<?php echo $di;?>]"
                       value="<?php echo esc_attr($day['location']??''); ?>"
                       placeholder="e.g. Greenacres BC" class="regular-text lgw-gchamp-day-location" style="width:140px"></td>
            <td><input type="number" name="lgw_gchamp_day_tgs[<?php echo $di;?>]"
                       value="<?php echo $tgs; ?>" min="2" max="20" step="1"
                       class="small-text lgw-gchamp-day-tgs" style="width:58px" <?php echo $drawn?'readonly':''; ?>></td>
            <td><input type="number" name="lgw_gchamp_day_wpg[<?php echo $di;?>]"
                       value="<?php echo $wpg; ?>" min="1" step="1"
                       class="small-text lgw-gchamp-day-wpg" style="width:52px"></td>
            <td><input type="number" name="lgw_gchamp_day_bru[<?php echo $di;?>]"
                       value="<?php echo $bru; ?>" min="0" step="1"
                       class="small-text lgw-gchamp-day-bru" style="width:52px"></td>
            <td><select name="lgw_gchamp_day_fq[<?php echo $di;?>]" class="small-text lgw-gchamp-day-fq" style="width:60px">
                <?php foreach([1,2,3,4] as $opt):?>
                <option value="<?php echo $opt;?>" <?php selected($fq,$opt);?>><?php echo $opt;?></option>
                <?php endforeach;?>
            </select></td>
            <td class="lgw-gchamp-day-calc" style="font-size:12px;color:#555">—</td>
            <td><?php if(!$drawn):?><button type="button" class="button button-small lgw-gchamp-day-remove" style="color:#c00;padding:2px 6px" title="Remove">✕</button><?php endif;?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if(!$drawn):?><p style="margin-top:8px"><button type="button" id="lgw-gchamp-add-day" class="button button-secondary">+ Add Day</button></p><?php endif;?>

    <?php
    // ── Preference settings + entry preferences panel ─────────────────────────
    $cur_entries       = array_values( array_filter( array_map( 'trim', explode( "\n", $entries_str ) ) ) );
    $exist_prefs       = $champ['entry_preferences'] ?? array();
    $pref_fields       = $champ['preference_fields'] ?? array( 'date' ); // default: date only (BC)
    $use_date_pref     = in_array( 'date',     $pref_fields, true );
    $use_location_pref = in_array( 'location', $pref_fields, true );
    $all_day_dates     = array_values( array_filter( array_column( $days_config, 'date' ) ) );
    $unique_dates      = array_values( array_unique( $all_day_dates ) );
    $all_day_locations = array_values( array_filter( array_column( $days_config, 'location' ) ) );
    $unique_locations  = array_values( array_unique( $all_day_locations ) );
    // Migrate any legacy string prefs to array format for display
    $exist_prefs_arr = array();
    foreach ( $exist_prefs as $e => $p ) {
        $exist_prefs_arr[$e] = is_array($p) ? $p : array('date'=>$p);
    }
    $any_pref_set = false;
    foreach ( $cur_entries as $e ) { if (!empty($exist_prefs_arr[$e])) { $any_pref_set=true; break; } }
    ?>
    <hr style="margin:24px 0">
    <h2 style="color:#072a82;margin-bottom:4px">⚙️ Preference Settings</h2>
    <p style="color:#555;font-size:13px;margin-top:0">
        Choose which data points entries can express a preference for when the draw is run.
        Only fields ticked here will appear in the entry preferences table.
    </p>
    <table class="form-table" style="max-width:600px">
        <tr><th style="padding-top:12px;font-weight:600">Active preference fields</th>
        <td>
            <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:10px">
                <input type="checkbox" name="lgw_gchamp_pref_fields[]" value="date"
                       id="lgw-pref-field-date"
                       <?php checked($use_date_pref,true);?>
                       onchange="lgwUpdatePrefTable()">
                <span>
                    <strong>Date</strong><br>
                    <span style="font-size:12px;color:#666">Entry can prefer a specific play date. The draw will try to place them on a day with that date.</span>
                </span>
            </label>
            <label style="display:flex;align-items:flex-start;gap:8px">
                <input type="checkbox" name="lgw_gchamp_pref_fields[]" value="location"
                       id="lgw-pref-field-location"
                       <?php checked($use_location_pref,true);?>
                       onchange="lgwUpdatePrefTable()">
                <span>
                    <strong>Location</strong><br>
                    <span style="font-size:12px;color:#666">Entry can express a preferred venue. Set each day's <em>Location</em> field above, then entries can request a specific venue. The draw respects this after date preferences.</span>
                </span>
            </label>
        </td></tr>
    </table>

    <?php if ( ! empty($cur_entries) && ( ! empty($unique_dates) || ! empty($unique_locations) ) ):?>
    <hr style="margin:24px 0">
    <h2 style="color:#072a82;margin-bottom:4px">🗓 Entry Preferences</h2>
    <details id="lgw-gchamp-prefs-details" <?php echo $any_pref_set?'open':'';?>>
        <summary style="cursor:pointer;font-weight:600;color:#072a82;margin-bottom:8px;user-select:none">
            <?php
            $sc=0; foreach($cur_entries as $e){if(!empty($exist_prefs_arr[$e]))$sc++;}
            echo $sc>0?esc_html($sc).' of '.count($cur_entries).' preferences set — click to edit':'Set entry preferences (optional)';
            ?>
        </summary>
        <div style="margin-top:8px">
            <div id="lgw-gchamp-prefs-toolbar" style="margin-bottom:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button type="button" id="lgw-gchamp-prefs-clear-all" class="button button-small">Clear all</button>
                <?php if($use_date_pref): foreach($unique_dates as $ud):?>
                <button type="button" class="button button-small lgw-gchamp-prefs-bulk-date" data-date="<?php echo esc_attr($ud);?>">All date → <?php echo esc_html($ud);?></button>
                <?php endforeach; endif;?>
                <?php if($use_location_pref): foreach($unique_locations as $ul):?>
                <button type="button" class="button button-small lgw-gchamp-prefs-bulk-loc" data-loc="<?php echo esc_attr($ul);?>">All venue → <?php echo esc_html($ul);?></button>
                <?php endforeach; endif;?>
            </div>
            <div style="max-height:340px;overflow-y:auto;border:1px solid #ddd;border-radius:4px">
            <table class="widefat" id="lgw-gchamp-prefs-table" style="font-size:13px">
                <thead>
                <tr>
                    <th style="width:36px">#</th>
                    <th>Entry</th>
                    <th id="lgw-pref-th-date" style="width:165px;<?php echo $use_date_pref?'':'display:none';?>">Preferred date</th>
                    <th id="lgw-pref-th-location" style="min-width:160px;<?php echo $use_location_pref?'':'display:none';?>">Preferred venue</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach($cur_entries as $idx=>$entry):
                    $ep   = $exist_prefs_arr[$entry] ?? array();
                    $pdv  = $ep['date']     ?? '';
                    $plv  = $ep['location'] ?? '';
                    $key  = md5($entry);
                ?>
                <tr>
                    <td style="color:#999"><?php echo $idx+1;?></td>
                    <td><?php echo esc_html($entry);?></td>
                    <td class="lgw-pref-col-date" style="<?php echo $use_date_pref?'':'display:none';?>">
                        <select name="lgw_gchamp_entry_pref_date[<?php echo esc_attr($key);?>]"
                                class="lgw-gchamp-pref-date-select" style="width:150px;font-size:13px">
                            <option value="">— no preference —</option>
                            <?php foreach($unique_dates as $ud):?>
                            <option value="<?php echo esc_attr($ud);?>" <?php selected($pdv,$ud);?>><?php echo esc_html($ud);?></option>
                            <?php endforeach;?>
                        </select>
                    </td>
                    <td class="lgw-pref-col-location" style="<?php echo $use_location_pref?'':'display:none';?>">
                        <select name="lgw_gchamp_entry_pref_location[<?php echo esc_attr($key);?>]"
                                class="lgw-gchamp-pref-loc-select" style="width:170px;font-size:13px">
                            <option value="">— no preference —</option>
                            <?php foreach($unique_locations as $ul):?>
                            <option value="<?php echo esc_attr($ul);?>" <?php selected($plv,$ul);?>><?php echo esc_html($ul);?></option>
                            <?php endforeach;?>
                        </select>
                    </td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
            </div>
            <p class="description" style="margin-top:6px">
                <span id="lgw-gchamp-prefs-summary"></span>
                <span style="margin-left:8px;color:#888;font-size:12px">
                    The draw satisfies date preferences first, then location preferences where possible.
                </span>
            </p>
        </div>
    </details>
    <script>
    function lgwUpdatePrefTable(){
        var useDatePref=document.getElementById('lgw-pref-field-date')&&document.getElementById('lgw-pref-field-date').checked;
        var useLocPref=document.getElementById('lgw-pref-field-location')&&document.getElementById('lgw-pref-field-location').checked;
        var th_date=document.getElementById('lgw-pref-th-date');
        var th_loc=document.getElementById('lgw-pref-th-location');
        if(th_date) th_date.style.display=useDatePref?'':'none';
        if(th_loc)  th_loc.style.display=useLocPref?'':'none';
        document.querySelectorAll('.lgw-pref-col-date').forEach(function(c){c.style.display=useDatePref?'':'none';});
        document.querySelectorAll('.lgw-pref-col-location').forEach(function(c){c.style.display=useLocPref?'':'none';});
    }
    </script>
    <?php endif;?>

    <?php if($has_ko):?>
    <hr style="margin:24px 0">
    <h2 style="color:#072a82;margin-bottom:4px">🏆 Knockout Stage</h2>
    <table class="form-table" style="max-width:780px">
        <tr><th><label>KO round dates</label></th>
        <td><textarea name="lgw_gchamp_dates" rows="5" style="width:200px;font-family:monospace;font-size:13px"
                      placeholder="dd/mm/yyyy"><?php echo esc_textarea($dates_str);?></textarea>
            <p class="description">One date per line in round order.</p></td></tr>
        <tr><th><label>Bracket size</label></th>
        <td><input type="number" name="lgw_gchamp_ko_bracket_size" value="<?php echo esc_attr($ko_bracket_size);?>" min="2" step="1" class="small-text">
            <p class="description">Must be a power of 2 (4, 8, 16…).</p></td></tr>
    </table>
    <?php endif;?>

    <hr style="margin:24px 0">
    <?php submit_button($is_new?'Create Championship':'Save Changes','primary','lgw_gchamp_submit',false);?>
    &nbsp;<a href="<?php echo esc_url(admin_url('admin.php?page=lgw-champs'));?>" class="button">Cancel</a>
    </form>

    <?php /* ── Run Draw ── */ ?>
    <?php if(!$is_new && !empty($champ['entries'])):?>
    <hr style="margin:32px 0">
    <h2 style="color:#072a82;margin-bottom:4px">🎲 Group Draw</h2>
    <?php if(!empty($champ['draw_warnings'])):?>
    <div class="notice notice-warning is-dismissible" style="max-width:700px">
        <p><strong>⚠ Draw warnings:</strong></p>
        <ul style="margin:0;padding-left:20px">
            <?php foreach($champ['draw_warnings'] as $w):?><li><?php echo esc_html($w);?></li><?php endforeach;?>
        </ul>
    </div>
    <?php endif;?>

    <?php if(!empty($champ['draw_complete'])):?>
    <div class="notice notice-info" style="max-width:700px;margin-bottom:12px">
        <p>✅ <strong>Draw complete.</strong>
        <?php
        $tfx=0;
        foreach($champ['days']??array() as $day) foreach($day['groups']??array() as $g) $tfx+=count($g['fixtures']??array());
        $nd=count($champ['days']??array());
        echo $nd.' day'.($nd!==1?'s':'').', '.$tfx.' fixtures.';
        if($has_scores) echo ' <span style="color:#c0202a;font-weight:600">Scores entered — re-draw will wipe them.</span>';
        ?>
        </p>
        <?php if(!empty($champ['draw_version'])):?>
        <p style="margin:2px 0 0;font-size:12px;color:#666">
            Drawn with plugin v<?php echo esc_html($champ['draw_version']);?>
            <?php if(!empty($champ['draw_timestamp'])):?>
            at <?php echo esc_html(date('d/m/Y H:i', $champ['draw_timestamp']));?>
            <?php endif;?>
        </p>
        <?php endif;?>
    </div>
    <?php foreach($champ['days']??array() as $day):?>
    <div style="display:inline-block;vertical-align:top;margin:0 12px 12px 0;border:1px solid #d0d5e8;border-radius:6px;padding:12px 16px;min-width:220px;background:#f8faff">
        <strong style="color:#072a82"><?php echo esc_html($day['name']??'');?></strong>
        <?php if(!empty($day['date'])):?><span style="margin-left:6px;font-size:12px;color:#888"><?php echo esc_html($day['date']);?></span><?php endif;?>
        <?php foreach($day['groups']??array() as $g):?>
        <div style="margin-top:6px;padding:6px 8px;background:#fff;border:1px solid #e0e4f0;border-radius:4px;font-size:12px">
            <strong style="color:#1a2e5a"><?php echo esc_html($g['name']??'');?></strong>
            <ul style="margin:4px 0 0;padding-left:14px">
                <?php foreach($g['entries']??array() as $e):?><li><?php echo esc_html($e);?></li><?php endforeach;?>
            </ul>
            <p style="margin:4px 0 0;color:#888"><?php echo count($g['entries']??array());?> entries · <?php echo count($g['fixtures']??array());?> fixtures</p>
        </div>
        <?php endforeach;?>
    </div>
    <?php endforeach;?>
    <?php endif;?>

    <div style="clear:both;margin-top:12px">
        <button type="button" id="lgw-gchamp-run-draw-btn" class="button button-primary button-large">
            <?php echo $drawn?'🔄 Re-run Draw':'🎲 Run Draw';?>
        </button>
        <span id="lgw-gchamp-draw-spinner" class="spinner" style="float:none;margin-left:8px;vertical-align:middle"></span>
        <p id="lgw-gchamp-draw-msg" style="margin-top:8px;font-size:13px"></p>
    </div>
    <script>
    (function(){
        var btn=document.getElementById('lgw-gchamp-run-draw-btn');
        var spinner=document.getElementById('lgw-gchamp-draw-spinner');
        var msg=document.getElementById('lgw-gchamp-draw-msg');
        var nonce='<?php echo esc_js(wp_create_nonce('lgw_gchamp_draw'));?>';
        var champId='<?php echo esc_js($champ_id);?>';
        var hasScores=<?php echo lgw_gchamp_has_any_scores($champ)?'true':'false';?>;
        if(!btn)return;
        btn.addEventListener('click',function(){
            if(hasScores&&!confirm('WARNING: Scores have been entered. Re-drawing will permanently delete all scores, fixtures, and player appearance records.\n\nAre you sure?'))return;
            runDraw(hasScores);
        });
        function runDraw(confirmed){
            btn.disabled=true;spinner.style.display='inline-block';
            msg.style.color='#555';msg.textContent='Running draw\u2026';
            var fd=new FormData();
            fd.append('action','lgw_gchamp_run_draw');fd.append('nonce',nonce);fd.append('champ_id',champId);
            if(confirmed)fd.append('confirmed','1');
            fetch('<?php echo esc_js(admin_url('admin-ajax.php'));?>',{method:'POST',body:fd})
                .then(function(r){return r.json();})
                .then(function(data){
                    spinner.style.display='none';btn.disabled=false;
                    if(!data.success){
                        if(data.data&&data.data.code==='scores_exist'){if(confirm('WARNING: '+data.data.message+'\n\nContinue?'))runDraw(true);return;}
                        msg.style.color='#c0202a';msg.textContent='Error: '+(data.data||'Unknown');return;
                    }
                    var warns=data.data.warnings||[];
                    var wh=warns.length?'<br><ul style="margin:4px 0;padding-left:18px">'+warns.map(function(w){return'<li>'+w+'</li>';}).join('')+'</ul>':'';
                    msg.style.color='#138211';msg.innerHTML='\u2705 Draw complete.'+(warns.length===0?' No warnings.':wh);
                    // Clear any stale warnings notice from the previous draw
                    var oldWarn=document.querySelector('.notice-warning');if(oldWarn)oldWarn.remove();
                    setTimeout(function(){window.location.reload();},1800);
                })
                .catch(function(err){spinner.style.display='none';btn.disabled=false;msg.style.color='#c0202a';msg.textContent='Failed: '+err.message;});
        }
    })();
    </script>
    <?php endif;?>

    <?php /* ── KO Seeding ── */ ?>
    <?php if(!$is_new&&$has_ko&&!empty($champ['group_stage_complete'])):?>
    <hr style="margin:32px 0">
    <h2 style="color:#072a82;margin-bottom:4px">🏆 Knockout Bracket</h2>
    <?php if(!empty($champ['ko_bracket'])):?>
    <div class="notice notice-success" style="max-width:700px;margin-bottom:8px">
        <p>✅ <strong>Seeded.</strong> <?php echo count($champ['qualifiers']??array());?> qualifiers.
        <a href="#" id="lgw-gchamp-reseed-link" style="margin-left:8px;color:#c0202a">Re-seed</a></p>
    </div>
    <div style="margin-bottom:12px">
        <?php foreach($champ['qualifiers']??array() as $idx=>$q):?>
        <span style="display:inline-block;padding:3px 10px;margin:2px;background:#e8f0fe;border-radius:12px;font-size:12px;color:#072a82;font-weight:600">
            <?php echo $idx+1;?>. <?php echo esc_html(lgw_gchamp_short_name($q));?>
        </span>
        <?php endforeach;?>
    </div>
    <?php endif;?>
    <button type="button" id="lgw-gchamp-seed-btn" class="button button-primary button-large">
        <?php echo empty($champ['ko_bracket'])?'🏆 Seed Knockout Bracket':'🔄 Re-seed';?>
    </button>
    <span id="lgw-gchamp-seed-spinner" class="spinner" style="float:none;margin-left:8px;vertical-align:middle"></span>
    <p id="lgw-gchamp-seed-msg" style="margin-top:8px;font-size:13px"></p>
    <script>
    (function(){
        var btn=document.getElementById('lgw-gchamp-seed-btn');
        var reseed=document.getElementById('lgw-gchamp-reseed-link');
        var spinner=document.getElementById('lgw-gchamp-seed-spinner');
        var msg=document.getElementById('lgw-gchamp-seed-msg');
        var nonce='<?php echo esc_js(wp_create_nonce('lgw_gchamp_draw'));?>';
        var champId='<?php echo esc_js($champ_id);?>';
        var hasKo=<?php echo !empty($champ['ko_bracket'])?'true':'false';?>;
        function doSeed(){
            if(btn)btn.disabled=true;spinner.style.display='inline-block';
            msg.style.color='#555';msg.textContent='Seeding\u2026';
            var fd=new FormData();fd.append('action','lgw_gchamp_seed_knockout');fd.append('nonce',nonce);fd.append('champ_id',champId);
            fetch('<?php echo esc_js(admin_url('admin-ajax.php'));?>',{method:'POST',body:fd})
                .then(function(r){return r.json();})
                .then(function(data){
                    spinner.style.display='none';if(btn)btn.disabled=false;
                    if(!data.success){msg.style.color='#c0202a';msg.textContent='Error: '+(data.data||'Unknown');return;}
                    msg.style.color='#138211';msg.textContent='\u2705 Seeded \u2014 '+(data.data.qualifiers||[]).length+' qualifiers.';
                    setTimeout(function(){window.location.reload();},1600);
                })
                .catch(function(err){spinner.style.display='none';if(btn)btn.disabled=false;msg.style.color='#c0202a';msg.textContent='Failed: '+err.message;});
        }
        if(btn)btn.addEventListener('click',function(){if(hasKo&&!confirm('Re-seeding will clear the existing bracket.\n\nContinue?'))return;doSeed();});
        if(reseed)reseed.addEventListener('click',function(e){e.preventDefault();if(!confirm('Re-seeding will clear the existing bracket.\n\nContinue?'))return;doSeed();});
    })();
    </script>
    <?php endif;?>

    <?php /* ── Manual Group Adjustments ── */ ?>
    <?php if(!$is_new&&$drawn&&!empty($champ['days'])):?>
    <hr style="margin:32px 0">
    <h2 style="color:#072a82;margin-bottom:4px">✏️ Manual Group Adjustments</h2>
    <p style="color:#555;font-size:13px;margin-top:0">
        Move an entry between groups on the same day, or withdraw an entry that has dropped out.
        Moving or withdrawing clears <em>that entry's fixtures only</em> — scores for other fixtures are preserved.
        The day's knockout bracket is reset and will need to be re-seeded after any changes.
    </p>
    <details id="lgw-gchamp-group-editor">
        <summary style="cursor:pointer;font-weight:600;color:#072a82;user-select:none;padding:6px 0">Open group editor ▸</summary>
        <div style="margin-top:12px">
        <?php foreach($champ['days'] as $day_idx=>$day): ?>
        <div style="margin-bottom:20px">
            <strong style="font-size:14px;color:#072a82"><?php echo esc_html($day['name']??'');?></strong>
            <?php if(!empty($day['date'])):?><span style="margin-left:6px;color:#888;font-size:12px"><?php echo esc_html($day['date']);?></span><?php endif;?>
            <table class="widefat" style="margin-top:8px;font-size:13px;max-width:820px">
                <thead><tr>
                    <th style="width:160px">Group</th>
                    <th>Entry</th>
                    <th style="width:220px">Move to group</th>
                    <th style="width:120px">Withdraw</th>
                </tr></thead>
                <tbody>
                <?php foreach($day['groups']??array() as $gi=>$group):
                    $other_groups=array();
                    foreach($day['groups'] as $ogi=>$og){
                        if($ogi!==$gi) $other_groups[$ogi]=$og['name']??('Group '.chr(65+$ogi));
                    }
                    foreach($group['entries']??array() as $entry):
                ?>
                <tr>
                    <td style="color:#666;vertical-align:middle"><?php echo esc_html($group['name']??('Group '.chr(65+$gi)));?></td>
                    <td style="vertical-align:middle;font-weight:600"><?php echo esc_html($entry);?></td>
                    <td style="vertical-align:middle">
                        <?php if(!empty($other_groups)): ?>
                        <div style="display:flex;align-items:center;gap:4px">
                        <select class="lgw-gchamp-move-target" style="font-size:12px;flex:1">
                            <option value="">— select target —</option>
                            <?php foreach($other_groups as $ogi=>$ogname): ?>
                            <option value="<?php echo esc_attr($ogi);?>"><?php echo esc_html($ogname);?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button button-small lgw-gchamp-move-btn"
                            data-champ-id="<?php echo esc_attr($champ_id);?>"
                            data-day-id="<?php echo esc_attr($day_idx);?>"
                            data-entry="<?php echo esc_attr($entry);?>"
                            data-source-gid="<?php echo esc_attr($gi);?>">Move</button>
                        </div>
                        <?php else: ?>
                        <span style="color:#999;font-size:12px">Only group on day</span>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align:middle">
                        <button type="button" class="button button-small button-link-delete lgw-gchamp-withdraw-btn"
                            data-champ-id="<?php echo esc_attr($champ_id);?>"
                            data-day-id="<?php echo esc_attr($day_idx);?>"
                            data-group-id="<?php echo esc_attr($gi);?>"
                            data-entry="<?php echo esc_attr($entry);?>">Withdraw</button>
                        <span class="lgw-gchamp-adj-status" style="font-size:12px;margin-left:6px;display:inline-block"></span>
                    </td>
                </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
        </div>
    </details>
    <script>
    (function(){
        var ajaxUrl='<?php echo esc_js(admin_url('admin-ajax.php'));?>';
        var nonce='<?php echo esc_js(wp_create_nonce('lgw_gchamp_score'));?>';

        function setStatus(row,msg,color){
            var st=row.querySelector('.lgw-gchamp-adj-status');
            if(st){st.textContent=msg;st.style.color=color||'#555';}
        }

        function doMove(btn,confirmed){
            var row=btn.closest('tr');
            var sel=row.querySelector('.lgw-gchamp-move-target');
            var targetGid=sel?sel.value:'';
            if(!targetGid){setStatus(row,'Select a target group.','#c00');return;}
            btn.disabled=true;setStatus(row,'Moving…','#555');
            var fd=new FormData();
            fd.append('action','lgw_gchamp_move_entry');
            fd.append('nonce',nonce);
            fd.append('champ_id',btn.getAttribute('data-champ-id'));
            fd.append('day_id',btn.getAttribute('data-day-id'));
            fd.append('entry',btn.getAttribute('data-entry'));
            fd.append('source_group_id',btn.getAttribute('data-source-gid'));
            fd.append('target_group_id',targetGid);
            if(confirmed)fd.append('confirmed','1');
            fetch(ajaxUrl,{method:'POST',body:fd})
                .then(function(r){return r.json();})
                .then(function(data){
                    btn.disabled=false;
                    if(!data.success){
                        if(data.data&&data.data.code==='scores_exist'){
                            if(confirm('⚠ '+data.data.message))doMove(btn,true);
                            else setStatus(row,'','');
                            return;
                        }
                        setStatus(row,data.data||'Error.','#c00');return;
                    }
                    setStatus(row,'✓ Moved','#138211');
                    setTimeout(function(){window.location.reload();},900);
                })
                .catch(function(err){btn.disabled=false;setStatus(row,'Failed: '+err.message,'#c00');});
        }

        function doWithdraw(btn,confirmed){
            var row=btn.closest('tr');
            var entry=btn.getAttribute('data-entry');
            if(!confirmed&&!confirm('Withdraw “'+entry+'”?\nTheir fixtures will be deleted. This cannot be undone.'))return;
            btn.disabled=true;setStatus(row,'Withdrawing…','#555');
            var fd=new FormData();
            fd.append('action','lgw_gchamp_withdraw_entry');
            fd.append('nonce',nonce);
            fd.append('champ_id',btn.getAttribute('data-champ-id'));
            fd.append('day_id',btn.getAttribute('data-day-id'));
            fd.append('group_id',btn.getAttribute('data-group-id'));
            fd.append('entry',entry);
            if(confirmed)fd.append('confirmed','1');
            fetch(ajaxUrl,{method:'POST',body:fd})
                .then(function(r){return r.json();})
                .then(function(data){
                    btn.disabled=false;
                    if(!data.success){
                        if(data.data&&data.data.code==='scores_exist'){
                            if(confirm('⚠ '+data.data.message))doWithdraw(btn,true);
                            else setStatus(row,'','');
                            return;
                        }
                        setStatus(row,data.data||'Error.','#c00');return;
                    }
                    setStatus(row,'✓ Withdrawn','#138211');
                    setTimeout(function(){window.location.reload();},900);
                })
                .catch(function(err){btn.disabled=false;setStatus(row,'Failed: '+err.message,'#c00');});
        }

        document.querySelectorAll('.lgw-gchamp-move-btn').forEach(function(b){
            b.addEventListener('click',function(){doMove(b,false);});
        });
        document.querySelectorAll('.lgw-gchamp-withdraw-btn').forEach(function(b){
            b.addEventListener('click',function(){doWithdraw(b,false);});
        });

        // Copy this championship (edit page link)
        var copyEditBtn=document.querySelector('.lgw-gchamp-copy-edit-btn');
        if(copyEditBtn){
            copyEditBtn.addEventListener('click',function(e){
                e.preventDefault();
                var srcId=copyEditBtn.getAttribute('data-id');
                var srcTitle=copyEditBtn.getAttribute('data-title');
                var copyAjax=copyEditBtn.getAttribute('data-ajax-url');
                var copyNonce=copyEditBtn.getAttribute('data-nonce');
                var newId=prompt('New championship ID (lowercase, hyphens only):',srcId+'-copy');
                if(!newId)return;
                newId=newId.trim().toLowerCase().replace(/[^a-z0-9-]/g,'-').replace(/^-+|-+$/g,'');
                if(!newId){alert('Invalid ID.');return;}
                var newTitle=prompt('New championship title:',srcTitle+' (Copy)');
                if(newTitle===null)return;
                var fd=new FormData();
                fd.append('action','lgw_gchamp_copy');
                fd.append('nonce',copyNonce);
                fd.append('source_id',srcId);
                fd.append('new_id',newId);
                fd.append('new_title',newTitle);
                fetch(copyAjax,{method:'POST',body:fd})
                    .then(function(r){return r.json();})
                    .then(function(data){
                        if(!data.success){alert('Error: '+(data.data||'Unknown'));return;}
                        window.location.href=data.data.edit_url;
                    })
                    .catch(function(err){alert('Failed: '+err.message);});
            });
        }
    })();
    </script>
    <?php endif;?>

    <?php /* ── Group Scores ── */ ?>
    <?php if(!$is_new&&$drawn&&!empty($champ['days'])):?>
    <hr style="margin:32px 0">
    <h2 style="color:#072a82;margin-bottom:4px">📋 Group Scores</h2>
    <p style="color:#555;font-size:13px;margin-top:0">
        <?php if($gs_complete):?><span style="color:#138211;font-weight:600">✅ All fixtures complete.</span>
        <?php else:?>Enter scores for each group fixture. Standings recalculate immediately.<?php endif;?>
    </p>
    <div class="lgw-gchamp-admin-scores-wrap">
    <?php foreach($champ['days'] as $day_idx=>$day):
        foreach($day['groups']??array() as $gi=>$group):
            $g_fx=$group['fixtures']??array(); $g_entries=$group['entries']??array();
            $by_round=array(); foreach($g_fx as $fx) $by_round[$fx['round']][]=$fx; ksort($by_round);
    ?>
    <div class="lgw-gchamp-admin-group-block">
        <div class="lgw-gchamp-admin-group-hdr">
            <?php echo esc_html($day['name']??'');?> — <?php echo esc_html($group['name']??'');?>
            <?php if(!empty($day['date'])):?><span class="lgw-gchamp-admin-group-date">📅 <?php echo esc_html($day['date']);?></span><?php endif;?>
            <span style="margin-left:auto;font-size:12px;font-weight:400;opacity:.8"><?php echo lgw_gchamp_count_played($g_fx);?>/<?php echo count($g_fx);?> played</span>
        </div>
        <table class="lgw-gchamp-admin-fx-table">
            <thead><tr><th style="width:36px">Rnd</th><th>Home</th><th style="width:128px;text-align:center">Score</th><th>Away</th><th style="width:140px"></th></tr></thead>
            <tbody>
            <?php foreach($by_round as $rn=>$rfxs): foreach($rfxs as $fx):
                $hs=$fx['home_score']; $as_v=$fx['away_score']; $scored=($hs!==null&&$as_v!==null);
                $pk=esc_attr($fx['pos_key']??'');
            ?>
            <tr class="lgw-gchamp-admin-fx-row" data-pos-key="<?php echo $pk;?>" data-day-id="<?php echo intval($day_idx);?>" data-group-id="<?php echo intval($gi);?>">
                <td style="color:#888;font-weight:700;text-align:center">R<?php echo $rn+1;?></td>
                <td><?php echo esc_html(lgw_gchamp_short_name($fx['home']));?></td>
                <td style="text-align:center">
                    <div style="display:flex;align-items:center;justify-content:center;gap:4px">
                        <input type="number" class="lgw-gchamp-admin-score-input lgw-gchamp-admin-score-h" value="<?php echo $scored?intval($hs):'';?>" min="0" step="1" placeholder="–">
                        <span style="font-weight:700;color:#888">–</span>
                        <input type="number" class="lgw-gchamp-admin-score-input lgw-gchamp-admin-score-a" value="<?php echo $scored?intval($as_v):'';?>" min="0" step="1" placeholder="–">
                    </div>
                </td>
                <td><?php echo esc_html(lgw_gchamp_short_name($fx['away']));?></td>
                <td>
                    <button type="button" class="lgw-gchamp-admin-save-btn">Save</button>
                    <?php if($scored):?><button type="button" class="lgw-gchamp-admin-clear-btn">Clear</button><?php endif;?>
                    <span class="lgw-gchamp-admin-score-status"></span>
                </td>
            </tr>
            <?php endforeach; endforeach;?>
            </tbody>
        </table>
    </div>
    <?php endforeach; endforeach;?>
    </div>
    <script>
    (function(){
        var ajaxUrl='<?php echo esc_js(admin_url('admin-ajax.php'));?>';
        var nonce='<?php echo esc_js(wp_create_nonce('lgw_gchamp_score'));?>';
        var champId='<?php echo esc_js($champ_id);?>';
        function ss(el,msg,cls){if(!el)return;el.textContent=msg;el.className='lgw-gchamp-admin-score-status'+(cls?' '+cls:'');}
        function saveScore(row,clear){
            var pk=row.getAttribute('data-pos-key'),dayId=row.getAttribute('data-day-id'),gid=row.getAttribute('data-group-id');
            var hi=row.querySelector('.lgw-gchamp-admin-score-h'),ai=row.querySelector('.lgw-gchamp-admin-score-a');
            var st=row.querySelector('.lgw-gchamp-admin-score-status');
            if(!clear&&(hi.value.trim()===''||ai.value.trim()==='')){ss(st,'Enter both scores','err');return;}
            ss(st,'Saving\u2026','');
            var fd=new FormData();
            fd.append('action','lgw_gchamp_save_score');fd.append('nonce',nonce);fd.append('champ_id',champId);
            fd.append('day_id',dayId);fd.append('group_id',gid);fd.append('pos_key',pk);
            if(clear)fd.append('clear','1');else{fd.append('home_score',hi.value);fd.append('away_score',ai.value);}
            fetch(ajaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){
                if(!data.success){ss(st,data.data||'Error','err');return;}
                if(clear){hi.value='';ai.value='';var cb=row.querySelector('.lgw-gchamp-admin-clear-btn');if(cb)cb.remove();}
                else if(!row.querySelector('.lgw-gchamp-admin-clear-btn')){
                    var sb=row.querySelector('.lgw-gchamp-admin-save-btn');
                    if(sb){var cb2=document.createElement('button');cb2.type='button';cb2.className='lgw-gchamp-admin-clear-btn';cb2.textContent='Clear';sb.parentNode.insertBefore(cb2,sb.nextSibling);}
                }
                ss(st,clear?'Cleared':'Saved \u2713','ok');setTimeout(function(){ss(st,'','');},2500);
                if(data.data&&data.data.group_stage_complete)location.reload();
            }).catch(function(err){ss(st,'Failed: '+err.message,'err');});
        }
        document.querySelectorAll('.lgw-gchamp-admin-save-btn').forEach(function(b){b.addEventListener('click',function(){saveScore(b.closest('.lgw-gchamp-admin-fx-row'),false);});});
        document.addEventListener('click',function(e){var cb=e.target.closest('.lgw-gchamp-admin-clear-btn');if(!cb)return;if(confirm('Clear this score?'))saveScore(cb.closest('.lgw-gchamp-admin-fx-row'),true);});
        document.querySelectorAll('.lgw-gchamp-admin-score-input').forEach(function(i){i.addEventListener('keydown',function(e){if(e.key!=='Enter')return;e.preventDefault();saveScore(i.closest('.lgw-gchamp-admin-fx-row'),false);});});
    })();
    </script>
    <?php endif;?>

    </div><!-- .wrap -->
    <script>
    (function(){
        var entriesTA=document.getElementById('lgw_gchamp_entries');
        var numDaysInp=document.getElementById('lgw_gchamp_num_days');
        var tbody=document.getElementById('lgw-gchamp-days-tbody');
        var entryCount=document.getElementById('lgw-gchamp-entry-count');
        var distSpan=document.getElementById('lgw-gchamp-distribution');
        var addDayBtn=document.getElementById('lgw-gchamp-add-day');
        var dayIndex=<?php echo $num_days;?>;

        function countEntries(){return entriesTA?entriesTA.value.split('\n').filter(function(l){return l.trim()!=='';}).length:0;}

        // ── Entry format validator ────────────────────────────────────────────
        var entryFormatWarn=document.getElementById('lgw-gchamp-entry-format-warn');
        function validateEntries(){
            if(!entriesTA)return true;
            var lines=entriesTA.value.split('\n').filter(function(l){return l.trim()!=='';});
            var bad=lines.filter(function(l){return l.indexOf(',')===-1;});
            if(bad.length>0){
                if(entryFormatWarn){
                    entryFormatWarn.style.display='block';
                    entryFormatWarn.innerHTML='<strong>⚠ '+bad.length+' entr'+(bad.length===1?'y':'ies')+' missing club name</strong> (no comma found):<br>'
                        +'<code style="font-size:12px">'+bad.slice(0,5).map(function(l){return l.replace(/</g,'&lt;');}).join('<br>')+(bad.length>5?'<br>…and '+(bad.length-5)+' more':'')+'</code>'
                        +'<br>Format must be: <code>Player Name(s), Club</code>';
                }
                return false;
            }
            if(entryFormatWarn)entryFormatWarn.style.display='none';
            return true;
        }
        if(entriesTA){
            entriesTA.addEventListener('input',function(){validateEntries();updateAll();});
            validateEntries(); // run on page load
        }

        function calcDay(row,dayEntries){
            var tgsEl=row.querySelector('.lgw-gchamp-day-tgs');
            var wpgEl=row.querySelector('.lgw-gchamp-day-wpg');
            var bruEl=row.querySelector('.lgw-gchamp-day-bru');
            var calc=row.querySelector('.lgw-gchamp-day-calc');
            if(!tgsEl||!wpgEl||!bruEl||!calc)return;
            var tgs=Math.max(2,parseInt(tgsEl.value)||4);
            var wpg=Math.max(1,parseInt(wpgEl.value)||1);
            var bru=Math.max(0,parseInt(bruEl.value)||0);
            var ng=Math.ceil(dayEntries/tgs);
            var rem=dayEntries%tgs;
            var totalQ=(ng*wpg)+bru;
            var sz=rem===0?ng+' groups of '+tgs:(ng-1)+' groups of '+tgs+', 1 of '+(dayEntries-tgs*(ng-1));
            calc.innerHTML='<strong>'+ng+' group'+(ng!==1?'s':'')+'</strong><br><span style="color:#555">'+sz+'</span><br><span style="color:#138211;font-weight:600">'+totalQ+' qualifier'+(totalQ!==1?'s':'')+'</span>';
        }

        function updateAll(){
            var n=countEntries();
            if(entryCount)entryCount.textContent=n;
            var rows=tbody?tbody.querySelectorAll('.lgw-gchamp-day-row'):[];
            var nd=rows.length;
            if(nd===0)return;
            var base=Math.floor(n/nd),rem=n%nd;
            rows.forEach(function(row,i){calcDay(row,base+(i<rem?1:0));});
            if(distSpan)distSpan.textContent=n===0?'':(nd===1?'':(rem?(' — ~'+base+'/~'+(base+1)+' per day'):(' — '+base+' per day')));
            if(numDaysInp)numDaysInp.value=nd;
        }

        function bindRow(row){row.querySelectorAll('input').forEach(function(i){i.addEventListener('input',updateAll);});}

        if(addDayBtn){
            addDayBtn.addEventListener('click',function(){
                var i=dayIndex++;
                var tr=document.createElement('tr');tr.className='lgw-gchamp-day-row';tr.setAttribute('data-day',i);
                tr.innerHTML='<td style="color:#888;font-weight:700;text-align:center">'+(i+1)+'</td>'+
                    '<td><input type="text" name="lgw_gchamp_day_names['+i+']" value="Day '+(i+1)+'" class="regular-text" style="width:100%"></td>'+
                    '<td><input type="text" name="lgw_gchamp_day_dates['+i+']" placeholder="dd/mm/yyyy" class="small-text" style="width:96px"></td>'+
                    '<td><input type="text" name="lgw_gchamp_day_locations['+i+']" placeholder="e.g. Greenacres BC" class="regular-text lgw-gchamp-day-location" style="width:140px"></td>'+
                    '<td><input type="number" name="lgw_gchamp_day_tgs['+i+']" value="4" min="2" max="20" step="1" class="small-text lgw-gchamp-day-tgs" style="width:58px"></td>'+
                    '<td><input type="number" name="lgw_gchamp_day_wpg['+i+']" value="1" min="1" step="1" class="small-text lgw-gchamp-day-wpg" style="width:52px"></td>'+
                    '<td><input type="number" name="lgw_gchamp_day_bru['+i+']" value="0" min="0" step="1" class="small-text lgw-gchamp-day-bru" style="width:52px"></td>'+
                    '<td><select name="lgw_gchamp_day_fq['+i+']" class="small-text lgw-gchamp-day-fq" style="width:60px"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option></select></td>'+
                    '<td class="lgw-gchamp-day-calc" style="font-size:12px;color:#555">\u2014</td>'+
                    '<td><button type="button" class="button button-small lgw-gchamp-day-remove" style="color:#c00;padding:2px 6px">\u2715</button></td>';
                tbody.appendChild(tr);bindRow(tr);updateAll();
            });
        }

        if(tbody){
            tbody.addEventListener('click',function(e){
                var btn=e.target.closest('.lgw-gchamp-day-remove');if(!btn)return;
                var rows=tbody.querySelectorAll('.lgw-gchamp-day-row');if(rows.length<=1)return;
                btn.closest('tr').remove();
                tbody.querySelectorAll('.lgw-gchamp-day-row').forEach(function(r,idx){
                    var nc=r.querySelector('td:first-child');if(nc)nc.textContent=idx+1;
                    r.querySelectorAll('input').forEach(function(inp){inp.name=inp.name.replace(/\[\d+\]/,'['+idx+']');});
                });
                updateAll();
            });
            tbody.querySelectorAll('.lgw-gchamp-day-row').forEach(bindRow);
        }
        if(entriesTA)entriesTA.addEventListener('input',updateAll);

        // Prefs panel
        function updatePrefSummary(){
            var s=document.getElementById('lgw-gchamp-prefs-summary');if(!s)return;
            var dateSels=document.querySelectorAll('.lgw-gchamp-pref-date-select');
            var locSels=document.querySelectorAll('.lgw-gchamp-pref-loc-select');
            var dateCounts={},dateTotal=0,locTotal=0;
            dateSels.forEach(function(x){if(x.value){dateCounts[x.value]=(dateCounts[x.value]||0)+1;dateTotal++;}});
            locSels.forEach(function(x){if(x.value)locTotal++;});
            var parts=[];
            if(dateTotal){var dp=[];Object.keys(dateCounts).sort().forEach(function(d){dp.push(dateCounts[d]+' \u2192 '+d);});parts.push(dateTotal+' date pref'+(dateTotal!==1?'s':'')+': '+dp.join(', '));}
            if(locTotal){parts.push(locTotal+' venue pref'+(locTotal!==1?'s':'')+'.');}
            s.textContent=parts.length?parts.join(' | '):'No preferences set.';
        }
        document.querySelectorAll('.lgw-gchamp-pref-date-select').forEach(function(s){s.addEventListener('change',updatePrefSummary);});
        document.querySelectorAll('.lgw-gchamp-pref-loc-select').forEach(function(s){s.addEventListener('change',updatePrefSummary);});
        document.querySelectorAll('.lgw-gchamp-prefs-bulk-date').forEach(function(b){b.addEventListener('click',function(){var d=b.getAttribute('data-date');document.querySelectorAll('.lgw-gchamp-pref-date-select').forEach(function(s){s.value=d;});updatePrefSummary();});});
        document.querySelectorAll('.lgw-gchamp-prefs-bulk-loc').forEach(function(b){b.addEventListener('click',function(){var l=b.getAttribute('data-loc');document.querySelectorAll('.lgw-gchamp-pref-loc-select').forEach(function(s){s.value=l;});updatePrefSummary();});});
        var ca=document.getElementById('lgw-gchamp-prefs-clear-all');if(ca)ca.addEventListener('click',function(){document.querySelectorAll('.lgw-gchamp-pref-date-select').forEach(function(s){s.value='';});document.querySelectorAll('.lgw-gchamp-pref-loc-select').forEach(function(s){s.value='';});updatePrefSummary();});

        updateAll();updatePrefSummary();

        // Block form submit if entries have format errors
        var gchampForm=entriesTA?entriesTA.closest('form'):null;
        if(gchampForm){
            gchampForm.addEventListener('submit',function(e){
                if(!validateEntries()){
                    e.preventDefault();
                    entryFormatWarn.scrollIntoView({behavior:'smooth',block:'center'});
                    entryFormatWarn.style.outline='2px solid #f0b429';
                    setTimeout(function(){entryFormatWarn.style.outline='';},2000);
                }
            });
        }
    })();
    </script>
    <?php
}


// ── AJAX: run the group draw ───────────────────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_run_draw', 'lgw_ajax_gchamp_run_draw' );
function lgw_ajax_gchamp_run_draw() {
    check_ajax_referer( 'lgw_gchamp_draw', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );
    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    if ( ! $champ_id ) wp_send_json_error( 'Missing championship ID' );
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) ) wp_send_json_error( 'Championship not found' );
    $confirmed = ! empty( $_POST['confirmed'] );
    if ( lgw_gchamp_has_any_scores( $champ ) && ! $confirmed ) {
        wp_send_json_error( array( 'code'=>'scores_exist', 'message'=>'Scores have already been entered. Confirming will permanently delete all scores, fixtures, and player appearance records.' ) );
    }
    $entries     = $champ['entries']           ?? array();
    $days_config = $champ['days_config']       ?? array();
    $entry_prefs = $champ['entry_preferences'] ?? array();
    if ( empty( $entries ) )     wp_send_json_error( 'No entries configured' );
    if ( empty( $days_config ) ) wp_send_json_error( 'No days configured' );
    if ( ! empty( $champ['draw_complete'] ) && function_exists( 'lgw_clear_all_champ_appearances' ) ) {
        lgw_clear_all_champ_appearances( $champ_id );
    }
    $result = lgw_gchamp_run_draw( $entries, $days_config, $entry_prefs );
    if ( is_wp_error( $result ) ) wp_send_json_error( $result->get_error_message() );

    // If draw has genuine club violations, retry up to 4 more times and keep best result
    $violation_warning = '⚠';
    $count_genuine_violations = function( $warnings ) use ( $violation_warning ) {
        return count( array_filter( $warnings, fn($w) => strpos($w, $violation_warning) === 0 && strpos($w, 'from  ') === false ) );
    };
    $best_result    = $result;
    $best_violations = $count_genuine_violations( $result['warnings'] );
    for ( $retry = 0; $retry < 4 && $best_violations > 0; $retry++ ) {
        $retry_result = lgw_gchamp_run_draw( $entries, $days_config, $entry_prefs );
        if ( ! is_wp_error( $retry_result ) ) {
            $rv = $count_genuine_violations( $retry_result['warnings'] );
            if ( $rv < $best_violations ) {
                $best_result     = $retry_result;
                $best_violations = $rv;
            }
        }
    }
    $result = $best_result;
    $champ['days']                 = $result['days'];
    $champ['draw_complete']        = true;
    $champ['group_stage_complete'] = false;
    $champ['ko_bracket']           = null;
    $champ['qualifiers']           = array();
    $champ['draw_warnings']        = $result['warnings'];
    $champ['draw_version']         = LGW_VERSION; // stamp which plugin version produced this draw
    $champ['draw_timestamp']       = time();
    if ( strlen( serialize( $champ ) ) > 800000 ) wp_send_json_error( 'Draw data exceeds safe storage limit.' );
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'days'=>$result['days'], 'warnings'=>$result['warnings'] ) );
}



// ── Helper: normalise whitespace for tolerant entry-name comparison ───────────
function lgw_gchamp_norm_entry( $s ) {
    return preg_replace( '/\s+/', ' ', trim( (string) $s ) );
}

// ── Helper: collect every unique entry-name string found anywhere in a gchamp ─
/**
 * Returns a deduplicated, sorted list of every entry-name string that appears
 * anywhere in the championship — the top-level entries list, every group's
 * entries and fixtures, every knockout bracket (per-day and top-level), and
 * every qualifiers list. Used to populate the Rename Entry dropdown so an
 * entry baked into a drawn group (which may have drifted from the current
 * top-level entries list) can still be selected and renamed.
 */
function lgw_gchamp_collect_all_entries( array $champ ) {
    $names = array();

    foreach ( $champ['entries'] ?? array() as $e ) {
        if ( $e !== null && $e !== '' ) $names[ $e ] = true;
    }

    $collect_matches = function( $matches ) use ( &$names ) {
        if ( empty( $matches ) || ! is_array( $matches ) ) return;
        foreach ( $matches as $m ) {
            if ( ! empty( $m['home'] ) ) $names[ $m['home'] ] = true;
            if ( ! empty( $m['away'] ) ) $names[ $m['away'] ] = true;
        }
    };

    foreach ( $champ['days'] ?? array() as $day ) {
        foreach ( $day['groups'] ?? array() as $group ) {
            foreach ( $group['entries'] ?? array() as $e ) {
                if ( $e !== null && $e !== '' ) $names[ $e ] = true;
            }
            $collect_matches( $group['fixtures'] ?? array() );
        }
        foreach ( ( $day['ko_bracket']['rounds'] ?? array() ) as $round ) {
            $collect_matches( $round['matches'] ?? array() );
        }
        foreach ( $day['qualifiers'] ?? array() as $q ) {
            if ( $q !== null && $q !== '' ) $names[ $q ] = true;
        }
    }

    if ( ! empty( $champ['ko_bracket']['matches'] ) && is_array( $champ['ko_bracket']['matches'] ) ) {
        foreach ( $champ['ko_bracket']['matches'] as $round ) {
            $collect_matches( $round );
        }
    }

    foreach ( $champ['qualifiers'] ?? array() as $q ) {
        if ( $q !== null && $q !== '' ) $names[ $q ] = true;
    }

    $list = array_keys( $names );
    sort( $list, SORT_STRING | SORT_FLAG_CASE );
    return $list;
}

// ── AJAX: rename an entry everywhere (entries list, group fixtures, KO brackets, qualifiers) ─
add_action( 'wp_ajax_lgw_gchamp_rename_entry', 'lgw_ajax_gchamp_rename_entry' );
/**
 * Admin-only: rename an entry across every structure in a group championship.
 *
 * POST params:
 *   champ_id  - championship slug
 *   old_name  - exact current entry string
 *   new_name  - corrected entry string
 *   nonce     - lgw_gchamp_score nonce
 *
 * Replaces all occurrences in:
 *   - champ['entries'] (top-level master list)
 *   - champ['days'][*]['groups'][*]['entries']
 *   - champ['days'][*]['groups'][*]['fixtures'] (home/away)
 *   - champ['days'][*]['ko_bracket']['rounds'][*]['matches'] (home/away)
 *   - champ['days'][*]['qualifiers']
 *   - champ['ko_bracket']['matches'][round][match] (home/away) — champ.php-style nested bracket
 *   - champ['qualifiers']
 * Does NOT reset any scores or cascade — this is a spelling-correction only.
 */
function lgw_ajax_gchamp_rename_entry() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $old_name = sanitize_text_field( wp_unslash( $_POST['old_name'] ?? '' ) );
    $new_name = sanitize_text_field( wp_unslash( $_POST['new_name'] ?? '' ) );

    if ( ! $champ_id || $old_name === '' || $new_name === '' ) {
        wp_send_json_error( 'Missing parameters' );
    }
    if ( $old_name === $new_name ) {
        wp_send_json_error( 'New name is identical to old name' );
    }

    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) ) wp_send_json_error( 'Championship not found' );

    $replaced  = 0;
    $norm_old  = lgw_gchamp_norm_entry( $old_name );

    // Whitespace-tolerant equality: a drawn group/bracket may have drifted
    // slightly (extra spaces, etc.) from the current top-level entries list.
    $matches_old = function( $val ) use ( $norm_old ) {
        return $val !== null && lgw_gchamp_norm_entry( $val ) === $norm_old;
    };

    // 1. Top-level entries list
    foreach ( $champ['entries'] ?? array() as $i => $entry ) {
        if ( $matches_old( $entry ) ) {
            $champ['entries'][$i] = $new_name;
            $replaced++;
        }
    }

    // Helper: rename flat home/away fixtures array (group fixtures, day ko_bracket round matches)
    $rename_in_flat_matches = function ( &$matches ) use ( $matches_old, $new_name, &$replaced ) {
        if ( empty( $matches ) || ! is_array( $matches ) ) return;
        foreach ( $matches as &$m ) {
            if ( $matches_old( $m['home'] ?? null ) ) { $m['home'] = $new_name; $replaced++; }
            if ( $matches_old( $m['away'] ?? null ) ) { $m['away'] = $new_name; $replaced++; }
        }
        unset( $m );
    };

    // Helper: rename champ.php-style [round][match] nested bracket
    $rename_in_nested_bracket = function ( &$bracket ) use ( $matches_old, $new_name, &$replaced ) {
        if ( empty( $bracket['matches'] ) || ! is_array( $bracket['matches'] ) ) return;
        foreach ( $bracket['matches'] as &$round ) {
            foreach ( $round as &$match ) {
                if ( $matches_old( $match['home'] ?? null ) ) { $match['home'] = $new_name; $replaced++; }
                if ( $matches_old( $match['away'] ?? null ) ) { $match['away'] = $new_name; $replaced++; }
            }
            unset( $match );
        }
        unset( $round );
    };

    // 2. Per-day groups: entries + fixtures, per-day ko_bracket rounds, per-day qualifiers
    //
    // IMPORTANT: do NOT write `foreach ( $champ['days'] ?? array() as $di => &$day )`.
    // The `??` operator produces a temporary value — `&$day` would then reference
    // an element of that temporary copy, and any writes made through $day (or
    // references derived from it, like $group below) are silently discarded and
    // never reach $champ['days']. Same applies one level down for $day['groups'].
    if ( ! empty( $champ['days'] ) && is_array( $champ['days'] ) ) {
        foreach ( $champ['days'] as $di => &$day ) {
            if ( ! empty( $day['groups'] ) && is_array( $day['groups'] ) ) {
                foreach ( $day['groups'] as $gi => &$group ) {
                    if ( ! empty( $group['entries'] ) && is_array( $group['entries'] ) ) {
                        foreach ( $group['entries'] as $ei => $entry ) {
                            if ( $matches_old( $entry ) ) {
                                $group['entries'][$ei] = $new_name;
                                $replaced++;
                            }
                        }
                    }
                    if ( ! empty( $group['fixtures'] ) ) {
                        $rename_in_flat_matches( $group['fixtures'] );
                    }
                }
                unset( $group );
            }

            if ( ! empty( $day['ko_bracket']['rounds'] ) ) {
                foreach ( $day['ko_bracket']['rounds'] as &$round ) {
                    if ( ! empty( $round['matches'] ) ) {
                        $rename_in_flat_matches( $round['matches'] );
                    }
                }
                unset( $round );
            }

            if ( ! empty( $day['qualifiers'] ) && is_array( $day['qualifiers'] ) ) {
                foreach ( $day['qualifiers'] as $qi => $q ) {
                    if ( $matches_old( $q ) ) {
                        $day['qualifiers'][$qi] = $new_name;
                        $replaced++;
                    }
                }
            }
        }
        unset( $day );
    }

    // 3. Top-level knockout bracket (champ.php-style nested [round][match])
    if ( ! empty( $champ['ko_bracket'] ) ) {
        $rename_in_nested_bracket( $champ['ko_bracket'] );
    }

    // 4. Top-level qualifiers
    foreach ( $champ['qualifiers'] ?? array() as $qi => $q ) {
        if ( $matches_old( $q ) ) {
            $champ['qualifiers'][$qi] = $new_name;
            $replaced++;
        }
    }

    if ( $replaced === 0 ) {
        wp_send_json_error( 'Entry "' . esc_html( $old_name ) . '" not found' );
    }

    update_option( 'lgw_gchamp_' . $champ_id, $champ );

    wp_send_json_success( array(
        'message'  => 'Renamed "' . esc_html( $old_name ) . '" → "' . esc_html( $new_name ) . '" (' . $replaced . ' occurrences updated).',
        'replaced' => $replaced,
    ) );
}

// ── AJAX: get entries list for a championship (for rename dropdown) ───────────
add_action( 'wp_ajax_lgw_gchamp_get_entries', 'lgw_ajax_gchamp_get_entries' );
function lgw_ajax_gchamp_get_entries() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) ) wp_send_json_error( 'Championship not found' );

    wp_send_json_success( array(
        'entries' => lgw_gchamp_collect_all_entries( $champ ),
    ) );
}

// ── AJAX: save a group score ──────────────────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_save_score',        'lgw_ajax_gchamp_save_score' );
add_action( 'wp_ajax_nopriv_lgw_gchamp_save_score', 'lgw_ajax_gchamp_save_score' );
function lgw_ajax_gchamp_save_score() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Permission denied.' );
    $champ_id = sanitize_key( $_POST['champ_id']  ?? '' );
    $day_id   = intval( $_POST['day_id']           ?? -1 );
    $group_id = intval( $_POST['group_id']         ?? -1 );
    $pos_key  = sanitize_text_field( $_POST['pos_key'] ?? '' );
    $clear    = ! empty( $_POST['clear'] );
    $context   = sanitize_key( $_POST['context'] ?? 'group' ); // 'group' or 'ko'
    $ko_round  = intval( $_POST['ko_round'] ?? -1 );
    $ko_match  = intval( $_POST['ko_match'] ?? -1 );
    // For group context, pos_key and group_id are required; for ko context they are not
    if ( $context !== 'ko' && ( $group_id < 0 || ! $pos_key ) ) wp_send_json_error( 'Missing parameters.' );
    if ( ! $champ_id || $day_id < 0 ) wp_send_json_error( 'Missing parameters.' );
    if ( ! $clear ) {
        $hs = $_POST['home_score'] ?? ''; $as = $_POST['away_score'] ?? '';
        if ( ! is_numeric($hs)||!is_numeric($as)||intval($hs)<0||intval($as)<0 ) wp_send_json_error( 'Scores must be non-negative whole numbers.' );
        $hs = intval($hs); $as = intval($as);
    }
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty($champ) )                    wp_send_json_error('Championship not found.');
    if ( empty($champ['draw_complete']) )   wp_send_json_error('Draw not yet performed.');
    if ( ! isset($champ['days'][$day_id]) ) wp_send_json_error('Day not found.');
    $standings = array();

    if ( $context === 'ko' ) {
        // ── Knockout fixture save ──────────────────────────────────────────────
        if ( ! isset( $champ['days'][$day_id]['ko_bracket'] ) || empty( $champ['days'][$day_id]['ko_bracket'] ) ) {
            wp_send_json_error('KO bracket not yet seeded for this day.');
        }
        $found = false;
        foreach ( $champ['days'][$day_id]['ko_bracket']['rounds'] as &$round ) {
            if ( $round['round'] !== $ko_round ) continue;
            foreach ( $round['matches'] as &$match ) {
                if ( $match['match'] !== $ko_match ) continue;
                $match['home_score'] = $clear ? null : $hs;
                $match['away_score'] = $clear ? null : $as;
                // Sync the next round to the new result. Passing the winner —
                // or null when cleared or drawn — advances, rolls back, or
                // replaces the downstream slot and cascades any invalidated
                // later-round results (fixes stale winners left after a clear
                // or a result edited to a different winner).
                $winner = ( ! $clear && $hs !== $as )
                    ? ( $hs > $as ? $match['home'] : $match['away'] )
                    : null;
                lgw_gchamp_set_ko_advance( $champ['days'][$day_id]['ko_bracket'], $ko_round, $ko_match, $winner );
                $found = true; break 2;
            }
            unset($match);
        }
        unset($round);
        if ( ! $found ) wp_send_json_error('KO fixture not found.');

        // Check ko_complete — done when required qualifier rounds are all played
        $num_days_total = count( $champ['days'] );
        $fq             = intval( $champ['days'][$day_id]['finals_qualifiers'] ?? $champ['finals_qualifiers_per_day'] ?? 1 );
        $play_final     = ! empty( $champ['ko_play_day_final'] );
        $ko_complete    = lgw_gchamp_ko_qualifiers_complete( $champ['days'][$day_id]['ko_bracket'], $fq, $play_final );
        if ( $ko_complete && ! $clear ) {
            $champ['days'][$day_id]['ko_complete']   = true;
            $champ['days'][$day_id]['ko_qualifiers'] = lgw_gchamp_compute_ko_qualifiers(
                $champ['days'][$day_id]['ko_bracket'], $fq, $play_final
            );
            // Rebuild Finals Week matches now that we have new qualifiers
            $new_q_count = array_sum( array_map(
                fn($d) => count( $d['ko_qualifiers'] ?? array() ),
                $champ['days']
            ) );
            if ( $new_q_count !== ( $champ['finals_q_count_at_build'] ?? -1 ) ) {
                $built = lgw_gchamp_build_finals_matches( $champ );
                if ( ! empty( $built ) ) {
                    $champ['finals_matches']          = $built;
                    $champ['finals_q_count_at_build'] = $new_q_count;
                }
            }
        } elseif ( $clear ) {
            $champ['days'][$day_id]['ko_complete']   = false;
            $champ['days'][$day_id]['ko_qualifiers'] = array();
            // Rebuild Finals Week matches without this day's qualifiers
            $new_q_count = array_sum( array_map(
                fn($d) => count( $d['ko_qualifiers'] ?? array() ),
                $champ['days']
            ) );
            if ( $new_q_count !== ( $champ['finals_q_count_at_build'] ?? -1 ) ) {
                $built = lgw_gchamp_build_finals_matches( $champ );
                $champ['finals_matches']          = ! empty( $built ) ? $built : array();
                $champ['finals_q_count_at_build'] = $new_q_count;
            }
        }

        $all_complete = true;
        foreach ( $champ['days'] as $d ) { if ( empty($d['day_complete']) ) { $all_complete=false; break; } }
        $champ['group_stage_complete'] = $all_complete;
        update_option( 'lgw_gchamp_' . $champ_id, $champ );
        wp_send_json_success( array(
            'context'        => 'ko',
            'day_id'         => $day_id,
            'ko_bracket'     => $champ['days'][$day_id]['ko_bracket'],
            'ko_complete'    => $ko_complete,
            'ko_qualifiers'  => $champ['days'][$day_id]['ko_qualifiers'] ?? array(),
        ) );

    } else {
        // ── Group fixture save ────────────────────────────────────────────────
        if ( $group_id < 0 || ! $pos_key ) wp_send_json_error('Missing group parameters.');
        if ( ! isset($champ['days'][$day_id]['groups'][$group_id]) ) wp_send_json_error('Group not found.');

        // Check if ANY KO scores exist (used to gate bracket reseeding)
        $day_ko = $champ['days'][$day_id]['ko_bracket'] ?? null;
        $ko_has_scores_check = false;
        if ( $day_ko ) {
            foreach ( ($day_ko['rounds'] ?? array()) as $_rnd ) {
                foreach ( ($_rnd['matches'] ?? array()) as $_mch ) {
                    if ( $_mch['home_score'] !== null || $_mch['away_score'] !== null ) {
                        $ko_has_scores_check = true; break 2;
                    }
                }
            }
        }

        // Block edits only if a qualifier from THIS group is already in a played KO match.
        // Other groups' edits are still allowed even if the KO bracket has scores elsewhere.
        if ( $ko_has_scores_check && $day_ko ) {
            $group_entries_list = $champ['days'][$day_id]['groups'][$group_id]['entries'] ?? array();
            // Get the qualifiers that came from this group
            $group_qualifiers = lgw_gchamp_qualifiers_from_group(
                $champ['days'][$day_id], $group_id
            );
            if ( lgw_gchamp_qualifiers_in_played_ko( $day_ko, $group_qualifiers ) ) {
                wp_send_json_error( 'Cannot edit these group scores — a qualifier from this group has already played in the knockout bracket.' );
            }
        }

        $found = false;
        foreach ( $champ['days'][$day_id]['groups'][$group_id]['fixtures'] as &$fx ) {
            if ( ($fx['pos_key']??'') === $pos_key ) {
                $fx['home_score'] = $clear ? null : $hs;
                $fx['away_score'] = $clear ? null : $as;
                $found = true; break;
            }
        }
        unset($fx);
        if ( ! $found ) wp_send_json_error('Fixture not found.');
        $g_entries = $champ['days'][$day_id]['groups'][$group_id]['entries']  ?? array();
        $g_fixtures= $champ['days'][$day_id]['groups'][$group_id]['fixtures'] ?? array();
        $standings  = lgw_gchamp_compute_standings( $g_entries, $g_fixtures );

        $was_complete = ! empty( $champ['days'][$day_id]['day_complete'] );
        $day_complete = lgw_gchamp_day_fixtures_all_played( $champ['days'][$day_id] );

        // Always recompute group-level completion and partial qualifiers
        $partial = lgw_gchamp_compute_partial_qualifiers( $champ['days'][$day_id] );
        $champ['days'][$day_id]['qualifier_slots'] = $partial['slots'];

        if ( $day_complete && ! $clear ) {
            $champ['days'][$day_id]['day_complete'] = true;
            $champ['days'][$day_id]['qualifiers']   = lgw_gchamp_compute_day_qualifiers( $champ['days'][$day_id] );
            if ( $ko_has_scores_check ) {
                // KO in progress — fill TBD slots only, don't disturb played matches
                lgw_gchamp_fill_ko_tbd_slots(
                    $champ['days'][$day_id]['ko_bracket'],
                    $partial['confirmed']
                );
            } else {
                lgw_gchamp_auto_seed_day_ko( $champ, $day_id );
            }
        } elseif ( $clear ) {
            $champ['days'][$day_id]['day_complete'] = false;
            $champ['days'][$day_id]['qualifiers']   = array();
            if ( $ko_has_scores_check ) {
                // KO in progress — slot-fill from remaining confirmed qualifiers
                lgw_gchamp_fill_ko_tbd_slots(
                    $champ['days'][$day_id]['ko_bracket'],
                    $partial['confirmed']
                );
            } elseif ( lgw_gchamp_any_group_complete( $champ['days'][$day_id] ) ) {
                lgw_gchamp_auto_seed_day_ko( $champ, $day_id );
            } else {
                $champ['days'][$day_id]['ko_bracket'] = null;
            }
        } else {
            // Partial completion — fill or seed as appropriate
            if ( $ko_has_scores_check ) {
                lgw_gchamp_fill_ko_tbd_slots(
                    $champ['days'][$day_id]['ko_bracket'],
                    $partial['confirmed']
                );
            } elseif ( lgw_gchamp_any_group_complete( $champ['days'][$day_id] ) ) {
                lgw_gchamp_auto_seed_day_ko( $champ, $day_id );
            }
        }

        $all_complete = true;
        foreach ( $champ['days'] as $d ) { if ( empty($d['day_complete']) ) { $all_complete=false; break; } }
        $champ['group_stage_complete'] = $all_complete;
        update_option( 'lgw_gchamp_' . $champ_id, $champ );
        wp_send_json_success( array(
            'context'              => 'group',
            'standings'            => $standings,
            'day_id'               => $day_id,
            'group_id'             => $group_id,
            'day_complete'         => $day_complete,
            'group_stage_complete' => $all_complete,
            'day_qualifiers'       => $champ['days'][$day_id]['qualifiers']   ?? array(),
            'ko_bracket'           => $champ['days'][$day_id]['ko_bracket']   ?? null,
            'ko_seeded'            => ! empty( $champ['days'][$day_id]['ko_bracket'] ),
        ) );
    }
}
// ── AJAX: clear all KO scores for a day ──────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_clear_ko_scores', 'lgw_ajax_gchamp_clear_ko_scores' );
function lgw_ajax_gchamp_clear_ko_scores() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );
    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $day_id   = intval( $_POST['day_id'] ?? -1 );
    if ( ! $champ_id || $day_id < 0 ) wp_send_json_error( 'Missing parameters.' );
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) )                    wp_send_json_error( 'Championship not found.' );
    if ( ! isset( $champ['days'][$day_id] ) ) wp_send_json_error( 'Day not found.' );

    // Clear all scores in the KO bracket and reset advancement
    if ( ! empty( $champ['days'][$day_id]['ko_bracket']['rounds'] ) ) {
        foreach ( $champ['days'][$day_id]['ko_bracket']['rounds'] as &$round ) {
            foreach ( $round['matches'] as &$match ) {
                $match['home_score'] = null;
                $match['away_score'] = null;
                // Clear advancement beyond round 0
                if ( $round['round'] > 0 ) {
                    $match['home'] = null;
                    $match['away'] = null;
                }
            }
            unset( $match );
        }
        unset( $round );
    }
    $champ['days'][$day_id]['ko_complete']   = false;
    $champ['days'][$day_id]['ko_qualifiers'] = array();

    // Re-fill TBD slots from current partial qualifiers
    $partial = lgw_gchamp_compute_partial_qualifiers( $champ['days'][$day_id] );
    $champ['days'][$day_id]['qualifier_slots'] = $partial['slots'];
    if ( ! empty( $champ['days'][$day_id]['ko_bracket'] ) ) {
        lgw_gchamp_fill_ko_tbd_slots( $champ['days'][$day_id]['ko_bracket'], $partial['confirmed'] );
    }

    // Unlock all groups on this day
    foreach ( $champ['days'][$day_id]['groups'] as &$group ) {
        $group['locked'] = false;
    }
    unset( $group );

    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'day_id' => $day_id ) );
}

// ── AJAX: manually set a first-round KO slot (manual seeding, admin) ──────────
add_action( 'wp_ajax_lgw_gchamp_ko_set_slot', 'lgw_ajax_gchamp_ko_set_slot' );
function lgw_ajax_gchamp_ko_set_slot() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $day_id   = intval( $_POST['day_id'] ?? -1 );
    $ko_match = intval( $_POST['ko_match'] ?? -1 );
    $slot     = ( ( $_POST['slot'] ?? '' ) === 'away' ) ? 'away' : 'home';
    // Full entry name as stored in the bracket, or '' to vacate the slot.
    $value    = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );
    if ( ! $champ_id || $day_id < 0 || $ko_match < 0 ) wp_send_json_error( 'Missing parameters.' );

    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) )                                 wp_send_json_error( 'Championship not found.' );
    if ( ! isset( $champ['days'][$day_id]['ko_bracket']['rounds'] ) ) wp_send_json_error( 'No knockout bracket for this day.' );

    $bracket = &$champ['days'][$day_id]['ko_bracket'];

    // Only the first round is manually seedable — later rounds are computed.
    $r0 = null;
    foreach ( $bracket['rounds'] as $ri => $round ) {
        if ( $round['round'] === 0 ) { $r0 = $ri; break; }
    }
    if ( $r0 === null ) wp_send_json_error( 'First round not found.' );

    // Locate the target match.
    $mi = null;
    foreach ( $bracket['rounds'][$r0]['matches'] as $k => $m ) {
        if ( intval( $m['match'] ) === $ko_match ) { $mi = $k; break; }
    }
    if ( $mi === null )                                    wp_send_json_error( 'Knockout match not found.' );
    if ( ! empty( $bracket['rounds'][$r0]['matches'][$mi]['bye'] ) ) wp_send_json_error( 'Cannot edit a bye match.' );

    // Validate the value against this day's known qualifiers (confirmed slots or
    // the day qualifier list). Empty is always allowed (vacate to TBD).
    if ( $value !== '' ) {
        $pool = array();
        foreach ( (array) ( $champ['days'][$day_id]['qualifier_slots'] ?? array() ) as $q ) { if ( $q !== null && $q !== '' ) $pool[] = $q; }
        foreach ( (array) ( $champ['days'][$day_id]['qualifiers']      ?? array() ) as $q ) { if ( $q !== null && $q !== '' ) $pool[] = $q; }
        // Entry names can contain runs of whitespace (e.g. "Name,    Club"),
        // but sanitize_text_field() collapses those to a single space — so a
        // strict compare against the stored pool would always miss. Match
        // whitespace-insensitively, then assign the exact stored form.
        $norm      = static function ( $s ) { return preg_replace( '/\s+/', ' ', trim( (string) $s ) ); };
        $canonical = null;
        foreach ( $pool as $q ) { if ( $norm( $q ) === $norm( $value ) ) { $canonical = $q; break; } }
        if ( $canonical === null ) wp_send_json_error( 'That entry is not a qualifier for this day.' );
        $value = $canonical;

        // Prevent placing the same entry in two first-round slots — clear it
        // from wherever it currently sits before assigning it here.
        foreach ( $bracket['rounds'][$r0]['matches'] as $k => $m ) {
            foreach ( array( 'home', 'away' ) as $s ) {
                if ( ( $m[$s] ?? null ) === $value && ! ( $k === $mi && $s === $slot ) ) {
                    $bracket['rounds'][$r0]['matches'][$k][$s] = null;
                    // That match lost a participant — reset its result and
                    // vacate whatever it had advanced downstream.
                    $bracket['rounds'][$r0]['matches'][$k]['home_score'] = null;
                    $bracket['rounds'][$r0]['matches'][$k]['away_score'] = null;
                    lgw_gchamp_set_ko_advance( $bracket, 0, intval( $m['match'] ), null );
                }
            }
        }
    }

    // Assign the slot. The participant changed, so the match's own result is no
    // longer valid and anything it fed downstream must be vacated + cascaded.
    $bracket['rounds'][$r0]['matches'][$mi][$slot]        = ( $value === '' ) ? null : $value;
    $bracket['rounds'][$r0]['matches'][$mi]['home_score'] = null;
    $bracket['rounds'][$r0]['matches'][$mi]['away_score'] = null;
    lgw_gchamp_set_ko_advance( $bracket, 0, $ko_match, null );

    // Bracket contents changed — qualifiers are no longer confirmed.
    $champ['days'][$day_id]['ko_complete']   = false;
    $champ['days'][$day_id]['ko_qualifiers'] = array();
    unset( $bracket );

    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'day_id' => $day_id, 'match' => $ko_match, 'slot' => $slot, 'value' => $value ) );
}


// ── Helper: check if any fixtures for a named entry in a group have scores ────
function lgw_gchamp_entry_has_scores( array $group, string $entry ): bool {
    foreach ( $group['fixtures'] ?? array() as $fx ) {
        if ( ( $fx['home'] === $entry || $fx['away'] === $entry )
             && ( $fx['home_score'] !== null || $fx['away_score'] !== null ) ) {
            return true;
        }
    }
    return false;
}

// ── AJAX: copy a group championship ──────────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_copy', 'lgw_ajax_gchamp_copy' );
function lgw_ajax_gchamp_copy() {
    check_ajax_referer( 'lgw_gchamp_draw', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $source_id = sanitize_key( $_POST['source_id'] ?? '' );
    $new_id    = sanitize_key( $_POST['new_id']    ?? '' );
    $new_title = sanitize_text_field( wp_unslash( $_POST['new_title'] ?? '' ) );

    if ( ! $source_id || ! $new_id ) wp_send_json_error( 'Missing parameters' );
    if ( get_option( 'lgw_gchamp_' . $new_id ) !== false ) {
        wp_send_json_error( 'ID "' . esc_html( $new_id ) . '" is already in use — choose another.' );
    }
    $source = get_option( 'lgw_gchamp_' . $source_id, array() );
    if ( empty( $source ) ) wp_send_json_error( 'Source championship not found' );

    $copy          = $source;
    $copy['id']    = $new_id;
    $copy['title'] = $new_title ?: ( ( $source['title'] ?? '' ) . ' (Copy)' );

    update_option( 'lgw_gchamp_' . $new_id, $copy );
    wp_send_json_success( array(
        'new_id'   => $new_id,
        'edit_url' => admin_url( 'admin.php?page=lgw-champs&gedit=' . $new_id . '&saved=1' ),
    ) );
}

// ── AJAX: move an entry from one group to another on the same day ─────────────
add_action( 'wp_ajax_lgw_gchamp_move_entry', 'lgw_ajax_gchamp_move_entry' );
function lgw_ajax_gchamp_move_entry() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $champ_id    = sanitize_key( $_POST['champ_id']         ?? '' );
    $day_id      = intval( $_POST['day_id']                  ?? -1 );
    $entry_query = trim( wp_unslash( $_POST['entry']         ?? '' ) );
    $source_gid  = intval( $_POST['source_group_id']         ?? -1 );
    $target_gid  = intval( $_POST['target_group_id']         ?? -1 );
    $confirmed   = ! empty( $_POST['confirmed'] );

    if ( ! $champ_id || $day_id < 0 || ! $entry_query || $source_gid < 0 || $target_gid < 0 ) {
        wp_send_json_error( 'Missing parameters' );
    }
    if ( $source_gid === $target_gid ) wp_send_json_error( 'Source and target groups are the same' );

    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) )                    wp_send_json_error( 'Championship not found' );
    if ( ! isset( $champ['days'][$day_id] ) ) wp_send_json_error( 'Day not found' );
    if ( ! isset( $champ['days'][$day_id]['groups'][$source_gid] ) ) wp_send_json_error( 'Source group not found' );
    if ( ! isset( $champ['days'][$day_id]['groups'][$target_gid] ) ) wp_send_json_error( 'Target group not found' );

    $src_group = $champ['days'][$day_id]['groups'][$source_gid];
    $tgt_group = $champ['days'][$day_id]['groups'][$target_gid];

    // Resolve canonical stored entry via normalised comparison (avoids whitespace mismatches)
    $norm_query = lgw_gchamp_norm_entry( $entry_query );
    $entry      = null;
    foreach ( $src_group['entries'] ?? array() as $stored ) {
        if ( lgw_gchamp_norm_entry( $stored ) === $norm_query ) { $entry = $stored; break; }
    }
    if ( $entry === null ) {
        wp_send_json_error( 'Entry "' . esc_html( $entry_query ) . '" not found in source group' );
    }
    if ( count( $src_group['entries'] ?? array() ) < 2 ) {
        wp_send_json_error( 'Cannot move — source group would have fewer than 1 entry remaining' );
    }

    $has_scores = lgw_gchamp_entry_has_scores( $src_group, $entry );
    if ( $has_scores && ! $confirmed ) {
        wp_send_json_error( array(
            'code'    => 'scores_exist',
            'message' => '"' . esc_html( $entry ) . '" has scored fixtures that will be deleted when moved. Continue?',
        ) );
    }

    // Remove entry from source group (entries list + their fixtures)
    $champ['days'][$day_id]['groups'][$source_gid]['entries'] = array_values( array_filter(
        $src_group['entries'],
        fn( $e ) => $e !== $entry
    ) );
    $champ['days'][$day_id]['groups'][$source_gid]['fixtures'] = array_values( array_filter(
        $src_group['fixtures'] ?? array(),
        fn( $fx ) => $fx['home'] !== $entry && $fx['away'] !== $entry
    ) );

    // Add entry to target group and generate new fixtures (entry vs every existing target member)
    $tgt_entries  = $tgt_group['entries']  ?? array();
    $tgt_fixtures = $tgt_group['fixtures'] ?? array();

    $max_round   = -1;
    $max_fix_idx = -1;
    foreach ( $tgt_fixtures as $fx ) {
        $max_round = max( $max_round, intval( $fx['round'] ?? 0 ) );
        if ( preg_match( '/f(\d+)$/', $fx['pos_key'] ?? '', $m ) ) {
            $max_fix_idx = max( $max_fix_idx, intval( $m[1] ) );
        }
    }
    $next_round   = $max_round + 1;
    $next_fix_idx = $max_fix_idx + 1;
    $gkey         = $day_id * 100 + $target_gid;

    foreach ( $tgt_entries as $member ) {
        $tgt_fixtures[] = array(
            'round'      => $next_round,
            'home'       => $entry,
            'away'       => $member,
            'home_score' => null,
            'away_score' => null,
            'pos_key'    => 'g' . $gkey . ':r' . $next_round . ':f' . $next_fix_idx,
        );
        $next_round++;
        $next_fix_idx++;
    }
    $tgt_entries[] = $entry;

    $champ['days'][$day_id]['groups'][$target_gid]['entries']  = $tgt_entries;
    $champ['days'][$day_id]['groups'][$target_gid]['fixtures'] = $tgt_fixtures;

    // Reset day-level completion so standings are recalculated fresh
    $champ['days'][$day_id]['day_complete']  = false;
    $champ['days'][$day_id]['qualifiers']    = array();
    $champ['days'][$day_id]['ko_bracket']    = null;
    $champ['days'][$day_id]['ko_complete']   = false;
    $champ['days'][$day_id]['ko_qualifiers'] = array();

    $all_complete = true;
    foreach ( $champ['days'] as $d ) { if ( empty( $d['day_complete'] ) ) { $all_complete = false; break; } }
    $champ['group_stage_complete'] = $all_complete;

    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array(
        'message' => 'Moved "' . esc_html( $entry ) . '" — day KO bracket reset, re-seed when ready.',
    ) );
}

// ── AJAX: withdraw an entry from a group championship ────────────────────────
add_action( 'wp_ajax_lgw_gchamp_withdraw_entry', 'lgw_ajax_gchamp_withdraw_entry' );
function lgw_ajax_gchamp_withdraw_entry() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised' );

    $champ_id    = sanitize_key( $_POST['champ_id']  ?? '' );
    $day_id      = intval( $_POST['day_id']           ?? -1 );
    $group_id    = intval( $_POST['group_id']         ?? -1 );
    $entry_query = trim( wp_unslash( $_POST['entry']  ?? '' ) );
    $confirmed   = ! empty( $_POST['confirmed'] );

    if ( ! $champ_id || $day_id < 0 || $group_id < 0 || ! $entry_query ) wp_send_json_error( 'Missing parameters' );

    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) )                                           wp_send_json_error( 'Championship not found' );
    if ( ! isset( $champ['days'][$day_id] ) )                       wp_send_json_error( 'Day not found' );
    if ( ! isset( $champ['days'][$day_id]['groups'][$group_id] ) )  wp_send_json_error( 'Group not found' );

    $group = $champ['days'][$day_id]['groups'][$group_id];

    // Resolve canonical stored entry via normalised comparison (avoids whitespace mismatches)
    $norm_query = lgw_gchamp_norm_entry( $entry_query );
    $entry      = null;
    foreach ( $group['entries'] ?? array() as $stored ) {
        if ( lgw_gchamp_norm_entry( $stored ) === $norm_query ) { $entry = $stored; break; }
    }
    if ( $entry === null ) wp_send_json_error( 'Entry not found in group' );
    if ( count( $group['entries'] ?? array() ) < 2 ) wp_send_json_error( 'Cannot withdraw — group would have fewer than 1 entry remaining' );

    $has_scores = lgw_gchamp_entry_has_scores( $group, $entry );
    if ( $has_scores && ! $confirmed ) {
        wp_send_json_error( array(
            'code'    => 'scores_exist',
            'message' => '"' . esc_html( $entry ) . '" has scored fixtures that will be deleted on withdrawal. Continue?',
        ) );
    }

    // Remove from group
    $champ['days'][$day_id]['groups'][$group_id]['entries']  = array_values( array_filter(
        $group['entries'],
        fn( $e ) => $e !== $entry
    ) );
    $champ['days'][$day_id]['groups'][$group_id]['fixtures'] = array_values( array_filter(
        $group['fixtures'] ?? array(),
        fn( $fx ) => $fx['home'] !== $entry && $fx['away'] !== $entry
    ) );

    // Remove from day entries (flat list) and top-level entries
    $champ['days'][$day_id]['entries'] = array_values( array_filter(
        $champ['days'][$day_id]['entries'] ?? array(),
        fn( $e ) => $e !== $entry
    ) );
    $champ['entries'] = array_values( array_filter(
        $champ['entries'] ?? array(),
        fn( $e ) => $e !== $entry
    ) );

    // Reset day-level completion
    $champ['days'][$day_id]['day_complete']  = false;
    $champ['days'][$day_id]['qualifiers']    = array();
    $champ['days'][$day_id]['ko_bracket']    = null;
    $champ['days'][$day_id]['ko_complete']   = false;
    $champ['days'][$day_id]['ko_qualifiers'] = array();

    $all_complete = true;
    foreach ( $champ['days'] as $d ) { if ( empty( $d['day_complete'] ) ) { $all_complete = false; break; } }
    $champ['group_stage_complete'] = $all_complete;

    if ( function_exists( 'lgw_clear_all_champ_appearances' ) ) {
        // Clear just this entry's appearances — use rename approach as a proxy (no dedicated single-entry clear)
    }

    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array(
        'message' => '"' . esc_html( $entry ) . '" withdrawn. Their fixtures have been removed.',
    ) );
}


add_action( 'wp_ajax_lgw_gchamp_get_standings',        'lgw_ajax_gchamp_get_standings' );
add_action( 'wp_ajax_nopriv_lgw_gchamp_get_standings', 'lgw_ajax_gchamp_get_standings' );
function lgw_ajax_gchamp_get_standings() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    if ( ! $champ_id ) wp_send_json_error('Missing championship ID.');
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty($champ) ) wp_send_json_error('Championship not found.');
    $result = array();
    foreach ( ($champ['days']??array()) as $di=>$day ) {
        $ddata = array('day_id'=>$di,'day_complete'=>!empty($day['day_complete']),'qualifiers'=>$day['qualifiers']??array(),'groups'=>array());
        foreach ( ($day['groups']??array()) as $gi=>$group ) {
            $ddata['groups'][] = array('group_id'=>$gi,'standings'=>lgw_gchamp_compute_standings($group['entries']??array(),$group['fixtures']??array()),'played'=>lgw_gchamp_count_played($group['fixtures']??array()),'total'=>count($group['fixtures']??array()));
        }
        $result[] = $ddata;
    }
    wp_send_json_success( array('days'=>$result,'group_stage_complete'=>!empty($champ['group_stage_complete'])) );
}


// ── AJAX: search group championship fixtures / results ────────────────────────
add_action( 'wp_ajax_lgw_gchamp_search',        'lgw_ajax_gchamp_search' );
add_action( 'wp_ajax_nopriv_lgw_gchamp_search', 'lgw_ajax_gchamp_search' );
/**
 * Search a group championship for fixtures/results matching a player or club.
 *
 * Scans:
 *   - Group stage fixtures  (days[n].groups[g].fixtures)
 *   - Day KO brackets       (days[n].ko_bracket.rounds[r].matches)
 *   - Finals Week matches   (finals_matches[r].matches)
 *
 * POST: gchamp_id, query, nonce (lgw_gchamp_search)
 * Returns: { matches[], query, title }
 */
function lgw_ajax_gchamp_search() {
    $nonce = sanitize_text_field( $_POST['nonce'] ?? '' );
    if ( ! wp_verify_nonce( $nonce, 'lgw_gchamp_search' ) ) {
        wp_send_json_error( 'Session expired — please refresh and try again.' );
    }

    $champ_id = sanitize_key( $_POST['gchamp_id'] ?? '' );
    $query    = strtolower( trim( sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) ) ) );

    if ( ! $champ_id ) wp_send_json_error( 'Missing championship ID.' );
    if ( strlen( $query ) < 2 ) wp_send_json_error( 'Please enter at least 2 characters.' );

    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) ) wp_send_json_error( 'Championship not found.' );

    $today   = mktime( 0, 0, 0 );
    $results = array();

    $parse_date = function( $s ) {
        if ( ! $s ) return false;
        if ( preg_match( '|^(\d{1,2})/(\d{1,2})/(\d{2,4})$|', trim($s), $m ) ) {
            $y = intval($m[3]); if ($y < 100) $y += 2000;
            return mktime( 0, 0, 0, intval($m[2]), intval($m[1]), $y );
        }
        return false;
    };

    $entry_matches = function( $entry ) use ( $query ) {
        return $entry && strpos( strtolower( $entry ), $query ) !== false;
    };

    // ── Group stage fixtures ──────────────────────────────────────────────────
    foreach ( ( $champ['days'] ?? array() ) as $di => $day ) {
        $date_str = $day['date'] ?? '';
        $date_ts  = $parse_date( $date_str );
        $is_future = ( $date_ts !== false ) ? ( $date_ts >= $today ) : true;
        $day_name  = $day['name'] ?? ( 'Day ' . ( $di + 1 ) );

        foreach ( ( $day['groups'] ?? array() ) as $gi => $group ) {
            $group_name = $group['name'] ?? ( $day_name . ' — Group ' . chr( 65 + $gi ) );
            foreach ( ( $group['fixtures'] ?? array() ) as $fx ) {
                $home = $fx['home'] ?? ''; $away = $fx['away'] ?? '';
                if ( ! $entry_matches($home) && ! $entry_matches($away) ) continue;

                $hs = $fx['home_score'] ?? null; $as = $fx['away_score'] ?? null;
                $has_result = ( $hs !== null && $hs !== '' && $as !== null && $as !== '' );

                $results[] = array(
                    'section'    => $group_name,
                    'stage'      => 'group',
                    'round'      => 'Round ' . ( ( $fx['round'] ?? 0 ) + 1 ),
                    'date'       => $date_str,
                    'date_ts'    => $date_ts !== false ? $date_ts : PHP_INT_MAX,
                    'home'       => $home, 'away' => $away,
                    'home_score' => $has_result ? $hs : null,
                    'away_score' => $has_result ? $as : null,
                    'has_result' => $has_result,
                    'is_fixture' => $is_future || ! $has_result,
                    'is_result'  => $has_result,
                    'game_num'   => null,
                );
            }
        }

        // ── Day KO bracket ───────────────────────────────────────────────────
        $ko = $day['ko_bracket'] ?? null;
        if ( $ko ) {
            foreach ( ( $ko['rounds'] ?? array() ) as $ri => $round ) {
                $round_name = $round['label'] ?? ( 'KO Round ' . ( $ri + 1 ) );
                foreach ( ( $round['matches'] ?? array() ) as $m ) {
                    if ( ! empty($m['bye']) ) continue;
                    $home = $m['home'] ?? ''; $away = $m['away'] ?? '';
                    if ( ! $entry_matches($home) && ! $entry_matches($away) ) continue;

                    $hs = $m['home_score'] ?? null; $as = $m['away_score'] ?? null;
                    $has_result = ( $hs !== null && $hs !== '' && $as !== null && $as !== '' );

                    $results[] = array(
                        'section'    => $day_name . ' KO',
                        'stage'      => 'ko',
                        'round'      => $round_name,
                        'date'       => $date_str,
                        'date_ts'    => $date_ts !== false ? $date_ts : PHP_INT_MAX,
                        'home'       => $home, 'away' => $away,
                        'home_score' => $has_result ? $hs : null,
                        'away_score' => $has_result ? $as : null,
                        'has_result' => $has_result,
                        'is_fixture' => ! $has_result,
                        'is_result'  => $has_result,
                        'game_num'   => null,
                    );
                }
            }
        }
    }

    // ── Finals Week matches ───────────────────────────────────────────────────
    foreach ( ( $champ['finals_matches'] ?? array() ) as $fm ) {
        $home = $fm['home'] ?? ''; $away = $fm['away'] ?? '';
        if ( ! $entry_matches($home) && ! $entry_matches($away) ) continue;

        $date_str  = $fm['date'] ?? '';
        $date_ts   = $parse_date( $date_str );
        $is_future = ( $date_ts !== false ) ? ( $date_ts >= $today ) : true;

        $hs = $fm['home_score'] ?? null; $as = $fm['away_score'] ?? null;
        $has_result = ( $hs !== null && $hs !== '' && $as !== null && $as !== '' );

        $results[] = array(
            'section'    => 'Finals Week',
            'stage'      => 'finals',
            'round'      => $fm['label'] ?? 'Finals',
            'date'       => $date_str,
            'date_ts'    => $date_ts !== false ? $date_ts : PHP_INT_MAX,
            'home'       => $home, 'away' => $away,
            'home_score' => $has_result ? $hs : null,
            'away_score' => $has_result ? $as : null,
            'has_result' => $has_result,
            'is_fixture' => $is_future || ! $has_result,
            'is_result'  => $has_result,
            'game_num'   => null,
        );
    }

    usort( $results, function($a,$b) { return $a['date_ts'] <=> $b['date_ts']; } );

    wp_send_json_success( array(
        'query'   => $query,
        'matches' => $results,
        'count'   => count( $results ),
        'title'   => $champ['title'] ?? $champ_id,
    ) );
}

// ── AJAX: seed knockout bracket ───────────────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_seed_knockout', 'lgw_ajax_gchamp_seed_knockout' );
function lgw_ajax_gchamp_seed_knockout() {
    check_ajax_referer( 'lgw_gchamp_draw', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorised');
    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $champ    = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty($champ) )                         wp_send_json_error('Championship not found');
    if ( empty($champ['draw_complete']) )         wp_send_json_error('Draw not yet performed');
    if ( empty($champ['group_stage_complete']) )  wp_send_json_error('Group stage not yet complete');
    if ( empty($champ['has_ko_bracket']) )        wp_send_json_error('This championship does not use an internal knockout bracket');
    $result = lgw_gchamp_build_knockout( $champ_id, $champ );
    if ( is_wp_error($result) ) wp_send_json_error( $result->get_error_message() );
    wp_send_json_success( array('qualifiers'=>$champ['qualifiers'],'warnings'=>$result['warnings']??array()) );
}

// ── Per-day KO bracket helpers ───────────────────────────────────────────────

/**
 * Auto-seed the KO bracket for a single day from its (partial) qualifiers.
 * $slots is an array of qualifier names where null means "not yet known".
 * Modifies $champ in place (does NOT call update_option — caller must save).
 */
function lgw_gchamp_auto_seed_day_ko( array &$champ, int $day_id ): void {
    $day   = &$champ['days'][ $day_id ];
    $slots = $day['qualifier_slots'] ?? $day['qualifiers'] ?? array();
    if ( empty( $slots ) ) return;

    $total_slots = count( $slots );
    if ( $total_slots < 2 ) return;

    // Determine bracket size
    $cfg_size = intval( $day['ko_bracket_size'] ?? 0 );
    if ( $cfg_size < $total_slots || ! lgw_gchamp_is_power_of_two( $cfg_size ) ) {
        $cfg_size = lgw_gchamp_next_power_of_two( $total_slots );
    }
    $byes = $cfg_size - $total_slots;

    $numbered = array();
    // Sort slots: confirmed entries first, nulls last.
    // Then pair them consecutively (1+2, 3+4, …) so matches fill top-down:
    //   Match 1: slot 1 vs slot 2  (both confirmed → playable immediately)
    //   Match 2: slot 3 vs slot 4
    //   Match 3: TBD vs TBD        (not yet playable)
    // Once all slots are confirmed the bracket is reseeded with standard 1-vs-N ordering.
    $confirmed_slots = array_values( array_filter( $slots, fn($s) => $s !== null ) );
    $tbd_slots       = array_values( array_filter( $slots, fn($s) => $s === null ) );
    $all_confirmed   = empty( $tbd_slots ) && $byes === 0;

    if ( $all_confirmed ) {
        // Full bracket — use standard 1 vs N seeding
        foreach ( $slots as $i => $q ) {
            $numbered[] = array( 'name' => $q, 'draw_num' => $i + 1 );
        }
        for ( $b = 0; $b < $byes; $b++ ) {
            $numbered[] = array( 'name' => null, 'draw_num' => $total_slots + $b + 1 );
        }
    } else {
        // Partial bracket — confirmed first, TBDs last
        $all_slots = array_merge( $confirmed_slots, $tbd_slots );
        for ( $b = 0; $b < $byes; $b++ ) $all_slots[] = null;
        foreach ( $all_slots as $i => $q ) {
            $numbered[] = array( 'name' => $q, 'draw_num' => $i + 1 );
        }
    }

    $rounds = lgw_gchamp_build_simple_bracket( $numbered, $day['date'] ?? '', ! $all_confirmed );

    $day['ko_bracket'] = array(
        'title'  => ( $champ['title'] ?? 'Championship' ) . ' — ' . ( $day['name'] ?? 'Day' ) . ' Knockout',
        'rounds' => $rounds,
        'size'   => $cfg_size,
    );
    $day['ko_qualifiers'] = array();
    $day['ko_complete']   = false;
}

/**
 * Build a simple single-elimination bracket from numbered entries.
 * Returns rounds array: [ ['round'=>0,'label'=>'QF','matches'=>[...]], ... ]
 * Matches: ['match'=>int,'round'=>int,'home'=>?string,'away'=>?string,'home_score'=>null,'away_score'=>null]
 */
function lgw_gchamp_build_simple_bracket( array $numbered, string $date = '', bool $consecutive = false ): array {
    $n      = count( $numbered );
    $rounds = array();
    $size   = $n; // must be power of two

    // Pairing:
    //   Standard (1 vs N):   1v8, 2v7, 3v6, 4v5  — used for complete brackets
    //   Consecutive (top-down): 1v2, 3v4, 5v6, 7v8 — used for partial fills so
    //   confirmed entries fill whole matches from the top before TBDs appear.
    $pairs = array();
    if ( $consecutive ) {
        for ( $i = 0; $i < $size; $i += 2 ) {
            $a = $numbered[ $i ]     ?? array( 'name' => null );
            $b = $numbered[ $i + 1 ] ?? array( 'name' => null );
            $pairs[] = array( $a['name'], $b['name'] );
        }
    } else {
        for ( $i = 0; $i < $size / 2; $i++ ) {
            $a = $numbered[ $i ]             ?? array( 'name' => null );
            $b = $numbered[ $size - 1 - $i ] ?? array( 'name' => null );
            $pairs[] = array( $a['name'], $b['name'] );
        }
    }

    $round_labels = array( 2=>'Final', 4=>'Semi-final', 8=>'Quarter-final', 16=>'Round of 16', 32=>'Round of 32' );
    $num_rounds   = intval( log( $size, 2 ) );
    $match_idx    = 0;

    for ( $r = 0; $r < $num_rounds; $r++ ) {
        $matches_in_round = $size >> ( $r + 1 ); // size/2, size/4, …
        $label = $round_labels[ $matches_in_round * 2 ] ?? ( 'Round ' . ( $r + 1 ) );
        $matches = array();
        for ( $m = 0; $m < $matches_in_round; $m++ ) {
            $home = ( $r === 0 ) ? ( $pairs[ $m ][0] ?? null ) : null;
            $away = ( $r === 0 ) ? ( $pairs[ $m ][1] ?? null ) : null;
            // Advance byes immediately in round 0
            if ( $r === 0 && $home !== null && $away === null ) {
                // bye — home advances automatically; mark as played
                $matches[] = array(
                    'match' => $m, 'round' => $r, 'home' => $home, 'away' => null,
                    'home_score' => null, 'away_score' => null, 'bye' => true,
                );
            } else {
                $matches[] = array(
                    'match' => $m, 'round' => $r, 'home' => $home, 'away' => $away,
                    'home_score' => null, 'away_score' => null, 'bye' => false,
                );
            }
        }
        $rounds[] = array( 'round' => $r, 'label' => $label, 'date' => $date, 'matches' => $matches );
    }

    // Process first-round byes: advance winners to round 1
    foreach ( $rounds[0]['matches'] as $idx => $match ) {
        if ( ! empty( $match['bye'] ) && $match['home'] !== null ) {
            lgw_gchamp_advance_ko_winner_rounds( $rounds, 0, $match['match'], $match['home'] );
        }
    }

    return $rounds;
}

/**
 * Advance a KO winner from round $r, match $m to the next round.
 * Works on the flat rounds array passed by reference.
 */
function lgw_gchamp_advance_ko_winner_rounds( array &$rounds, int $r, int $m, string $winner ): void {
    $next_r    = $r + 1;
    $next_m    = intval( floor( $m / 2 ) );
    $is_home   = ( $m % 2 === 0 );
    foreach ( $rounds as &$round ) {
        if ( $round['round'] !== $next_r ) continue;
        foreach ( $round['matches'] as &$match ) {
            if ( $match['match'] !== $next_m ) continue;
            if ( $is_home ) $match['home'] = $winner;
            else            $match['away'] = $winner;
            return;
        }
        unset( $match );
    }
    unset( $round );
}

/**
 * Advance winner into the bracket stored inside $bracket (array with 'rounds' key).
 */
function lgw_gchamp_advance_ko_winner( array &$bracket, int $r, int $m, string $winner ): void {
    lgw_gchamp_advance_ko_winner_rounds( $bracket['rounds'], $r, $m, $winner );
}

/**
 * Sync the downstream slot fed by round $r / match $m to $winner, where
 * $winner is the entry that should occupy it (or null to vacate — a cleared
 * or drawn result). Used on every KO score save so that editing or clearing a
 * result never leaves a stale winner in the next round.
 *
 * If the occupant actually changes, the downstream match's own result is
 * invalidated (its scores are cleared) and the vacancy cascades further down
 * the bracket. When the occupant is unchanged (e.g. a score edit that keeps
 * the same winner) nothing downstream is touched, so an already-played later
 * round is preserved.
 */
function lgw_gchamp_set_ko_advance( array &$bracket, int $r, int $m, ?string $winner ): void {
    $next_r  = $r + 1;
    $next_m  = intdiv( $m, 2 );
    $is_home = ( $m % 2 === 0 );
    $slot    = $is_home ? 'home' : 'away';

    foreach ( $bracket['rounds'] as $ri => $round ) {
        if ( $round['round'] !== $next_r ) continue;
        foreach ( $round['matches'] as $mi => $match ) {
            if ( $match['match'] !== $next_m ) continue;
            // Unchanged occupant → leave this match (and everything below) intact
            if ( ( $match[$slot] ?? null ) === $winner ) return;
            $had_result = $match['home_score'] !== null || $match['away_score'] !== null;
            $bracket['rounds'][$ri]['matches'][$mi][$slot]         = $winner;
            $bracket['rounds'][$ri]['matches'][$mi]['home_score']  = null;
            $bracket['rounds'][$ri]['matches'][$mi]['away_score']  = null;
            // If a result was recorded here it can no longer stand — cascade the
            // vacancy into the round this match fed.
            if ( $had_result ) {
                lgw_gchamp_set_ko_advance( $bracket, $next_r, $next_m, null );
            }
            return;
        }
        return; // next round located but match not found — nothing to do
    }
}

/**
 * Return true if all non-bye KO matches have scores.
 */
/**
 * Returns true when enough KO rounds have been played to confirm all
 * required Finals Week qualifiers for this day.
 *
 * finals_qualifiers 1, 3 → need the final round (winner + runner-up known)
 * finals_qualifiers 2   → both finalists qualify; when $play_final is false the
 *                         day final is NOT played (it is a Finals-Week fixture),
 *                         so completion only needs the final's two slots filled
 * finals_qualifiers 4   → need the semi-final round (all 4 SFs known)
 */
function lgw_gchamp_ko_qualifiers_complete( array $bracket, int $finals_qualifiers, bool $play_final = true ): bool {
    $rounds     = $bracket['rounds'] ?? array();
    $num_rounds = count( $rounds );
    if ( $num_rounds === 0 ) return false;

    // 2-qualifier day with no day-final: both finalists advance, so we don't
    // need the final scored — only every round *before* it played (to resolve
    // the two final slots) and the final match's slots non-null.
    if ( $finals_qualifiers === 2 && ! $play_final ) {
        for ( $ri = 0; $ri < $num_rounds - 1; $ri++ ) {
            foreach ( $rounds[$ri]['matches'] as $match ) {
                if ( ! empty( $match['bye'] ) ) continue;
                if ( $match['home'] === null || $match['away'] === null ) continue; // TBD
                if ( $match['home_score'] === null || $match['away_score'] === null ) return false;
            }
        }
        $final = $rounds[ $num_rounds - 1 ];
        foreach ( $final['matches'] as $match ) {
            if ( ! empty( $match['bye'] ) ) continue;
            if ( $match['home'] === null || $match['away'] === null ) return false; // slots not resolved
        }
        return true;
    }

    // Determine the last round index we need to be complete
    // fq >= 4 → semi-finals (2 rounds from end), else → final (last round)
    $required_up_to = $finals_qualifiers >= 4
        ? max( 0, $num_rounds - 2 )  // semi-final round index
        : $num_rounds - 1;            // final round index

    for ( $ri = 0; $ri <= $required_up_to; $ri++ ) {
        $round = $rounds[$ri] ?? null;
        if ( ! $round ) return false;
        foreach ( $round['matches'] as $match ) {
            if ( ! empty( $match['bye'] ) ) continue;
            if ( $match['home'] === null || $match['away'] === null ) continue; // TBD
            if ( $match['home_score'] === null || $match['away_score'] === null ) return false;
        }
    }
    return true;
}

function lgw_gchamp_ko_all_played( array $bracket ): bool {
    foreach ( $bracket['rounds'] as $round ) {
        foreach ( $round['matches'] as $match ) {
            if ( ! empty( $match['bye'] ) ) continue;
            if ( $match['home'] === null || $match['away'] === null ) continue; // TBD slot
            if ( $match['home_score'] === null || $match['away_score'] === null ) return false;
        }
    }
    return true;
}

/**
 * Compute Finals Week qualifiers from a completed KO bracket.
 * Rule: 1 day = SF (4), 2 days = F (2 each), 4 days = W (1 each).
 * When $play_final is false and per_day == 2 the day final is not played —
 * both finalists (the two players occupying the final slots) qualify.
 * Returns qualifiers in finish order.
 */
function lgw_gchamp_compute_ko_qualifiers( array $bracket, int $per_day, bool $play_final = true ): array {

    // Work back from the final round
    $rounds     = $bracket['rounds'];
    $num_rounds = count( $rounds );
    $qualifiers = array();

    // Final round (last round) winner = champion
    $final_round = $rounds[ $num_rounds - 1 ] ?? null;
    if ( ! $final_round ) return array();

    // 2-qualifier day, no day-final: both finalists advance to Finals Week.
    if ( $per_day === 2 && ! $play_final ) {
        foreach ( $final_round['matches'] as $match ) {
            if ( ! empty( $match['bye'] ) ) continue;
            if ( $match['home'] ) $qualifiers[] = $match['home'];
            if ( $match['away'] ) $qualifiers[] = $match['away'];
        }
        return array_values( array_filter( array_unique( $qualifiers ) ) );
    }

    // Final round: winner and runner-up (only needed when per_day < 4)
    if ( $per_day < 4 ) {
        foreach ( $final_round['matches'] as $match ) {
            if ( $match['home_score'] === null || $match['away_score'] === null ) continue;
            $hs = intval( $match['home_score'] );
            $as = intval( $match['away_score'] );
            $winner = $hs >= $as ? $match['home'] : $match['away'];
            $loser  = $hs >= $as ? $match['away'] : $match['home'];
            if ( $per_day >= 1 && $winner ) $qualifiers[] = $winner;
            if ( $per_day >= 2 && $loser  ) $qualifiers[] = $loser;
        }
    }

    // Semi-final round participants (all 4 semi-finalists qualify when per_day >= 4)
    if ( $per_day >= 4 && $num_rounds >= 2 ) {
        $sf_round = $rounds[ $num_rounds - 2 ];
        foreach ( $sf_round['matches'] as $match ) {
            if ( ! empty( $match['bye'] ) ) continue;
            // Collect both participants — these are the 4 semi-finalists
            if ( $match['home'] ) $qualifiers[] = $match['home'];
            if ( $match['away'] ) $qualifiers[] = $match['away'];
        }
    } elseif ( $per_day === 3 && $num_rounds >= 2 ) {
        // per_day = 3 → final winner + runner-up + 1 SF loser
        $sf_round  = $rounds[ $num_rounds - 2 ];
        $sf_losers = array();
        foreach ( $sf_round['matches'] as $match ) {
            if ( ! empty( $match['bye'] ) ) continue;
            if ( $match['home_score'] === null || $match['away_score'] === null ) continue;
            $hs    = intval( $match['home_score'] );
            $as    = intval( $match['away_score'] );
            $loser = $hs >= $as ? $match['away'] : $match['home'];
            if ( $loser ) $sf_losers[] = $loser;
        }
        if ( ! empty( $sf_losers ) ) $qualifiers[] = $sf_losers[0];
    }

    return array_values( array_filter( array_unique( $qualifiers ) ) );
}


// ── AJAX: toggle group lock ───────────────────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_toggle_group_lock', 'lgw_ajax_gchamp_toggle_group_lock' );
function lgw_ajax_gchamp_toggle_group_lock() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $day_id   = intval( $_POST['day_id']   ?? -1 );
    $group_id = intval( $_POST['group_id'] ?? -1 );
    $lock     = $_POST['lock'] === '1';

    if ( ! $champ_id || $day_id < 0 || $group_id < 0 ) wp_send_json_error( 'Missing parameters.' );

    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( ! isset( $champ['days'][$day_id]['groups'][$group_id] ) ) wp_send_json_error( 'Group not found.' );

    // Block unlock if KO bracket has any scores
    if ( ! $lock ) {
        $ko = $champ['days'][$day_id]['ko_bracket'] ?? null;
        if ( $ko ) {
            foreach ( $ko['rounds'] as $round ) {
                foreach ( $round['matches'] as $match ) {
                    if ( $match['home_score'] !== null || $match['away_score'] !== null ) {
                        wp_send_json_error( 'Cannot unlock: KO bracket has scores entered. Clear KO scores first.' );
                    }
                }
            }
        }
    }

    $champ['days'][$day_id]['groups'][$group_id]['locked'] = $lock;
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'locked' => $lock ) );
}


// ── Finals Week — AJAX handlers ──────────────────────────────────────────────

add_action( 'wp_ajax_lgw_gchamp_seed_finals',        'lgw_ajax_gchamp_seed_finals' );
function lgw_ajax_gchamp_seed_finals() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );
    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $champ    = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) ) wp_send_json_error( 'Championship not found.' );

    $matches = lgw_gchamp_build_finals_matches( $champ );
    if ( empty( $matches ) ) wp_send_json_error( 'No qualifiers available yet.' );

    $champ['finals_matches'] = $matches;
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'count' => count( $matches ) ) );
}

add_action( 'wp_ajax_lgw_gchamp_finals_save_datetime', 'lgw_ajax_gchamp_finals_save_datetime' );
function lgw_ajax_gchamp_finals_save_datetime() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );
    $champ_id  = sanitize_key( $_POST['champ_id'] ?? '' );
    $match_idx = intval( $_POST['match_idx'] ?? -1 );
    $datetime  = sanitize_text_field( $_POST['datetime'] ?? '' );
    $rink      = sanitize_text_field( $_POST['rink']     ?? '' );
    if ( ! $champ_id || $match_idx < 0 ) wp_send_json_error( 'Invalid parameters.' );
    if ( $datetime && ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $datetime ) )
        wp_send_json_error( 'Invalid datetime format — use YYYY-MM-DD HH:MM.' );
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( ! isset( $champ['finals_matches'][$match_idx] ) ) wp_send_json_error( 'Match not found.' );
    $champ['finals_matches'][$match_idx]['finals_datetime'] = $datetime;
    $champ['finals_matches'][$match_idx]['finals_rink']     = $rink;
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array(
        'formatted' => lgw_finals_format_datetime( $datetime ),
        'raw'       => $datetime,
        'rink'      => $rink,
    ) );
}

add_action( 'wp_ajax_lgw_gchamp_finals_save_end', 'lgw_ajax_gchamp_finals_save_end' );
function lgw_ajax_gchamp_finals_save_end() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );
    $champ_id   = sanitize_key( $_POST['champ_id']  ?? '' );
    $match_idx  = intval( $_POST['match_idx']        ?? -1 );
    $action     = sanitize_key( $_POST['end_action'] ?? 'add' );
    $home_end   = intval( $_POST['home_end']         ?? 0 );
    $away_end   = intval( $_POST['away_end']         ?? 0 );
    if ( ! $champ_id || $match_idx < 0 ) wp_send_json_error( 'Invalid parameters.' );
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( ! isset( $champ['finals_matches'][$match_idx] ) ) wp_send_json_error( 'Match not found.' );
    $ends = $champ['finals_matches'][$match_idx]['ends'] ?? array();
    if ( $action === 'delete_last' ) { if ( ! empty( $ends ) ) array_pop( $ends ); }
    else $ends[] = array( max(0,$home_end), max(0,$away_end) );
    $champ['finals_matches'][$match_idx]['ends'] = $ends;
    $ht = 0; $at = 0;
    foreach ( $ends as $e ) { $ht += $e[0]; $at += $e[1]; }
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'ends'=>$ends, 'homeTotal'=>$ht, 'awayTotal'=>$at, 'endCount'=>count($ends) ) );
}

add_action( 'wp_ajax_lgw_gchamp_finals_save_score', 'lgw_ajax_gchamp_finals_save_score' );
function lgw_ajax_gchamp_finals_save_score() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );
    $champ_id   = sanitize_key( $_POST['champ_id']  ?? '' );
    $match_idx  = intval( $_POST['match_idx']        ?? -1 );
    $hs         = $_POST['home_score'] !== '' ? intval( $_POST['home_score'] ) : null;
    $as         = $_POST['away_score'] !== '' ? intval( $_POST['away_score'] ) : null;
    if ( ! $champ_id || $match_idx < 0 ) wp_send_json_error( 'Invalid parameters.' );
    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( ! isset( $champ['finals_matches'][$match_idx] ) ) wp_send_json_error( 'Match not found.' );
    $champ['finals_matches'][$match_idx]['home_score'] = $hs;
    $champ['finals_matches'][$match_idx]['away_score'] = $as;
    // Rebuild so the winner flows into the next round (scores preserved).
    $champ['finals_matches'] = lgw_gchamp_build_finals_matches( $champ );
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'homeScore'=>$hs, 'awayScore'=>$as ) );
}

// ── AJAX: manually set a Finals Week draw position (source → seed) ────────────
add_action( 'wp_ajax_lgw_gchamp_finals_set_slot', 'lgw_ajax_gchamp_finals_set_slot' );
function lgw_ajax_gchamp_finals_set_slot() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );
    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $seed     = intval( $_POST['seed'] ?? 0 );                          // 1-indexed draw position
    $src      = sanitize_text_field( wp_unslash( $_POST['src'] ?? '' ) ); // source to place there
    if ( ! $champ_id || $seed < 1 ) wp_send_json_error( 'Missing parameters.' );

    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) ) wp_send_json_error( 'Championship not found.' );

    $slots      = lgw_gchamp_finals_slots( $champ );
    $n          = count( $slots );
    if ( $n < 2 )       wp_send_json_error( 'No finals qualifiers configured.' );
    if ( $seed > $n )   wp_send_json_error( 'Invalid draw position.' );
    $valid_srcs = array_map( fn($s) => $s['src'], $slots );
    if ( ! in_array( $src, $valid_srcs, true ) ) wp_send_json_error( 'Unknown qualifier source.' );

    // Swap the chosen source into the target position; whatever sat there moves
    // to the source's old spot (the draw is always a permutation of the slots).
    $draw   = lgw_gchamp_finals_draw_order( $champ, $slots );
    $target = $seed - 1;
    $pos    = array_search( $src, $draw, true );
    if ( $pos !== false && $pos !== $target ) {
        $tmp             = $draw[ $target ];
        $draw[ $target ] = $src;
        $draw[ $pos ]    = $tmp;
    }
    $champ['finals_draw']    = array_values( $draw );
    $champ['finals_matches'] = lgw_gchamp_build_finals_matches( $champ );
    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array( 'draw' => $champ['finals_draw'] ) );
}

// ── Finals Week bracket ───────────────────────────────────────────────────────

/**
 * The expected Finals Week qualifier slots, in day order. One per day per
 * finals_qualifiers. Each slot:
 *   src   'd{day}:{rank}'  stable source key
 *   day   int
 *   rank  int              0 = winner, 1 = runner-up, …
 *   label string           e.g. "21 June Ards Winner"
 *   name  ?string          resolved qualifier once the day's KO confirms it
 * Because it is built from the day configuration (not confirmed results) the
 * full set of slots exists from the moment the championship is drawn — the
 * Finals Week draw can be arranged before any knockout has finished.
 */
function lgw_gchamp_finals_slots( array $champ ): array {
    $slots = array();
    foreach ( $champ['days'] ?? array() as $di => $day ) {
        $fq    = intval( $day['finals_qualifiers'] ?? $champ['finals_qualifiers_per_day'] ?? 1 );
        $names = array_values( $day['ko_qualifiers'] ?? array() );
        $dname = $day['name'] ?? ( 'Day ' . ( $di + 1 ) );
        for ( $r = 0; $r < $fq; $r++ ) {
            $slots[] = array(
                'src'   => 'd' . $di . ':' . $r,
                'day'   => $di,
                'rank'  => $r,
                'label' => $dname . ' ' . lgw_gchamp_finals_rank_label( $r, $fq ),
                'name'  => $names[ $r ] ?? null,
            );
        }
    }
    return $slots;
}

function lgw_gchamp_finals_rank_label( int $rank, int $fq ): string {
    if ( $fq <= 1 )  return 'Winner';
    if ( $fq === 2 ) return $rank === 0 ? 'Winner' : 'Runner-up';
    if ( $fq === 3 ) return array( 'Winner', 'Runner-up', 'Semi-finalist' )[ $rank ] ?? ( 'Q' . ( $rank + 1 ) );
    return 'Semi-finalist ' . ( $rank + 1 );
}

/**
 * Seeded single-elimination layout for N finals qualifiers (2–8), rounded up to
 * the next power of two with the top seeds byed straight into the later round.
 * Each match is [ round_name, slotA, slotB ] where a slot is:
 *   array('seed'=>k)  — 1-indexed draw position (a real qualifier)
 *   array('win'=>j)   — winner of the match at index j in this list
 */
function lgw_gchamp_finals_layout( int $n ): array {
    $L = array(
        2 => array(
            array( 'Final', array('seed'=>1), array('seed'=>2) ),
        ),
        3 => array(
            array( 'Semi-final', array('seed'=>2), array('seed'=>3) ),
            array( 'Final',      array('seed'=>1), array('win'=>0) ),
        ),
        4 => array(
            array( 'Semi-final', array('seed'=>1), array('seed'=>4) ),
            array( 'Semi-final', array('seed'=>2), array('seed'=>3) ),
            array( 'Final',      array('win'=>0),  array('win'=>1) ),
        ),
        5 => array(
            array( 'Quarter-final', array('seed'=>4), array('seed'=>5) ),
            array( 'Semi-final',    array('seed'=>2), array('seed'=>3) ),
            array( 'Semi-final',    array('seed'=>1), array('win'=>0) ),
            array( 'Final',         array('win'=>2),  array('win'=>1) ),
        ),
        6 => array(
            array( 'Quarter-final', array('seed'=>3), array('seed'=>6) ),
            array( 'Quarter-final', array('seed'=>4), array('seed'=>5) ),
            array( 'Semi-final',    array('seed'=>1), array('win'=>0) ),
            array( 'Semi-final',    array('seed'=>2), array('win'=>1) ),
            array( 'Final',         array('win'=>2),  array('win'=>3) ),
        ),
        7 => array(
            array( 'Quarter-final', array('seed'=>4), array('seed'=>5) ),
            array( 'Quarter-final', array('seed'=>3), array('seed'=>6) ),
            array( 'Quarter-final', array('seed'=>2), array('seed'=>7) ),
            array( 'Semi-final',    array('seed'=>1), array('win'=>0) ),
            array( 'Semi-final',    array('win'=>1),  array('win'=>2) ),
            array( 'Final',         array('win'=>3),  array('win'=>4) ),
        ),
        8 => array(
            array( 'Quarter-final', array('seed'=>1), array('seed'=>8) ),
            array( 'Quarter-final', array('seed'=>4), array('seed'=>5) ),
            array( 'Quarter-final', array('seed'=>3), array('seed'=>6) ),
            array( 'Quarter-final', array('seed'=>2), array('seed'=>7) ),
            array( 'Semi-final',    array('win'=>0),  array('win'=>1) ),
            array( 'Semi-final',    array('win'=>2),  array('win'=>3) ),
            array( 'Final',         array('win'=>4),  array('win'=>5) ),
        ),
    );
    return $L[ $n ] ?? array();
}

/**
 * Return the finals draw order (list of source keys, one per seed position).
 * Falls back to the natural day order when no valid stored draw exists.
 */
function lgw_gchamp_finals_draw_order( array $champ, array $slots ): array {
    $default = array_map( fn($s) => $s['src'], $slots );
    $draw    = $champ['finals_draw'] ?? array();
    if ( count( $draw ) !== count( $default ) ) return $default;
    // Must be a permutation of the current source set
    if ( array_diff( $draw, $default ) || array_diff( $default, $draw ) ) return $default;
    return array_values( $draw );
}

// ── Build Finals Week match list (seeded 8-slot-style bracket) ────────────────
function lgw_gchamp_build_finals_matches( array $champ ): array {
    $slots = lgw_gchamp_finals_slots( $champ );
    $n     = count( $slots );
    if ( $n < 2 ) return array();

    $by_src = array();
    foreach ( $slots as $s ) $by_src[ $s['src'] ] = $s;
    $draw   = lgw_gchamp_finals_draw_order( $champ, $slots );
    $layout = lgw_gchamp_finals_layout( $n );
    if ( empty( $layout ) ) return array(); // unsupported count (>8) — no auto bracket

    $round_short = array( 'Quarter-final' => 'QF', 'Semi-final' => 'SF', 'Final' => 'F' );
    $round_counts = array();
    $matches = array();
    foreach ( $layout as $j => $spec ) {
        $round = $spec[0];
        $round_counts[ $round ] = ( $round_counts[ $round ] ?? 0 ) + 1;
        $m = array(
            'round' => $round, 'match_num' => $round_counts[ $round ],
            'home' => null, 'away' => null,
            'home_src' => null, 'away_src' => null,
            'home_seed' => null, 'away_seed' => null,
            'home_prev' => null, 'away_prev' => null,
            'home_label' => 'TBD', 'away_label' => 'TBD',
            'home_score' => null, 'away_score' => null,
            'ends' => array(), 'finals_datetime' => '', 'finals_rink' => '',
        );
        foreach ( array( 'home', 'away' ) as $k => $side ) {
            $ss = $spec[ $k + 1 ];
            if ( isset( $ss['seed'] ) ) {
                $src = $draw[ $ss['seed'] - 1 ] ?? null;
                $sl  = ( $src !== null ) ? ( $by_src[ $src ] ?? null ) : null;
                $m[ $side . '_src' ]   = $src;
                $m[ $side . '_seed' ]  = $ss['seed'];
                $m[ $side ]            = $sl['name'] ?? null;
                $m[ $side . '_label' ] = $sl['label'] ?? 'TBD';
            } else {
                $prev = intval( $ss['win'] );
                $m[ $side . '_prev' ]  = $prev;
                $pr = $layout[ $prev ][0] ?? 'match';
                $m[ $side . '_label' ] = 'Winner of ' . ( $round_short[ $pr ] ?? $pr );
            }
        }
        $matches[ $j ] = $m;
    }

    // Give "Winner of QF/SF" labels a match number now that numbering is known.
    foreach ( $matches as $j => &$m ) {
        foreach ( array( 'home', 'away' ) as $side ) {
            $prev = $m[ $side . '_prev' ];
            if ( $prev === null || ! isset( $matches[ $prev ] ) ) continue;
            $pm = $matches[ $prev ];
            $m[ $side . '_label' ] = 'Winner of ' . ( $round_short[ $pm['round'] ] ?? $pm['round'] ) . $pm['match_num'];
        }
    }
    unset( $m );

    // Preserve scores / schedule already entered against the previous build,
    // matched by (round, match_num) so rebuilding never loses played results.
    foreach ( $champ['finals_matches'] ?? array() as $old ) {
        foreach ( $matches as &$m ) {
            if ( $m['round'] === ( $old['round'] ?? '' ) && $m['match_num'] === ( $old['match_num'] ?? -1 ) ) {
                foreach ( array( 'home_score', 'away_score', 'ends', 'finals_datetime', 'finals_rink' ) as $f ) {
                    if ( isset( $old[ $f ] ) ) $m[ $f ] = $old[ $f ];
                }
                break;
            }
        }
        unset( $m );
    }

    lgw_gchamp_finals_propagate_matches( $matches );
    return array_values( $matches );
}

/**
 * Flow decided-match winners into the prev-linked slots of later matches.
 * Recomputed each build, so a rescored earlier match re-propagates cleanly.
 */
function lgw_gchamp_finals_propagate_matches( array &$matches ): void {
    $count = count( $matches );
    for ( $pass = 0; $pass <= $count; $pass++ ) {
        $changed = false;
        foreach ( $matches as $i => $m ) {
            foreach ( array( 'home', 'away' ) as $side ) {
                $prev = $m[ $side . '_prev' ] ?? null;
                if ( $prev === null || ! isset( $matches[ $prev ] ) ) continue;
                if ( $matches[ $i ][ $side ] !== null ) continue;
                $src = $matches[ $prev ];
                if ( $src['home_score'] === null || $src['away_score'] === null ) continue;
                $hs = intval( $src['home_score'] ); $as = intval( $src['away_score'] );
                if ( $hs === $as ) continue; // no winner
                $winner = $hs > $as ? $src['home'] : $src['away'];
                if ( $winner === null ) continue;
                $matches[ $i ][ $side ] = $winner;
                $changed = true;
            }
        }
        if ( ! $changed ) break;
    }
}

// ── Compat wrapper: rebuild the finals bracket in place ──────────────────────
// Older call sites used lgw_gchamp_finals_propagate($champ) to push SF winners
// into the Final. Rebuilding from source is a superset of that and also fills
// the new source labels / QF round, so this now rebuilds the whole bracket
// (scores preserved) and stores it back on the champ.
function lgw_gchamp_finals_propagate( array &$champ ): void {
    if ( empty( $champ['finals_matches'] ) ) return;
    $champ['finals_matches'] = lgw_gchamp_build_finals_matches( $champ );
}


// ── AJAX: seed a single day's KO bracket ─────────────────────────────────────
add_action( 'wp_ajax_lgw_gchamp_seed_day_ko',        'lgw_ajax_gchamp_seed_day_ko' );
add_action( 'wp_ajax_nopriv_lgw_gchamp_seed_day_ko', 'lgw_ajax_gchamp_seed_day_ko' );
function lgw_ajax_gchamp_seed_day_ko() {
    check_ajax_referer( 'lgw_gchamp_score', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorised.' );

    $champ_id = sanitize_key( $_POST['champ_id'] ?? '' );
    $day_id   = intval( $_POST['day_id'] ?? -1 );
    if ( ! $champ_id || $day_id < 0 ) wp_send_json_error( 'Missing parameters.' );

    $champ = get_option( 'lgw_gchamp_' . $champ_id, array() );
    if ( empty( $champ ) )                    wp_send_json_error( 'Championship not found.' );
    if ( ! isset( $champ['days'][$day_id] ) ) wp_send_json_error( 'Day not found.' );
    if ( ! lgw_gchamp_any_group_complete( $champ['days'][$day_id] ) ) wp_send_json_error( 'No groups have completed yet.' );

    // Compute partial qualifiers (confirmed + null stubs for pending groups)
    $partial = lgw_gchamp_compute_partial_qualifiers( $champ['days'][$day_id] );
    $champ['days'][$day_id]['qualifier_slots'] = $partial['slots'];
    if ( $champ['days'][$day_id]['day_complete'] ?? false ) {
        $champ['days'][$day_id]['qualifiers'] = lgw_gchamp_compute_day_qualifiers( $champ['days'][$day_id] );
    }

    // Check if KO bracket already has scores — if so, only fill TBD slots
    $existing_bracket = $champ['days'][$day_id]['ko_bracket'] ?? null;
    $ko_has_scores = false;
    if ( $existing_bracket ) {
        foreach ( $existing_bracket['rounds'] ?? array() as $rnd ) {
            foreach ( $rnd['matches'] ?? array() as $mch ) {
                if ( $mch['home_score'] !== null || $mch['away_score'] !== null ) {
                    $ko_has_scores = true; break 2;
                }
            }
        }
    }

    if ( $ko_has_scores ) {
        lgw_gchamp_fill_ko_tbd_slots( $champ['days'][$day_id]['ko_bracket'], $partial['confirmed'] );
    } else {
        lgw_gchamp_auto_seed_day_ko( $champ, $day_id );
    }

    if ( empty( $champ['days'][$day_id]['ko_bracket'] ) ) {
        wp_send_json_error( 'Could not seed bracket — check that qualifiers exist for this day.' );
    }

    update_option( 'lgw_gchamp_' . $champ_id, $champ );
    wp_send_json_success( array(
        'day_id'     => $day_id,
        'qualifiers' => $champ['days'][$day_id]['qualifiers'],
    ) );
}


// ── Draw algorithm ────────────────────────────────────────────────────────────

/**
 * Main draw: distribute entries across days (respecting preferences),
 * then within each day distribute into groups (club cap 50%).
 * Number of groups per day = ceil(day_entries / target_group_size).
 */
function lgw_gchamp_run_draw( array $entries, array $days_config, array $entry_prefs ) {
    $n        = count($entries);
    $num_days = count($days_config);
    $warnings = array();
    if ( $n < $num_days ) return new WP_Error('too_few','Fewer entries ('.$n.') than days ('.$num_days.').');

    // Normalise entry_prefs: migrate legacy string (date-only) to array format
    $prefs_norm = array();
    foreach ( $entry_prefs as $e => $p ) {
        $prefs_norm[$e] = is_array($p) ? $p : array('date'=>$p);
    }

    // Step 1: target entries per day (even split)
    $day_sizes = lgw_gchamp_compute_group_sizes( $n, $num_days );

    // Step 3: allocate entries to specific days, respecting date then location preferences.
    //
    // Each entry is scored against each day:
    //   3 = date matches AND location matches
    //   2 = date matches only (no loc pref), OR location matches only (no date pref)
    //   1 = partial match (date ok but wrong loc, or loc ok but wrong date)
    //   0 = no match
    // Entries are sorted highest-score first so the most-constrained are placed first.
    // Within equal scores the order is random (pool is pre-shuffled).
    // After preference-based placement, unplaced entries fill remaining slots randomly.
    //
    // Date normalisation: dd/mm/yy and dd/mm/yyyy are treated as equivalent.
    // Leading zeros on day/month are also normalised (6/6/26 == 06/06/2026).

    $pool = $entries; shuffle($pool);

    // Normalise a dd/mm/yy(yy) date string to d/m/yyyy for comparison
    $norm_date = function($raw) {
        $raw = trim($raw);
        if ($raw === '') return '';
        $parts = explode('/', $raw);
        if (count($parts) !== 3) return $raw;
        list($d,$m,$y) = $parts;
        $d = ltrim($d,'0') ?: '0';
        $m = ltrim($m,'0') ?: '0';
        $y = intval($y);
        if ($y < 100) $y += 2000; // 26 → 2026
        return $d.'/'.$m.'/'.$y;
    };

    // Build per-day normalised date and location lookups
    $day_norm_date = array();
    $day_location  = array();
    foreach ($days_config as $di=>$day) {
        $day_norm_date[$di] = $norm_date($day['date'] ?? '');
        $loc = strtolower(trim($day['location'] ?? ''));
        if ($loc !== '') $day_location[$di] = $loc;
    }

    // Score each entry against each day index
    $scored = array(); // [ [score, entry, day_index], ... ]
    foreach ($pool as $entry) {
        $pref_date      = $norm_date($prefs_norm[$entry]['date'] ?? '');
        $pref_loc       = strtolower(trim($prefs_norm[$entry]['location'] ?? ''));
        $has_date_pref  = ($pref_date !== '');
        $has_loc_pref   = ($pref_loc  !== '');
        foreach ($days_config as $di=>$day) {
            $day_date    = $day_norm_date[$di];
            $day_loc_str = $day_location[$di] ?? '';
            $date_match  = $has_date_pref && ($day_date === $pref_date);
            $loc_match   = $has_loc_pref  && ($day_loc_str !== '') &&
                           ($day_loc_str === $pref_loc ||
                            strpos($day_loc_str,$pref_loc)!==false ||
                            strpos($pref_loc,$day_loc_str)!==false);
            $score = 0;
            if ($date_match && $loc_match)              $score = 3;
            elseif ($date_match && !$has_loc_pref)      $score = 2; // date only, no loc pref
            elseif ($date_match)                        $score = 1; // date ok but wrong loc
            elseif ($loc_match  && !$has_date_pref)     $score = 2; // loc only, no date pref
            elseif ($loc_match)                         $score = 1; // loc ok but wrong date
            $scored[] = array($score, $entry, $di);
        }
    }
    // Sort descending by score (stable-ish since pool was shuffled)
    usort($scored, function($a,$b){ return $b[0]-$a[0]; });

    $day_entries_pool = array_fill(0,$num_days,array());
    $placed_entries   = array(); // track which entries are placed

    foreach ($scored as $row) {
        list($score,$entry,$di) = $row;
        if (isset($placed_entries[$entry]))      continue; // already placed
        if ($score === 0)                        continue; // no preference match, handle below
        if (count($day_entries_pool[$di]) >= $day_sizes[$di]) continue; // day full
        $day_entries_pool[$di][] = $entry;
        $placed_entries[$entry]  = true;
    }

    // Fill remaining slots with unplaced entries in random order
    $still_unplaced = array();
    foreach ($pool as $entry) {
        if (!isset($placed_entries[$entry])) $still_unplaced[] = $entry;
    }
    shuffle($still_unplaced);

    // Warn if any date-preferring entries ended up unplaced (oversubscription)
    foreach ($still_unplaced as $entry) {
        $pref_date = $prefs_norm[$entry]['date'] ?? '';
        if ($pref_date !== '') {
            $warnings[] = esc_html($entry).': preferred date '.$pref_date.' was oversubscribed — placed randomly.';
        }
    }

    // Distribute unplaced entries into whichever days have space, using dated days first
    $all_day_indices = range(0,$num_days-1);
    // Sort day indices: dated days first (they have fixed capacity expectations), then undated
    usort($all_day_indices, function($a,$b) use ($days_config) {
        $da = ($days_config[$a]['date']??'') !== '' ? 0 : 1;
        $db = ($days_config[$b]['date']??'') !== '' ? 0 : 1;
        return $da - $db;
    });
    foreach ($still_unplaced as $entry) {
        foreach ($all_day_indices as $di) {
            if (count($day_entries_pool[$di]) < $day_sizes[$di]) {
                $day_entries_pool[$di][] = $entry;
                break;
            }
        }
    }

    // Warn about preference satisfaction
    $date_satisfied=0; $date_total=0; $loc_satisfied=0; $loc_total=0;

    // Count distinct day locations — if only one (or none), location preference can't affect placement
    $distinct_locations = array_values(array_unique(array_filter(array_map(function($d){
        return strtolower(trim($d['location']??''));
    }, $days_config))));
    $location_pref_meaningful = count($distinct_locations) > 1;

    foreach ($pool as $entry) {
        $pref_date = $norm_date($prefs_norm[$entry]['date'] ?? '');
        $pref_loc  = strtolower(trim($prefs_norm[$entry]['location'] ?? ''));
        if ($pref_date !== '') {
            $date_total++;
            foreach ($days_config as $di=>$day) {
                if (in_array($entry,$day_entries_pool[$di],true) && $day_norm_date[$di]===$pref_date) {
                    $date_satisfied++; break;
                }
            }
        }
        if ($pref_loc !== '' && $location_pref_meaningful) {
            $loc_total++;
            foreach ($days_config as $di=>$day) {
                if (in_array($entry,$day_entries_pool[$di],true)) {
                    $dloc=strtolower(trim($day['location']??''));
                    if ($dloc===$pref_loc||strpos($dloc,$pref_loc)!==false||strpos($pref_loc,$dloc)!==false) {
                        $loc_satisfied++; break;
                    }
                }
            }
        }
    }
    if ($date_total>0 && $date_satisfied<$date_total)
        $warnings[]='Date preferences: '.$date_satisfied.' of '.$date_total.' satisfied (rest oversubscribed).';
    if ($loc_total>0 && $loc_satisfied<$loc_total)
        $warnings[]='Venue preferences: '.$loc_satisfied.' of '.$loc_total.' satisfied (rest could not be matched).';
    // No warning when all preferences satisfied — the draw went fine.

    // Step 4: within each day split into groups
    $days=array();
    foreach ($days_config as $di=>$day_cfg) {
        $day_pool=$day_entries_pool[$di];
        $tgs=max(2,intval($day_cfg['target_group_size']??4));
        $n_day=count($day_pool);
        $num_groups=max(1,(int)ceil($n_day/$tgs));
        $group_sizes=lgw_gchamp_compute_group_sizes($n_day,$num_groups);
        $group_entries_arr=array_fill(0,$num_groups,array());
        lgw_gchamp_distribute_to_groups($day_pool,range(0,$num_groups-1),$group_sizes,$group_entries_arr,$warnings);
        $groups=array();
        foreach ($group_entries_arr as $gi=>$g_entries) {
            $g_name=($day_cfg['name']??('Day '.($di+1))).' — Group '.chr(65+$gi);
            $fixtures=lgw_gchamp_generate_fixtures($di*100+$gi,$g_entries,1);
            $groups[]=array('id'=>$gi,'name'=>$g_name,'entries'=>$g_entries,'fixtures'=>$fixtures);
        }
        $days[]=array(
            'id'                => $di,
            'name'              => $day_cfg['name']              ??('Day '.($di+1)),
            'date'              => $day_cfg['date']              ??'',
            'location'          => $day_cfg['location']          ??'',
            'finals_qualifiers' => intval($day_cfg['finals_qualifiers'] ?? 1),
            'target_group_size' => $tgs,
            'winners_per_group' => intval($day_cfg['winners_per_group']??1),
            'best_runners_up'   => intval($day_cfg['best_runners_up']  ??0),
            'ko_bracket_size'   => intval($day_cfg['ko_bracket_size']  ??0),
            'entries'           => $day_pool,
            'groups'            => $groups,
            'qualifiers'        => array(),
            'day_complete'      => false,
            'ko_bracket'        => null,
            'ko_qualifiers'     => array(),
            'ko_complete'       => false,
        );
    }
    // Post-draw violation audit — only meaningful when entries have club info (comma format)
    $has_club_info = false;
    foreach ( $entries as $e ) {
        if ( strpos( $e, ',' ) !== false ) { $has_club_info = true; break; }
    }
    if ( $has_club_info ) {
        foreach ($days as $day) {
            foreach ($day['groups'] ?? array() as $group) {
                $seen = array();
                foreach ($group['entries'] ?? array() as $e) {
                    $c = lgw_gchamp_entry_club($e);
                    if ( $c === '' ) continue; // no club info — skip
                    if (isset($seen[$c])) {
                        $warnings[] = '⚠ ' . ($day['name'] ?? '') . ' ' . ($group['name'] ?? '') . ': multiple entries from ' . $c . ' (draw algorithm failure — please re-draw)';
                        break;
                    }
                    $seen[$c] = true;
                }
            }
        }
    }

    return array('days'=>$days,'warnings'=>$warnings);
}

// ── Per-day qualification ─────────────────────────────────────────────────────

function lgw_gchamp_compute_day_qualifiers( array $day ): array {
    $wpg=$intval=intval($day['winners_per_group']??1);
    $bru=intval($day['best_runners_up']??0);
    $auto=array(); $pool=array();
    foreach ($day['groups']??array() as $gi=>$group) {
        $standings=lgw_gchamp_compute_standings($group['entries']??array(),$group['fixtures']??array());
        foreach ($standings as $pos=>$row) {
            $aug=array_merge($row,array('group_id'=>$gi,'diff'=>$row['sf']-$row['sa']));
            if ($pos<$wpg) $auto[]=$aug; else $pool[]=$aug;
        }
    }
    usort($auto,'lgw_gchamp_sort_qualifier');
    $best=array();
    if ($bru>0&&!empty($pool)) {
        usort($pool,'lgw_gchamp_sort_qualifier');
        $best=array_slice($pool,0,min($bru,count($pool)));
    }
    return array_map(fn($q)=>$q['entry'],array_merge($auto,$best));
}

function lgw_gchamp_day_fixtures_all_played( array $day ): bool {
    foreach ($day['groups']??array() as $group) {
        foreach ($group['fixtures']??array() as $fx) {
            if ($fx['home_score']===null||$fx['away_score']===null) return false;
        }
    }
    return true;
}

/** Check if a single group has all fixtures played. */
function lgw_gchamp_group_fixtures_all_played( array $group ): bool {
    foreach ( $group['fixtures'] ?? array() as $fx ) {
        if ( $fx['home_score'] === null || $fx['away_score'] === null ) return false;
    }
    return ! empty( $group['fixtures'] );
}

/**
 * Return the qualifier names that came from a specific group on a day.
 * Looks at the day's current qualifier_slots to find which entries
 * originated from this group's standings.
 */
function lgw_gchamp_qualifiers_from_group( array $day, int $group_id ): array {
    $wpg   = intval( $day['winners_per_group'] ?? 1 );
    $group = $day['groups'][$group_id] ?? null;
    if ( ! $group ) return array();
    $standings = lgw_gchamp_compute_standings( $group['entries'] ?? array(), $group['fixtures'] ?? array() );
    $qs = array();
    foreach ( $standings as $pos => $row ) {
        if ( $pos < $wpg ) $qs[] = $row['entry'];
    }
    return $qs;
}

/**
 * Return true if any of $qualifiers appear as home or away in a KO match
 * that already has a score entered.
 */
function lgw_gchamp_qualifiers_in_played_ko( array $ko_bracket, array $qualifiers ): bool {
    if ( empty( $qualifiers ) ) return false;
    foreach ( $ko_bracket['rounds'] ?? array() as $rnd ) {
        foreach ( $rnd['matches'] ?? array() as $mch ) {
            if ( $mch['home_score'] === null && $mch['away_score'] === null ) continue;
            if ( in_array( $mch['home'], $qualifiers, true ) ) return true;
            if ( in_array( $mch['away'], $qualifiers, true ) ) return true;
        }
    }
    return false;
}


/**
 * Fill TBD slots in an existing KO bracket with newly confirmed qualifiers,
 * without touching any match that already has scores.
 *
 * The bracket was built with confirmed entries at the top and nulls below,
 * using consecutive pairing. We simply walk round 0 matches in order and
 * replace null home/away slots with the next unplaced confirmed qualifier.
 *
 * Also advances byes for any newly-filled slots.
 */
function lgw_gchamp_fill_ko_tbd_slots( array &$bracket, array $confirmed_names ): void {
    if ( empty( $bracket['rounds'] ) ) return;

    // Collect names already placed anywhere in round 0
    $placed = array();
    foreach ( $bracket['rounds'][0]['matches'] as $m ) {
        if ( $m['home'] !== null ) $placed[] = $m['home'];
        if ( $m['away'] !== null ) $placed[] = $m['away'];
    }

    // Queue of confirmed qualifiers not yet in the bracket
    $to_place = array();
    foreach ( $confirmed_names as $name ) {
        if ( ! in_array( $name, $placed, true ) ) $to_place[] = $name;
    }
    if ( empty( $to_place ) ) return;

    // Fill null slots in round 0 matches that are not yet scored
    foreach ( $bracket['rounds'][0]['matches'] as &$match ) {
        if ( empty( $to_place ) ) break;
        // Don't touch matches that already have scores
        if ( $match['home_score'] !== null || $match['away_score'] !== null ) continue;
        if ( $match['home'] === null ) {
            $match['home'] = array_shift( $to_place );
        }
        if ( empty( $to_place ) ) break;
        if ( $match['away'] === null ) {
            $match['away'] = array_shift( $to_place );
        }
    }
    unset( $match );

    // Re-process byes: if home is filled and away is still null, mark as bye and advance
    foreach ( $bracket['rounds'][0]['matches'] as &$match ) {
        if ( ! empty( $match['bye'] ) ) continue;
        if ( $match['home'] !== null && $match['away'] === null ) {
            $match['bye'] = true;
            lgw_gchamp_advance_ko_winner_rounds( $bracket['rounds'], 0, $match['match'], $match['home'] );
        }
    }
    unset( $match );
}

/**
 * Check whether any group on a day is complete.
 */
function lgw_gchamp_any_group_complete( array $day ): bool {
    foreach ( $day['groups'] ?? array() as $group ) {
        if ( lgw_gchamp_group_fixtures_all_played( $group ) ) return true;
    }
    return false;
}

/**
 * Compute qualifiers from completed groups only, returning an array of
 * confirmed qualifier names. Incomplete groups contribute null placeholders
 * so the bracket can be seeded with the right size.
 *
 * Returns: [ 'confirmed' => ['Name',...], 'slots' => ['Name'|null,...] ]
 * where slots is in draw order (confirmed entries followed by nulls for
 * groups not yet done).
 */
function lgw_gchamp_compute_partial_qualifiers( array $day ): array {
    $wpg  = intval( $day['winners_per_group'] ?? 1 );
    $bru  = intval( $day['best_runners_up']   ?? 0 );
    $confirmed_auto = array();
    $pending_auto   = 0;
    $runner_up_pool = array();
    $pending_bru    = 0;

    foreach ( $day['groups'] ?? array() as $group ) {
        $complete = lgw_gchamp_group_fixtures_all_played( $group );
        $standings = lgw_gchamp_compute_standings( $group['entries'] ?? array(), $group['fixtures'] ?? array() );
        if ( $complete ) {
            foreach ( $standings as $pos => $row ) {
                $aug = array_merge( $row, array( 'diff' => $row['sf'] - $row['sa'] ) );
                if ( $pos < $wpg ) $confirmed_auto[] = $aug;
                elseif ( $bru > 0 ) $runner_up_pool[] = $aug;
            }
        } else {
            $pending_auto += $wpg;
            if ( $bru > 0 ) $pending_bru++;
        }
    }

    usort( $confirmed_auto, 'lgw_gchamp_sort_qualifier' );
    $confirmed_names = array_map( fn($q) => $q['entry'], $confirmed_auto );

    // Best runners-up from completed groups
    $confirmed_bru = array();
    if ( $bru > 0 && ! empty( $runner_up_pool ) ) {
        usort( $runner_up_pool, 'lgw_gchamp_sort_qualifier' );
        $confirmed_bru = array_map( fn($q) => $q['entry'], array_slice( $runner_up_pool, 0, $bru ) );
    }

    // Slots: confirmed winners + null stubs for pending groups + BRU slots
    $slots = array_merge(
        $confirmed_names,
        array_fill( 0, $pending_auto, null ),
        $confirmed_bru,
        array_fill( 0, max( 0, $bru - count( $confirmed_bru ) - $pending_bru ), null ),
        array_fill( 0, min( $pending_bru, $bru ), null )
    );

    return array(
        'confirmed' => array_merge( $confirmed_names, $confirmed_bru ),
        'slots'     => $slots,
    );
}

// ── Knockout bracket seeding ──────────────────────────────────────────────────

function lgw_gchamp_build_knockout( string $champ_id, array &$champ ) {
    $ko_size=$intval=intval($champ['ko_bracket_size']??0);
    $dates=$champ['dates']??array(); $warnings=array();
    $all_qualifiers=array();
    foreach ($champ['days']??array() as $day) foreach ($day['qualifiers']??array() as $q) $all_qualifiers[]=$q;
    $total_q=count($all_qualifiers);
    if ($total_q<2) return new WP_Error('too_few','Fewer than 2 qualifiers across all days.');
    if ($ko_size<$total_q||!lgw_gchamp_is_power_of_two($ko_size)) {
        $ko_size=lgw_gchamp_next_power_of_two($total_q);
        $warnings[]='Bracket size set to '.$ko_size.' to fit '.$total_q.' qualifiers.';
    }
    $byes=$ko_size-$total_q;
    $numbered=array();
    foreach ($all_qualifiers as $i=>$q) $numbered[]=array('name'=>$q,'draw_num'=>$i+1);
    for ($b=0;$b<$byes;$b++) $numbered[]=array('name'=>null,'draw_num'=>$total_q+$b+1);
    if (!function_exists('lgw_draw_build_bracket')) return new WP_Error('missing_dep','lgw_draw_build_bracket() not available.');
    $result=lgw_draw_build_bracket($numbered,array(
        'get_club'=>'lgw_gchamp_entry_club','home_at_limit'=>function($c,$cnt){return false;},
        'separate_prelim'=>'lgw_champ_separate_clubs','separate_r2'=>'lgw_champ_separate_r2_slots',
        'stored_rounds'=>lgw_draw_default_rounds($ko_size),'dates'=>$dates,'r2_label'=>'Knockout Draw','game_nums'=>true,
    ));
    if (!$result) return new WP_Error('bracket_fail','Bracket build failed.');
    $champ['ko_bracket']=array('title'=>($champ['title']??'Championship').' — Knockout Stage','rounds'=>$result['rounds'],'dates'=>$dates,'matches'=>$result['matches']);
    $champ['qualifiers']=$all_qualifiers;
    if (strlen(serialize($champ))>800000) return new WP_Error('too_large','Bracket data exceeds safe storage limit.');
    update_option('lgw_gchamp_'.$champ_id,$champ);
    return array('warnings'=>$warnings);
}


// ── Standings computation ──────────────────────────────────────────────────────

/**
 * Compute group standings from entries and fixtures.
 * Returns array sorted by: pts desc, diff desc, sf desc, entry name asc.
 * Head-to-head tiebreak (spec §5.2) is applied when pts AND diff are equal.
 *
 * Each row: [ entry, p, w, d, l, sf, sa, pts ]
 */
function lgw_gchamp_compute_standings( array $entries, array $fixtures ): array {
    // Initialise
    $rows = array();
    foreach ( $entries as $e ) {
        $rows[ $e ] = array( 'entry' => $e, 'p' => 0, 'w' => 0, 'd' => 0, 'l' => 0, 'sf' => 0, 'sa' => 0, 'pts' => 0 );
    }

    // Accumulate results
    foreach ( $fixtures as $fx ) {
        $hs = $fx['home_score'];
        $as = $fx['away_score'];
        if ( $hs === null || $as === null ) continue;
        $hs = intval( $hs );
        $as = intval( $as );
        $h  = $fx['home'];
        $a  = $fx['away'];
        if ( ! isset( $rows[ $h ] ) || ! isset( $rows[ $a ] ) ) continue;

        $rows[ $h ]['p']++;
        $rows[ $a ]['p']++;
        $rows[ $h ]['sf'] += $hs; $rows[ $h ]['sa'] += $as;
        $rows[ $a ]['sf'] += $as; $rows[ $a ]['sa'] += $hs;

        if ( $hs > $as ) {
            $rows[ $h ]['w']++;   $rows[ $h ]['pts'] += 2;
            $rows[ $a ]['l']++;
        } elseif ( $as > $hs ) {
            $rows[ $a ]['w']++;   $rows[ $a ]['pts'] += 2;
            $rows[ $h ]['l']++;
        } else {
            $rows[ $h ]['d']++;   $rows[ $h ]['pts']++;
            $rows[ $a ]['d']++;   $rows[ $a ]['pts']++;
        }
    }

    $sorted = array_values( $rows );

    // Sort: pts desc → diff desc → h2h → sf desc → name asc
    usort( $sorted, function( $a, $b ) use ( $fixtures ) {
        if ( $b['pts'] !== $a['pts'] ) return $b['pts'] - $a['pts'];
        $diff_a = $a['sf'] - $a['sa'];
        $diff_b = $b['sf'] - $b['sa'];
        if ( $diff_b !== $diff_a ) return $diff_b - $diff_a;
        // Head-to-head
        $h2h = lgw_gchamp_h2h_compare( $a['entry'], $b['entry'], $fixtures );
        if ( $h2h !== 0 ) return $h2h;
        if ( $b['sf'] !== $a['sf'] ) return $b['sf'] - $a['sf'];
        return strcmp( $a['entry'], $b['entry'] );
    } );

    return $sorted;
}

/**
 * Compare two entries by their head-to-head record in the given fixture list.
 * Returns -1 if $entry_a is ahead, +1 if $entry_b is ahead, 0 if equal/not played.
 */
function lgw_gchamp_h2h_compare( string $entry_a, string $entry_b, array $fixtures ): int {
    $pts_a = 0;
    $pts_b = 0;
    foreach ( $fixtures as $fx ) {
        $hs = $fx['home_score'];
        $as = $fx['away_score'];
        if ( $hs === null || $as === null ) continue;
        $hs = intval( $hs );
        $as = intval( $as );
        if ( $fx['home'] === $entry_a && $fx['away'] === $entry_b ) {
            if ( $hs > $as ) $pts_a += 2;
            elseif ( $as > $hs ) $pts_b += 2;
            else { $pts_a++; $pts_b++; }
        } elseif ( $fx['home'] === $entry_b && $fx['away'] === $entry_a ) {
            if ( $hs > $as ) $pts_b += 2;
            elseif ( $as > $hs ) $pts_a += 2;
            else { $pts_a++; $pts_b++; }
        }
    }
    if ( $pts_a > $pts_b ) return -1;
    if ( $pts_b > $pts_a ) return 1;
    return 0;
}

/**
 * Count the number of played fixtures (both scores set).
 */
function lgw_gchamp_count_played( array $fixtures ): int {
    $count = 0;
    foreach ( $fixtures as $fx ) {
        if ( $fx['home_score'] !== null && $fx['away_score'] !== null ) $count++;
    }
    return $count;
}

/**
 * Return true if every fixture in every group has both scores set.
 */
function lgw_gchamp_all_fixtures_played( array $groups ): bool {
    foreach ( $groups as $group ) {
        foreach ( ( $group['fixtures'] ?? array() ) as $fx ) {
            if ( $fx['home_score'] === null || $fx['away_score'] === null ) return false;
        }
    }
    return true;
}

/**
 * Return the player-name part of an entry string (before the last comma).
 * Used for compact display in the standings and fixture rows.
 */
function lgw_gchamp_short_name( string $entry ): string {
    $parts = array_map( 'trim', explode( ',', $entry, 2 ) );
    return $parts[0] ?? $entry;
}


// ── Pure helper functions ────────────────────────────────────────────────────────

function lgw_gchamp_count_club_in_group( string $club, array $group_entries ): int {
    $count = 0;
    foreach ( $group_entries as $e ) {
        if ( lgw_gchamp_entry_club( $e ) === $club ) $count++;
    }
    return $count;
}

function lgw_gchamp_entry_club( ?string $entry ): string {
    // Bye slots carry a null name — treat as clubless so bracket seeding
    // (which passes every slot through this callback) does not fatal.
    if ( $entry === null || $entry === '' ) return '';
    $parts = array_map( 'trim', explode( ',', $entry, 2 ) );
    return strtolower( $parts[1] ?? '' );
}


function lgw_gchamp_sort_qualifier( array $a, array $b ): int {
    if ( $b['pts']  !== $a['pts']  ) return $b['pts']  - $a['pts'];
    if ( $b['diff'] !== $a['diff'] ) return $b['diff'] - $a['diff'];
    if ( $b['sf']   !== $a['sf']   ) return $b['sf']   - $a['sf'];
    return strcmp( $a['entry'], $b['entry'] );
}

function lgw_gchamp_distribute_to_groups(
    array  $pool,
    array  $group_indices,
    array  $sizes,
    array &$group_entries,
    array &$warnings
) {
    if ( empty( $pool ) ) return;

    // If entries have no club info (no comma), skip club separation entirely
    $has_club_info = false;
    foreach ( $pool as $e ) {
        if ( strpos( $e, ',' ) !== false ) { $has_club_info = true; break; }
    }
    if ( ! $has_club_info ) {
        // Simple round-robin fill
        shuffle( $pool );
        $i = 0;
        foreach ( $group_indices as $gi ) {
            while ( count( $group_entries[$gi] ) < $sizes[$gi] && $i < count( $pool ) ) {
                $group_entries[$gi][] = $pool[$i++];
            }
        }
        return;
    }

    // Count club frequencies in this pool
    $club_counts = array();
    foreach ( $pool as $e ) {
        $c = lgw_gchamp_entry_club( $e );
        $club_counts[$c] = ( $club_counts[$c] ?? 0 ) + 1;
    }

    // Helper: run one placement attempt, return [group_entries_arr, violation_count]
    $attempt = function() use ( $pool, $group_indices, $sizes, $club_counts ) {
        $ge = array_fill_keys( $group_indices, array() );

        // Sort: largest clubs first, random within same count
        $sorted = $pool;
        usort( $sorted, function( $a, $b ) use ( $club_counts ) {
            $ca = lgw_gchamp_entry_club( $a );
            $cb = lgw_gchamp_entry_club( $b );
            $d  = ( $club_counts[$cb] ?? 0 ) - ( $club_counts[$ca] ?? 0 );
            return $d !== 0 ? $d : rand( -1, 1 );
        } );

        // Place each entry into the group that minimises same-club collisions
        foreach ( $sorted as $entry ) {
            $club = lgw_gchamp_entry_club( $entry );

            // Separate groups with space into clean (0 same-club) and dirty (≥1)
            $clean = $dirty = array();
            foreach ( $group_indices as $gi ) {
                if ( count( $ge[$gi] ) >= $sizes[$gi] ) continue;
                if ( lgw_gchamp_count_club_in_group( $club, $ge[$gi] ) === 0 ) $clean[] = $gi;
                else $dirty[] = $gi;
            }

            // Pick from clean first; within clean/dirty prefer most remaining space
            $candidates = ! empty( $clean ) ? $clean : $dirty;
            usort( $candidates, function( $a, $b ) use ( $sizes, &$ge ) {
                $d = ( $sizes[$b] - count( $ge[$b] ) ) - ( $sizes[$a] - count( $ge[$a] ) );
                return $d !== 0 ? $d : rand( -1, 1 );
            } );

            if ( ! empty( $candidates ) ) $ge[ $candidates[0] ][] = $entry;
        }

        // ── Repair: iterative swap until no violations or no progress ────────
        $max_r = count( $pool ) * 6;
        for ( $r = 0; $r < $max_r; $r++ ) {
            // Collect all current violations (group → [entry_idx, ...])
            $all_violations = array();
            foreach ( $group_indices as $gi ) {
                $seen = array();
                foreach ( $ge[$gi] as $idx => $e ) {
                    $c = lgw_gchamp_entry_club( $e );
                    if ( isset( $seen[$c] ) ) $all_violations[] = array( 'gi'=>$gi, 'idx'=>$idx, 'entry'=>$e, 'club'=>$c );
                    else $seen[$c] = true;
                }
            }
            if ( empty( $all_violations ) ) break;

            $fixed = false;
            foreach ( $all_violations as $v ) {
                $vgi = $v['gi']; $ve = $v['entry']; $vc = $v['club'];

                // Re-find current index of ve in vgi (may have shifted)
                $vidx = array_search( $ve, $ge[$vgi], true );
                if ( $vidx === false ) continue; // already moved

                $targets = $group_indices; shuffle( $targets );
                foreach ( $targets as $tgi ) {
                    if ( $tgi === $vgi ) continue;

                    // Try swap with each entry in tgi
                    $tgi_entries = $ge[$tgi];
                    shuffle( $tgi_entries );
                    foreach ( $tgi_entries as $te ) {
                        $tc = lgw_gchamp_entry_club( $te );
                        if ( $tc === $vc ) continue;

                        // Simulate the swap and check both groups are clean after
                        $vgi_new = array_values( array_filter( $ge[$vgi], fn($x) => $x !== $ve ) );
                        $vgi_new[] = $te;
                        $tgi_new = array_values( array_filter( $ge[$tgi], fn($x) => $x !== $te ) );
                        $tgi_new[] = $ve;

                        // vgi_new must have no duplicate of tc (incoming club)
                        // tgi_new must have no duplicate of vc (incoming club)
                        // Note: the entries being swapped are already included in _new arrays,
                        // so a count of 1 means only the swapped entry — no collision.
                        $vgi_ok = lgw_gchamp_count_club_in_group( $tc, $vgi_new ) <= 1;
                        $tgi_ok = lgw_gchamp_count_club_in_group( $vc, $tgi_new ) <= 1;

                        if ( $vgi_ok && $tgi_ok ) {
                            $ge[$vgi] = $vgi_new;
                            $ge[$tgi] = $tgi_new;
                            $fixed = true;
                            break 3;
                        }
                    }
                }
            }
            if ( ! $fixed ) break;
        }

        // Count remaining violations
        $v_count = 0;
        foreach ( $group_indices as $gi ) {
            $seen = array();
            foreach ( $ge[$gi] as $e ) {
                $c = lgw_gchamp_entry_club( $e );
                if ( isset( $seen[$c] ) ) { $v_count++; break; }
                $seen[$c] = true;
            }
        }

        return array( 'ge' => $ge, 'violations' => $v_count );
    };

    // Run up to 20 attempts, keep the result with fewest violations
    $best = null;
    for ( $t = 0; $t < 20; $t++ ) {
        $result = $attempt();
        if ( $best === null || $result['violations'] < $best['violations'] ) {
            $best = $result;
        }
        if ( $best['violations'] === 0 ) break;
    }

    // Apply best result to $group_entries
    foreach ( $group_indices as $gi ) {
        $group_entries[$gi] = $best['ge'][$gi] ?? array();
    }

    // Only warn if the best result still has violations (genuinely unavoidable)
    if ( $best['violations'] > 0 ) {
        $warnings[] = $best['violations'] . ' group' . ( $best['violations'] > 1 ? 's have' : ' has' )
            . ' more than one entry from the same club — unavoidable given the club distribution on this day.';
    }
}

function lgw_gchamp_generate_fixtures( int $group_id, array $entries, int $games_per_opp = 1 ): array {
    $n        = count( $entries );
    $fixtures = array();
    if ( $n < 2 ) return $fixtures;

    $has_bye = ( $n % 2 !== 0 );
    $wheel   = $entries;
    if ( $has_bye ) $wheel[] = '__BYE__';
    $m = count( $wheel );

    $num_rounds = $m - 1;
    $fixed      = $wheel[0];
    $fix_idx    = 0;

    for ( $rep = 0; $rep < $games_per_opp; $rep++ ) {
        $rotating = array_slice( $wheel, 1 );

        for ( $round = 0; $round < $num_rounds; $round++ ) {
            $current_wheel = array_merge( array( $fixed ), $rotating );

            for ( $slot = 0; $slot < $m / 2; $slot++ ) {
                $home_entry = $current_wheel[ $slot ];
                $away_entry = $current_wheel[ $m - 1 - $slot ];

                if ( $home_entry === '__BYE__' || $away_entry === '__BYE__' ) continue;

                // Reverse home/away on even-numbered repetitions
                if ( $rep % 2 === 1 ) {
                    list( $home_entry, $away_entry ) = array( $away_entry, $home_entry );
                }

                $global_round = $rep * $num_rounds + $round;
                $pos_key      = 'g' . $group_id . ':r' . $global_round . ':f' . $fix_idx;

                $fixtures[] = array(
                    'round'      => $global_round,
                    'home'       => $home_entry,
                    'away'       => $away_entry,
                    'home_score' => null,
                    'away_score' => null,
                    'pos_key'    => $pos_key,
                );
                $fix_idx++;
            }

            // Rotate: move last element of rotating to front
            array_unshift( $rotating, array_pop( $rotating ) );
        }
    }

    return $fixtures;
}

function lgw_gchamp_has_any_scores( array $champ ): bool {
    foreach ( ( $champ['days'] ?? array() ) as $day ) {
        foreach ( ( $day['groups'] ?? array() ) as $group ) {
            foreach ( ( $group['fixtures'] ?? array() ) as $fixture ) {
                if ( $fixture['home_score'] !== null || $fixture['away_score'] !== null ) {
                    return true;
                }
            }
        }
    }
    return false;
}

function lgw_gchamp_is_power_of_two( int $n ): bool {
    return $n > 0 && ( $n & ( $n - 1 ) ) === 0;
}

function lgw_gchamp_next_power_of_two( int $n ): int {
    if ( $n <= 1 ) return 2;
    $p = 1;
    while ( $p < $n ) $p <<= 1;
    return $p;
}

function lgw_gchamp_compute_group_sizes( int $total_entries, int $num_groups ): array {
    if ( $num_groups <= 0 ) return array();
    $base      = intdiv( $total_entries, $num_groups );
    $remainder = $total_entries % $num_groups;
    $sizes     = array();
    for ( $i = 0; $i < $num_groups; $i++ ) {
        $sizes[] = $base + ( $i < $remainder ? 1 : 0 );
    }
    return $sizes;
}

// ── Asset enqueue ─────────────────────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'lgw_gchamp_enqueue' );
function lgw_gchamp_enqueue() {
    global $post;
    if ( ! is_singular() || ! is_a( $post, 'WP_Post' ) ) return;
    $content = $post->post_content . ' ' . get_the_content( null, false, $post );
    if ( ! has_shortcode( $content, 'lgw_gchamp' ) ) return;

    wp_enqueue_style( 'lgw-saira',   'https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700&display=swap', array(), null );
    wp_enqueue_style( 'lgw-widget',  plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-widget.css',  array( 'lgw-saira' ),   LGW_VERSION );
    wp_enqueue_style( 'lgw-champ',   plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-champ.css',   array( 'lgw-widget' ),  LGW_VERSION );
    wp_enqueue_style( 'lgw-gchamp',  plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-gchamp.css',  array( 'lgw-champ' ),   LGW_VERSION );

    if ( ! wp_script_is( 'lgw-scorecard', 'registered' ) ) {
        wp_register_script( 'lgw-scorecard', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-scorecard.js', array(), LGW_VERSION, true );
        wp_register_style(  'lgw-scorecard', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-scorecard.css', array(), LGW_VERSION );
    }
    if ( ! wp_script_is( 'lgw-scorecard', 'enqueued' ) ) {
        wp_enqueue_script( 'lgw-scorecard' );
        wp_enqueue_style(  'lgw-scorecard' );
    }

    // lgw-champ.js handles the knockout bracket rendering (reuses lgw-champ-wrap)
    if ( ! wp_script_is( 'lgw-champ', 'enqueued' ) ) {
        wp_enqueue_script( 'lgw-champ', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-champ.js', array( 'lgw-scorecard' ), LGW_VERSION, true );
        if ( ! wp_script_is( 'lgw-widget', 'enqueued' ) ) {
            wp_localize_script( 'lgw-champ', 'lgwChampData', array(
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'isAdmin'    => current_user_can( 'manage_options' ) ? 1 : 0,
                'scoreNonce' => wp_create_nonce( 'lgw_champ_score' ),
                'champNonce' => wp_create_nonce( 'lgw_champ_nonce' ),
                'badges'     => get_option( 'lgw_badges',      array() ),
                'clubBadges' => get_option( 'lgw_club_badges', array() ),
            ) );
        }
    }
    wp_enqueue_style(  'lgw-finals', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-finals.css', array( 'lgw-widget' ), LGW_VERSION );
    if ( ! wp_script_is( 'lgw-finals', 'enqueued' ) ) {
        wp_enqueue_script( 'lgw-finals', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-finals.js', array(), LGW_VERSION, true );
    }
    wp_enqueue_script( 'lgw-gchamp', plugin_dir_url( LGW_PLUGIN_FILE ) . 'lgw-gchamp.js', array( 'lgw-champ', 'lgw-finals' ), LGW_VERSION, true );

    // Provide badge + AJAX data to front-end JS
    if ( ! wp_script_is( 'lgw-widget', 'enqueued' ) ) {
        wp_localize_script( 'lgw-gchamp', 'lgwData', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'badges'     => get_option( 'lgw_badges',      array() ),
            'clubBadges' => get_option( 'lgw_club_badges', array() ),
        ) );
    }
    wp_localize_script( 'lgw-gchamp', 'lgwGchampData', array(
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'isAdmin'     => current_user_can( 'manage_options' ) ? 1 : 0,
        'canScore'    => ( function_exists( 'lgw_user_can_manage_scores' ) && lgw_user_can_manage_scores() ) ? 1 : 0,
        'nonce'       => wp_create_nonce( 'lgw_gchamp_score' ),
        'searchNonce' => wp_create_nonce( 'lgw_gchamp_search' ),
        'badges'      => get_option( 'lgw_badges',      array() ),
        'clubBadges'  => get_option( 'lgw_club_badges', array() ),
    ) );
}

// ── Shortcode ─────────────────────────────────────────────────────────────────
add_shortcode( 'lgw_gchamp', 'lgw_gchamp_shortcode' );
function lgw_gchamp_shortcode( $atts ) {
    $atts      = shortcode_atts( array( 'id' => '' ), $atts );
    $gchamp_id = sanitize_key( $atts['id'] );
    if ( ! $gchamp_id ) return '<p><em>[lgw_gchamp]: no id specified.</em></p>';

    $champ = get_option( 'lgw_gchamp_' . $gchamp_id, array() );
    if ( empty( $champ ) ) return '<p><em>[lgw_gchamp]: championship "' . esc_html( $gchamp_id ) . '" not found.</em></p>';

    $title       = $champ['title']         ?? $gchamp_id;
    $drawn       = ! empty( $champ['draw_complete'] );
    $gs_complete = ! empty( $champ['group_stage_complete'] );
    $warnings    = $champ['draw_warnings'] ?? array();
    $num_entries = count( $champ['entries'] ?? array() );
    $days_config = $champ['days_config']   ?? array();
    $days        = $champ['days']          ?? array();
    $can_score   = current_user_can( 'edit_posts' );
    $num_days    = count( $days );
    $club_badges = get_option( 'lgw_club_badges', array() );
    $team_badges = get_option( 'lgw_badges',      array() );

    ob_start();
    ?>
    <?php
    $colour     = $champ['colour_scheme'] ?? '#c0202a';
    $tab_colour = $champ['tab_colour']    ?? '#e8b400';
    ?>
    <div class="lgw-gchamp-wrap" data-gchamp-id="<?php echo esc_attr( $gchamp_id ); ?>"
         style="--lgw-accent:<?php echo esc_attr($colour); ?>;--lgw-header-bg:<?php echo esc_attr($colour); ?>;--lgw-gold:<?php echo esc_attr($tab_colour); ?>">

        <div class="lgw-gchamp-header">
            <div>
                <div class="lgw-gchamp-title"><?php echo esc_html( $title ); ?></div>
                <div class="lgw-gchamp-subtitle">
                    <?php echo esc_html( $num_entries ); ?> entries &middot;
                    <?php echo count( $days_config ); ?> day<?php echo count( $days_config ) !== 1 ? 's' : ''; ?>
                </div>
            </div>
            <span class="lgw-gchamp-header-badge">Group + KO</span>
        </div>

        <?php if ( ! empty( $warnings ) ): ?>
        <div class="lgw-gchamp-warnings">
            &#x26A0; <strong>Draw notes:</strong>
            <ul><?php foreach ( $warnings as $w ): ?><li><?php echo esc_html( $w ); ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <?php if ( ! $drawn ): ?>
        <div class="lgw-gchamp-empty">
            <div class="lgw-gchamp-empty-icon">&#x1F3B2;</div>
            <p>The group draw has not taken place yet. Check back soon!</p>
        </div>
        <?php else: ?>

        <?php /* ── Top-level day tabs ── */ ?>
        <?php
        // Show finals tab as soon as any day's KO is complete
        $has_any_ko_complete = false;
        foreach ( $days as $d ) { if ( ! empty( $d['ko_complete'] ) ) { $has_any_ko_complete = true; break; } }
        // The Finals Week draw is available as soon as the championship is drawn
        // and uses an internal knockout — the qualifier slots exist from the
        // day config, so the draw can be arranged before any KO has finished.
        $show_finals = $drawn && ! empty( $champ['has_ko_bracket'] );
        $finals_matches = $champ['finals_matches'] ?? array();
        $all_ko_complete = true;
        foreach ( $days as $d ) { if ( empty( $d['ko_complete'] ) ) { $all_ko_complete = false; break; } }
        ?>
        <div class="lgw-gchamp-day-tabs" role="tablist">
            <?php foreach ( $days as $day_idx => $day ): ?>
            <button class="lgw-gchamp-day-tab<?php echo $day_idx === 0 ? ' active' : ''; ?>"
                    data-day-tab="<?php echo $day_idx; ?>"
                    role="tab"
                    aria-selected="<?php echo $day_idx === 0 ? 'true' : 'false'; ?>">
                <?php echo esc_html( $day['name'] ); ?>
                <?php if ( ! empty( $day['ko_complete'] ) ): ?>
                    <span class="lgw-gchamp-day-tab-badge">&#x2713;</span>
                <?php elseif ( ! empty( $day['day_complete'] ) ): ?>
                    <span class="lgw-gchamp-day-tab-badge lgw-gchamp-day-tab-badge-groups">G</span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
            <?php if ( $show_finals ): ?>
            <button class="lgw-gchamp-day-tab<?php echo $all_ko_complete && !empty($finals_matches) ? '' : ''; ?>"
                    data-day-tab="finals"
                    role="tab"
                    aria-selected="false">
                &#x1F3C6; Finals Week
                <?php if ( $all_ko_complete ): ?>
                    <span class="lgw-gchamp-day-tab-badge">&#x2713;</span>
                <?php endif; ?>
            </button>
            <?php endif; ?>
            <button class="lgw-gchamp-search-tab"
                    data-gchamp-id="<?php echo esc_attr( $gchamp_id ); ?>"
                    title="Search fixtures and results">
                &#x1F50D; Search
            </button>
        </div>

        <?php foreach ( $days as $day_idx => $day ):
            $wpg               = intval( $day['winners_per_group'] ?? 1 );
            $bru               = intval( $day['best_runners_up']   ?? 0 );
            $day_complete      = ! empty( $day['day_complete'] );
            $any_group_complete = lgw_gchamp_any_group_complete( $day );
            $ko_bracket        = $day['ko_bracket']   ?? null;
            $ko_complete       = ! empty( $day['ko_complete'] );
            $ko_quals          = $day['ko_qualifiers'] ?? array();
        ?>
        <div class="lgw-gchamp-day-pane<?php echo $day_idx === 0 ? ' active' : ''; ?>"
             data-day-pane="<?php echo $day_idx; ?>"
             data-seed-needed="<?php echo ($any_group_complete && empty($day['ko_bracket'])) ? '1' : '0'; ?>">

            <?php /* ── Sub-tabs: Groups | Knockout ── */ ?>
            <div class="lgw-gchamp-sub-tabs">
                <button class="lgw-gchamp-sub-tab active" data-sub-pane="groups">
                    &#x26BD; Groups
                </button>
                <button class="lgw-gchamp-sub-tab<?php echo $any_group_complete ? '' : ' locked'; ?>"
                        data-sub-pane="knockout">
                    &#x1F3C6; Knockout
                    <?php if ( $ko_complete ): ?><span class="lgw-gchamp-sub-tab-done">&#x2713;</span><?php endif; ?>
                </button>
            </div>

            <?php /* ── Groups sub-pane ── */ ?>
            <div class="lgw-gchamp-sub-pane active" data-sub-pane="groups">

                <?php if ( ! empty( $day['date'] ) ): ?>
                <div class="lgw-gchamp-day-date-bar">&#x1F4C5; <?php echo esc_html( $day['date'] ); ?></div>
                <?php endif; ?>

                <div class="lgw-gchamp-groups-grid">
                <?php foreach ( ( $day['groups'] ?? array() ) as $group ):
                    $g_entries  = $group['entries']  ?? array();
                    $g_fixtures = $group['fixtures']  ?? array();
                    $g_name     = $group['name']      ?? '';
                    $standings  = lgw_gchamp_compute_standings( $g_entries, $g_fixtures );
                    $n_played   = lgw_gchamp_count_played( $g_fixtures );
                    $n_total    = count( $g_fixtures );
                    $g_parts    = explode( ' — ', $g_name, 2 );
                    $g_label    = count( $g_parts ) > 1 ? $g_parts[1] : $g_name;
                ?>
                <div class="lgw-gchamp-group-card">
                    <?php
                    $group_locked = ! empty( $group['locked'] );
                    $group_complete = lgw_gchamp_group_fixtures_all_played( $group );
                    // KO has scores for THIS group's qualifier = can't unlock
                    $ko_has_scores = false;
                    if ( ! empty( $day['ko_bracket'] ) ) {
                        $group_qs = lgw_gchamp_qualifiers_from_group( $day, $group['id'] ?? $gi );
                        $ko_has_scores = lgw_gchamp_qualifiers_in_played_ko( $day['ko_bracket'], $group_qs );
                    }
                    ?>
                    <div class="lgw-gchamp-group-header">
                        <span class="lgw-gchamp-group-name"><?php echo esc_html( $g_label ); ?></span>
                        <span class="lgw-gchamp-group-progress"><?php echo $n_played; ?>/<?php echo $n_total; ?></span>
                        <?php if ( current_user_can('manage_options') && $group_complete ): ?>
                        <button type="button"
                                class="lgw-gchamp-group-lock-btn"
                                data-day-id="<?php echo $day_idx; ?>"
                                data-group-id="<?php echo $group['id'] ?? $gi; ?>"
                                data-locked="<?php echo $group_locked ? '1' : '0'; ?>"
                                data-ko-has-scores="<?php echo $ko_has_scores ? '1' : '0'; ?>"
                                title="<?php echo $ko_has_scores ? 'Cannot unlock — this group\'s qualifier has played in the KO bracket. Use \'Clear all KO scores\' first.' : ($group_locked ? 'Unlock group for editing' : 'Lock group scores'); ?>"
                                <?php echo $ko_has_scores ? 'disabled' : ''; ?>>
                            <?php echo $group_locked ? '🔒' : '🔓'; ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <table class="lgw-gchamp-standings">
                        <thead><tr>
                            <th class="lgw-gs-pos">#</th>
                            <th class="lgw-gs-crest lgw-gs-hide-sm"></th>
                            <th class="lgw-gs-col-name">Entry</th>
                            <th class="lgw-gs-num lgw-gs-hide-xs" title="Played">P</th>
                            <th class="lgw-gs-num lgw-gs-hide-xs" title="Won">W</th>
                            <th class="lgw-gs-num lgw-gs-hide-xs" title="Drawn">D</th>
                            <th class="lgw-gs-num lgw-gs-hide-xs" title="Lost">L</th>
                            <th class="lgw-gs-num" title="Shots For">SF</th>
                            <th class="lgw-gs-num" title="Shots Against">SA</th>
                            <th class="lgw-gs-diff" title="Difference">+/-</th>
                            <th class="lgw-gs-pts" title="Points">Pts</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $standings as $pos => $row ):
                            $is_q  = ( $pos < $wpg );
                            $is_r  = ( ! $is_q && $bru > 0 && $pos < $wpg + $bru );
                            $rc    = $is_q ? 'lgw-gs-row-qualify' : ( $is_r ? 'lgw-gs-row-runners' : '' );
                            $diff  = $row['sf'] - $row['sa'];
                            $dc    = $diff > 0 ? 'lgw-gs-diff-pos' : ( $diff < 0 ? 'lgw-gs-diff-neg' : 'lgw-gs-diff-zero' );
                            // Badge lookup
                            $entry_str = $row['entry'];
                            $badge_url = '';
                            foreach ( $team_badges as $t => $url ) {
                                if ( stripos( $entry_str, $t ) !== false ) { $badge_url = $url; break; }
                            }
                            if ( ! $badge_url ) {
                                $club_lower = strtolower( trim( explode( ',', $entry_str, 2 )[1] ?? '' ) );
                                if ( $club_lower && isset( $club_badges[$club_lower] ) ) {
                                    $badge_url = $club_badges[$club_lower];
                                } else {
                                    foreach ( $club_badges as $k => $url ) {
                                        if ( strtolower($k) === $club_lower ) { $badge_url = $url; break; }
                                    }
                                }
                            }
                        ?>
                        <tr class="<?php echo $rc; ?>">
                            <td class="lgw-gs-pos"><?php echo $pos + 1; ?></td>
                            <td class="lgw-gs-crest lgw-gs-hide-sm">
                                <?php if ( $badge_url ): ?>
                                <img src="<?php echo esc_url( $badge_url ); ?>" alt="" class="lgw-gs-crest-img">
                                <?php endif; ?>
                            </td>
                            <td class="lgw-gs-name"><?php echo esc_html( lgw_gchamp_short_name( $row['entry'] ) ); ?></td>
                            <td class="lgw-gs-num lgw-gs-hide-xs"><?php echo $row['p']; ?></td>
                            <td class="lgw-gs-num lgw-gs-hide-xs"><?php echo $row['w']; ?></td>
                            <td class="lgw-gs-num lgw-gs-hide-xs"><?php echo $row['d']; ?></td>
                            <td class="lgw-gs-num lgw-gs-hide-xs"><?php echo $row['l']; ?></td>
                            <td class="lgw-gs-num"><?php echo $row['sf']; ?></td>
                            <td class="lgw-gs-num"><?php echo $row['sa']; ?></td>
                            <td class="lgw-gs-diff <?php echo $dc; ?>"><?php echo ( $diff > 0 ? '+' : '' ) . $diff; ?></td>
                            <td class="lgw-gs-pts"><?php echo $row['pts']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="lgw-gchamp-qualify-legend">
                        <span class="lgw-gchamp-qualify-legend-item">
                            <span class="lgw-gchamp-qualify-dot lgw-gchamp-qualify-dot-q"></span>
                            Top <?php echo $wpg; ?> qualify
                        </span>
                        <?php if ( $bru > 0 ): ?>
                        <span class="lgw-gchamp-qualify-legend-item">
                            <span class="lgw-gchamp-qualify-dot lgw-gchamp-qualify-dot-r"></span>
                            Best runners-up
                        </span>
                        <?php endif; ?>
                    </div>
                    <button class="lgw-gchamp-fixtures-toggle open" type="button">
                        Fixtures (<?php echo count( $g_fixtures ); ?>)
                        <span class="lgw-gchamp-fixtures-toggle-arrow">&#x25BC;</span>
                    </button>
                    <div class="lgw-gchamp-fixtures-body open">
                    <?php
                    $by_round = array();
                    foreach ( $g_fixtures as $fx ) $by_round[ $fx['round'] ][] = $fx;
                    ksort( $by_round );
                    foreach ( $by_round as $rn => $rfxs ):
                    foreach ( $rfxs as $fx ):
                        $hs     = $fx['home_score'];
                        $as_v   = $fx['away_score'];
                        $scored = ( $hs !== null && $as_v !== null );
                        $hw     = $scored && intval($hs) > intval($as_v);
                        $aw     = $scored && intval($as_v) > intval($hs);
                        $rc     = $scored ? ( $hw ? ' lgw-gf-home-win' : ( $aw ? ' lgw-gf-away-win' : '' ) ) : '';
                        $pk     = esc_attr( $fx['pos_key'] ?? '' );
                        $raw_g  = intval( substr( explode( ':', $fx['pos_key'] ?? 'g0:' )[0], 1 ) );
                        $fk_day = intval( $raw_g / 100 );
                        $fk_grp = $raw_g % 100;
                    ?>
                    <div class="lgw-gchamp-fixture-row<?php echo $rc; ?>"
                         data-pos-key="<?php echo $pk; ?>"
                         data-day-id="<?php echo $fk_day; ?>"
                         data-group-id="<?php echo $fk_grp; ?>"
                         data-context="group">
                        <span class="lgw-gchamp-fixture-round">R<?php echo $rn + 1; ?></span>
                        <span class="lgw-gchamp-fixture-teams">
                            <span class="lgw-gchamp-fixture-team"><?php echo esc_html( lgw_gchamp_short_name( $fx['home'] ) ); ?></span>
                            <span class="lgw-gchamp-fixture-vs">v</span>
                            <span class="lgw-gchamp-fixture-team"><?php echo esc_html( lgw_gchamp_short_name( $fx['away'] ) ); ?></span>
                        </span>
                        <?php
                    $group_locked = ! empty( $group['locked'] );
                    $score_open = $can_score && ! $group_locked && ! $ko_has_scores;
                    ?>
                    <?php if ( $score_open ): ?>
                        <span class="lgw-gchamp-score-entry">
                            <?php if ( $scored ): ?>
                            <span class="lgw-gchamp-fixture-score lgw-gchamp-score-display"><?php echo intval($hs); ?>&ndash;<?php echo intval($as_v); ?></span>
                            <button class="lgw-gchamp-score-edit-btn" type="button">&#x270F;</button>
                            <?php else: ?>
                            <button class="lgw-gchamp-score-add-btn" type="button">+ Score</button>
                            <?php endif; ?>
                            <span class="lgw-gchamp-score-form" style="display:none">
                                <input type="number" class="lgw-gchamp-score-h" min="0" step="1" value="<?php echo $scored ? intval($hs) : ''; ?>" placeholder="0">
                                <span class="lgw-gchamp-score-sep">&ndash;</span>
                                <input type="number" class="lgw-gchamp-score-a" min="0" step="1" value="<?php echo $scored ? intval($as_v) : ''; ?>" placeholder="0">
                                <button type="button" class="lgw-gchamp-score-save-btn">&#x2713;</button>
                                <?php if ( $scored ): ?><button type="button" class="lgw-gchamp-score-clear-btn">&#x2715;</button><?php endif; ?>
                                <button type="button" class="lgw-gchamp-score-cancel-btn">Cancel</button>
                            </span>
                            <span class="lgw-gchamp-score-saving" style="display:none">Saving&hellip;</span>
                        </span>
                        <?php elseif ( $scored ): ?>
                        <span class="lgw-gchamp-fixture-score"><?php echo intval($hs); ?>&ndash;<?php echo intval($as_v); ?></span>
                        <?php else: ?>
                        <span class="lgw-gchamp-fixture-score lgw-gchamp-fixture-score-tbd">TBD</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; endforeach; ?>
                    </div>
                </div><!-- group-card -->
                <?php endforeach; // groups ?>
                </div><!-- groups-grid -->

                <?php if ( $day_complete && ! empty( $day['qualifiers'] ) ): ?>
                <div class="lgw-gchamp-qualifiers-strip">
                    <span class="lgw-gchamp-qualifiers-label">&#x2705; KO bracket seeded from <?php echo count( $day['qualifiers'] ); ?> qualifiers</span>
                    <?php foreach ( $day['qualifiers'] as $idx => $q ): ?>
                    <span class="lgw-gchamp-qualifier-badge"><?php echo $idx + 1; ?>. <?php echo esc_html( lgw_gchamp_short_name( $q ) ); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div><!-- groups sub-pane -->

            <?php /* ── Knockout sub-pane ── */ ?>
            <div class="lgw-gchamp-sub-pane" data-sub-pane="knockout">
            <?php if ( $ko_bracket ):
                // Render KO bracket inline (no lgw-champ.js dependency)
                $rounds     = $ko_bracket['rounds'] ?? array();
                $num_rounds = count( $rounds );
                // Determine Finals Week qualifier threshold for display
                $num_days   = count( $days );
                $per_day_q  = intval( $day['finals_qualifiers'] ?? $champ['finals_qualifiers_per_day'] ?? (
                    $num_days === 1 ? 4 : ( $num_days === 2 ? 2 : max(1, intval(ceil(4/$num_days))) )
                ) );
                // 2-qualifier day with the day-final setting off: the final is a
                // Finals-Week fixture, not a day game — both finalists qualify.
                $play_day_final   = ! empty( $champ['ko_play_day_final'] );
                $final_not_played = ( $per_day_q === 2 && ! $play_day_final );
                // No-final days confirm their qualifiers at the semi stage, so
                // ko_complete goes true early — don't let that lock scoring
                // (semis stay editable and the final can still be recorded).
                $ko_locked        = $ko_complete && ! $final_not_played;
                $total_matches = 0;
                $played_matches = 0;
                foreach ($rounds as $ri_c => $round) {
                    $is_final_c = ( $round['round'] === $num_rounds - 1 );
                    if ( $final_not_played && $is_final_c ) continue; // not a scored day game
                    foreach ($round['matches'] as $m) { if (empty($m['bye'])) { $total_matches++; if ($m['home_score']!==null&&$m['away_score']!==null) $played_matches++; } }
                }
                // Manual seeding (admin): first-round slots can be reassigned to
                // any of this day's qualifiers. Build the option pool once.
                $can_seed_ko = current_user_can( 'manage_options' );
                $seed_pool   = array();
                foreach ( (array) ( $day['qualifier_slots'] ?? array() ) as $q ) { if ( $q !== null && $q !== '' ) $seed_pool[ $q ] = true; }
                foreach ( (array) ( $day['qualifiers']      ?? array() ) as $q ) { if ( $q !== null && $q !== '' ) $seed_pool[ $q ] = true; }
                $seed_pool = array_keys( $seed_pool );
            ?>
                <div class="lgw-gchamp-ko-wrap" data-day-id="<?php echo $day_idx; ?>">
                    <div class="lgw-gchamp-ko-header">
                        <span class="lgw-gchamp-ko-title"><?php echo esc_html( $day['name'] ); ?> — Knockout</span>
                        <span class="lgw-gchamp-ko-progress"><?php echo $played_matches; ?>/<?php echo $total_matches; ?> played</span>
                        <?php if ( current_user_can('manage_options') && $played_matches > 0 ): ?>
                        <button type="button"
                                class="lgw-gchamp-ko-clear-all-btn"
                                data-day-id="<?php echo $day_idx; ?>"
                                data-champ-id="<?php echo esc_attr( $gchamp_id ); ?>">
                            🗑 Clear KO scores
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="lgw-gchamp-ko-bracket-scroll">
                    <div class="lgw-gchamp-ko-bracket">
                    <?php foreach ( $rounds as $round ):
                        $is_final = ( $round['round'] === $num_rounds - 1 );
                        $is_sf    = ( $round['round'] === $num_rounds - 2 );
                        $qualifies_at_final   = ( $per_day_q >= 1 );
                        $qualifies_at_sf      = ( $per_day_q >= 4 );
                        $qualifies_at_sf_one  = ( $per_day_q === 3 );
                        $qualifies_at_f_loser = ( $per_day_q >= 2 );
                    ?>
                    <div class="lgw-gchamp-ko-round">
                        <div class="lgw-gchamp-ko-round-label">
                            <?php echo esc_html( $round['label'] ); ?>
                            <?php if ( $is_final && $final_not_played ): ?>
                                <span class="lgw-gchamp-ko-qualifies-note">&#x1F3C6; Played at Finals Week</span>
                            <?php elseif ( $is_final && $qualifies_at_final ): ?>
                                <span class="lgw-gchamp-ko-qualifies-note">&#x1F3C6; Finals Week</span>
                            <?php elseif ( $is_sf && ( $qualifies_at_sf || $qualifies_at_sf_one || $final_not_played ) ): ?>
                                <span class="lgw-gchamp-ko-qualifies-note">&#x1F3C6; Finals Week</span>
                            <?php endif; ?>
                        </div>
                        <?php foreach ( $round['matches'] as $match ):
                            $scored = ( $match['home_score'] !== null && $match['away_score'] !== null );
                            $is_bye = ! empty( $match['bye'] );
                            $hw     = $scored && intval($match['home_score']) > intval($match['away_score']);
                            $aw     = $scored && intval($match['away_score']) > intval($match['home_score']);
                            // First-round, non-bye slots are manually seedable by admins.
                            $slot_editable = $can_seed_ko && $round['round'] === 0 && ! $is_bye && ! empty( $seed_pool );
                        ?>
                        <div class="lgw-gchamp-ko-match<?php echo $is_bye ? ' lgw-gchamp-ko-match-bye' : ''; echo ($is_final&&$scored) ? ' lgw-gchamp-ko-match-final' : ''; ?>"
                             data-round="<?php echo intval($round['round']); ?>"
                             data-match="<?php echo intval($match['match']); ?>"
                             data-day-id="<?php echo $day_idx; ?>"
                             data-context="ko">
                            <div class="lgw-gchamp-ko-team<?php echo $hw ? ' lgw-gchamp-ko-team-win' : ($aw ? ' lgw-gchamp-ko-team-loss' : ''); ?>" data-slot="home">
                                <span class="lgw-gchamp-ko-team-name"><?php echo $match['home'] ? esc_html(lgw_gchamp_short_name($match['home'])) : '<span class="lgw-gchamp-ko-tbd">TBD</span>'; ?></span>
                                <?php if ( $scored ): ?><span class="lgw-gchamp-ko-score<?php echo $hw?' lgw-gchamp-ko-score-win':''; ?>"><?php echo intval($match['home_score']); ?></span><?php endif; ?>
                                <?php if ( $slot_editable ): ?><button type="button" class="lgw-gchamp-ko-seed-btn" title="Change entry (manual seed)">&#x270F;</button>
                                <span class="lgw-gchamp-ko-seed-form" style="display:none">
                                    <select class="lgw-gchamp-ko-seed-select">
                                        <option value="">&mdash; TBD &mdash;</option>
                                        <?php foreach ( $seed_pool as $opt ): ?>
                                        <option value="<?php echo esc_attr( $opt ); ?>"<?php selected( $opt, $match['home'] ); ?>><?php echo esc_html( lgw_gchamp_short_name( $opt ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="lgw-gchamp-ko-seed-save" title="Save">&#x2713;</button>
                                    <button type="button" class="lgw-gchamp-ko-seed-cancel" title="Cancel">&#x2715;</button>
                                </span><?php endif; ?>
                            </div>
                            <div class="lgw-gchamp-ko-team<?php echo $aw ? ' lgw-gchamp-ko-team-win' : ($hw ? ' lgw-gchamp-ko-team-loss' : ''); ?>" data-slot="away">
                                <span class="lgw-gchamp-ko-team-name"><?php echo $match['away'] ? esc_html(lgw_gchamp_short_name($match['away'])) : '<span class="lgw-gchamp-ko-tbd">TBD</span>'; ?></span>
                                <?php if ( $scored ): ?><span class="lgw-gchamp-ko-score<?php echo $aw?' lgw-gchamp-ko-score-win':''; ?>"><?php echo intval($match['away_score']); ?></span><?php endif; ?>
                                <?php if ( $slot_editable ): ?><button type="button" class="lgw-gchamp-ko-seed-btn" title="Change entry (manual seed)">&#x270F;</button>
                                <span class="lgw-gchamp-ko-seed-form" style="display:none">
                                    <select class="lgw-gchamp-ko-seed-select">
                                        <option value="">&mdash; TBD &mdash;</option>
                                        <?php foreach ( $seed_pool as $opt ): ?>
                                        <option value="<?php echo esc_attr( $opt ); ?>"<?php selected( $opt, $match['away'] ); ?>><?php echo esc_html( lgw_gchamp_short_name( $opt ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="lgw-gchamp-ko-seed-save" title="Save">&#x2713;</button>
                                    <button type="button" class="lgw-gchamp-ko-seed-cancel" title="Cancel">&#x2715;</button>
                                </span><?php endif; ?>
                            </div>
                            <?php if ( $is_final && $final_not_played && $match['home'] && $match['away'] ): ?>
                            <div class="lgw-gchamp-ko-finals-fixture-note">&#x1F3C6; Both qualify &middot; final is optional (played at Finals Week)</div>
                            <?php endif; ?>
                            <?php if ( $can_score && ! $is_bye && ! $ko_locked && $match['home'] && $match['away'] ): ?>
                            <div class="lgw-gchamp-ko-score-entry">
                                <?php if ( ! $scored ): ?>
                                <button type="button" class="lgw-gchamp-ko-score-btn">+ Score</button>
                                <?php else: ?>
                                <button type="button" class="lgw-gchamp-ko-score-btn lgw-gchamp-ko-score-edit-btn">&#x270F;</button>
                                <?php endif; ?>
                                <span class="lgw-gchamp-ko-score-form" style="display:none">
                                    <input type="number" class="lgw-gchamp-ko-score-h" min="0" step="1" value="<?php echo $scored?intval($match['home_score']):''; ?>" placeholder="0">
                                    <span class="lgw-gchamp-score-sep">&ndash;</span>
                                    <input type="number" class="lgw-gchamp-ko-score-a" min="0" step="1" value="<?php echo $scored?intval($match['away_score']):''; ?>" placeholder="0">
                                    <button type="button" class="lgw-gchamp-ko-save-btn">&#x2713;</button>
                                    <?php if ($scored): ?><button type="button" class="lgw-gchamp-ko-clear-btn">&#x2715;</button><?php endif; ?>
                                    <button type="button" class="lgw-gchamp-ko-cancel-btn">Cancel</button>
                                </span>
                                <span class="lgw-gchamp-ko-saving" style="display:none">Saving&hellip;</span>
                            </div>
                            <?php endif; ?>
                        </div><!-- ko-match -->
                        <?php endforeach; ?>
                    </div><!-- ko-round -->
                    <?php endforeach; ?>
                    </div><!-- ko-bracket -->
                    </div><!-- scroll -->

                    <?php if ( $ko_complete && ! empty( $ko_quals ) ): ?>
                    <div class="lgw-gchamp-ko-finals-strip">
                        <span class="lgw-gchamp-ko-finals-label">&#x1F3C6; Finals Week qualifiers from <?php echo esc_html($day['name']); ?>:</span>
                        <?php foreach ( $ko_quals as $idx => $q ): ?>
                        <span class="lgw-gchamp-ko-finals-badge"><?php echo $idx+1; ?>. <?php echo esc_html(lgw_gchamp_short_name($q)); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif ( ! $ko_complete ): ?>
                    <div class="lgw-gchamp-ko-finals-strip lgw-gchamp-ko-finals-strip-pending">
                        <?php
                        $qstage = $per_day_q . ' qualifier' . ( $per_day_q > 1 ? 's' : '' );
                        if ( $per_day_q === 1 )     $qstage .= ' (winner)';
                        elseif ( $per_day_q === 2 ) $qstage .= ' (finalist + runner-up)';
                        elseif ( $per_day_q === 3 ) $qstage .= ' (finalists + 1 semi-finalist)';
                        elseif ( $per_day_q >= 4 )  $qstage .= ' (semi-finalists)';
                        ?>
                        &#x1F3C6; <strong>Finals Week qualifiers:</strong> <?php echo esc_html($qstage); ?> — complete the knockout to confirm
                    </div>
                    <?php endif; ?>
                </div><!-- ko-wrap -->
            <?php else: ?>
                <div class="lgw-gchamp-empty" style="padding:32px 20px">
                    <div class="lgw-gchamp-empty-icon">&#x1F3C6;</div>
                    <p>Knockout bracket will appear as groups complete. Confirmed qualifiers are seeded immediately — TBD slots fill as remaining groups finish.</p>
                </div>
            <?php endif; ?>
            </div><!-- knockout sub-pane -->

        </div><!-- day-pane -->
        <?php endforeach; // days ?>

        <?php endif; // drawn ?>

        <?php /* ── Finals Week pane ── */ ?>
        <?php if ( $show_finals ): ?>
        <div class="lgw-gchamp-day-pane" data-day-tab="finals" data-day-pane="finals">
            <div class="lgw-gchamp-finals-pane">

                <?php
                // Always rebuild from source: cheap, preserves entered scores /
                // schedule, resolves confirmed qualifiers into their seeded
                // positions and propagates winners. Persist only when changed.
                $built = lgw_gchamp_build_finals_matches( $champ );
                if ( ! empty( $built ) && $built !== ( $champ['finals_matches'] ?? null ) ) {
                    $champ['finals_matches'] = $built;
                    update_option( 'lgw_gchamp_' . $gchamp_id, $champ );
                }
                $finals_matches = $champ['finals_matches'] ?? array();

                // Source → label map for the manual-seeding dropdowns. Prefer the
                // resolved name once a day's qualifiers are known so admins pick a
                // person, not "Day 1 Winner".
                $finals_src_label = array();
                foreach ( lgw_gchamp_finals_slots( $champ ) as $s ) {
                    $finals_src_label[ $s['src'] ] = $s['name']
                        ? lgw_gchamp_short_name( $s['name'] ) . ' — ' . $s['label']
                        : $s['label'] . ' (TBD)';
                }

                // The draw can be rearranged until the finals actually start (no
                // scores / live ends anywhere). After that, seed positions lock.
                $finals_started = false;
                foreach ( $finals_matches as $_fm ) {
                    if ( ( $_fm['home_score'] !== null && $_fm['away_score'] !== null ) || ! empty( $_fm['ends'] ) ) { $finals_started = true; break; }
                }
                // Emits the pencil + qualifier-source dropdown for a seed slot.
                // Available on both unresolved (pending) and resolved seed slots so
                // the draw stays movable once names have flowed forward.
                $seed_ctrl = function( $match, $side ) use ( $can_score, $finals_src_label, $gchamp_id, $finals_started ) {
                    if ( ! $can_score || $finals_started || empty( $finals_src_label ) ) return;
                    $seed = $match[ $side . '_seed' ] ?? null;
                    if ( $seed === null ) return; // prev-linked (Winner of QFx) — not a draw position
                    $src = $match[ $side . '_src' ] ?? null;
                    echo '<button type="button" class="lgw-gchamp-finals-seed-btn" title="Move this qualifier to another draw position">&#x270F;</button>';
                    echo '<span class="lgw-gchamp-finals-seed-form" style="display:none" data-seed="' . intval( $seed ) . '" data-champ-id="' . esc_attr( $gchamp_id ) . '">';
                    echo '<select class="lgw-gchamp-finals-seed-select">';
                    foreach ( $finals_src_label as $osrc => $olabel ) {
                        echo '<option value="' . esc_attr( $osrc ) . '"' . selected( $osrc, $src, false ) . '>' . esc_html( $olabel ) . '</option>';
                    }
                    echo '</select>';
                    echo '<button type="button" class="lgw-gchamp-finals-seed-save" title="Save">&#x2713;</button>';
                    echo '<button type="button" class="lgw-gchamp-finals-seed-cancel" title="Cancel">&#x2715;</button>';
                    echo '</span>';
                };

                $club_badges = get_option( 'lgw_club_badges', array() );
                $team_badges = get_option( 'lgw_badges',      array() );
                $nonce_finals = wp_create_nonce( 'lgw_gchamp_score' );

                // Group by round
                $by_round = array();
                foreach ( $finals_matches as $mi => $match ) {
                    $by_round[ $match['round'] ][] = array_merge( $match, array( '_idx' => $mi ) );
                }
                ?>

                <div class="lgw-gchamp-finals-header">
                    <span class="lgw-gchamp-finals-title">&#x1F3C6; Finals Week</span>
                    <span class="lgw-gchamp-finals-subtitle">
                        <?php
                        $confirmed = array_filter( array_map( fn($d) => $d['ko_qualifiers'] ?? array(), $days ) );
                        $total_q   = array_sum( array_map( 'count', $confirmed ) );
                        $total_days= count( $days );
                        $done_days = count( array_filter( $days, fn($d) => ! empty( $d['ko_complete'] ) ) );
                        echo esc_html( $done_days . ' of ' . $total_days . ' section' . ( $total_days !== 1 ? 's' : '' ) . ' confirmed · ' . $total_q . ' qualifier' . ( $total_q !== 1 ? 's' : '' ) );
                        ?>
                    </span>
                </div>

                <?php foreach ( $by_round as $round_name => $round_matches ): ?>
                <div class="lgw-gchamp-finals-round">
                    <div class="lgw-gchamp-finals-round-label"><?php echo esc_html( $round_name ); ?></div>
                    <?php foreach ( $round_matches as $match ):
                        $mi        = $match['_idx'];
                        $home      = $match['home']  ?? null;
                        $away      = $match['away']  ?? null;
                        $hs        = $match['home_score'] ?? null;
                        $as_v      = $match['away_score'] ?? null;
                        $has_score = $hs !== null && $as_v !== null;
                        $ends      = $match['ends']  ?? array();
                        $dt        = $match['finals_datetime'] ?? '';
                        $rink      = $match['finals_rink']     ?? '';
                        $pending   = ! $home || ! $away;
                        $mid       = 'gf-' . $gchamp_id . '-' . $mi;
                        $status_cls = $pending   ? 'lgw-finals-match--pending'
                                    : ($has_score ? 'lgw-finals-match--complete' : 'lgw-finals-match--upcoming');
                        // Running ends total
                        $ht = 0; $at = 0;
                        foreach ( $ends as $end ) { $ht += intval($end[0]??0); $at += intval($end[1]??0); }
                        // Badges
                        $home_badge = $away_badge = '';
                        foreach ( $team_badges as $t => $url ) {
                            if ( $home && stripos($home,$t)!==false ) $home_badge=$url;
                            if ( $away && stripos($away,$t)!==false ) $away_badge=$url;
                        }
                        if (!$home_badge && $home) { $hc=strtolower(trim(explode(',',$home,2)[1]??'')); $home_badge=$club_badges[$hc]??''; }
                        if (!$away_badge && $away) { $ac=strtolower(trim(explode(',',$away,2)[1]??'')); $away_badge=$club_badges[$ac]??''; }
                    ?>
                    <div class="lgw-finals-match <?php echo $status_cls; ?>"
                         id="lgw-fm-<?php echo esc_attr($mid); ?>"
                         data-mid="<?php echo esc_attr($mid); ?>"
                         data-match-idx="<?php echo $mi; ?>"
                         data-champ-id="<?php echo esc_attr($gchamp_id); ?>">

                        <?php if ( $dt || $rink || $can_score ): ?>
                        <div class="lgw-finals-datetime<?php echo (!$dt && !$rink) ? ' lgw-finals-datetime--unset' : ''; ?>">
                            <?php if ( $dt ): ?>
                            <span class="lgw-finals-datetime-val"><?php echo esc_html( function_exists('lgw_finals_format_datetime') ? lgw_finals_format_datetime($dt) : $dt ); ?></span>
                            <?php endif; ?>
                            <?php if ( $rink ): ?>
                            <span class="lgw-finals-rink-val">Rink <?php echo esc_html($rink); ?></span>
                            <?php endif; ?>
                            <?php if ( $can_score ): ?>
                            <button class="lgw-finals-edit-dt" data-mid="<?php echo esc_attr($mid); ?>" title="Set date, time &amp; rink">
                                <?php echo ($dt||$rink) ? '&#x270F;&#xFE0F;' : '&#x1F4C5; Set date, time &amp; rink'; ?>
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ( $pending ): ?>
                        <div class="lgw-finals-teams lgw-finals-teams--pending">
                            <?php
                            // One placeholder side: the resolved name if the KO
                            // has confirmed it, else the source label ("Day 1
                            // Winner" / "Winner of QF1"). A seed slot also gets an
                            // admin dropdown to set which qualifier takes it.
                            $render_ph = function( $side ) use ( $match, $seed_ctrl ) {
                                $name  = $match[ $side ] ?? null;
                                $label = $match[ $side . '_label' ] ?? 'TBD';
                                echo '<span class="lgw-finals-ph-slot">';
                                if ( $name ) {
                                    echo '<span class="lgw-finals-ph-name">' . esc_html( lgw_gchamp_short_name( $name ) ) . '</span>';
                                } else {
                                    echo '<span class="lgw-finals-ph-label">' . esc_html( $label ) . '</span>';
                                }
                                $seed_ctrl( $match, $side );
                                echo '</span>';
                            };
                            $render_ph( 'home' );
                            ?>
                            <span class="lgw-finals-vs">v</span>
                            <?php $render_ph( 'away' ); ?>
                        </div>
                        <?php else: ?>
                        <div class="lgw-finals-teams">
                            <div class="lgw-finals-team lgw-finals-team--home">
                                <?php if ($home_badge): ?><img src="<?php echo esc_url($home_badge); ?>" class="lgw-finals-badge" alt=""><?php endif; ?>
                                <div class="lgw-finals-team-info">
                                    <span class="lgw-finals-team-name"><?php echo esc_html( lgw_gchamp_short_name($home) ); ?><?php $seed_ctrl( $match, 'home' ); ?></span>
                                    <?php $hclub = trim(explode(',',$home,2)[1]??''); if ($hclub): ?>
                                    <span class="lgw-finals-team-club"><?php echo esc_html($hclub); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="lgw-finals-score-block">
                                <?php if ($has_score): ?>
                                    <span class="lgw-finals-score lgw-finals-score--home<?php echo intval($hs)>intval($as_v)?' lgw-finals-score--win':''; ?>"><?php echo intval($hs); ?></span>
                                    <span class="lgw-finals-score-sep">&#x2013;</span>
                                    <span class="lgw-finals-score lgw-finals-score--away<?php echo intval($as_v)>intval($hs)?' lgw-finals-score--win':''; ?>"><?php echo intval($as_v); ?></span>
                                <?php elseif (!empty($ends)): ?>
                                    <span class="lgw-finals-score lgw-finals-score--live"><?php echo $ht; ?></span>
                                    <span class="lgw-finals-score-sep">&#x2013;</span>
                                    <span class="lgw-finals-score lgw-finals-score--live"><?php echo $at; ?></span>
                                    <span class="lgw-finals-live-badge">LIVE</span>
                                <?php else: ?>
                                    <span class="lgw-finals-score-placeholder">v</span>
                                <?php endif; ?>
                                <?php if ( $can_score && !$pending ): ?>
                                <button class="lgw-finals-edit-score" data-mid="<?php echo esc_attr($mid); ?>" title="Enter score">&#x270F;&#xFE0F;</button>
                                <?php endif; ?>
                            </div>
                            <div class="lgw-finals-team lgw-finals-team--away">
                                <div class="lgw-finals-team-info">
                                    <span class="lgw-finals-team-name"><?php echo esc_html( lgw_gchamp_short_name($away) ); ?><?php $seed_ctrl( $match, 'away' ); ?></span>
                                    <?php $aclub = trim(explode(',',$away,2)[1]??''); if ($aclub): ?>
                                    <span class="lgw-finals-team-club"><?php echo esc_html($aclub); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($away_badge): ?><img src="<?php echo esc_url($away_badge); ?>" class="lgw-finals-badge" alt=""><?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($ends)): ?>
                        <div class="lgw-finals-ends" id="lgw-ends-<?php echo esc_attr($mid); ?>">
                            <?php
                            if (function_exists('lgw_finals_render_ends_table')) {
                                echo lgw_finals_render_ends_table($ends, $home, $away, $can_score, $mid, true);
                            }
                            ?>
                        </div>
                        <?php elseif ($can_score && !$has_score): ?>
                        <div class="lgw-finals-ends" id="lgw-ends-<?php echo esc_attr($mid); ?>">
                            <div class="lgw-finals-ends-empty">
                                <button class="lgw-finals-add-end-btn" data-mid="<?php echo esc_attr($mid); ?>">+ Start live scoring</button>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endif; // !pending ?>

                    </div><!-- .lgw-finals-match -->
                    <?php endforeach; ?>
                </div><!-- .lgw-gchamp-finals-round -->
                <?php endforeach; ?>

            </div><!-- .lgw-gchamp-finals-pane -->
        </div><!-- finals day-pane -->

        <script>
        (function(){
            if(typeof lgwFinalsData==='undefined') window.lgwFinalsData={};
            var matches={};
            <?php foreach ($finals_matches as $mi => $match):
                $mid = 'gf-' . $gchamp_id . '-' . $mi; ?>
            matches[<?php echo wp_json_encode($mid); ?>]={
                champId:   <?php echo wp_json_encode($gchamp_id); ?>,
                matchIdx:  <?php echo $mi; ?>,
                home:      <?php echo wp_json_encode($match['home']??null); ?>,
                away:      <?php echo wp_json_encode($match['away']??null); ?>,
                homeScore: <?php echo wp_json_encode($match['home_score']??null); ?>,
                awayScore: <?php echo wp_json_encode($match['away_score']??null); ?>,
                ends:      <?php echo wp_json_encode($match['ends']??array()); ?>,
                datetime:  <?php echo wp_json_encode($match['finals_datetime']??''); ?>,
                rink:      <?php echo wp_json_encode($match['finals_rink']??''); ?>,
                nonce:     <?php echo wp_json_encode(wp_create_nonce('lgw_gchamp_score')); ?>,
                isGchamp:  true,
            };
            <?php endforeach; ?>
            lgwFinalsData.matches = Object.assign(lgwFinalsData.matches||{}, matches);
            lgwFinalsData.nonce   = <?php echo wp_json_encode(wp_create_nonce('lgw_gchamp_score')); ?>;
            lgwFinalsData.isAdmin = <?php echo $can_score ? '1' : '0'; ?>;
        })();
        </script>
        <?php endif; ?>

        <div class="lgw-gchamp-status">
            <span class="lgw-gchamp-status-dot<?php echo ($drawn && !$gs_complete) ? ' live' : ''; ?>"></span>
            <span class="lgw-gchamp-status-text">
            <?php
            if (!$drawn) { echo 'Awaiting draw&hellip;'; }
            elseif ($gs_complete) { echo 'Group stage complete'; }
            else {
                $tfx=0; $played=0;
                foreach ($days as $day) foreach ($day['groups']??array() as $g) {
                    $tfx   += count($g['fixtures']??array());
                    $played += lgw_gchamp_count_played($g['fixtures']??array());
                }
                echo esc_html($played.' of '.$tfx.' group fixtures played');
            }
            ?>
            </span>
        </div>

    </div><!-- .lgw-gchamp-wrap -->
    <?php
    return ob_get_clean();
}
