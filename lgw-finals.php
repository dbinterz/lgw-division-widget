<?php
/**
 * LGW Finals Week Widget
 * Shortcode: [lgw_finals season="2026"]
 *
 * Displays all championship finals-week matches across a season:
 *   1-section competitions  → last 2 rounds of the section bracket (SF + Final)
 *   2/4-section competitions → final_bracket (SF + Final)
 *
 * Per-match extras stored on the match object:
 *   finals_datetime  string  "YYYY-MM-DD HH:MM" — manually set by admin
 *   finals_rink      string  rink number/label — manually set by admin
 *   ends             array   [[home_end, away_end], …] — end-by-end scores
 *
 * Final aggregate scores use the existing home_score / away_score fields.
 *
 * @version 7.1.26
 */

if (!defined('ABSPATH')) exit;

// ── Enqueue ───────────────────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', 'lgw_finals_enqueue');
function lgw_finals_enqueue() {
    global $post;
    if (!is_singular() || !is_a($post, 'WP_Post')) return;
    $content = $post->post_content . ' ' . get_the_content(null, false, $post);
    if (!has_shortcode($content, 'lgw_finals')) return;

    wp_enqueue_style('lgw-saira', 'https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700&display=swap', array(), null);
    wp_enqueue_style('lgw-widget', plugin_dir_url(LGW_PLUGIN_FILE) . 'lgw-widget.css', array('lgw-saira'), LGW_VERSION);
    wp_enqueue_style('lgw-finals', plugin_dir_url(LGW_PLUGIN_FILE) . 'lgw-finals.css', array('lgw-widget'), LGW_VERSION);
    wp_enqueue_script('lgw-finals', plugin_dir_url(LGW_PLUGIN_FILE) . 'lgw-finals.js', array(), LGW_VERSION, true);

    wp_localize_script('lgw-finals', 'lgwFinalsData', array(
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'isAdmin'    => current_user_can('manage_options') ? 1 : 0,
        'nonce'      => wp_create_nonce('lgw_finals_nonce'),
        'gchampNonce'=> wp_create_nonce('lgw_gchamp_score'),
        'clubBadges' => get_option('lgw_club_badges', array()),
        'badges'     => get_option('lgw_badges',      array()),
    ));
}

// ── Helper: extract finals-week matches from a champ ─────────────────────────
// Returns array of match descriptors:
//   [ champ_id, bracket_key, round_idx, match_idx, round_name, match ]
function lgw_finals_get_matches($champ_id, $champ) {
    $n_sections = count($champ['sections'] ?? array());
    $out = array();

    if ($n_sections === 1) {
        // Surface last 2 rounds of the single section bracket
        $bracket = $champ['section_0_bracket'] ?? null;
        if (!$bracket) return $out;
        $matches  = $bracket['matches'] ?? array();
        $rounds   = $bracket['rounds']  ?? array();
        $n_rounds = count($matches);
        // Last 2 rounds = SF + Final (or just Final if only 1 round somehow)
        $start = max(0, $n_rounds - 2);
        for ($ri = $start; $ri < $n_rounds; $ri++) {
            $round_name = $rounds[$ri] ?? ('Round ' . ($ri + 1));
            foreach (($matches[$ri] ?? array()) as $mi => $match) {
                if ($match['bye'] ?? false) continue;
                $out[] = array(
                    'champ_id'    => $champ_id,
                    'bracket_key' => 'section_0_bracket',
                    'round_idx'   => $ri,
                    'match_idx'   => $mi,
                    'round_name'  => $round_name,
                    'match'       => $match,
                );
            }
        }
    } else {
        // Use final_bracket for 2/4-section competitions
        $bracket = $champ['final_bracket'] ?? null;
        if (!$bracket) return $out;
        $matches = $bracket['matches'] ?? array();
        $rounds  = $bracket['rounds']  ?? array();
        foreach ($matches as $ri => $round_matches) {
            $round_name = $rounds[$ri] ?? ('Round ' . ($ri + 1));
            foreach ($round_matches as $mi => $match) {
                if ($match['bye'] ?? false) continue;
                $out[] = array(
                    'champ_id'    => $champ_id,
                    'bracket_key' => 'final_bracket',
                    'round_idx'   => $ri,
                    'match_idx'   => $mi,
                    'round_name'  => $round_name,
                    'match'       => $match,
                );
            }
        }
    }
    return $out;
}

/**
 * Propagate gchamp SF winners into the Final slot.
 * Called from lgw-finals.php save_score when bracket_key=gchamp.
 * Mirrors lgw_gchamp_finals_propagate() in lgw-gchamp.php.
 */
function lgw_gchamp_finals_propagate_ext( array &$champ ): void {
    if ( ! function_exists('lgw_gchamp_finals_propagate') ) return;
    lgw_gchamp_finals_propagate( $champ );
}

/**
 * Build a match list from a Group Championship's finals_matches array,
 * in the same format as lgw_finals_get_matches().
 * Uses bracket_key='gchamp' and round_idx=0 as sentinels; match_idx is the
 * flat index into finals_matches.
 */
function lgw_finals_get_gchamp_matches( string $gchamp_id, array $champ ): array {
    $out = array();
    foreach ( $champ['finals_matches'] ?? array() as $mi => $match ) {
        $out[] = array(
            'champ_id'    => $gchamp_id,
            'bracket_key' => 'gchamp',
            'round_idx'   => 0,
            'match_idx'   => $mi,
            'round_name'  => $match['round'] ?? 'Match',
            'match'       => $match,
        );
    }
    return $out;
}

