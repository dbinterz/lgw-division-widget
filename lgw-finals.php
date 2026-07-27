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
 * Bowls disc-colour palette used to identify each side of a finals match.
 * slug => [ label, dot hex, readable text colour for a tinted background ].
 */
function lgw_finals_disc_palette(): array {
    return array(
        'red'    => array( 'Red',    '#d32f2f', '#fff' ),
        'yellow' => array( 'Yellow', '#f7c400', '#222' ),
        'blue'   => array( 'Blue',   '#1565c0', '#fff' ),
        'green'  => array( 'Green',  '#2e7d32', '#fff' ),
        'orange' => array( 'Orange', '#ef6c00', '#fff' ),
        'brown'  => array( 'Brown',  '#6d4c41', '#fff' ),
        'black'  => array( 'Black',  '#222222', '#fff' ),
        'white'  => array( 'White',  '#f5f5f5', '#222' ),
        'pink'   => array( 'Pink',   '#e91e8c', '#fff' ),
    );
}

/**
 * Render a small coloured "disc" chip (dot + label) for one side of a match.
 * $slug is a palette key; unknown/empty slugs render nothing.
 */
function lgw_finals_disc_chip( $slug ): string {
    $slug = sanitize_key( $slug );
    $pal  = lgw_finals_disc_palette();
    if ( ! isset( $pal[ $slug ] ) ) return '';
    list( $label, $hex ) = $pal[ $slug ];
    return '<span class="lgw-finals-disc lgw-finals-disc--' . esc_attr( $slug ) . '" title="' . esc_attr( $label . ' disc' ) . '">'
         . '<span class="lgw-finals-disc-dot" style="background:' . esc_attr( $hex ) . '"></span>'
         . '<span class="lgw-finals-disc-label">' . esc_html( $label ) . '</span></span>';
}

/**
 * Admin control (gchamp only) to set the home/away disc colours for a whole
 * championship at once — the "bulk" convention (e.g. all home = Yellow, all
 * away = Blue). Posts to lgw_gchamp_finals_set_discs; the page reloads on save.
 */
