<?php
/**
 * NIPGL Player Tracking
 * Records player appearances from confirmed/admin-resolved scorecards.
 * Groups by club, tracks which teams played for, supports season date ranges.
 */

// ── DB table setup ────────────────────────────────────────────────────────────
function nipgl_players_table()     { global $wpdb; return $wpdb->prefix . 'nipgl_players'; }
function nipgl_appearances_table() { global $wpdb; return $wpdb->prefix . 'nipgl_appearances'; }

register_activation_hook(NIPGL_PLUGIN_FILE, 'nipgl_create_player_tables');
function nipgl_create_player_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $sql1 = "CREATE TABLE IF NOT EXISTS " . nipgl_players_table() . " (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        club        VARCHAR(100) NOT NULL,
        name        VARCHAR(150) NOT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY club_name (club(100), name(150))
    ) $charset;";

    $sql2 = "CREATE TABLE IF NOT EXISTS " . nipgl_appearances_table() . " (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        player_id   INT UNSIGNED NOT NULL,
        team        VARCHAR(150) NOT NULL,
        match_title VARCHAR(255) NOT NULL,
        match_date  VARCHAR(50)  NOT NULL,
        rink        TINYINT      NOT NULL DEFAULT 0,
        scorecard_id INT UNSIGNED NOT NULL DEFAULT 0,
        played_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY player_id (player_id),
        KEY scorecard_id (scorecard_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql1);
    dbDelta($sql2);
}

// Run table creation on every load in case tables are missing (e.g. after plugin update)
add_action('plugins_loaded', 'nipgl_maybe_create_player_tables');
function nipgl_maybe_create_player_tables() {
    global $wpdb;
    if ($wpdb->get_var("SHOW TABLES LIKE '" . nipgl_players_table() . "'") !== nipgl_players_table()) {
        nipgl_create_player_tables();
    }
}

// ── Season helpers ────────────────────────────────────────────────────────────
function nipgl_get_season() {
    return get_option('nipgl_season', array(
        'start' => '',
        'end'   => '',
        'label' => '',
    ));
}

function nipgl_season_where() {
    global $wpdb;
    $season = nipgl_get_season();
    if (!empty($season['start']) && !empty($season['end'])) {
        return $wpdb->prepare(
            "AND a.played_at >= %s AND a.played_at <= %s",
            $season['start'] . ' 00:00:00',
            $season['end']   . ' 23:59:59'
        );
    }
    return ''; // no filter — show all time
}

// ── Core: log appearances from a scorecard ────────────────────────────────────
function nipgl_log_appearances($scorecard_post_id) {
    global $wpdb;
    $sc = get_post_meta($scorecard_post_id, 'nipgl_scorecard_data', true);
    if (!$sc || empty($sc['rinks'])) return;

    $home_team  = $sc['home_team'] ?? '';
    $away_team  = $sc['away_team'] ?? '';
    $match_date = $sc['date']      ?? '';
    $match_title = $home_team . ' v ' . $away_team;

    // Resolve clubs from team names using existing prefix-matching
    $home_club = nipgl_team_to_club($home_team);
    $away_club = nipgl_team_to_club($away_team);

    // Clear any existing appearances for this scorecard (idempotent re-log)
    $wpdb->delete(nipgl_appearances_table(), array('scorecard_id' => $scorecard_post_id), array('%d'));

    foreach ($sc['rinks'] as $rink) {
        $rink_num = intval($rink['rink'] ?? 0);

        // Home players
        foreach (($rink['home_players'] ?? array()) as $name) {
            $name = nipgl_clean_player_name($name);
            if (!$name) continue;
            $player_id = nipgl_get_or_create_player($home_club ?: $home_team, $name);
            $wpdb->insert(nipgl_appearances_table(), array(
                'player_id'   => $player_id,
                'team'        => $home_team,
                'match_title' => $match_title,
                'match_date'  => $match_date,
                'rink'        => $rink_num,
                'scorecard_id'=> $scorecard_post_id,
                'played_at'   => current_time('mysql'),
            ), array('%d','%s','%s','%s','%d','%d','%s'));
        }

        // Away players
        foreach (($rink['away_players'] ?? array()) as $name) {
            $name = nipgl_clean_player_name($name);
            if (!$name) continue;
            $player_id = nipgl_get_or_create_player($away_club ?: $away_team, $name);
            $wpdb->insert(nipgl_appearances_table(), array(
                'player_id'   => $player_id,
                'team'        => $away_team,
                'match_title' => $match_title,
                'match_date'  => $match_date,
                'rink'        => $rink_num,
                'scorecard_id'=> $scorecard_post_id,
                'played_at'   => current_time('mysql'),
            ), array('%d','%s','%s','%s','%d','%d','%s'));
        }
    }
}