/**
 * Admin draw control for a pending Group Championship finals slot on the public
 * Finals Week page. Mixed rounds (semi-finals/final) use the shared occupant
 * dropdown — byes and "Winner of QFx" feeds in one list. Pure-seed rounds
 * (quarter-finals) get the qualifier-pool dropdown. Same markup/classes as the
 * championship admin pane so lgw-finals.js reuses the handlers. Returns '' for
 * non-editable slots.
 */
function lgw_finals_gchamp_draw_ctrl( $cid, $match, $side, $match_idx, $src_label, $occ_groups ) {
    $round = $match['round'] ?? '';
    $g     = $occ_groups[ $round ] ?? array();
    if ( ! empty( $g['has_win'] ) && function_exists( 'lgw_gchamp_finals_occupant_ctrl' ) ) {
        $wkey = intval( $match_idx ) . ':' . $side;
        return lgw_gchamp_finals_occupant_ctrl( (string) $cid, $wkey, $g );
    }
    // Pure-seed round: qualifier-pool dropdown on the seed slot.
    $seed = $match[ $side . '_seed' ] ?? null;
    if ( $seed === null || empty( $src_label ) ) return '';
    $src = $match[ $side . '_src' ] ?? null;
    $out  = '<button type="button" class="lgw-gchamp-finals-seed-btn" title="Choose the qualifier for this position">&#x270F;</button>';
    $out .= '<span class="lgw-gchamp-finals-seed-form" style="display:none" data-seed="' . intval( $seed ) . '" data-champ-id="' . esc_attr( $cid ) . '">';
    $out .= '<select class="lgw-gchamp-finals-seed-select">';
    foreach ( $src_label as $osrc => $olabel ) {
        $out .= '<option value="' . esc_attr( $osrc ) . '"' . selected( $osrc, $src, false ) . '>' . esc_html( $olabel ) . '</option>';
    }
    $out .= '</select><button type="button" class="lgw-gchamp-finals-seed-save" title="Save">&#x2713;</button><button type="button" class="lgw-gchamp-finals-seed-cancel" title="Cancel">&#x2715;</button></span>';
    return $out;
}

