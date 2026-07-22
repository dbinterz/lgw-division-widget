<?php
/**
 * tests/manual/gchamp-knockout-regression.php
 *
 * Self-contained regression harness for the group-championship knockout code
 * in lgw-gchamp.php. Runs with plain PHP — no PHPUnit / WordPress required:
 *
 *     php tests/manual/gchamp-knockout-regression.php
 *
 * Exit code 0 = all checks pass, 1 = a check failed.
 *
 * It stubs the handful of WordPress functions the plugin files touch at load
 * time, requires the real lgw-draw.php / lgw-champ.php / lgw-gchamp.php, then
 * exercises the two knockout bugs fixed in 2026.30.4:
 *
 *   1. Seeding a bracket whose qualifier count is not a power of two (byes get
 *      a null name) must not fatal in the club-extraction callback.
 *      Regression: TypeError "lgw_gchamp_entry_club(): Argument #1 must be of
 *      type string, null given" → WordPress HTML error page → the browser's
 *      "Unexpected token '<' … is not valid JSON".
 *
 *   2. Clearing or editing a knockout score must keep the next round in sync —
 *      a cleared/changed result must not leave a stale winner downstream, and a
 *      score edit that keeps the same winner must not wipe an already-played
 *      later round. Covered by lgw_gchamp_set_ko_advance().
 *
 * The fixture in fixtures/gchamp-over55-pairs.ser is the real "Over 55 Pairs
 * 2026" championship (three days, four qualifiers each → 12 qualifiers → a
 * 16-slot bracket with 4 byes) that first surfaced bug #1 on the live site.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'display_errors', 1 );

// ── Minimal WordPress stubs (load-time + option store) ────────────────────────
$GLOBALS['__opt'] = array();
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function register_activation_hook( ...$a ) {}
function register_deactivation_hook( ...$a ) {}
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_textarea_field( $s ) { return (string) $s; }
function wp_unslash( $s ) { return $s; }
function absint( $n ) { return abs( intval( $n ) ); }
function current_user_can( $c ) { return true; }
function check_ajax_referer( $a, $b = false, $die = true ) { return 1; }
function wp_verify_nonce( $a, $b ) { return 1; }
function wp_create_nonce( $a ) { return 'nonce'; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function admin_url( $p = '' ) { return $p; }
function wp_redirect( $u ) {}
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; }
function get_transient( $k ) { return false; }
function set_transient( ...$a ) { return true; }
function delete_transient( $k ) { return true; }
function wp_next_scheduled( ...$a ) { return false; }
function wp_schedule_event( ...$a ) {}
function do_action( ...$a ) {}
function apply_filters( $t, $v ) { return $v; }
function selected( $a, $b = true ) { return $a == $b ? 'selected' : ''; }
function checked( $a, $b = true ) { return $a == $b ? 'checked' : ''; }
function number_format_i18n( $n ) { return $n; }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function get_current_user_id() { return 1; }
function wp_kses_post( $s ) { return $s; }
function wp_enqueue_script( ...$a ) {}
function wp_enqueue_style( ...$a ) {}
function wp_register_script( ...$a ) {}
function wp_register_style( ...$a ) {}
function wp_localize_script( ...$a ) {}
function plugins_url( $p = '', $f = '' ) { return $p; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return ''; }
class WP_Error {
    public $c, $m;
    function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
    function get_error_message() { return $this->m; }
    function get_error_code() { return $this->c; }
}
// wp_send_json_* normally halt the request; here they throw so a test can
// capture the AJAX handler's outcome.
class LGW_JsonDone extends Exception {
    public $ok, $data;
    function __construct( $ok, $data ) { $this->ok = $ok; $this->data = $data; parent::__construct( 'json' ); }
}
function wp_send_json_success( $d = null ) { throw new LGW_JsonDone( true, $d ); }
function wp_send_json_error( $d = null ) { throw new LGW_JsonDone( false, $d ); }
if ( ! defined( 'ABSPATH' ) )    define( 'ABSPATH', '/tmp/' );
if ( ! defined( 'LGW_VERSION' ) ) define( 'LGW_VERSION', 'test' );

// ── Load the real plugin code under test ──────────────────────────────────────
$plugin = dirname( __DIR__, 2 ) . '/';
require $plugin . 'lgw-draw.php';
require $plugin . 'lgw-champ.php';
require $plugin . 'lgw-gchamp.php';

// ── Tiny assertion helpers ────────────────────────────────────────────────────
$GLOBALS['__fails'] = 0;
function check( string $label, bool $ok ): void {
    echo ( $ok ? "  PASS  " : "  FAIL  " ) . $label . "\n";
    if ( ! $ok ) $GLOBALS['__fails']++;
}
function eq( string $label, $expected, $actual ): void {
    $ok = $expected === $actual;
    check( $label, $ok );
    if ( ! $ok ) {
        echo "        expected: " . var_export( $expected, true ) . "\n";
        echo "        actual:   " . var_export( $actual, true ) . "\n";
    }
}

// Build a fresh 4-slot day bracket: two semi-finals feeding one final.
function make_bracket(): array {
    return array(
        'title' => 'Test Knockout',
        'size'  => 4,
        'rounds' => array(
            array( 'round' => 0, 'label' => 'Semi-final', 'matches' => array(
                array( 'match' => 0, 'round' => 0, 'home' => 'A', 'away' => 'B', 'home_score' => null, 'away_score' => null, 'bye' => false ),
                array( 'match' => 1, 'round' => 0, 'home' => 'C', 'away' => 'D', 'home_score' => null, 'away_score' => null, 'bye' => false ),
            ) ),
            array( 'round' => 1, 'label' => 'Final', 'matches' => array(
                array( 'match' => 0, 'round' => 1, 'home' => null, 'away' => null, 'home_score' => null, 'away_score' => null, 'bye' => false ),
            ) ),
        ),
    );
}
function final_match( array $b ): array { return $b['rounds'][1]['matches'][0]; }

echo "== Test 1: build_knockout with byes (non-power-of-two qualifiers) ==\n";
$champ = unserialize( file_get_contents( __DIR__ . '/fixtures/gchamp-over55-pairs.ser' ) );
if ( ! is_array( $champ ) ) {
    echo "  FAIL  fixture failed to unserialize\n";
    exit( 1 );
}
$GLOBALS['__opt']['lgw_gchamp_nipgl-over55-pairs-2026'] = $champ;
$total_q = 0;
foreach ( $champ['days'] as $d ) { $total_q += count( $d['qualifiers'] ?? array() ); }
eq( "fixture has 12 qualifiers across 3 days", 12, $total_q );
try {
    $c = $champ;
    $res = lgw_gchamp_build_knockout( 'nipgl-over55-pairs-2026', $c );
    check( "build_knockout did not fatal on null-name byes", true );
    check( "build_knockout returned success (not WP_Error)", ! is_wp_error( $res ) );
    check( "bracket stored on champ", ! empty( $c['ko_bracket']['rounds'] ) );
    $warned_16 = false;
    foreach ( $res['warnings'] ?? array() as $w ) { if ( strpos( $w, '16' ) !== false ) $warned_16 = true; }
    check( "bracket rounded up to 16 slots to fit 12 qualifiers", $warned_16 );
} catch ( \Throwable $e ) {
    check( "build_knockout did not fatal on null-name byes — got " . get_class( $e ) . ': ' . $e->getMessage(), false );
}

echo "\n== Test 2: set_ko_advance keeps the next round in sync ==\n";

// A — a decisive semi advances its winner into the final
$b = make_bracket();
$b['rounds'][0]['matches'][1]['home_score'] = 1; // C
$b['rounds'][0]['matches'][1]['away_score'] = 2; // D wins
lgw_gchamp_set_ko_advance( $b, 0, 1, 'D' );
eq( "A: SF2 winner D advances to final away slot", 'D', final_match( $b )['away'] );

// B — clearing that semi must vacate the downstream slot (the 2026.30.4 bug)
lgw_gchamp_set_ko_advance( $b, 0, 1, null );
eq( "B: clearing SF2 vacates final away slot", null, final_match( $b )['away'] );

// C — both semis played, final played, then SF2 edited to a different winner:
//     downstream slot swaps AND the now-invalid final score is cleared
$b = make_bracket();
lgw_gchamp_set_ko_advance( $b, 0, 0, 'A' ); // SF1 -> A to final home
lgw_gchamp_set_ko_advance( $b, 0, 1, 'D' ); // SF2 -> D to final away
$b['rounds'][1]['matches'][0]['home_score'] = 5;
$b['rounds'][1]['matches'][0]['away_score'] = 4;
lgw_gchamp_set_ko_advance( $b, 0, 1, 'C' ); // SF2 re-decided: C now wins
eq( "C: final away swaps to new winner C", 'C', final_match( $b )['away'] );
eq( "C: invalidated final home_score cleared", null, final_match( $b )['home_score'] );
eq( "C: invalidated final away_score cleared", null, final_match( $b )['away_score'] );
eq( "C: final home (from unchanged SF1) preserved", 'A', final_match( $b )['home'] );

// D — editing a semi score but keeping the SAME winner must NOT disturb a
//     played final
$b = make_bracket();
lgw_gchamp_set_ko_advance( $b, 0, 0, 'A' );
lgw_gchamp_set_ko_advance( $b, 0, 1, 'D' );
$b['rounds'][1]['matches'][0]['home_score'] = 5;
$b['rounds'][1]['matches'][0]['away_score'] = 4;
lgw_gchamp_set_ko_advance( $b, 0, 1, 'D' ); // same winner
eq( "D: final away unchanged", 'D', final_match( $b )['away'] );
eq( "D: played final home_score preserved", 5, final_match( $b )['home_score'] );
eq( "D: played final away_score preserved", 4, final_match( $b )['away_score'] );

echo "\n== Test 3: manual seeding — lgw_ajax_gchamp_ko_set_slot ==\n";
// Give day 0 a clean 2-slot-per-SF bracket built from its four qualifiers.
$champ = unserialize( file_get_contents( __DIR__ . '/fixtures/gchamp-over55-pairs.ser' ) );
$q0 = $champ['days'][0]['qualifiers']; // [M Stewart, B McComb, B Trimble, P Canning]
$champ['days'][0]['ko_bracket'] = array(
    'title' => 'D0', 'size' => 4,
    'rounds' => array(
        array( 'round' => 0, 'label' => 'Semi-final', 'matches' => array(
            array( 'match' => 0, 'round' => 0, 'home' => $q0[0], 'away' => $q0[3], 'home_score' => null, 'away_score' => null, 'bye' => false ),
            array( 'match' => 1, 'round' => 0, 'home' => $q0[1], 'away' => $q0[2], 'home_score' => null, 'away_score' => null, 'bye' => false ),
        ) ),
        array( 'round' => 1, 'label' => 'Final', 'matches' => array(
            array( 'match' => 0, 'round' => 1, 'home' => null, 'away' => null, 'home_score' => null, 'away_score' => null, 'bye' => false ),
        ) ),
    ),
);
$GLOBALS['__opt']['lgw_gchamp_nipgl-over55-pairs-2026'] = $champ;

function call_set_slot( array $args ): array {
    $_POST = array_merge( array( 'champ_id' => 'nipgl-over55-pairs-2026', 'day_id' => '0', 'nonce' => 'x' ), $args );
    try { lgw_ajax_gchamp_ko_set_slot(); return array( true, null ); }
    catch ( LGW_JsonDone $j ) { return array( $j->ok, $j->data ); }
}
function d0_r0( $slot_match, $slot ) {
    return $GLOBALS['__opt']['lgw_gchamp_nipgl-over55-pairs-2026']['days'][0]['ko_bracket']['rounds'][0]['matches'][ $slot_match ][ $slot ];
}

// Reject an entry that is not a qualifier for the day
list( $ok, $msg ) = call_set_slot( array( 'ko_match' => '0', 'slot' => 'home', 'value' => 'Nobody, Nowhere' ) );
check( "rejects a non-qualifier value", $ok === false );

// Move an existing qualifier into another slot → it must leave its old slot.
// Submit the WHITESPACE-COLLAPSED form the browser + sanitize_text_field()
// produce (entry names hold runs of spaces); the handler must still match it
// and store the exact canonical form.
$collapsed = preg_replace( '/\s+/', ' ', $q0[1] ); // e.g. "B McComb/B Worthington, Balmoral"
check( "collapsed value really differs from stored", $collapsed !== $q0[1] );
list( $ok, ) = call_set_slot( array( 'ko_match' => '0', 'slot' => 'away', 'value' => $collapsed ) );
check( "manual seed succeeded with collapsed whitespace", $ok === true );
eq( "SF1 away stored as canonical multi-space form", $q0[1], d0_r0( 0, 'away' ) );
eq( "duplicate cleared from SF2 home (was the same entry)", null, d0_r0( 1, 'home' ) );

// Vacate a slot with an empty value
list( $ok, ) = call_set_slot( array( 'ko_match' => '1', 'slot' => 'away', 'value' => '' ) );
eq( "empty value vacates the slot to TBD", null, d0_r0( 1, 'away' ) );

echo "\n";
if ( $GLOBALS['__fails'] > 0 ) {
    echo $GLOBALS['__fails'] . " check(s) FAILED\n";
    exit( 1 );
}
echo "All checks passed.\n";
exit( 0 );
