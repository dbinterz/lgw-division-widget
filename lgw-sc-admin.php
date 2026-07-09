<?php
/**
 * LGW Scorecard Admin Edit + Audit Trail
 * Full admin edit of any scorecard field, with before/after audit logging.
 */

// ── Audit helpers ─────────────────────────────────────────────────────────────

/**
 * Append an entry to a scorecard's audit log.
 *
 * @param int    $post_id   Scorecard post ID
 * @param string $action    e.g. 'edited', 'confirmed', 'resolved', 'submitted'
 * @param string $note      Human-readable summary
 * @param array  $before    Snapshot of data before change (optional)
 * @param array  $after     Snapshot of data after change (optional)
 */
function lgw_audit_log($post_id, $action, $note, $before = array(), $after = array()) {
    $log   = get_post_meta($post_id, 'lgw_audit_log', true) ?: array();
    $log[] = array(
        'ts'     => current_time('mysql'),
        'user'   => is_user_logged_in() ? wp_get_current_user()->user_login : 'system',
        'action' => $action,
        'note'   => $note,
        'before' => $before,
        'after'  => $after,
    );
    update_post_meta($post_id, 'lgw_audit_log', $log);
}

/**
 * Build a compact diff summary between two scorecard data arrays.
 * Returns a human-readable list of what changed.
 */
function lgw_audit_diff($before, $after) {
    $changes = array();

    $scalar_fields = array(
        'home_team'   => 'Home team',
        'away_team'   => 'Away team',
        'date'        => 'Date',
        'venue'       => 'Venue',
        'division'    => 'Division',
        'competition' => 'Competition',
        'home_total'  => 'Home total shots',
        'away_total'  => 'Away total shots',
        'home_points' => 'Home points',
        'away_points' => 'Away points',
    );

    foreach ($scalar_fields as $key => $label) {
        $b = $before[$key] ?? '';
        $a = $after[$key]  ?? '';
        if ((string)$b !== (string)$a) {
            $changes[] = $label . ': "' . $b . '" → "' . $a . '"';
        }
    }

    // Rink-level diff
    $before_rinks = array();
    foreach (($before['rinks'] ?? array()) as $rk) {
        $before_rinks[$rk['rink']] = $rk;
    }
    foreach (($after['rinks'] ?? array()) as $rk) {
        $rn  = $rk['rink'];
        $brk = $before_rinks[$rn] ?? array();
        if (($brk['home_score'] ?? '') !== ($rk['home_score'] ?? '')) {
            $changes[] = 'Rink ' . $rn . ' home score: "' . ($brk['home_score'] ?? '') . '" → "' . $rk['home_score'] . '"';
        }
        if (($brk['away_score'] ?? '') !== ($rk['away_score'] ?? '')) {
            $changes[] = 'Rink ' . $rn . ' away score: "' . ($brk['away_score'] ?? '') . '" → "' . $rk['away_score'] . '"';
        }
        // Players
        $bp_home = $brk['home_players'] ?? array();
        $ap_home = $rk['home_players']  ?? array();
        if ($bp_home !== $ap_home) {
            $changes[] = 'Rink ' . $rn . ' home players: [' . implode(', ', $bp_home) . '] → [' . implode(', ', $ap_home) . ']';
        }
        $bp_away = $brk['away_players'] ?? array();
        $ap_away = $rk['away_players']  ?? array();
        if ($bp_away !== $ap_away) {
            $changes[] = 'Rink ' . $rn . ' away players: [' . implode(', ', $bp_away) . '] → [' . implode(', ', $ap_away) . ']';
        }
    }

    return $changes;
}

// ── Hook audit into existing confirmation/resolution flows ────────────────────

// Called when second club confirms a matching scorecard (lgw-scorecards.php)
function lgw_audit_on_confirm($post_id, $club) {
    lgw_audit_log($post_id, 'confirmed',
        'Confirmed by ' . $club . ' — scores matched'
    );
}

// Called when admin resolves a disputed scorecard
function lgw_audit_on_resolve($post_id, $version, $sub_by, $con_by) {
    if ($version === 'admin_edit') {
        $note = 'Admin edited scorecard directly to resolve dispute — both versions overridden';
    } elseif ($version === 'away') {
        $note = 'Admin accepted Version B (' . $con_by . ') to resolve dispute';
    } else {
        $note = 'Admin accepted Version A (' . $sub_by . ') to resolve dispute';
    }
    lgw_audit_log($post_id, 'resolved', $note);
}