// ── Shortcode ─────────────────────────────────────────────────────────────────
add_shortcode('lgw_finals', 'lgw_finals_shortcode');
function lgw_finals_shortcode($atts) {
    $atts = shortcode_atts(array(
        'season' => '',
        'title'  => '',
    ), $atts);

    $season = trim($atts['season']);
    if (!$season) return '<p>No season specified for <code>[lgw_finals]</code>.</p>';

    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'lgw_champ_%' ORDER BY option_name"
    );
    $champs = array();
    foreach ($rows as $row) {
        $id  = substr($row->option_name, strlen('lgw_champ_'));
        $val = maybe_unserialize($row->option_value);
        if (is_array($val) && isset($val['title']) && ($val['season'] ?? '') === $season) {
            $champs[$id] = array_merge($val, array('_type' => 'champ'));
        }
    }

    // Also include Group Championships for this season
    $gchamp_rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'lgw_gchamp_%' ORDER BY option_name"
    );
    foreach ($gchamp_rows as $row) {
        $id  = substr($row->option_name, strlen('lgw_gchamp_'));
        $val = maybe_unserialize($row->option_value);
        if (! is_array($val) || ! isset($val['title']) || ($val['season'] ?? '') !== $season) continue;
        // Build the Finals Week bracket on the fly when it hasn't been persisted
        // yet (so the placeholder draw appears as soon as the champ is drawn),
        // and also rebuild whenever the qualifier set has grown since the stored
        // build — otherwise a bracket built while slots were still placeholders
        // keeps showing "Day 1 Winner" after the names are known. Rebuilding
        // preserves any scores/schedule already entered (matched by round+match).
        if (! empty($val['draw_complete']) && ! empty($val['has_ko_bracket'])
            && function_exists('lgw_gchamp_build_finals_matches')) {
            $q_now = 0;
            foreach ($val['days'] ?? array() as $d) { $q_now += count($d['ko_qualifiers'] ?? array()); }
            if (empty($val['finals_matches']) || ($val['finals_q_count_at_build'] ?? -1) !== $q_now) {
                $val['finals_matches']            = lgw_gchamp_build_finals_matches($val);
                $val['finals_q_count_at_build']   = $q_now;
                // Persist so the stored option matches what we render — otherwise the
                // schedule/score AJAX handlers re-read an empty/stale finals_matches and
                // fail with "Match not found".
                update_option('lgw_gchamp_' . $id, $val);
            }
        }
        if (! empty($val['finals_matches'])) {
            $champs['gchamp_' . $id] = array_merge($val, array('_type' => 'gchamp', '_gchamp_id' => $id));
        }
    }

    if (empty($champs)) {
        return '<p>No championships found for season <strong>' . esc_html($season) . '</strong>.</p>';
    }

    $is_admin    = current_user_can('manage_options');
    $club_badges = get_option('lgw_club_badges', array());
    $team_badges = get_option('lgw_badges',      array());
    $nonce       = wp_create_nonce('lgw_finals_nonce');

    // Build all match data for JS
    $all_js_data = array();

    ob_start();
    $heading = $atts['title'] ?: esc_html($season) . ' Finals Week';
    ?>
    <div class="lgw-finals-wrap" data-season="<?php echo esc_attr($season); ?>">
      <div class="lgw-finals-heading"><?php echo esc_html($heading); ?></div>

      <div class="lgw-finals-sort" role="tablist" aria-label="Sort finals">
        <span class="lgw-finals-sort-label">Sort:</span>
        <button type="button" class="lgw-finals-sort-btn is-active" data-sort="competition">By competition</button>
        <button type="button" class="lgw-finals-sort-btn" data-sort="date">By date &amp; rink</button>
      </div>

      <?php $flat_matches = array(); ?>
      <div class="lgw-finals-view lgw-finals-view--comp" data-view="competition">
      <?php foreach ($champs as $champ_id => $champ):
        $is_gchamp = ($champ['_type'] ?? '') === 'gchamp';
        $matches   = $is_gchamp
            ? lgw_finals_get_gchamp_matches($champ['_gchamp_id'], $champ)
            : lgw_finals_get_matches($champ_id, $champ);
        if (empty($matches)) continue;

        // Admin draw-editing context for a Group Championship: qualifier-source
        // labels (QF seed dropdowns), occupant groups (semi/final combined
        // dropdowns), and whether the finals have started (any score/live end
        // → draw locks).
        $g_src_label = array(); $g_occ_groups = array(); $g_finals_started = false;
        if ($is_gchamp && $is_admin && function_exists('lgw_gchamp_finals_slots')) {
            foreach (lgw_gchamp_finals_slots($champ) as $s) {
                $g_src_label[$s['src']] = $s['name']
                    ? lgw_gchamp_short_name($s['name']) . ' — ' . $s['label']
                    : $s['label'] . ' (TBD)';
            }
            $g_occ_groups = lgw_gchamp_finals_occupant_groups($champ);
            foreach ($champ['finals_matches'] ?? array() as $m0) {
                if (($m0['home_score'] !== null && $m0['away_score'] !== null) || !empty($m0['ends'])) $g_finals_started = true;
            }
        }
        $gchamp_draw_editable = $is_gchamp && $is_admin && !$g_finals_started;

        // Group by round
        $by_round = array();
        foreach ($matches as $m) {
            $by_round[$m['round_name']][] = $m;
        }
        ?>
        <div class="lgw-finals-champ" data-champ-id="<?php echo esc_attr($champ_id); ?>">
          <div class="lgw-finals-champ-header">
            <span class="lgw-finals-champ-title"><?php echo esc_html($champ['title'] ?? $champ_id); ?></span>
          </div>

          <?php foreach ($by_round as $round_name => $round_matches): ?>
          <div class="lgw-finals-round">
            <div class="lgw-finals-round-name"><?php echo esc_html($round_name); ?></div>
            <?php foreach ($round_matches as $m):
              $match   = $m['match'];
              $home    = $match['home'] ?? '';
              $away    = $match['away'] ?? '';
              $mid     = $is_gchamp
                  ? 'gchamp_' . $champ['_gchamp_id'] . '--gchamp--0--' . $m['match_idx']
                  : $champ_id . '--' . $m['bracket_key'] . '--' . $m['round_idx'] . '--' . $m['match_idx'];
              $hs      = $match['home_score'] ?? null;
              $as      = $match['away_score'] ?? null;
              $has_score = $hs !== null && $as !== null;
              $ends    = $match['ends'] ?? array();
              $dt      = $match['finals_datetime'] ?? '';
              $rink    = $match['finals_rink']     ?? '';
              $pending = !$home || !$away;

              // Compute running totals from ends
              $home_running = 0; $away_running = 0;
              foreach ($ends as $end) {
                  $home_running += intval($end[0] ?? 0);
                  $away_running += intval($end[1] ?? 0);
              }

              // Club badge lookup
              $home_badge = ''; $away_badge = '';
              foreach ($team_badges as $team => $url) {
                  if ($home && stripos($home, $team) !== false) $home_badge = $url;
                  if ($away && stripos($away, $team) !== false) $away_badge = $url;
              }
              if (!$home_badge) {
                  $hclub = strtolower(trim(explode(',', $home, 2)[1] ?? ''));
                  $home_badge = $club_badges[$hclub] ?? '';
              }
              if (!$away_badge) {
                  $aclub = strtolower(trim(explode(',', $away, 2)[1] ?? ''));
                  $away_badge = $club_badges[$aclub] ?? '';
              }

              $status_cls = $pending ? 'lgw-finals-match--pending'
                          : ($has_score ? 'lgw-finals-match--complete' : 'lgw-finals-match--upcoming');

              // Collect a flat record for the "by date & rink" view
              $flat_matches[] = array(
                  'champ'      => $champ['title'] ?? $champ_id,
                  'round'      => $m['round_name'],
                  'dt'         => $dt,
                  'rink'       => $rink,
                  'home'       => $home,
                  'away'       => $away,
                  'home_label' => $match['home_label'] ?? '',
                  'away_label' => $match['away_label'] ?? '',
                  'hs'         => $hs,
                  'as'         => $as,
                  'has_score'  => $has_score,
                  'pending'    => $pending,
              );

              // Store data for JS
              $all_js_data[$mid] = array(
                  // For gchamp, lgw-finals.js routes to the gchamp AJAX handlers
                  // (which prepend lgw_gchamp_ to champ_id and verify the
                  // lgw_gchamp_score nonce). Pass the BARE gchamp id + gchamp
                  // nonce here — the mid-derived champId still carries the
                  // "gchamp_" prefix and would double it into a missing option,
                  // yielding "Match not found".
                  'isGchamp'   => $is_gchamp ? 1 : 0,
                  'champId'    => $is_gchamp ? $champ['_gchamp_id'] : $champ_id,
                  'nonce'      => $is_gchamp ? wp_create_nonce('lgw_gchamp_score') : $nonce,
                  'bracketKey' => $m['bracket_key'],
                  'roundIdx'   => $m['round_idx'],
                  'matchIdx'   => $m['match_idx'],
                  'home'       => $home,
                  'away'       => $away,
                  'homeScore'  => $hs,
                  'awayScore'  => $as,
                  'ends'       => $ends,
                  'datetime'   => $dt,
                  'rink'       => $rink,
              );
            ?>
            <div class="lgw-finals-match <?php echo $status_cls; ?>"
                 id="lgw-fm-<?php echo esc_attr($mid); ?>"
                 data-mid="<?php echo esc_attr($mid); ?>">

              <?php if ($dt || $rink): ?>
              <div class="lgw-finals-datetime">
                <?php if ($dt): ?>
                <span class="lgw-finals-datetime-val"><?php echo esc_html(lgw_finals_format_datetime($dt)); ?></span>
                <?php endif; ?>
                <?php if ($rink): ?>
                <span class="lgw-finals-rink-val">Rink <?php echo esc_html($rink); ?></span>
                <?php endif; ?>
                <?php if ($is_admin): ?>
                <button class="lgw-finals-edit-dt" data-mid="<?php echo esc_attr($mid); ?>" title="Edit date/time &amp; rink">✏️</button>
                <?php endif; ?>
              </div>
              <?php elseif ($is_admin): ?>
              <div class="lgw-finals-datetime lgw-finals-datetime--unset">
                <button class="lgw-finals-edit-dt" data-mid="<?php echo esc_attr($mid); ?>">📅 Set date, time &amp; rink</button>
              </div>
              <?php endif; ?>

              <?php if ($pending):
                // Group Championship matches carry source labels ("Day 1 Winner",
                // "Winner of QF1") so pending slots read meaningfully before the
                // knockouts finish; other brackets just show TBD.
                $home_ph = $home ? lgw_finals_player_name($home) : ($match['home_label'] ?? 'TBD');
                $away_ph = $away ? lgw_finals_player_name($away) : ($match['away_label'] ?? 'TBD');
              ?>
              <div class="lgw-finals-teams lgw-finals-teams--pending">
                <span class="lgw-finals-tbd<?php echo $home ? ' lgw-finals-tbd--named' : ''; ?>"><?php echo esc_html($home_ph);
                  if ($gchamp_draw_editable) echo lgw_finals_gchamp_draw_ctrl($champ['_gchamp_id'], $match, 'home', $m['match_idx'], $g_src_label, $g_occ_groups); ?></span>
                <span class="lgw-finals-vs">v</span>
                <span class="lgw-finals-tbd<?php echo $away ? ' lgw-finals-tbd--named' : ''; ?>"><?php echo esc_html($away_ph);
                  if ($gchamp_draw_editable) echo lgw_finals_gchamp_draw_ctrl($champ['_gchamp_id'], $match, 'away', $m['match_idx'], $g_src_label, $g_occ_groups); ?></span>
              </div>
              <?php else: ?>
              <div class="lgw-finals-teams">
                <div class="lgw-finals-team lgw-finals-team--home">
                  <?php if ($home_badge): ?><img src="<?php echo esc_url($home_badge); ?>" class="lgw-finals-badge" alt=""><?php endif; ?>
                  <div class="lgw-finals-team-info">
                    <span class="lgw-finals-team-name"><?php echo esc_html(lgw_finals_player_name($home)); ?></span>
                    <?php $hclub = lgw_finals_club_name($home); if ($hclub): ?>
                    <span class="lgw-finals-team-club"><?php echo esc_html($hclub); ?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="lgw-finals-score-block">
                  <?php if ($has_score): ?>
                    <span class="lgw-finals-score lgw-finals-score--home<?php echo $hs > $as ? ' lgw-finals-score--win' : ''; ?>"><?php echo intval($hs); ?></span>
                    <span class="lgw-finals-score-sep">–</span>
                    <span class="lgw-finals-score lgw-finals-score--away<?php echo $as > $hs ? ' lgw-finals-score--win' : ''; ?>"><?php echo intval($as); ?></span>
                  <?php elseif (!empty($ends)): ?>
                    <span class="lgw-finals-score lgw-finals-score--live"><?php echo $home_running; ?></span>
                    <span class="lgw-finals-score-sep">–</span>
                    <span class="lgw-finals-score lgw-finals-score--live"><?php echo $away_running; ?></span>
                    <span class="lgw-finals-live-badge">LIVE</span>
                  <?php else: ?>
                    <span class="lgw-finals-score-placeholder">v</span>
                  <?php endif; ?>
                  <?php if ($is_admin && !$pending): ?>
                  <button class="lgw-finals-edit-score" data-mid="<?php echo esc_attr($mid); ?>" title="Enter score">✏️</button>
                  <?php endif; ?>
                </div>

                <div class="lgw-finals-team lgw-finals-team--away">
                  <div class="lgw-finals-team-info">
                    <span class="lgw-finals-team-name"><?php echo esc_html(lgw_finals_player_name($away)); ?></span>
                    <?php $aclub = lgw_finals_club_name($away); if ($aclub): ?>
                    <span class="lgw-finals-team-club"><?php echo esc_html($aclub); ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if ($away_badge): ?><img src="<?php echo esc_url($away_badge); ?>" class="lgw-finals-badge" alt=""><?php endif; ?>
                </div>
              </div>

              <?php if (!empty($ends)): ?>
              <div class="lgw-finals-ends" id="lgw-ends-<?php echo esc_attr($mid); ?>">
                <?php echo lgw_finals_render_ends_table($ends, $home, $away, $is_admin, $mid, true); ?>
              </div>
              <?php elseif ($is_admin && !$has_score && !$pending): ?>
              <div class="lgw-finals-ends" id="lgw-ends-<?php echo esc_attr($mid); ?>">
                <div class="lgw-finals-ends-empty">
                  <button class="lgw-finals-add-end-btn" data-mid="<?php echo esc_attr($mid); ?>">+ Start live scoring</button>
                </div>
              </div>
              <?php endif; ?>

              <?php endif; // !pending ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      </div><!-- .lgw-finals-view--comp -->

      <?php
      // ── "By date & rink" view: flat, read-only, chronological across all
      // competitions. Scheduled matches first (by datetime then rink), unscheduled
      // last. Editing stays in the by-competition view to avoid duplicate ids.
      usort($flat_matches, function($a, $b) {
          $ad = $a['dt'] ?: '';
          $bd = $b['dt'] ?: '';
          if (($ad === '') !== ($bd === '')) return ($ad === '') ? 1 : -1; // unscheduled last
          if ($ad !== $bd) return strcmp($ad, $bd);
          return strnatcasecmp((string)$a['rink'], (string)$b['rink']);
      });
      ?>
      <div class="lgw-finals-view lgw-finals-view--date" data-view="date" style="display:none">
        <?php if (empty($flat_matches)): ?>
        <p class="lgw-finals-empty">No matches yet.</p>
        <?php else: foreach ($flat_matches as $fm):
          $f_pending = $fm['pending'];
          $f_home    = $fm['home'] ? lgw_finals_player_name($fm['home']) : ($fm['home_label'] ?: 'TBD');
          $f_away    = $fm['away'] ? lgw_finals_player_name($fm['away']) : ($fm['away_label'] ?: 'TBD');
          $f_cls     = $f_pending ? 'lgw-finals-match--pending'
                     : ($fm['has_score'] ? 'lgw-finals-match--complete' : 'lgw-finals-match--upcoming');
        ?>
        <div class="lgw-finals-daterow <?php echo $f_cls; ?>">
          <div class="lgw-finals-daterow-when">
            <?php if ($fm['dt']): ?>
              <span class="lgw-finals-daterow-dt"><?php echo esc_html(lgw_finals_format_datetime($fm['dt'])); ?></span>
            <?php else: ?>
              <span class="lgw-finals-daterow-dt lgw-finals-daterow-dt--tbc">Date TBC</span>
            <?php endif; ?>
            <?php if ($fm['rink']): ?><span class="lgw-finals-daterow-rink">Rink <?php echo esc_html($fm['rink']); ?></span><?php endif; ?>
          </div>
          <div class="lgw-finals-daterow-body">
            <div class="lgw-finals-daterow-meta">
              <span class="lgw-finals-daterow-comp"><?php echo esc_html($fm['champ']); ?></span>
              <span class="lgw-finals-daterow-round"><?php echo esc_html($fm['round']); ?></span>
            </div>
            <div class="lgw-finals-daterow-teams">
              <span class="lgw-finals-daterow-team<?php echo (!$f_pending && $fm['has_score'] && intval($fm['hs'])>intval($fm['as'])) ? ' is-win' : ''; ?>"><?php echo esc_html($f_home); ?></span>
              <?php if ($fm['has_score']): ?>
                <span class="lgw-finals-daterow-score"><?php echo intval($fm['hs']); ?>&ndash;<?php echo intval($fm['as']); ?></span>
              <?php else: ?>
                <span class="lgw-finals-daterow-vs">v</span>
              <?php endif; ?>
              <span class="lgw-finals-daterow-team<?php echo (!$f_pending && $fm['has_score'] && intval($fm['as'])>intval($fm['hs'])) ? ' is-win' : ''; ?>"><?php echo esc_html($f_away); ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div><!-- .lgw-finals-view--date -->
    </div>

    <script>
    if (typeof lgwFinalsData === 'undefined') var lgwFinalsData = {};
    lgwFinalsData.matches = <?php echo wp_json_encode($all_js_data); ?>;
    lgwFinalsData.nonce   = <?php echo wp_json_encode($nonce); ?>;
    lgwFinalsData.isAdmin = <?php echo $is_admin ? '1' : '0'; ?>;
    (function(){
      var wrap = document.currentScript ? document.currentScript.closest('.lgw-finals-wrap') : null;
      if (!wrap) { var ws = document.querySelectorAll('.lgw-finals-wrap'); wrap = ws[ws.length-1]; }
      if (!wrap || wrap.__lgwSortWired) return;
      wrap.__lgwSortWired = true;
      var KEY = 'lgwFinalsSort:' + (wrap.getAttribute('data-season') || '');
      function apply(sort) {
        wrap.querySelectorAll('.lgw-finals-sort-btn').forEach(function(b){
          b.classList.toggle('is-active', b.getAttribute('data-sort') === sort);
        });
        wrap.querySelectorAll('.lgw-finals-view').forEach(function(v){
          v.style.display = (v.getAttribute('data-view') === sort) ? '' : 'none';
        });
      }
      var saved = null;
      try { saved = localStorage.getItem(KEY); } catch (e) {}
      apply(saved === 'date' ? 'date' : 'competition');
      wrap.querySelectorAll('.lgw-finals-sort-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
          var sort = btn.getAttribute('data-sort');
          apply(sort);
          try { localStorage.setItem(KEY, sort); } catch (e) {}
        });
      });
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ── Helper: format datetime for display ──────────────────────────────────────
function lgw_finals_format_datetime($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return $dt;
    return date('D j M Y, H:i', $ts);
}

