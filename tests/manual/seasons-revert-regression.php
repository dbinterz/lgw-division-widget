<?php
/**
 * tests/manual/seasons-revert-regression.php
 *
 * Self-contained regression harness for lgw_seasons_make_active() — the pure
 * transform behind the "⏮ Make Active" (revert an archived season) button added
 * in 2026.31.6. Runs with plain PHP — no PHPUnit / WordPress required:
 *
 *     php tests/manual/seasons-revert-regression.php
 *
 * Exit code 0 = all checks pass, 1 = a check failed.
 *
 * It stubs the two WordPress functions lgw-seasons.php calls at load time
 * (add_submenu_page / add_action are wired via add_action only here), requires
 * the real lgw-seasons.php, then exercises the reactivation transform:
 *
 *   1. Reverting an archived season demotes the current active one, promotes the
 *      target, and returns the target's divisions for the Drive/Sheets sync.
 *   2. Exactly one season is active afterwards, and the list is re-sorted with
 *      the active season first.
 *   3. Reverting the already-active season, or an unknown ID, returns null
 *      (the handler turns this into the "not_found" error) and changes nothing.
 *   4. Scorecard season tags are irrelevant here — the transform only flips the
 *      `active` flag and never rewrites season IDs, so archived data is safe.
 */

error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'display_errors', 1 );

// ── Minimal WordPress stubs (load-time only) ──────────────────────────────────
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function get_option( $k, $d = false ) { return $d; }

require __DIR__ . '/../../lgw-seasons.php';

// ── Tiny assert harness ───────────────────────────────────────────────────────
$failures = 0;
function check( $label, $cond ) {
    global $failures;
    if ( $cond ) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label\n";
        $failures++;
    }
}
function active_id( $seasons ) {
    foreach ( $seasons as $s ) {
        if ( ! empty( $s['active'] ) ) return $s['id'];
    }
    return null;
}
function count_active( $seasons ) {
    $n = 0;
    foreach ( $seasons as $s ) {
        if ( ! empty( $s['active'] ) ) $n++;
    }
    return $n;
}

// ── Fixture: one active (2027) + two archived (2026, 2025) ────────────────────
function fixture() {
    return array(
        array( 'id' => '2027', 'label' => '2027 Season', 'active' => true,
               'divisions' => array( array( 'division' => 'Div 1', 'csv_url' => 'https://x/2027d1' ) ) ),
        array( 'id' => '2026', 'label' => '2026 Season', 'active' => false,
               'divisions' => array(
                   array( 'division' => 'Div 1', 'csv_url' => 'https://x/2026d1' ),
                   array( 'division' => 'Div 2', 'csv_url' => 'https://x/2026d2' ),
               ) ),
        array( 'id' => '2025', 'label' => '2025 Season', 'active' => false,
               'divisions' => array( array( 'division' => 'Div 1', 'csv_url' => 'https://x/2025d1' ) ) ),
    );
}

echo "── Revert an archived season (2026) ──\n";
$r = lgw_seasons_make_active( fixture(), '2026' );
check( 'returns a result (not null)',            $r !== null );
check( '2026 is now the active season',          active_id( $r['seasons'] ) === '2026' );
check( 'exactly one active season remains',      count_active( $r['seasons'] ) === 1 );
check( 'previously-active 2027 is now archived',
       ( function( $ss ) { foreach ( $ss as $s ) { if ( $s['id'] === '2027' ) return empty( $s['active'] ); } return false; } )( $r['seasons'] ) );
check( 'active season sorted first',             $r['seasons'][0]['id'] === '2026' );
check( 'returned divisions are 2026\'s (2 divs)', count( $r['divisions'] ) === 2 );
check( 'no seasons were lost',                    count( $r['seasons'] ) === 3 );

echo "\n── Reverting the already-active season is a no-op ──\n";
$r2 = lgw_seasons_make_active( fixture(), '2027' );
check( 'returns null for the active season',     $r2 === null );

echo "\n── Reverting an unknown season is a no-op ──\n";
$r3 = lgw_seasons_make_active( fixture(), '1999' );
check( 'returns null for an unknown ID',         $r3 === null );

echo "\n── Empty / missing target id ──\n";
$r4 = lgw_seasons_make_active( fixture(), '' );
check( 'returns null for an empty ID',           $r4 === null );

echo "\n";
if ( $failures ) {
    echo "RESULT: $failures check(s) FAILED\n";
    exit( 1 );
}
echo "RESULT: all checks passed\n";
exit( 0 );