// ── AJAX: save admin edit ─────────────────────────────────────────────────────
add_action('wp_ajax_lgw_admin_edit_scorecard', 'lgw_ajax_admin_edit_scorecard');
function lgw_ajax_admin_edit_scorecard() {
    try {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    check_ajax_referer('lgw_admin_nonce', 'nonce');

    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Missing post ID');

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'lgw_scorecard') wp_send_json_error('Scorecard not found');

    // Snapshot before
    $before = get_post_meta($post_id, 'lgw_scorecard_data', true) ?: array();

    // Build updated scorecard from POST
    $after = array(
        'home_team'   => sanitize_text_field($_POST['home_team']   ?? ''),
        'away_team'   => sanitize_text_field($_POST['away_team']   ?? ''),
        'date'        => sanitize_text_field($_POST['match_date']  ?? ''),
        'venue'       => sanitize_text_field($_POST['venue']       ?? ''),
        'division'    => sanitize_text_field($_POST['division']    ?? ''),
        'competition' => sanitize_text_field($_POST['competition'] ?? ''),
        'home_total'  => floatval($_POST['home_total']  ?? 0),
        'away_total'  => floatval($_POST['away_total']  ?? 0),
        'home_points' => floatval($_POST['home_points'] ?? 0),
        'away_points' => floatval($_POST['away_points'] ?? 0),
        'rinks'       => array(),
    );

    // Rinks
    $rink_nums    = $_POST['rink_num']         ?? array();
    $home_scores  = $_POST['rink_home_score']  ?? array();
    $away_scores  = $_POST['rink_away_score']  ?? array();
    $home_players = $_POST['rink_home_players'] ?? array(); // array of comma-separated strings
    $away_players = $_POST['rink_away_players'] ?? array();

    foreach ($rink_nums as $i => $rn) {
        $hp = array_filter(array_map('sanitize_text_field',
            explode(',', $home_players[$i] ?? '')));
        $ap = array_filter(array_map('sanitize_text_field',
            explode(',', $away_players[$i] ?? '')));
        $hs_raw = $home_scores[$i] ?? '';
        $as_raw = $away_scores[$i] ?? '';
        $after['rinks'][] = array(
            'rink'         => intval($rn),
            'home_score'   => is_numeric($hs_raw) ? floatval($hs_raw) : null,
            'away_score'   => is_numeric($as_raw) ? floatval($as_raw) : null,
            'home_players' => array_values($hp),
            'away_players' => array_values($ap),
        );
    }

    // Diff
    $changes = lgw_audit_diff($before, $after);
    $note    = empty($changes)
        ? 'Admin edited scorecard (no changes detected)'
        : 'Admin edited: ' . implode('; ', $changes);

    // Save
    update_post_meta($post_id, 'lgw_scorecard_data', $after);
    update_post_meta($post_id, 'lgw_admin_edited',   current_time('mysql'));

    // If the scorecard was disputed, an admin edit is the authoritative resolution —
    // override both versions, clear the dispute, and confirm.
    $pre_save_status = get_post_meta($post_id, 'lgw_sc_status', true) ?: 'pending';
    if ($pre_save_status === 'disputed') {
        $sub_by_res = get_post_meta($post_id, 'lgw_submitted_by', true);
        $con_by_res = get_post_meta($post_id, 'lgw_confirmed_by', true);
        update_post_meta($post_id, 'lgw_sc_status',      'confirmed');
        update_post_meta($post_id, 'lgw_away_scorecard', '');
        update_post_meta($post_id, 'lgw_confirmed_by',   wp_get_current_user()->user_login);
        lgw_audit_on_resolve($post_id, 'admin_edit', $sub_by_res, $con_by_res);
    }
    // Ensure sc_context is set, and self-heal cup scorecards that were mis-tagged
    // as league (their division is a cup title — see lgw_scorecard_is_cup_by_data).
    // Blindly defaulting missing context to league used to cement cup scorecards
    // as league on the first admin save, leaving them permanently "unresolved".
    $existing_ctx = get_post_meta($post_id, 'lgw_sc_context', true);
    $is_cup_data  = function_exists('lgw_scorecard_is_cup_by_data') && lgw_scorecard_is_cup_by_data($after);
    if ($is_cup_data) {
        if ($existing_ctx !== 'cup') update_post_meta($post_id, 'lgw_sc_context', 'cup');
        delete_post_meta($post_id, 'lgw_division_unresolved');
    } elseif (empty($existing_ctx)) {
        update_post_meta($post_id, 'lgw_sc_context', 'league');
    }
    // Clear division-unresolved flag if division now maps to a known sheet tab
    $drive_opts    = get_option('lgw_drive', array());
    $resolved_tab  = lgw_sheets_tab_for_division($after['division'], $drive_opts);
    if (!empty($after['division']) && $resolved_tab) {
        delete_post_meta($post_id, 'lgw_division_unresolved');
    }

    // Update post title if teams changed
    $new_title = $after['home_team'] . ' v ' . $after['away_team'] . ' (' . $after['date'] . ')';
    wp_update_post(array('ID' => $post_id, 'post_title' => $new_title));

    // Audit
    lgw_audit_log($post_id, 'edited', $note, $before, $after);

    // Re-log appearances (idempotent — deletes and re-inserts for this scorecard)
    lgw_log_appearances($post_id);

    // Prune orphaned player records left by name corrections (players with zero appearances)
    if (function_exists('lgw_prune_orphaned_players')) {
        lgw_prune_orphaned_players();
    }

    // Fire action — triggers Drive upload (versioned) and sheets writeback
    // Wrapped separately so a Drive/Sheets failure doesn't 500 the whole request
    $skip_sheets_edit = !empty($_POST['skip_sheets']) && current_user_can('manage_options');
    if ($skip_sheets_edit) {
        remove_action('lgw_scorecard_admin_edited', 'lgw_sheets_on_confirmed');
        update_post_meta($post_id, 'lgw_skip_google', 1);
    }
    try {
        do_action('lgw_scorecard_admin_edited', $post_id);
    } catch (\Throwable $e) {
        // Log but don't fail the save
        lgw_audit_log($post_id, 'error', 'Post-save action failed: ' . $e->getMessage());
    }
    if ($skip_sheets_edit) {
        add_action('lgw_scorecard_admin_edited', 'lgw_sheets_on_confirmed');
        delete_post_meta($post_id, 'lgw_skip_google');
    }

    wp_send_json_success(array('message' => 'Scorecard updated. ✅'));

    } catch (\Throwable $e) {
        wp_send_json_error('Server error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

// ── Meta boxes on the native WP post edit screen ─────────────────────────────
// ── save_post hook: keep score overrides and appearances in sync when the WP
// post editor's "Update" button is used (e.g. arriving via the player modal link).
add_action('save_post_lgw_scorecard', 'lgw_sc_on_save_post', 20, 1);
function lgw_sc_on_save_post($post_id) {
    // Skip autosaves, revisions, and bulk-edit requests
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Only run for confirmed scorecards (ignore draft/pending saves)
    $status = get_post_meta($post_id, 'lgw_sc_status', true);
    if (!in_array($status, array('confirmed', 'submitted'), true)) return;

    // Re-log appearances (idempotent)
    if (function_exists('lgw_log_appearances')) {
        lgw_log_appearances($post_id);
    }
    // Sync score overrides so the widget reflects the latest totals
    if (function_exists('lgw_sync_override_from_scorecard')) {
        lgw_sync_override_from_scorecard($post_id);
    }
}

add_action( 'add_meta_boxes', 'lgw_sc_register_meta_boxes' );
function lgw_sc_register_meta_boxes() {
    add_meta_box(
        'lgw_sc_edit',
        '✏️ Scorecard',
        'lgw_sc_meta_box_edit',
        'lgw_scorecard',
        'normal',
        'high'
    );
    add_meta_box(
        'lgw_sc_audit',
        '📋 Audit Log',
        'lgw_sc_meta_box_audit',
        'lgw_scorecard',
        'normal',
        'default'
    );
}

function lgw_sc_meta_box_edit( $post ) {
    // Enqueue the admin JS/CSS needed for the save handler
    wp_enqueue_style( 'lgw-admin-css',   plugin_dir_url( __FILE__ ) . 'lgw-admin.css',   array(), LGW_VERSION );
    wp_enqueue_script( 'lgw-admin-js',   plugin_dir_url( __FILE__ ) . 'lgw-admin.js',    array('jquery'), LGW_VERSION, true );
    wp_localize_script( 'lgw-admin-js', 'lgwAdminData', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('lgw_admin_nonce'),
    ) );
    $sc = get_post_meta( $post->ID, 'lgw_scorecard_data', true );
    lgw_render_admin_edit_form( $post->ID, $sc );
}

function lgw_sc_meta_box_audit( $post ) {
    lgw_render_audit_log( $post->ID );
}

// Hide the unnecessary default meta boxes on this CPT edit screen
add_action( 'admin_head', 'lgw_sc_hide_default_boxes' );
function lgw_sc_hide_default_boxes() {
    $screen = get_current_screen();
    if ( !$screen || $screen->id !== 'lgw_scorecard' ) return;
    echo '<style>
        #submitdiv .misc-pub-post-status,
        #submitdiv .misc-pub-visibility,
        #misc-publishing-actions .misc-pub-post-status,
        #misc-publishing-actions .misc-pub-visibility,
        #minor-publishing-actions,
        #slugdiv, #authordiv, #commentstatusdiv, #commentsdiv,
        #revisionsdiv, #trackbacksdiv
        { display:none !important }
        #post-body-content { display:none !important }
        #titlediv { margin-bottom:0 }
        #submitdiv { min-width:0 }
    </style>';
}

// ── Admin edit form renderer ──────────────────────────────────────────────────
function lgw_render_admin_edit_form($post_id, $sc) {
    if (!$sc) { echo '<p>No scorecard data to edit.</p>'; return; }
    $rinks           = $sc['rinks'] ?? array();
    $drive_opts      = get_option('lgw_drive', array());
    $known_divisions = array_map(function($e){ return $e['division']; }, $drive_opts['sheets_tabs'] ?? array());
    $sc_ctx          = get_post_meta($post_id, 'lgw_sc_context', true) ?: 'league';
    $mc_game_id      = get_post_meta($post_id, 'lgw_multichamp_game_id', true);
    // Treat cup scorecards as cup even if the context meta is stale/mis-tagged —
    // their division is a cup title and never maps to a league sheet tab.
    $is_league_ctx  = ($sc_ctx === 'league') && !(function_exists('lgw_scorecard_is_cup_by_data') && lgw_scorecard_is_cup_by_data($sc));
    // Re-evaluate live so a stale flag doesn't persist after the division is corrected
    $resolved_tab   = $is_league_ctx ? lgw_sheets_tab_for_division($sc['division'] ?? '', $drive_opts) : true;
    $div_unresolved = $is_league_ctx && (empty($sc['division']) || !$resolved_tab);
    if (!$div_unresolved) {
        // Proactively clear any stale flag
        delete_post_meta($post_id, 'lgw_division_unresolved');
    }
    ?>
    <?php
    $edit_status     = get_post_meta($post_id, 'lgw_sc_status', true) ?: 'pending';
    $is_disputed_edit = ($edit_status === 'disputed');
    ?>
    <div class="lgw-edit-form" id="lgw-edit-<?php echo $post_id; ?>"<?php if ($is_disputed_edit) echo ' data-disputed="1"'; ?>>
        <h4 style="margin:0 0 12px;color:#1a2e5a">✏️ Edit Scorecard</h4>

        <?php if ($is_disputed_edit): ?>
        <div class="lgw-disputed-edit-notice" style="background:#f8d7da;border:1px solid #f1aeb5;border-radius:4px;padding:10px 14px;margin-bottom:14px;font-size:13px;display:flex;align-items:flex-start;gap:10px">
            <span style="font-size:16px;line-height:1.3">⚠️</span>
            <div>
                <strong>This scorecard is disputed.</strong><br>
                <span style="color:#842029">Saving your edits will override both submitted versions and mark the scorecard as <strong>Confirmed</strong>. The dispute will be resolved.</span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($mc_game_id):
            // Parse: champ_id:fix_id:disc_id:game_idx
            $mc_parts = explode(':', $mc_game_id);
            $mc_champ = get_option('lgw_multichamp_' . ($mc_parts[0] ?? ''), []);
            $mc_edit_url = admin_url('admin.php?page=lgw-multichamp&action=edit&id=' . ($mc_parts[0] ?? '') . '&tab=scores');
        ?>
        <div style="background:#eaf3de;border:1px solid #b7d9a0;border-radius:4px;padding:8px 12px;margin-bottom:14px;font-size:12px;display:flex;align-items:center;gap:12px;">
            <span>🏅 <strong>Multi-Discipline Championship scorecard</strong></span>
            <span><?php echo esc_html($mc_champ['title'] ?? $mc_parts[0] ?? ''); ?></span>
            <span>·</span>
            <span><?php echo esc_html(ucfirst($mc_parts[2] ?? '')); ?><?php if (!empty($mc_parts[3])) echo ' game ' . ((int)$mc_parts[3] + 1); ?></span>
            <a href="<?php echo esc_url($mc_edit_url); ?>" style="margin-left:auto">← Back to championship scores</a>
        </div>
        <?php endif; ?>

        <div class="lgw-edit-grid">
            <div class="lgw-edit-row">
                <label>Home Team</label>
                <input type="text" name="home_team" value="<?php echo esc_attr($sc['home_team'] ?? ''); ?>" class="regular-text">
            </div>
            <div class="lgw-edit-row">
                <label>Away Team</label>
                <input type="text" name="away_team" value="<?php echo esc_attr($sc['away_team'] ?? ''); ?>" class="regular-text">
            </div>
            <div class="lgw-edit-row">
                <label>Date Played</label>
                <input type="text" name="match_date" value="<?php echo esc_attr($sc['date'] ?? ''); ?>" placeholder="dd/mm/yyyy" class="medium-text">
            </div>
            <div class="lgw-edit-row">
                <label>Venue</label>
                <input type="text" name="venue" value="<?php echo esc_attr($sc['venue'] ?? ''); ?>" class="regular-text">
            </div>
            <div class="lgw-edit-row">
                <label>Division
                    <?php if ($div_unresolved): ?>
                        <span style="font-size:11px;background:#f8d7da;color:#842029;padding:1px 6px;border-radius:3px;font-weight:600;margin-left:6px">⚠️ Unresolved — sheet writeback blocked</span>
                    <?php endif; ?>
                </label>
                <input type="text" name="division" value="<?php echo esc_attr($sc['division'] ?? ''); ?>" class="regular-text"
                    <?php if ($div_unresolved): ?>style="border-color:#dc3545"<?php endif; ?>>
                <?php if (!empty($known_divisions)): ?>
                    <span style="font-size:11px;color:#666;margin-top:2px">
                        Known: <?php echo esc_html(implode(', ', $known_divisions)); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="lgw-edit-row">
                <label>Competition</label>
                <input type="text" name="competition" value="<?php echo esc_attr($sc['competition'] ?? ''); ?>" class="regular-text">
            </div>
        </div>

        <h4 style="margin:16px 0 8px;color:#1a2e5a">Rinks</h4>
        <table class="widefat lgw-edit-rinks-table">
            <thead>
                <tr>
                    <th style="width:50px">Rink</th>
                    <th>Home Players <small style="font-weight:400">(comma-separated)</small></th>
                    <th style="width:80px">Home Score</th>
                    <th style="width:80px">Away Score</th>
                    <th>Away Players <small style="font-weight:400">(comma-separated)</small></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rinks as $i => $rk): ?>
                <tr>
                    <td>
                        <input type="hidden" name="rink_num[]" value="<?php echo intval($rk['rink']); ?>">
                        <strong><?php echo intval($rk['rink']); ?></strong>
                    </td>
                    <td>
                        <input type="text" name="rink_home_players[]"
                            value="<?php echo esc_attr(implode(', ', $rk['home_players'] ?? array())); ?>"
                            class="large-text">
                    </td>
                    <td>
                        <input type="number" name="rink_home_score[]"
                            value="<?php echo floatval($rk['home_score']); ?>"
                            min="0" step="0.5" class="small-text">
                    </td>
                    <td>
                        <input type="number" name="rink_away_score[]"
                            value="<?php echo floatval($rk['away_score']); ?>"
                            min="0" step="0.5" class="small-text">
                    </td>
                    <td>
                        <input type="text" name="rink_away_players[]"
                            value="<?php echo esc_attr(implode(', ', $rk['away_players'] ?? array())); ?>"
                            class="large-text">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h4 style="margin:16px 0 8px;color:#1a2e5a">Totals</h4>
        <div class="lgw-edit-grid">
            <div class="lgw-edit-row">
                <label>Home Total Shots</label>
                <input type="number" name="home_total" value="<?php echo floatval($sc['home_total'] ?? 0); ?>" min="0" step="0.5" class="small-text">
            </div>
            <div class="lgw-edit-row">
                <label>Away Total Shots</label>
                <input type="number" name="away_total" value="<?php echo floatval($sc['away_total'] ?? 0); ?>" min="0" step="0.5" class="small-text">
            </div>
            <div class="lgw-edit-row">
                <label>Home Points</label>
                <input type="number" name="home_points" value="<?php echo floatval($sc['home_points'] ?? 0); ?>" min="0" max="7" step="0.5" class="small-text">
            </div>
            <div class="lgw-edit-row">
                <label>Away Points</label>
                <input type="number" name="away_points" value="<?php echo floatval($sc['away_points'] ?? 0); ?>" min="0" max="7" step="0.5" class="small-text">
            </div>
        </div>

        <div style="margin-top:16px;display:flex;align-items:center;gap:12px">
            <button class="button button-primary lgw-save-edit"
                data-postid="<?php echo $post_id; ?>"
                data-nonce="<?php echo wp_create_nonce('lgw_admin_nonce'); ?>"
                <?php if ($is_disputed_edit) echo 'data-disputed="1"'; ?>>
                <?php echo $is_disputed_edit ? '⚖️ Save &amp; Resolve Dispute' : '💾 Save Changes'; ?>
            </button>
            <span class="lgw-edit-msg" style="display:none"></span>
        </div>

        <p style="margin-top:8px;font-size:11px;color:#888">
            ⚠️ All edits are logged with your username and a before/after record. Player appearances will be automatically re-logged after saving.
        </p>
    </div>
    <?php
}