// ── Helper: render ends table ─────────────────────────────────────────────────
function lgw_finals_render_ends_table($ends, $home, $away, $is_admin, $mid, $collapsed = false) {
    if (empty($ends)) return '';
    $home_total = 0; $away_total = 0;
    $rows = '';
    foreach ($ends as $i => $end) {
        $he = intval($end[0] ?? 0);
        $ae = intval($end[1] ?? 0);
        $home_total += $he; $away_total += $ae;
        $rows .= '<tr>'
               . '<td class="lgw-finals-ends-td lgw-finals-ends-td--end-score' . ($he > $ae ? ' win' : '') . '">' . $he . '</td>'
               . '<td class="lgw-finals-ends-td lgw-finals-ends-td--running">' . $home_total . '</td>'
               . '<td class="lgw-finals-ends-td lgw-finals-ends-td--end">' . ($i + 1) . '</td>'
               . '<td class="lgw-finals-ends-td lgw-finals-ends-td--running lgw-finals-ends-td--right">' . $away_total . '</td>'
               . '<td class="lgw-finals-ends-td lgw-finals-ends-td--end-score lgw-finals-ends-td--right' . ($ae > $he ? ' win' : '') . '">' . $ae . '</td>'
               . '</tr>';
    }

    $n_ends = count($ends);
    $hdr = '<div class="lgw-finals-ends-hdr" data-ends-toggle="' . esc_attr($mid) . '">'
         . '<span class="lgw-finals-ends-hdr-label">Ends (' . $n_ends . ')</span>'
         . '<span class="lgw-finals-ends-hdr-toggle' . ($collapsed ? ' collapsed' : '') . '">▼</span>'
         . '</div>';

    $body_class = 'lgw-finals-ends-body' . ($collapsed ? ' hidden' : '');
    $table = '<table class="lgw-finals-ends-table">'
           . '<thead><tr>'
           . '<th class="lgw-finals-ends-th lgw-finals-ends-th--end-score">'  . esc_html(lgw_finals_short_name($home)) . '</th>'
           . '<th class="lgw-finals-ends-th lgw-finals-ends-th--running">Tot</th>'
           . '<th class="lgw-finals-ends-th lgw-finals-ends-th--end">End</th>'
           . '<th class="lgw-finals-ends-th lgw-finals-ends-th--running lgw-finals-ends-th--right">Tot</th>'
           . '<th class="lgw-finals-ends-th lgw-finals-ends-th--end-score lgw-finals-ends-th--right">' . esc_html(lgw_finals_short_name($away)) . '</th>'
           . '</tr></thead><tbody>' . $rows . '</tbody>'
           . '<tfoot><tr>'
           . '<td class="lgw-finals-ends-td lgw-finals-ends-td--total' . ($home_total > $away_total ? ' win' : '') . '">' . $home_total . '</td>'
           . '<td class="lgw-finals-ends-td lgw-finals-ends-td--end"></td>'
           . '<td class="lgw-finals-ends-td lgw-finals-ends-td--end">Total</td>'
           . '<td class="lgw-finals-ends-td lgw-finals-ends-td--end"></td>'
           . '<td class="lgw-finals-ends-td lgw-finals-ends-td--total lgw-finals-ends-td--right' . ($away_total > $home_total ? ' win' : '') . '">' . $away_total . '</td>'
           . '</tr></tfoot></table>';

    $actions = '';
    if ($is_admin) {
        $actions = '<div class="lgw-finals-ends-actions">'
                 . '<button class="lgw-finals-add-end-btn" data-mid="' . esc_attr($mid) . '">+ Add end</button>'
                 . '<button class="lgw-finals-del-end-btn" data-mid="' . esc_attr($mid) . '">✕ Remove last end</button>'
                 . '<button class="lgw-finals-complete-btn" data-mid="' . esc_attr($mid) . '" data-home-total="' . $home_total . '" data-away-total="' . $away_total . '">✓ Complete game</button>'
                 . '</div>';
    }

    return $hdr . '<div class="' . $body_class . '">' . $table . $actions . '</div>';
}