function nipgl_clean_player_name($name) {
    // Strip trailing asterisks (e.g. "SJ Curran*") and extra whitespace
    return trim(rtrim(trim($name), '*'));
}

function nipgl_team_to_club($team) {
    // Match team name to a configured club using existing prefix logic
    $clubs = nipgl_get_clubs();
    foreach ($clubs as $club) {
        if (nipgl_club_matches_team($club['name'], $team)) return $club['name'];
    }
    return ''; // unknown club — caller falls back to team name
}

function nipgl_get_or_create_player($club, $name) {
    global $wpdb;
    $tbl = nipgl_players_table();
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $tbl WHERE club = %s AND name = %s",
        $club, $name
    ));
    if ($existing) return intval($existing);
    $wpdb->insert($tbl, array('club' => $club, 'name' => $name), array('%s','%s'));
    return intval($wpdb->insert_id);
}

// ── Admin menu ────────────────────────────────────────────────────────────────
add_action('admin_menu', 'nipgl_players_admin_menu');
function nipgl_players_admin_menu() {
    add_submenu_page(
        'nipgl-settings',
        'Player Tracking',
        'Players',
        'manage_options',
        'nipgl-players',
        'nipgl_players_admin_page'
    );
}

// ── Admin page ────────────────────────────────────────────────────────────────
function nipgl_players_admin_page() {
    global $wpdb;
    $pt  = nipgl_players_table();
    $at  = nipgl_appearances_table();
    $season = nipgl_get_season();
    $nonce  = wp_create_nonce('nipgl_players_nonce');
    $season_where = nipgl_season_where();

    // Handle POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nipgl_players_action'])) {
        check_admin_referer('nipgl_players_nonce', 'nipgl_players_nonce_field');
        $action = $_POST['nipgl_players_action'];

        if ($action === 'save_season') {
            update_option('nipgl_season', array(
                'start' => sanitize_text_field($_POST['season_start'] ?? ''),
                'end'   => sanitize_text_field($_POST['season_end']   ?? ''),
                'label' => sanitize_text_field($_POST['season_label'] ?? ''),
            ));
            echo '<div class="notice notice-success"><p>Season settings saved.</p></div>';
            $season = nipgl_get_season();
            $season_where = nipgl_season_where();
        }

        if ($action === 'merge') {
            $keep_id    = intval($_POST['keep_id']   ?? 0);
            $remove_id  = intval($_POST['remove_id'] ?? 0);
            if ($keep_id && $remove_id && $keep_id !== $remove_id) {
                $wpdb->update($at, array('player_id' => $keep_id), array('player_id' => $remove_id), array('%d'), array('%d'));
                $wpdb->delete($pt, array('id' => $remove_id), array('%d'));
                echo '<div class="notice notice-success"><p>Players merged.</p></div>';
            }
        }

        if ($action === 'add_player') {
            $club = sanitize_text_field($_POST['new_club'] ?? '');
            $name = sanitize_text_field($_POST['new_name'] ?? '');
            if ($club && $name) {
                nipgl_get_or_create_player($club, $name);
                echo '<div class="notice notice-success"><p>Player added.</p></div>';
            }
        }

        if ($action === 'delete_player') {
            $pid = intval($_POST['player_id'] ?? 0);
            if ($pid) {
                $wpdb->delete($at, array('player_id' => $pid), array('%d'));
                $wpdb->delete($pt, array('id' => $pid),        array('%d'));
                echo '<div class="notice notice-success"><p>Player deleted.</p></div>';
            }
        }

        if ($action === 'rename_player') {
            $pid  = intval($_POST['player_id']  ?? 0);
            $name = sanitize_text_field($_POST['new_name'] ?? '');
            if ($pid && $name) {
                $wpdb->update($pt, array('name' => $name), array('id' => $pid), array('%s'), array('%d'));
                echo '<div class="notice notice-success"><p>Player renamed.</p></div>';
            }
        }
    }

    // Fetch all players with appearance counts
    $players = $wpdb->get_results("
        SELECT p.id, p.club, p.name,
               COUNT(DISTINCT a.id) as appearances,
               GROUP_CONCAT(DISTINCT a.team ORDER BY a.team SEPARATOR ', ') as teams
        FROM $pt p
        LEFT JOIN $at a ON a.player_id = p.id " .
        ($season_where ? "WHERE 1=1 $season_where " : "") . "
        GROUP BY p.id
        ORDER BY p.club, p.name
    ");

    // Group by club
    $by_club = array();
    foreach ($players as $pl) {
        $by_club[$pl->club][] = $pl;
    }

    // Get clubs list for dropdowns
    $clubs = array_merge(
        array_map(function($c){ return $c['name']; }, nipgl_get_clubs()),
        array_keys($by_club)
    );
    $clubs = array_unique($clubs);
    sort($clubs);

    ?>
    <div class="wrap">
    <h1>Player Tracking</h1>

    <style>
    .nipgl-pt-tabs{display:flex;gap:0;margin-bottom:0;border-bottom:2px solid #1a2e5a}
    .nipgl-pt-tab{padding:8px 18px;cursor:pointer;background:#f0f2f8;border:1px solid #ccc;border-bottom:none;font-weight:600;font-size:13px}
    .nipgl-pt-tab.active{background:#1a2e5a;color:#fff;border-color:#1a2e5a}
    .nipgl-pt-panel{display:none;padding:16px 0}
    .nipgl-pt-panel.active{display:block}
    .nipgl-club-section{margin-bottom:24px}
    .nipgl-club-section h3{background:#1a2e5a;color:#fff;padding:8px 12px;margin:0 0 0;font-size:14px;border-radius:4px 4px 0 0}
    .nipgl-club-section table{margin:0;border-radius:0 0 4px 4px;border-top:none}
    .nipgl-player-actions{display:flex;gap:6px;align-items:center}
    .nipgl-merge-form{background:#f6f7f7;border:1px solid #ddd;padding:16px;border-radius:4px;margin-bottom:16px}
    .nipgl-merge-form select{min-width:220px}
    .nipgl-season-form{background:#f6f7f7;border:1px solid #ddd;padding:16px;border-radius:4px;margin-bottom:16px;max-width:500px}
    .nipgl-season-form label{display:block;margin-bottom:4px;font-weight:600;font-size:13px}
    .nipgl-season-form input[type=date],.nipgl-season-form input[type=text]{width:100%;margin-bottom:12px}
    .nipgl-add-form{background:#f6f7f7;border:1px solid #ddd;padding:16px;border-radius:4px;margin-bottom:16px;max-width:500px}
    .nipgl-add-form label{display:block;margin-bottom:4px;font-weight:600;font-size:13px}
    .nipgl-add-form input,.nipgl-add-form select{width:100%;margin-bottom:12px}
    .nipgl-appearances-zero{color:#999}
    .nipgl-highlight{background:#fff3cd}
    </style>

    <div class="nipgl-pt-tabs">
        <div class="nipgl-pt-tab active" onclick="nipglTab('players')">Players</div>
        <div class="nipgl-pt-tab" onclick="nipglTab('merge')">Merge Duplicates</div>
        <div class="nipgl-pt-tab" onclick="nipglTab('add')">Add Player</div>
        <div class="nipgl-pt-tab" onclick="nipglTab('season')">Season Settings</div>
    </div>

    <script>
    function nipglTab(tab) {
        document.querySelectorAll('.nipgl-pt-tab').forEach(function(t,i){
            t.classList.toggle('active', ['players','merge','add','season'][i]===tab);
        });
        document.querySelectorAll('.nipgl-pt-panel').forEach(function(p){
            p.classList.toggle('active', p.id==='nipgl-panel-'+tab);
        });
    }
    function nipglConfirmDelete(name) {
        return confirm('Delete player "' + name + '" and all their appearance records? This cannot be undone.');
    }
    function nipglStartRename(id, currentName) {
        var newName = prompt('Rename player:', currentName);
        if (newName && newName.trim() && newName.trim() !== currentName) {
            document.getElementById('rename-id-'+id).value = id;
            document.getElementById('rename-name-'+id).value = newName.trim();
            document.getElementById('rename-form-'+id).submit();
        }
    }
    // Populate merge dropdowns when club changes
    function nipglMergeClub(val) {
        var selA = document.getElementById('merge-keep');
        var selB = document.getElementById('merge-remove');
        [selA, selB].forEach(function(sel) {
            Array.from(sel.options).forEach(function(opt) {
                opt.style.display = (!val || opt.dataset.club === val || opt.value === '') ? '' : 'none';
            });
            sel.value = '';
        });
    }
    </script>

    <?php // ── Tab 1: Players ── ?>
    <div class="nipgl-pt-panel active" id="nipgl-panel-players">
        <?php if (!empty($season['label'])): ?>
            <p><strong>Season:</strong> <?php echo esc_html($season['label']); ?>
            <?php if ($season['start'] && $season['end']): ?>
                (<?php echo esc_html($season['start']); ?> – <?php echo esc_html($season['end']); ?>)
            <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if (empty($by_club)): ?>
            <p>No players recorded yet. Players are logged automatically when scorecards are confirmed.</p>
        <?php else: ?>
            <?php foreach ($by_club as $club => $club_players): ?>
            <div class="nipgl-club-section">
                <h3><?php echo esc_html($club); ?> — <?php echo count($club_players); ?> player<?php echo count($club_players)!==1?'s':''; ?></h3>
                <table class="widefat striped">
                <thead><tr>
                    <th>Name</th>
                    <th>Teams played for</th>
                    <th style="text-align:center">Appearances<?php echo $season_where ? ' (this season)' : ''; ?></th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($club_players as $pl): ?>
                <tr<?php echo $pl->appearances == 0 ? ' class="nipgl-appearances-zero"' : ''; ?>>
                    <td><strong><?php echo esc_html($pl->name); ?></strong></td>
                    <td><?php echo esc_html($pl->teams ?: '—'); ?></td>
                    <td style="text-align:center"><?php echo intval($pl->appearances); ?></td>
                    <td>
                        <div class="nipgl-player-actions">
                            <button class="button button-small" onclick="nipglStartRename(<?php echo $pl->id; ?>, <?php echo json_encode($pl->name); ?>)">Rename</button>
                            <form method="post" style="display:inline" onsubmit="return nipglConfirmDelete(<?php echo json_encode($pl->name); ?>)">
                                <?php wp_nonce_field('nipgl_players_nonce','nipgl_players_nonce_field'); ?>
                                <input type="hidden" name="nipgl_players_action" value="delete_player">
                                <input type="hidden" name="player_id" value="<?php echo $pl->id; ?>">
                                <button type="submit" class="button button-small button-link-delete">Delete</button>
                            </form>
                            <?php // Hidden rename forms ?>
                            <form method="post" id="rename-form-<?php echo $pl->id; ?>" style="display:none">
                                <?php wp_nonce_field('nipgl_players_nonce','nipgl_players_nonce_field'); ?>
                                <input type="hidden" name="nipgl_players_action" value="rename_player">
                                <input type="hidden" name="player_id" id="rename-id-<?php echo $pl->id; ?>" value="">
                                <input type="hidden" name="new_name"  id="rename-name-<?php echo $pl->id; ?>" value="">
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                </table>
            </div>
            <?php endforeach; ?>

            <p style="margin-top:16px">
                <a href="<?php echo admin_url('admin-post.php?action=nipgl_export_players&_wpnonce='.wp_create_nonce('nipgl_export_players')); ?>" class="button button-primary">⬇ Export to Excel</a>
            </p>
        <?php endif; ?>
    </div>

    <?php // ── Tab 2: Merge ── ?>
    <div class="nipgl-pt-panel" id="nipgl-panel-merge">
        <h2>Merge Duplicate Players</h2>
        <p>Use this when the same person appears under two different spellings (e.g. "J Smith" and "John Smith"). All appearances will be moved to the player you keep, and the other record deleted.</p>

        <?php if (count($players) < 2): ?>
            <p>Not enough players to merge yet.</p>
        <?php else: ?>
        <form method="post" class="nipgl-merge-form" onsubmit="return confirm('Merge these two players? The removed player record cannot be recovered.')">
            <?php wp_nonce_field('nipgl_players_nonce','nipgl_players_nonce_field'); ?>
            <input type="hidden" name="nipgl_players_action" value="merge">

            <label>Filter by club:</label>
            <select onchange="nipglMergeClub(this.value)" style="margin-bottom:12px;min-width:200px">
                <option value="">— All clubs —</option>
                <?php foreach ($clubs as $c): ?>
                <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                <?php endforeach; ?>
            </select><br>

            <table style="width:100%;max-width:600px">
            <tr>
                <td style="padding-right:16px">
                    <label><strong>Keep</strong> (canonical name)</label>
                    <select name="keep_id" id="merge-keep" required style="width:100%;margin-top:4px">
                        <option value="">— Select player —</option>
                        <?php foreach ($players as $pl): ?>
                        <option value="<?php echo $pl->id; ?>" data-club="<?php echo esc_attr($pl->club); ?>">
                            <?php echo esc_html($pl->club . ' — ' . $pl->name . ' (' . $pl->appearances . ' apps)'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <label><strong>Remove</strong> (duplicate to delete)</label>
                    <select name="remove_id" id="merge-remove" required style="width:100%;margin-top:4px">
                        <option value="">— Select player —</option>
                        <?php foreach ($players as $pl): ?>
                        <option value="<?php echo $pl->id; ?>" data-club="<?php echo esc_attr($pl->club); ?>">
                            <?php echo esc_html($pl->club . ' — ' . $pl->name . ' (' . $pl->appearances . ' apps)'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            </table>
            <br>
            <button type="submit" class="button button-primary">Merge Players</button>
        </form>
        <?php endif; ?>
    </div>

    <?php // ── Tab 3: Add player ── ?>
    <div class="nipgl-pt-panel" id="nipgl-panel-add">
        <h2>Add Player Manually</h2>
        <p>Players are added automatically from confirmed scorecards. Use this to add someone who hasn't appeared on a scorecard yet.</p>
        <form method="post" class="nipgl-add-form">
            <?php wp_nonce_field('nipgl_players_nonce','nipgl_players_nonce_field'); ?>
            <input type="hidden" name="nipgl_players_action" value="add_player">
            <label>Club</label>
            <select name="new_club" required>
                <option value="">— Select club —</option>
                <?php foreach ($clubs as $c): ?>
                <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Player name</label>
            <input type="text" name="new_name" required placeholder="e.g. J. Smith">
            <button type="submit" class="button button-primary">Add Player</button>
        </form>
    </div>

    <?php // ── Tab 4: Season settings ── ?>
    <div class="nipgl-pt-panel" id="nipgl-panel-season">
        <h2>Season Settings</h2>
        <p>Set the current season date range. Appearance counts on the Players tab will only count matches within this range. Leave blank to show all-time totals.</p>
        <form method="post" class="nipgl-season-form">
            <?php wp_nonce_field('nipgl_players_nonce','nipgl_players_nonce_field'); ?>
            <input type="hidden" name="nipgl_players_action" value="save_season">
            <label>Season label (e.g. "2025/26")</label>
            <input type="text" name="season_label" value="<?php echo esc_attr($season['label'] ?? ''); ?>" placeholder="2025/26">
            <label>Season start date</label>
            <input type="date" name="season_start" value="<?php echo esc_attr($season['start'] ?? ''); ?>">
            <label>Season end date</label>
            <input type="date" name="season_end" value="<?php echo esc_attr($season['end'] ?? ''); ?>">
            <button type="submit" class="button button-primary">Save Season</button>
        </form>
    </div>

    </div><!-- .wrap -->
    <?php
}

// ── Export to Excel ───────────────────────────────────────────────────────────
add_action('admin_post_nipgl_export_players', 'nipgl_export_players_xlsx');
function nipgl_export_players_xlsx() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('nipgl_export_players');

    global $wpdb;
    $pt = nipgl_players_table();
    $at = nipgl_appearances_table();
    $season_where = nipgl_season_where();
    $season = nipgl_get_season();

    // Fetch all appearances with player/club info
    $rows = $wpdb->get_results("
        SELECT p.club, p.name, a.team, a.match_title, a.match_date, a.rink
        FROM $pt p
        JOIN $at a ON a.player_id = p.id
        " . ($season_where ? "WHERE 1=1 $season_where" : "") . "
        ORDER BY p.club, p.name, a.match_date, a.match_title
    ");

    // Group by club
    $by_club = array();
    foreach ($rows as $row) {
        $by_club[$row->club][] = $row;
    }

    // Also get players with zero appearances this season
    $all_players = $wpdb->get_results("SELECT id, club, name FROM $pt ORDER BY club, name");
    $players_with_apps = array();
    foreach ($rows as $r) {
        // We'll track this via appearance data
    }

    // Build CSV-style data per club, then output as simple HTML table Excel file
    $label = !empty($season['label']) ? ' - ' . $season['label'] : '';
    $filename = 'nipgl-players' . str_replace('/', '-', $label) . '.xls';

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets>';

    // Sheet names
    $sheet_names = array('Summary');
    foreach (array_keys($by_club) as $club) {
        $sheet_names[] = substr(preg_replace('/[^A-Za-z0-9 ]/', '', $club), 0, 31);
    }
    // Also add clubs with no appearances
    foreach ($all_players as $pl) {
        $sn = substr(preg_replace('/[^A-Za-z0-9 ]/', '', $pl->club), 0, 31);
        if (!in_array($sn, $sheet_names)) $sheet_names[] = $sn;
    }
    $sheet_names = array_unique($sheet_names);

    foreach ($sheet_names as $sn) {
        echo '<x:ExcelWorksheet><x:Name>' . esc_html($sn) . '</x:Name><x:WorksheetSource HRef="#' . esc_attr($sn) . '"/></x:ExcelWorksheet>';
    }
    echo '</x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    echo '<style>
        td,th{font-family:Arial;font-size:11pt;border:1px solid #ccc;padding:4px 8px}
        th{background:#1a2e5a;color:#fff;font-weight:bold}
        .hdr{background:#d0d8ee;font-weight:bold}
    </style></head><body>';

    // Summary sheet
    echo '<table id="Summary"><tr><th colspan="4">NIPGL Player Appearances' . esc_html($label) . '</th></tr>';
    echo '<tr class="hdr"><td>Club</td><td>Player</td><td>Teams</td><td>Appearances</td></tr>';

    // Get summary data
    $summary = $wpdb->get_results("
        SELECT p.club, p.name,
               COUNT(DISTINCT a.id) as apps,
               GROUP_CONCAT(DISTINCT a.team ORDER BY a.team SEPARATOR ', ') as teams
        FROM $pt p
        LEFT JOIN $at a ON a.player_id = p.id " .
        ($season_where ? "WHERE 1=1 $season_where" : "") . "
        GROUP BY p.id ORDER BY p.club, p.name
    ");
    foreach ($summary as $s) {
        echo '<tr><td>' . esc_html($s->club) . '</td><td>' . esc_html($s->name) . '</td><td>'
            . esc_html($s->teams ?: '—') . '</td><td style="text-align:center">' . intval($s->apps) . '</td></tr>';
    }
    echo '</table>';

    // One sheet per club
    foreach ($sheet_names as $sn) {
        if ($sn === 'Summary') continue;
        // Find the matching club name
        $club_name = '';
        foreach (array_keys($by_club) as $c) {
            if (substr(preg_replace('/[^A-Za-z0-9 ]/', '', $c), 0, 31) === $sn) { $club_name = $c; break; }
        }
        if (!$club_name) {
            // Club exists but no appearances — just show player list
            echo '<table id="' . esc_attr($sn) . '"><tr><th colspan="4">' . esc_html($sn) . '</th></tr>';
            echo '<tr class="hdr"><td>Player</td><td colspan="3">No appearances recorded this season</td></tr>';
            foreach ($all_players as $pl) {
                if (substr(preg_replace('/[^A-Za-z0-9 ]/', '', $pl->club), 0, 31) === $sn) {
                    echo '<tr><td>' . esc_html($pl->name) . '</td><td colspan="3"></td></tr>';
                }
            }
            echo '</table>';
            continue;
        }

        echo '<table id="' . esc_attr($sn) . '">';
        echo '<tr><th colspan="5">' . esc_html($club_name) . esc_html($label) . '</th></tr>';
        echo '<tr class="hdr"><td>Player</td><td>Team</td><td>Match</td><td>Date</td><td>Rink</td></tr>';
        foreach ($by_club[$club_name] as $r) {
            echo '<tr><td>' . esc_html($r->name) . '</td><td>' . esc_html($r->team) . '</td>'
                . '<td>' . esc_html($r->match_title) . '</td><td>' . esc_html($r->match_date) . '</td>'
                . '<td style="text-align:center">' . intval($r->rink) . '</td></tr>';
        }
        echo '</table>';
    }

    echo '</body></html>';
    exit;
}