// ── Audit log renderer ────────────────────────────────────────────────────────
function lgw_render_audit_log($post_id) {
    $log = get_post_meta($post_id, 'lgw_audit_log', true) ?: array();
    if (empty($log)) {
        echo '<p style="color:#888;font-size:12px">No audit history yet.</p>';
        return;
    }

    $action_icons = array(
        'submitted' => '📥',
        'confirmed' => '✅',
        'resolved'  => '⚖️',
        'edited'    => '✏️',
    );

    echo '<div class="lgw-audit-log">';
    // Show newest first
    foreach (array_reverse($log) as $entry) {
        $icon = $action_icons[$entry['action']] ?? '📋';
        $ts   = date('d M Y H:i', strtotime($entry['ts']));
        echo '<div class="lgw-audit-entry">';
        echo '<div class="lgw-audit-header">';
        echo '<span class="lgw-audit-icon">' . $icon . '</span>';
        echo '<span class="lgw-audit-action lgw-audit-action-' . esc_attr($entry['action']) . '">' . ucfirst(esc_html($entry['action'])) . '</span>';
        echo '<span class="lgw-audit-user">' . esc_html($entry['user']) . '</span>';
        echo '<span class="lgw-audit-ts">' . esc_html($ts) . '</span>';
        echo '</div>';
        echo '<div class="lgw-audit-note">' . esc_html($entry['note']) . '</div>';

        // Show diff if there were changes
        if (!empty($entry['before']) && !empty($entry['after'])) {
            $changes = lgw_audit_diff($entry['before'], $entry['after']);
            if (!empty($changes)) {
                echo '<ul class="lgw-audit-changes">';
                foreach ($changes as $ch) {
                    echo '<li>' . esc_html($ch) . '</li>';
                }
                echo '</ul>';
            }
        }
        echo '</div>';
    }
    echo '</div>';
}