// ── Helper: short name for ends table header ──────────────────────────────────
function lgw_finals_short_name($entry) {
    // "J. Smith / B. Jones, Ballymena" → "J. Smith / B. Jones"
    $parts = explode(',', $entry, 2);
    $name  = trim($parts[0]);
    // Trim long names
    return mb_strlen($name) > 22 ? mb_substr($name, 0, 20) . '…' : $name;
}

// ── Helper: player name part of an entry (before the last comma) ──────────────
function lgw_finals_player_name($entry) {
    if (!$entry) return '';
    $parts = explode(',', $entry, 2);
    return trim($parts[0]);
}

// ── Helper: club name part of an entry (after the last comma) ─────────────────
function lgw_finals_club_name($entry) {
    if (!$entry) return '';
    $parts = explode(',', $entry, 2);
    return isset($parts[1]) ? trim($parts[1]) : '';
}

// ── AJAX: save datetime ───────────────────────────────────────────────────────
add_action('wp_ajax_lgw_finals_save_datetime', 'lgw_ajax_finals_save_datetime');
function lgw_ajax_finals_save_datetime() {
    check_ajax_referer('lgw_finals_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');

    $champ_id   = sanitize_key($_POST['champ_id']    ?? '');
    $bracket_key = sanitize_key($_POST['bracket_key'] ?? '');
    $round_idx  = intval($_POST['round_idx']          ?? -1);
    $match_idx  = intval($_POST['match_idx']          ?? -1);
    $datetime   = sanitize_text_field($_POST['datetime'] ?? '');
    $rink       = sanitize_text_field($_POST['rink']     ?? '');

    if (!$champ_id || !$bracket_key || $match_idx < 0) {
        wp_send_json_error('Invalid parameters');
    }

    // Validate datetime format YYYY-MM-DD HH:MM
    if ($datetime && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $datetime)) {
        wp_send_json_error('Invalid datetime format — use YYYY-MM-DD HH:MM');
    }

    // Route: gchamp vs standard champ
    if ( $bracket_key === 'gchamp' ) {
        $champ = get_option('lgw_gchamp_' . $champ_id, array());
        if (!isset($champ['finals_matches'][$match_idx])) wp_send_json_error('Match not found');
        $champ['finals_matches'][$match_idx]['finals_datetime'] = $datetime;
        $champ['finals_matches'][$match_idx]['finals_rink']     = $rink;
        update_option('lgw_gchamp_' . $champ_id, $champ);
    } else {
        if ($round_idx < 0) wp_send_json_error('Invalid parameters');
        $champ = get_option('lgw_champ_' . $champ_id, array());
        if (!isset($champ[$bracket_key]['matches'][$round_idx][$match_idx])) {
            wp_send_json_error('Match not found');
        }
        $champ[$bracket_key]['matches'][$round_idx][$match_idx]['finals_datetime'] = $datetime;
        $champ[$bracket_key]['matches'][$round_idx][$match_idx]['finals_rink']     = $rink;
        update_option('lgw_champ_' . $champ_id, $champ);
    }
    wp_send_json_success(array(
        'formatted' => lgw_finals_format_datetime($datetime),
        'raw'       => $datetime,
        'rink'      => $rink,
    ));
}

