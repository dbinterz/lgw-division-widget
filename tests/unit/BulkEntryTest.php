<?php
/**
 * tests/unit/BulkEntryTest.php
 * Unit tests for bulk club entry parsing + player validation (capitation warning).
 * Pure-PHP-stub style (see bootstrap.php).
 */

use PHPUnit\Framework\TestCase;

// Load-time stubs lgw-entry.php needs (this file may load before EntryTest.php).
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $tag, $cb ) {} }
if ( ! function_exists( 'lgw_audit_log' ) )  { function lgw_audit_log( $id, $action, $note, $before = array(), $after = array() ) {} }
if ( ! function_exists( 'lgw_get_clubs' ) )  { function lgw_get_clubs() { return get_option( 'lgw_clubs', array() ); } }
if ( ! function_exists( 'get_posts' ) )      { function get_posts( $args ) { return array(); } }
if ( ! function_exists( 'wp_list_pluck' ) )  {
	function wp_list_pluck( $list, $field ) {
		return array_map( function ( $r ) use ( $field ) { return is_array( $r ) ? ( $r[ $field ] ?? null ) : ( $r->$field ?? null ); }, $list );
	}
}
if ( ! function_exists( 'user_can' ) )            { function user_can( $uid, $cap ) { return false; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 7; } }

if ( ! function_exists( 'lgw_players_table' ) ) {
	function lgw_players_table() { return 'wp_lgw_players'; }
}
if ( ! function_exists( 'lgw_clean_player_name' ) ) {
	function lgw_clean_player_name( $n ) { return trim( preg_replace( '/ {2,}/', ' ', rtrim( trim( (string) $n ), '*' ) ) ); }
}

/** Fake $wpdb whose get_var matches (club,name) against a seeded roster. */
if ( ! class_exists( 'LGW_Roster_Wpdb' ) ) {
	class LGW_Roster_Wpdb {
		/** @var array<int, array{club:string,name:string}> */
		public array $roster = array();
		public function prepare( $q, ...$args ) {
			foreach ( $args as $a ) $q = preg_replace( '/%s/', "'" . addslashes( (string) $a ) . "'", $q, 1 );
			return $q;
		}
		public function get_var( $q ) {
			if ( ! preg_match( "/club = '([^']*)' AND name = '([^']*)'/", $q, $m ) ) return null;
			foreach ( $this->roster as $i => $r ) {
				if ( $r['club'] === $m[1] && $r['name'] === $m[2] ) return $i + 1;
			}
			return null;
		}
	}
}

if ( ! function_exists( 'lgw_entry_parse_bulk' ) ) {
	require_once __DIR__ . '/../../lgw-entry.php';
}

final class BulkEntryTest extends TestCase {

	protected function setUp(): void {
		WpStubs::resetOptions();
	}

	// ── Parsing ───────────────────────────────────────────────────────────────
	public function test_parse_singles_newline(): void {
		$r = lgw_entry_parse_bulk( "A Smith\nB Jones\nC McKee", 'singles' );
		$this->assertCount( 3, $r['entries'] );
		$this->assertSame( array( 'A Smith' ), $r['entries'][0]['players'] );
		$this->assertEmpty( $r['errors'] );
	}

	public function test_parse_singles_comma_and_newline_mixed(): void {
		$r = lgw_entry_parse_bulk( "A Smith, B Jones\nC McKee", 'singles' );
		$this->assertCount( 3, $r['entries'] );
		$this->assertSame( array( 'C McKee' ), $r['entries'][2]['players'] );
	}

	public function test_parse_pairs_slash(): void {
		$r = lgw_entry_parse_bulk( "A Smith / B Jones\nC McKee / D Watt", 'pairs' );
		$this->assertCount( 2, $r['entries'] );
		$this->assertSame( array( 'A Smith', 'B Jones' ), $r['entries'][0]['players'] );
		$this->assertEmpty( $r['errors'] );
	}

	public function test_parse_pairs_wrong_count_is_error_not_entry(): void {
		$r = lgw_entry_parse_bulk( "A Smith\nC McKee / D Watt", 'pairs' );
		$this->assertCount( 1, $r['entries'] );          // only the valid pair
		$this->assertCount( 1, $r['errors'] );           // the lone name flagged
		$this->assertStringContainsString( 'expected 2', $r['errors'][0] );
	}

	public function test_parse_fours_slash(): void {
		$r = lgw_entry_parse_bulk( "A / B / C / D", 'fours' );
		$this->assertSame( array( 'A', 'B', 'C', 'D' ), $r['entries'][0]['players'] );
	}

	public function test_parse_ignores_blank_lines_and_trims(): void {
		$r = lgw_entry_parse_bulk( "  A Smith  \n\n\n  B Jones \n", 'singles' );
		$this->assertCount( 2, $r['entries'] );
		$this->assertSame( array( 'A Smith' ), $r['entries'][0]['players'] );
	}

	// ── Player validation / capitation ────────────────────────────────────────
	public function test_unknown_players_flags_missing(): void {
		$wpdb = new LGW_Roster_Wpdb();
		$wpdb->roster = array( array( 'club' => 'Ballymena', 'name' => 'A Smith' ) );
		$GLOBALS['wpdb'] = $wpdb;
		$unknown = lgw_entry_unknown_players( 'Ballymena', array( 'A Smith', 'Z Nobody' ) );
		$this->assertSame( array( 'Z Nobody' ), $unknown );
	}

	public function test_unknown_players_all_known_returns_empty(): void {
		$wpdb = new LGW_Roster_Wpdb();
		$wpdb->roster = array(
			array( 'club' => 'Ballymena', 'name' => 'A Smith' ),
			array( 'club' => 'Ballymena', 'name' => 'B Jones' ),
		);
		$GLOBALS['wpdb'] = $wpdb;
		$this->assertSame( array(), lgw_entry_unknown_players( 'Ballymena', array( 'A Smith', 'B Jones' ) ) );
	}

	public function test_unknown_players_respects_club(): void {
		$wpdb = new LGW_Roster_Wpdb();
		$wpdb->roster = array( array( 'club' => 'Belmont', 'name' => 'A Smith' ) );
		$GLOBALS['wpdb'] = $wpdb;
		// Same name, different club → unknown for Ballymena.
		$this->assertSame( array( 'A Smith' ), lgw_entry_unknown_players( 'Ballymena', array( 'A Smith' ) ) );
	}

	public function test_capitation_message_singular_plural(): void {
		$this->assertSame( '', lgw_entry_capitation_message( 'Ballymena', array() ) );
		$one = lgw_entry_capitation_message( 'Ballymena', array( 'Z Nobody' ) );
		$this->assertStringContainsString( 'Z Nobody is not', $one );
		$this->assertStringContainsString( 'capitation', $one );
		$two = lgw_entry_capitation_message( 'Ballymena', array( 'X', 'Y' ) );
		$this->assertStringContainsString( 'X, Y are not', $two );
	}
}