function lgw_finals_disc_bulk_ctrl( $cid, $home_slug, $away_slug, $is_gchamp = true ): string {
    $pal = lgw_finals_disc_palette();
    $sel = function( $name, $current ) use ( $pal ) {
        $out = '<select class="lgw-finals-disc-select" data-side="' . esc_attr( $name ) . '">';
        foreach ( $pal as $slug => $meta ) {
            $out .= '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, $current, false ) . '>'
                  . esc_html( $meta[0] ) . '</option>';
        }
        return $out . '</select>';
    };
    return '<div class="lgw-finals-disc-ctrl" data-champ-id="' . esc_attr( $cid ) . '" data-is-gchamp="' . ( $is_gchamp ? '1' : '0' ) . '">'
         . '<span class="lgw-finals-disc-ctrl-label">Discs:</span>'
         . '<label>Home ' . $sel( 'home', $home_slug ) . '</label>'
         . '<label>Away ' . $sel( 'away', $away_slug ) . '</label>'
         . '<button type="button" class="lgw-finals-disc-save">Apply to all</button>'
         . '<span class="lgw-finals-disc-status" aria-live="polite"></span>'
         . '</div>';
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
    // Club-badge keys are stored in their original case ("N.I.C.S.", "Ards"),
    // but the club name parsed from a player string is lowercased for lookup.
    // Key a lowercased copy so the match is case-insensitive.
    $club_badges_lc = array();
    foreach ($club_badges as $k => $v) $club_badges_lc[strtolower($k)] = $v;
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
        <button type="button" class="lgw-finals-sort-btn" data-sort="board">📟 Scoreboard</button>
        <button type="button" class="lgw-finals-sort-btn" data-sort="conditions">📜 Conditions of Play</button>
      </div>

      <?php $flat_matches = array(); $board_matches = array(); ?>
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

        // Disc-colour convention for this championship (bulk: applies to every
        // match). Home defaults Red, away Yellow; gchamp stores overrides.
        $disc_home = sanitize_key($champ['finals_disc_home'] ?? 'red');
        $disc_away = sanitize_key($champ['finals_disc_away'] ?? 'yellow');

        // Group by round
        $by_round = array();
        foreach ($matches as $m) {
            $by_round[$m['round_name']][] = $m;
        }
        ?>
        <div class="lgw-finals-champ" data-champ-id="<?php echo esc_attr($champ_id); ?>">
          <div class="lgw-finals-champ-header">
            <span class="lgw-finals-champ-title"><?php echo esc_html($champ['title'] ?? $champ_id); ?></span>
            <?php if ($is_admin) echo lgw_finals_disc_bulk_ctrl($is_gchamp ? $champ['_gchamp_id'] : $champ_id, $disc_home, $disc_away, $is_gchamp); ?>
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

              // Per-match disc override (empty = inherit the championship
              // convention $disc_home / $disc_away).
              $ov_home   = sanitize_key($match['finals_disc_home'] ?? '');
              $ov_away   = sanitize_key($match['finals_disc_away'] ?? '');
              $eff_home  = $ov_home !== '' ? $ov_home : $disc_home;
              $eff_away  = $ov_away !== '' ? $ov_away : $disc_away;

              // Live score = an ordered log (summary checkpoints + ends) folded
              // into a running total. See lgw_finals_score_items()/fold_items().
              $score_items  = lgw_finals_score_items($match);
              $fold         = lgw_finals_fold_items($score_items);
              $home_running = $fold['runH'];
              $away_running = $fold['runA'];
              $cur_end      = $fold['endNo'];
              $is_live      = !$has_score && !empty($score_items);

              // Club badge lookup
              $home_badge = ''; $away_badge = '';
              foreach ($team_badges as $team => $url) {
                  if ($home && stripos($home, $team) !== false) $home_badge = $url;
                  if ($away && stripos($away, $team) !== false) $away_badge = $url;
              }
              if (!$home_badge) {
                  $hclub = strtolower(trim(explode(',', $home, 2)[1] ?? ''));
                  $home_badge = $club_badges_lc[$hclub] ?? '';
              }
              if (!$away_badge) {
                  $aclub = strtolower(trim(explode(',', $away, 2)[1] ?? ''));
                  $away_badge = $club_badges_lc[$aclub] ?? '';
              }

              $status_cls = $pending ? 'lgw-finals-match--pending'
                          : ($has_score ? 'lgw-finals-match--complete'
                          : ($is_live ? 'lgw-finals-match--live' : 'lgw-finals-match--upcoming'));

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

              // Collect a flat record for the LED scoreboard view (summary
              // scores only). Live matches show the running total; complete
              // matches the final; scheduled matches a dashed placeholder.
              if (!$pending) {
                  $board_matches[] = array(
                      'mid'       => $mid,
                      'champ'     => $champ['title'] ?? $champ_id,
                      'round'     => $m['round_name'],
                      'dt'        => $dt,
                      'rink'      => $rink,
                      'home'      => lgw_finals_player_name($home),
                      'away'      => lgw_finals_player_name($away),
                      'home_club' => trim(explode(',', $home, 2)[1] ?? ''),
                      'away_club' => trim(explode(',', $away, 2)[1] ?? ''),
                      'hs'        => $has_score ? intval($hs) : ($is_live ? $home_running : null),
                      'as'        => $has_score ? intval($as) : ($is_live ? $away_running : null),
                      'has_score' => $has_score,
                      'is_live'   => $is_live,
                      'cur_end'   => $cur_end,
                  );
              }

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
                  'curHome'    => $home_running,
                  'curAway'    => $away_running,
                  'curEnd'     => $cur_end,
                  'datetime'   => $dt,
                  'rink'       => $rink,
                  // Per-match disc override slugs ('' = inherit convention);
                  // discHomeEff/discAwayEff = resolved colour actually shown.
                  'discHome'    => $ov_home,
                  'discAway'    => $ov_away,
                  'discHomeEff' => $eff_home,
                  'discAwayEff' => $eff_away,
                  'discDefHome' => $disc_home,
                  'discDefAway' => $disc_away,
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
                    <?php echo lgw_finals_disc_chip($eff_home); ?>
                  </div>
                </div>

                <div class="lgw-finals-score-block">
                  <?php if ($has_score): ?>
                    <span class="lgw-finals-score lgw-finals-score--home<?php echo $hs > $as ? ' lgw-finals-score--win' : ''; ?>"><?php echo intval($hs); ?></span>
                    <span class="lgw-finals-score-sep">–</span>
                    <span class="lgw-finals-score lgw-finals-score--away<?php echo $as > $hs ? ' lgw-finals-score--win' : ''; ?>"><?php echo intval($as); ?></span>
                  <?php elseif ($is_live): ?>
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
                    <?php echo lgw_finals_disc_chip($eff_away); ?>
                  </div>
                  <?php if ($away_badge): ?><img src="<?php echo esc_url($away_badge); ?>" class="lgw-finals-badge" alt=""><?php endif; ?>
                </div>
              </div>

              <?php if (!$pending && !$has_score && ($is_admin || $is_live)): ?>
              <div class="lgw-finals-ends" id="lgw-ends-<?php echo esc_attr($mid); ?>">
                <?php echo lgw_finals_render_scoring_area($score_items, $home, $away, $is_admin, $mid, true); ?>
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

      <?php
      // ── LED scoreboard view: old-style dark panel showing summary scores
      // for every scheduled match. Live matches first, then upcoming, then
      // finished; each group by datetime/rink. Score cells carry stable ids so
      // lgw-finals.js can refresh them from the same update path as the rest of
      // the page (admin saves + public poll).
      usort($board_matches, function($a, $b) {
          $rank = function($m) { return $m['is_live'] ? 0 : ($m['has_score'] ? 2 : 1); };
          $ra = $rank($a); $rb = $rank($b);
          if ($ra !== $rb) return $ra - $rb;
          $ad = $a['dt'] ?: ''; $bd = $b['dt'] ?: '';
          if (($ad === '') !== ($bd === '')) return ($ad === '') ? 1 : -1;
          if ($ad !== $bd) return strcmp($ad, $bd);
          return strnatcasecmp((string)$a['rink'], (string)$b['rink']);
      });
      $led = function($n) {
          return $n === null ? '––' : str_pad((string)intval($n), 2, '0', STR_PAD_LEFT);
      };
      ?>
      <div class="lgw-finals-view lgw-finals-view--board" data-view="board" style="display:none">
        <?php if (empty($board_matches)): ?>
        <p class="lgw-finals-empty">No matches to show yet.</p>
        <?php else: foreach ($board_matches as $bm):
          $b_state = $bm['is_live'] ? 'live' : ($bm['has_score'] ? 'final' : 'upcoming');
        ?>
        <div class="lgw-finals-led lgw-finals-led--<?php echo $b_state; ?>" data-mid="<?php echo esc_attr($bm['mid']); ?>">
          <div class="lgw-finals-led-head">
            <span class="lgw-finals-led-comp"><?php echo esc_html($bm['champ']); ?></span>
            <span class="lgw-finals-led-round"><?php echo esc_html($bm['round']); ?></span>
            <?php if ($bm['rink']): ?><span class="lgw-finals-led-rink">Rink <?php echo esc_html($bm['rink']); ?></span><?php endif; ?>
          </div>
          <div class="lgw-finals-led-row">
            <span class="lgw-finals-led-name">
              <span class="lgw-finals-led-team"><?php echo str_replace('/', '/<wbr>', esc_html($bm['home'])); ?></span>
              <?php if ($bm['home_club']): ?><span class="lgw-finals-led-club"><?php echo esc_html($bm['home_club']); ?></span><?php endif; ?>
            </span>
            <span class="lgw-finals-led-num" data-side="home" id="lgw-led-<?php echo esc_attr($bm['mid']); ?>-h"><?php echo esc_html($led($bm['hs'])); ?></span>
          </div>
          <div class="lgw-finals-led-row">
            <span class="lgw-finals-led-name">
              <span class="lgw-finals-led-team"><?php echo str_replace('/', '/<wbr>', esc_html($bm['away'])); ?></span>
              <?php if ($bm['away_club']): ?><span class="lgw-finals-led-club"><?php echo esc_html($bm['away_club']); ?></span><?php endif; ?>
            </span>
            <span class="lgw-finals-led-num" data-side="away" id="lgw-led-<?php echo esc_attr($bm['mid']); ?>-a"><?php echo esc_html($led($bm['as'])); ?></span>
          </div>
          <div class="lgw-finals-led-foot">
            <span class="lgw-finals-led-status" id="lgw-led-<?php echo esc_attr($bm['mid']); ?>-s">
              <?php
              if ($bm['is_live']) echo '<span class="lgw-finals-led-dot"></span>LIVE' . ($bm['cur_end'] ? ' &middot; END ' . intval($bm['cur_end']) : '');
              elseif ($bm['has_score']) echo 'FINAL';
              elseif ($bm['dt']) echo esc_html(lgw_finals_format_datetime($bm['dt']));
              else echo 'SCHEDULED';
              ?>
            </span>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div><!-- .lgw-finals-view--board -->

      <?php
      // ── Conditions of Play view: admin-editable rich text (per season). ──
      $conditions_html = lgw_finals_get_conditions($season);
      ?>
      <div class="lgw-finals-view lgw-finals-view--conditions" data-view="conditions" style="display:none">
        <?php if ($is_admin): ?>
        <div class="lgw-finals-cond-toolbar">
          <button type="button" class="lgw-finals-cond-edit">✏️ Edit conditions</button>
        </div>
        <?php endif; ?>
        <div class="lgw-finals-cond-body"><?php echo wp_kses($conditions_html, lgw_finals_conditions_allowed_html()); ?></div>
        <?php if ($is_admin): ?>
        <div class="lgw-finals-cond-editor" style="display:none">
          <p class="lgw-finals-cond-hint">Basic HTML allowed: <code>&lt;h3&gt; &lt;h4&gt; &lt;p&gt; &lt;ul&gt;/&lt;ol&gt;/&lt;li&gt; &lt;strong&gt; &lt;em&gt; &lt;a&gt;</code>. Saved for the <strong><?php echo esc_html($season); ?></strong> season only.</p>
          <textarea class="lgw-finals-cond-text" rows="20" spellcheck="false"><?php echo esc_textarea($conditions_html); ?></textarea>
          <div class="lgw-finals-cond-actions">
            <button type="button" class="lgw-finals-cond-save">Save</button>
            <button type="button" class="lgw-finals-cond-cancel">Cancel</button>
            <span class="lgw-finals-cond-status"></span>
          </div>
        </div>
        <?php endif; ?>
      </div><!-- .lgw-finals-view--conditions -->
    </div>

    <script>
    // Match map for lgw-finals.js. IMPORTANT: wp_localize_script prints
    // `var lgwFinalsData = {…}` in the FOOTER (after this body script), which
    // REDEFINES lgwFinalsData and wipes any .matches we set on it here — before
    // lgw-finals.js runs. That made every gchamp match fall through to the
    // standard save handler with a prefixed champ id ("gchamp_<id>") and fail
    // with "Match not found". So we also stash the boot data on a DEDICATED
    // global that the footer localize never touches; lgw-finals.js reads matches
    // from there when lgwFinalsData.matches is absent. (Order-independent.)
    window.lgwFinalsData = window.lgwFinalsData || {};
    lgwFinalsData.matches = <?php echo wp_json_encode($all_js_data); ?>;
    lgwFinalsData.nonce   = <?php echo wp_json_encode($nonce); ?>;
    lgwFinalsData.isAdmin = <?php echo $is_admin ? '1' : '0'; ?>;
    window.__lgwFinalsBoot = {
      matches: lgwFinalsData.matches,
      nonce:   lgwFinalsData.nonce,
      isAdmin: lgwFinalsData.isAdmin
    };
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
      apply((saved === 'date' || saved === 'board' || saved === 'conditions') ? saved : 'competition');
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

// ── Conditions of Play ───────────────────────────────────────────────────────
// Admin-editable rich text shown in the "Conditions of Play" finals tab.
// Stored per-season as option lgw_finals_conditions_<season> (sanitised HTML);
// seeded from lgw_finals_conditions_default() the first time it's shown.

// Whitelist of tags admins may use (passed to wp_kses on save AND render).
function lgw_finals_conditions_allowed_html() {
    return array(
        'p'      => array(),
        'br'     => array(),
        'strong' => array(), 'b' => array(),
        'em'     => array(), 'i' => array(),
        'u'      => array(),
        'h3'     => array(), 'h4' => array(),
        'ul'     => array(), 'ol' => array(), 'li' => array(),
        'a'      => array('href' => array(), 'title' => array(), 'target' => array(), 'rel' => array()),
    );
}

// Stored conditions HTML for a season, or the default seed if never edited.
function lgw_finals_get_conditions($season) {
    $stored = get_option('lgw_finals_conditions_' . sanitize_key($season), null);
    if ($stored === null || $stored === '') return lgw_finals_conditions_default();
    return $stored;
}

// Default seed: the IBA Championships Stage 1 & 2 Conditions of Play 2026.
function lgw_finals_conditions_default() {
    return <<<'HTML'
<p>The IBA Championships (hereafter "the Championships") will be adopted by each member Association at Stage 1, will also apply at Stage 2, and shall comprise:</p>

<h3>Singles — Open, Under 18 (Youth) and Under 25</h3>
<ul>
<li>Under 18 (Youth) — competitors must be under 18 years of age on 1st April of the season in which they are competing.</li>
<li>Under 25 — competitors must be under 25 years of age on 1st April of the season in which they are competing.</li>
<li>Each player shall play with a set of 4 bowls (matched set), singly and in turn.</li>
<li>All singles games are played on a <strong>first player to 21 shots</strong> basis.</li>
<li>Substitutes in singles are not allowed.</li>
</ul>

<h3>Pairs — Open, Under 25 and Over 55</h3>
<ul>
<li>Open Pairs — each player shall play with a set of 4 bowls (matched set), singly and in turn.</li>
<li>Under 25 Pairs — competitors must be under 25 years of age on 1st April of the season in which they are competing.</li>
<li>Over 55 Pairs — competitors must be 55 years of age, or over, on 1st April of the season in which they are competing.</li>
<li>Under 25 Pairs / Over 55 Pairs — each player shall play with a set of 3 bowls from a matched set of 4, singly and in turn.</li>
</ul>

<h3>Triples — Open</h3>
<ul>
<li>Each player shall play with a set of 3 bowls from a matched set of 4, singly and in turn.</li>
</ul>

<h3>Fours — Open and Senior</h3>
<ul>
<li>Senior Fours — competitors must be 55 years of age or over on 1st April of the season in which they are competing.</li>
<li>Each player shall play with a set of 2 bowls from a matched set of 4, singly and in turn.</li>
</ul>

<h3>In all Pairs, Triples and Fours competitions</h3>
<ul>
<li>Each match will consist of 18 ends.</li>
<li>In the event of a tie, an extra end(s) shall be played until a winner is determined.</li>
<li>Substitutes in Pairs, Triples and Fours will be determined as per Championship Rules.</li>
</ul>

<h3>Restricting the movement of players during play</h3>
<p>After delivering their first bowl, players will only be allowed to walk up to the head under the following circumstances:</p>
<h4>Singles</h4>
<ul>
<li>The opponents: after delivery of their third and fourth bowls.</li>
</ul>
<h4>Pairs (each player playing four bowls)</h4>
<ul>
<li>The leads: after delivery of their third and fourth bowls.</li>
<li>The skips: after delivery of their second, third and fourth bowls.</li>
</ul>
<h4>Pairs (each player playing three bowls)</h4>
<ul>
<li>The leads: after delivery of their third bowl.</li>
<li>The skips: after delivery of their second and third bowls.</li>
</ul>
<h4>Triples (each player playing three bowls)</h4>
<ul>
<li>The leads: after delivery of their third bowl.</li>
<li>The seconds: after delivery of their second and third bowls.</li>
<li>The skips: after delivery of each of their second and third bowls.</li>
</ul>
<h4>Fours (each player playing two bowls)</h4>
<ul>
<li>The leads: after the second player in their team has delivered their second bowl.</li>
<li>The seconds: after delivery of their second bowl.</li>
<li>The thirds: after delivery of their second bowl.</li>
<li>The skips: after delivery of each of their bowls.</li>
</ul>

<h3>Other conditions</h3>
<ol>
<li>To ensure the schedule runs smoothly and all games are completed as planned, a maximum time limit of 3 hours 30 minutes (including trial ends) will be strictly enforced. Each match will commence at the same time as indicated by the senior umpire on duty.</li>
<li>Play must be continuous — players must play without undue delay and not in a way that prevents their opponents from completing the match within the time limit. Slow play will be monitored by umpires in conjunction with the Controlling Body.</li>
</ol>
HTML;
}

// ── AJAX: save conditions of play (per season) ───────────────────────────────
add_action('wp_ajax_lgw_finals_save_conditions', 'lgw_ajax_finals_save_conditions');
function lgw_ajax_finals_save_conditions() {
    check_ajax_referer('lgw_finals_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');
    $season = sanitize_key($_POST['season'] ?? '');
    if (!$season) wp_send_json_error('Missing season');
    // wp_unslash before kses (WP slashes POST); allow only the whitelisted tags.
    $raw   = wp_unslash($_POST['content'] ?? '');
    $clean = wp_kses($raw, lgw_finals_conditions_allowed_html());
    update_option('lgw_finals_conditions_' . $season, $clean, false);
    wp_send_json_success(array('html' => $clean));
}

// ── Helper: normalise a match's live-scoring items ───────────────────────────
// The live score is an ordered log stored in $match['ends']. Each item is
// either an END (a delta pair, [h, a]) or a SUMMARY checkpoint (an ABSOLUTE
// score declared at a given end). Checkpoints are stored as the delta needed
// to reach their absolute (keys 'sum'=>1 and 'end'=>N alongside 0/1), so a
// plain sum of item[0]/item[1] still equals the running total everywhere.
// Also folds forward any legacy 2026.20/.21 baseline (live_home/away/ends)
// into a leading checkpoint so old data keeps working.
function lgw_finals_score_items(array $match): array {
    $items = isset($match['ends']) && is_array($match['ends']) ? array_values($match['ends']) : array();
    if (isset($match['live_home'], $match['live_away']) && $match['live_home'] !== '' && $match['live_away'] !== '') {
        array_unshift($items, array(
            0 => intval($match['live_home']),
            1 => intval($match['live_away']),
            'sum' => 1,
            'end' => intval($match['live_ends'] ?? 0),
        ));
    }
    return $items;
}

// ── Helper: fold the item log into display rows + running totals ─────────────
// Walks the ordered items: a checkpoint jumps the running score to its absolute
// and sets the end number to its declared end; an end adds its delta and bumps
// the end number by one. Returns rows[] (kind/endNo/scoreH/scoreA/runH/runA),
// the final running totals and the current end number.
function lgw_finals_fold_items(array $items): array {
    $rows = array(); $rh = 0; $ra = 0; $end_no = 0;
    foreach ($items as $e) {
        $dh = intval($e[0] ?? 0); $da = intval($e[1] ?? 0);
        $rh += $dh; $ra += $da;
        if (isset($e['sum'])) {
            $end_no  = intval($e['end'] ?? $end_no);
            $rows[]  = array('kind' => 'sum', 'endNo' => $end_no, 'scoreH' => $rh, 'scoreA' => $ra, 'runH' => $rh, 'runA' => $ra);
        } else {
            $end_no += 1;
            $rows[]  = array('kind' => 'end', 'endNo' => $end_no, 'scoreH' => $dh, 'scoreA' => $da, 'runH' => $rh, 'runA' => $ra);
        }
    }
    return array('rows' => $rows, 'runH' => $rh, 'runA' => $ra, 'endNo' => $end_no);
}

// ── Helper: render the whole live-scoring area for a match ───────────────────
// Single source of truth for the inner HTML of the `.lgw-finals-ends` container,
// used on first render, as the save_end AJAX fragment, and by the live poll.
//   - live       : any items → running-total table (checkpoints + ends) + toolbar
//   - not started: admin sees a start toolbar (+ Start live scoring / Quick score)
function lgw_finals_render_scoring_area($items, $home, $away, $is_admin, $mid, $collapsed = false) {
    $items = is_array($items) ? $items : array();
    $mid_a = esc_attr($mid);

    if (!empty($items)) {
        $fold = lgw_finals_fold_items($items);
        $table = lgw_finals_render_ends_table($items, $home, $away, $is_admin, $mid, $collapsed);
        if (!$is_admin) return $table;
        $has_ends = false;
        foreach ($items as $e) { if (!isset($e['sum'])) { $has_ends = true; break; } }
        $actions = '<div class="lgw-finals-ends-actions">'
                 . '<button class="lgw-finals-add-end-btn" data-mid="' . $mid_a . '">+ Add end</button>'
                 . ($has_ends ? '<button class="lgw-finals-del-end-btn" data-mid="' . $mid_a . '">✕ Remove last end</button>' : '')
                 . '<button class="lgw-finals-quick-btn" data-mid="' . $mid_a . '">⚡ Update score</button>'
                 . '<button class="lgw-finals-reset-btn" data-mid="' . $mid_a . '">↺ Reset</button>'
                 . '<button class="lgw-finals-complete-btn" data-mid="' . $mid_a . '" data-home-total="' . $fold['runH'] . '" data-away-total="' . $fold['runA'] . '">✓ Complete game</button>'
                 . '</div>';
        return $table . $actions;
    }

    // Not started.
    if (!$is_admin) return '';
    return '<div class="lgw-finals-ends-empty">'
         . '<button class="lgw-finals-add-end-btn" data-mid="' . $mid_a . '">+ Start live scoring</button>'
         . '<button class="lgw-finals-quick-btn" data-mid="' . $mid_a . '">⚡ Quick score</button>'
         . '</div>';
}

// ── Helper: apply a live-scoring action to a match slot (by reference) ───────
// Actions: add (append an end delta) | delete_last | reset (clear all) |
// set_total (append a summary checkpoint = the delta to reach an absolute
// score at a declared end). Everything lives in the ordered $match['ends'] log.
function lgw_finals_apply_end_action(array &$match, string $action, int $he, int $ae, int $summary_ends = 0): void {
    // Migrate any legacy baseline into the log, then work purely on the log.
    $items = lgw_finals_score_items($match);
    unset($match['live_home'], $match['live_away'], $match['live_ends']);

    switch ($action) {
        case 'reset':
            $items = array();
            break;
        case 'set_total':
            $fold = lgw_finals_fold_items($items);
            // Store the checkpoint as the delta to jump the running total to the
            // requested absolute, so summing item[0]/item[1] stays correct.
            $items[] = array(
                0 => max(0, $he) - $fold['runH'],
                1 => max(0, $ae) - $fold['runA'],
                'sum' => 1,
                'end' => max(0, $summary_ends),
            );
            break;
        case 'delete_last':
            if (!empty($items)) array_pop($items);
            break;
        default: // add — append an end delta
            $items[] = array(max(0, $he), max(0, $ae));
            break;
    }
    $match['ends'] = array_values($items);
}

// ── Helper: build the AJAX response for a live-scoring change ─────────────────
// Returns totals + the re-rendered scoring-area HTML fragment so the browser
// can swap it in wholesale (no divergent client-side rebuild).
function lgw_finals_scoring_response(array $match, string $mid): array {
    $items = lgw_finals_score_items($match);
    $fold  = lgw_finals_fold_items($items);
    return array(
        'ends'      => array_values($items),
        'homeTotal' => $fold['runH'],
        'awayTotal' => $fold['runA'],
        'endCount'  => count($items),
        'curEnd'    => $fold['endNo'],
        'isLive'    => !empty($items) ? 1 : 0,
        'html'      => lgw_finals_render_scoring_area($items, $match['home'] ?? '', $match['away'] ?? '', true, $mid, false),
    );
}

// ── Helper: render the running-total table from the item log ─────────────────
function lgw_finals_render_ends_table($items, $home, $away, $is_admin, $mid, $collapsed = false) {
    $items = is_array($items) ? $items : array();
    if (empty($items)) return '';
    $fold = lgw_finals_fold_items($items);
    $rows = '';
    $n_ends = 0;
    foreach ($fold['rows'] as $r) {
        $is_sum = $r['kind'] === 'sum';
        if (!$is_sum) $n_ends++;
        $sh = intval($r['scoreH']); $sa = intval($r['scoreA']);
        $end_cell = $is_sum ? ('≤' . $r['endNo']) : $r['endNo'];
        $end_title = $is_sum ? ' title="Summary — score after end ' . intval($r['endNo']) . '"' : '';
        $rows .= '<tr' . ($is_sum ? ' class="lgw-finals-ends-tr--summary"' : '') . '>'
               . '<td class="lgw-finals-ends-td lgw-finals-ends-td--end-score' . ($sh > $sa ? ' win' : '') . '">' . $sh . '</td>'
               . '<td class="lgw-finals-ends-td lgw-finals-ends-td--running">' . intval($r['runH']) . '</td>'
               . '<td class="lgw-finals-ends-td lgw-finals-ends-td--end"' . $end_title . '>' . esc_html($end_cell) . '</td>'
               . '<td class="lgw-finals-ends-td lgw-finals-ends-td--running lgw-finals-ends-td--right">' . intval($r['runA']) . '</td>'
               . '<td class="lgw-finals-ends-td lgw-finals-ends-td--end-score lgw-finals-ends-td--right' . ($sa > $sh ? ' win' : '') . '">' . $sa . '</td>'
               . '</tr>';
    }
    $home_total = intval($fold['runH']); $away_total = intval($fold['runA']);

    $n_sum = count($fold['rows']) - $n_ends;
    $hdr_label = $n_sum > 0
        ? ('Score (' . $n_ends . ' end' . ($n_ends === 1 ? '' : 's') . ' + ' . $n_sum . ' summary)')
        : ('Ends (' . $n_ends . ')');
    $hdr = '<div class="lgw-finals-ends-hdr" data-ends-toggle="' . esc_attr($mid) . '">'
         . '<span class="lgw-finals-ends-hdr-label">' . esc_html($hdr_label) . '</span>'
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

    // Toolbar/actions are added by lgw_finals_render_scoring_area(); this helper
    // returns just the header + table so it can be reused without buttons.
    return $hdr . '<div class="' . $body_class . '">' . $table . '</div>';
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

    // Optional per-match disc override (only written when the keys are posted;
    // blank/invalid clears it so the match inherits the championship convention).
    $pal      = lgw_finals_disc_palette();
    $set_disc = function( &$slot ) use ( $pal ) {
        foreach ( array( 'disc_home' => 'finals_disc_home', 'disc_away' => 'finals_disc_away' ) as $pk => $mk ) {
            if ( ! array_key_exists( $pk, $_POST ) ) continue;
            $slug = sanitize_key( $_POST[ $pk ] );
            $slot[ $mk ] = isset( $pal[ $slug ] ) ? $slug : '';
        }
    };

    // Route: gchamp vs standard champ
    if ( $bracket_key === 'gchamp' ) {
        $champ = get_option('lgw_gchamp_' . $champ_id, array());
        if (!isset($champ['finals_matches'][$match_idx])) wp_send_json_error('Match not found');
        $slot = &$champ['finals_matches'][$match_idx];
        $slot['finals_datetime'] = $datetime;
        $slot['finals_rink']     = $rink;
        $set_disc( $slot );
        update_option('lgw_gchamp_' . $champ_id, $champ);
    } else {
        if ($round_idx < 0) wp_send_json_error('Invalid parameters');
        $champ = get_option('lgw_champ_' . $champ_id, array());
        if (!isset($champ[$bracket_key]['matches'][$round_idx][$match_idx])) {
            wp_send_json_error('Match not found');
        }
        $slot = &$champ[$bracket_key]['matches'][$round_idx][$match_idx];
        $slot['finals_datetime'] = $datetime;
        $slot['finals_rink']     = $rink;
        $set_disc( $slot );
        update_option('lgw_champ_' . $champ_id, $champ);
    }

    $ov_home  = $slot['finals_disc_home'] ?? '';
    $ov_away  = $slot['finals_disc_away'] ?? '';
    $eff_home = $ov_home !== '' ? $ov_home : ( $champ['finals_disc_home'] ?? 'red' );
    $eff_away = $ov_away !== '' ? $ov_away : ( $champ['finals_disc_away'] ?? 'yellow' );
    wp_send_json_success(array(
        'formatted'   => lgw_finals_format_datetime($datetime),
        'raw'         => $datetime,
        'rink'        => $rink,
        'discHome'    => $ov_home,
        'discAway'    => $ov_away,
        'discHomeEff' => $eff_home,
        'discAwayEff' => $eff_away,
    ));
}

// ── AJAX: set the home/away disc convention for a whole championship (bulk) ───
// Works for any finals competition — gchamp (lgw_gchamp_<id>) or standard
// champ (lgw_champ_<id>) — routed by the is_gchamp flag.
add_action('wp_ajax_lgw_finals_set_discs', 'lgw_ajax_finals_set_discs');
function lgw_ajax_finals_set_discs() {
    check_ajax_referer('lgw_finals_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorised');
    $champ_id  = sanitize_key($_POST['champ_id'] ?? '');
    $is_gchamp = !empty($_POST['is_gchamp']);
    $home      = sanitize_key($_POST['home_disc'] ?? '');
    $away      = sanitize_key($_POST['away_disc'] ?? '');
    if (!$champ_id) wp_send_json_error('Missing championship');
    $pal = lgw_finals_disc_palette();
    if (!isset($pal[$home]) || !isset($pal[$away])) wp_send_json_error('Unknown disc colour');
    $okey  = $is_gchamp ? 'lgw_gchamp_' . $champ_id : 'lgw_champ_' . $champ_id;
    $champ = get_option($okey, array());
    if (empty($champ)) wp_send_json_error('Championship not found');
    $champ['finals_disc_home'] = $home;
    $champ['finals_disc_away'] = $away;
    update_option($okey, $champ);
    wp_send_json_success(array('home' => $home, 'away' => $away));
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
    $action_type = sanitize_key($_POST['end_action']  ?? 'add'); // add | delete_last | reset | set_total
    $home_end    = intval($_POST['home_end']           ?? 0);
    $away_end    = intval($_POST['away_end']           ?? 0);

    if (!$champ_id || !$bracket_key || $match_idx < 0) {
        wp_send_json_error('Invalid parameters');
    }

    if ( $bracket_key === 'gchamp' ) {
        $champ = get_option('lgw_gchamp_' . $champ_id, array());
        if (!isset($champ['finals_matches'][$match_idx])) wp_send_json_error('Match not found');
        $match = &$champ['finals_matches'][$match_idx];
        $mid   = 'gchamp_' . $champ_id . '--gchamp--0--' . $match_idx;
    } else {
        if ($round_idx < 0) wp_send_json_error('Invalid parameters');
        $champ = get_option('lgw_champ_' . $champ_id, array());
        if (!isset($champ[$bracket_key]['matches'][$round_idx][$match_idx])) {
            wp_send_json_error('Match not found');
        }
        $match = &$champ[$bracket_key]['matches'][$round_idx][$match_idx];
        $mid   = $champ_id . '--' . $bracket_key . '--' . $round_idx . '--' . $match_idx;
    }

    $summary_ends = intval($_POST['summary_ends'] ?? 0);
    lgw_finals_apply_end_action($match, $action_type, $home_end, $away_end, $summary_ends);

    update_option( $bracket_key === 'gchamp' ? 'lgw_gchamp_' . $champ_id : 'lgw_champ_' . $champ_id, $champ );

    wp_send_json_success( lgw_finals_scoring_response($match, $mid) );
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
    $poll_admin = current_user_can('manage_options');
    foreach ($rows as $row) {
        $id  = substr($row->option_name, strlen('lgw_champ_'));
        $val = maybe_unserialize($row->option_value);
        if (!is_array($val) || ($val['season'] ?? '') !== $season) continue;
        $match_list = lgw_finals_get_matches($id, $val);
        foreach ($match_list as $m) {
            $mid = $id . '--' . $m['bracket_key'] . '--' . $m['round_idx'] . '--' . $m['match_idx'];
            $match = $m['match'];
            $items = lgw_finals_score_items($match);
            $fold  = lgw_finals_fold_items($items);
            $out[$mid] = array(
                'home'      => $match['home']            ?? null,
                'away'      => $match['away']            ?? null,
                'homeScore' => $match['home_score']      ?? null,
                'awayScore' => $match['away_score']      ?? null,
                'ends'      => array_values($items),
                'homeTotal' => $fold['runH'],
                'awayTotal' => $fold['runA'],
                'curEnd'    => $fold['endNo'],
                'isLive'    => !empty($items) ? 1 : 0,
                'html'      => lgw_finals_render_scoring_area($items, $match['home'] ?? '', $match['away'] ?? '', $poll_admin, $mid, true),
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
            $items = lgw_finals_score_items($match);
            $fold  = lgw_finals_fold_items($items);
            $out[$mid] = array(
                'home'      => $match['home']            ?? null,
                'away'      => $match['away']            ?? null,
                'homeScore' => $match['home_score']      ?? null,
                'awayScore' => $match['away_score']      ?? null,
                'ends'      => array_values($items),
                'homeTotal' => $fold['runH'],
                'awayTotal' => $fold['runA'],
                'curEnd'    => $fold['endNo'],
                'isLive'    => !empty($items) ? 1 : 0,
                'html'      => lgw_finals_render_scoring_area($items, $match['home'] ?? '', $match['away'] ?? '', $poll_admin, $mid, true),
                'datetime'  => $match['finals_datetime'] ?? '',
                'rink'      => $match['finals_rink']     ?? '',
            );
        }
    }

    wp_send_json_success($out);
}