// ── AJAX: save end ────────────────────────────────────────────────────────────
add_action('wp_ajax_lgw_finals_save_end', 'lgw_ajax_finals_save_end');
function lgw_ajax_finals_save_end() {
    check_ajax_referer('lgw_finals_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');

    $champ_id    = sanitize_key($_POST['champ_id']    ?? '');
    $bracket_key = sanitize_key($_POST['bracket_key'] ?? '');
    $round_idx   = intval($_POST['round_idx']         ?? -1);
    $match_idx   = intval($_POST['match_idx']         ?? -1);
    $action_type = sanitize_key($_POST['end_action']  ?? 'add'); // add | delete_last
    $home_end    = intval($_POST['home_end']           ?? 0);
    $away_end    = intval($_POST['away_end']           ?? 0);

    if (!$champ_id || !$bracket_key || $match_idx < 0) {
        wp_send_json_error('Invalid parameters');
    }

    if ( $bracket_key === 'gchamp' ) {
        $champ = get_option('lgw_gchamp_' . $champ_id, array());
        if (!isset($champ['finals_matches'][$match_idx])) wp_send_json_error('Match not found');
        $match = &$champ['finals_matches'][$match_idx];
    } else {
        if ($round_idx < 0) wp_send_json_error('Invalid parameters');
        $champ = get_option('lgw_champ_' . $champ_id, array());
        if (!isset($champ[$bracket_key]['matches'][$round_idx][$match_idx])) {
            wp_send_json_error('Match not found');
        }
        $match = &$champ[$bracket_key]['matches'][$round_idx][$match_idx];
    }

    $ends  = $match['ends'] ?? array();

    if ($action_type === 'delete_last') {
        if (!empty($ends)) array_pop($ends);
    } else {
        $ends[] = array(max(0, $home_end), max(0, $away_end));
    }

    $match['ends'] = $ends;

    // Recompute running totals
    $ht = 0; $at = 0;
    foreach ($ends as $e) { $ht += $e[0]; $at += $e[1]; }

    update_option( $bracket_key === 'gchamp' ? 'lgw_gchamp_' . $champ_id : 'lgw_champ_' . $champ_id, $champ );

    wp_send_json_success(array(
        'ends'       => $ends,
        'homeTotal'  => $ht,
        'awayTotal'  => $at,
        'endCount'   => count($ends),
    ));
}

// ── AJAX: save final score ────────────────────────────────────────────────────
add_action('wp_ajax_lgw_finals_save_score', 'lgw_ajax_finals_save_score');
function lgw_ajax_finals_save_score() {
    check_ajax_referer('lgw_finals_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');

    $champ_id    = sanitize_key($_POST['champ_id']    ?? '');
    $bracket_key = sanitize_key($_POST['bracket_key'] ?? '');
    $round_idx   = intval($_POST['round_idx']         ?? -1);
    $match_idx   = intval($_POST['match_idx']         ?? -1);
    $home_score  = $_POST['home_score'] !== '' ? intval($_POST['home_score']) : null;
    $away_score  = $_POST['away_score'] !== '' ? intval($_POST['away_score']) : null;

    if (!$champ_id || !$bracket_key || $match_idx < 0) {
        wp_send_json_error('Invalid parameters');
    }

    if ( $bracket_key === 'gchamp' ) {
        // ── Group Championship finals match ───────────────────────────────────
        $champ = get_option('lgw_gchamp_' . $champ_id, array());
        if (!isset($champ['finals_matches'][$match_idx])) wp_send_json_error('Match not found');

        $champ['finals_matches'][$match_idx]['home_score'] = $home_score;
        $champ['finals_matches'][$match_idx]['away_score'] = $away_score;

        // Propagate SF winners into Final
        lgw_gchamp_finals_propagate_ext( $champ );

        update_option('lgw_gchamp_' . $champ_id, $champ);
    } else {
        // ── Standard Championship finals match ────────────────────────────────
        if ($round_idx < 0) wp_send_json_error('Invalid parameters');
        $champ = get_option('lgw_champ_' . $champ_id, array());
        if (!isset($champ[$bracket_key]['matches'][$round_idx][$match_idx])) {
            wp_send_json_error('Match not found');
        }

        $match = &$champ[$bracket_key]['matches'][$round_idx][$match_idx];
        $match['home_score'] = $home_score;
        $match['away_score'] = $away_score;

        // Propagate winner to next round if score is decisive
        if ($home_score !== null && $away_score !== null && $home_score !== $away_score) {
            $bracket = &$champ[$bracket_key];
            lgw_champ_cascade_reset($bracket, $round_idx, $match_idx);
            $winner     = $home_score > $away_score ? $match['home'] : $match['away'];
            $next_round = $round_idx + 1;
            $this_game  = $match['game_num'] ?? null;
            if (isset($bracket['matches'][$next_round]) && $this_game) {
                foreach ($bracket['matches'][$next_round] as $nm => &$nr) {
                    if (($nr['prev_game_home'] ?? null) == $this_game) { $nr['home'] = $winner; $nr['home_score'] = null; break; }
                    if (($nr['prev_game_away'] ?? null) == $this_game) { $nr['away'] = $winner; $nr['away_score'] = null; break; }
                }
                unset($nr);
            } elseif (isset($bracket['matches'][$next_round])) {
                $fb = intval(floor($match_idx / 2));
                $fs = $match_idx % 2 === 0 ? 'home' : 'away';
                if (isset($bracket['matches'][$next_round][$fb])) {
                    $bracket['matches'][$next_round][$fb][$fs]            = $winner;
                    $bracket['matches'][$next_round][$fb][$fs . '_score'] = null;
                }
            }
        }
        if ($home_score === null && $away_score === null) {
            $bracket = &$champ[$bracket_key];
            lgw_champ_cascade_reset($bracket, $round_idx, $match_idx);
        }
        update_option('lgw_champ_' . $champ_id, $champ);
    }

    wp_send_json_success(array(
        'homeScore' => $home_score,
        'awayScore' => $away_score,
    ));
}

// ── AJAX: poll for live updates ───────────────────────────────────────────────
add_action('wp_ajax_lgw_finals_poll',        'lgw_ajax_finals_poll');
add_action('wp_ajax_nopriv_lgw_finals_poll', 'lgw_ajax_finals_poll');
function lgw_ajax_finals_poll() {
    $season = sanitize_text_field($_GET['season'] ?? '');
    if (!$season) wp_send_json_error('Missing season');

    global $wpdb;
    $out = array();

    // Standard championships
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'lgw_champ_%'"
    );
    foreach ($rows as $row) {
        $id  = substr($row->option_name, strlen('lgw_champ_'));
        $val = maybe_unserialize($row->option_value);
        if (!is_array($val) || ($val['season'] ?? '') !== $season) continue;
        $match_list = lgw_finals_get_matches($id, $val);
        foreach ($match_list as $m) {
            $mid = $id . '--' . $m['bracket_key'] . '--' . $m['round_idx'] . '--' . $m['match_idx'];
            $match = $m['match'];
            $out[$mid] = array(
                'home'      => $match['home']            ?? null,
                'away'      => $match['away']            ?? null,
                'homeScore' => $match['home_score']      ?? null,
                'awayScore' => $match['away_score']      ?? null,
                'ends'      => $match['ends']            ?? array(),
                'datetime'  => $match['finals_datetime'] ?? '',
                'rink'      => $match['finals_rink']     ?? '',
            );
        }
    }

    // Group championships
    $grows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options}
         WHERE option_name LIKE 'lgw_gchamp_%'"
    );
    foreach ($grows as $row) {
        $id  = substr($row->option_name, strlen('lgw_gchamp_'));
        $val = maybe_unserialize($row->option_value);
        if (!is_array($val) || ($val['season'] ?? '') !== $season || empty($val['finals_matches'])) continue;
        $match_list = lgw_finals_get_gchamp_matches($id, $val);
        foreach ($match_list as $m) {
            $mid = 'gchamp_' . $id . '--gchamp--0--' . $m['match_idx'];
            $match = $m['match'];
            $out[$mid] = array(
                'home'      => $match['home']            ?? null,
                'away'      => $match['away']            ?? null,
                'homeScore' => $match['home_score']      ?? null,
                'awayScore' => $match['away_score']      ?? null,
                'ends'      => $match['ends']            ?? array(),
                'datetime'  => $match['finals_datetime'] ?? '',
                'rink'      => $match['finals_rink']     ?? '',
            );
        }
    }

    wp_send_json_success($out);
}
