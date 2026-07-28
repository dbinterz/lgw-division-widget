<?php
/**
 * tests/unit/EntryTest.php
 * Unit tests for the championship entry ledger + projection + policy + Stripe sig.
 *
 * Pure-PHP-stub style (see bootstrap.php). Loads lgw-entry.php directly after
 * defining the few extra WP/LGW stubs it touches at load and call time.
 */

use PHPUnit\Framework\TestCase;

// ── Extra stubs lgw-entry.php needs beyond the shared bootstrap ────────────────
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $tag, $cb ) {} }
if ( ! function_exists( 'lgw_audit_log' ) )  { function lgw_audit_log( $id, $action, $note, $before = array(), $after = array() ) {} }
if ( ! function_exists( 'lgw_get_clubs' ) )  { function lgw_get_clubs() { return get_option( 'lgw_clubs', array() ); } }
if ( ! function_exists( 'get_posts' ) )      { function get_posts( $args ) { return array(); } }
if ( ! function_exists( 'wp_list_pluck' ) )  {
	function wp_list_pluck( $list, $field ) {
		return array_map( function ( $r ) use ( $field ) { return is_array( $r ) ? ( $r[ $field ] ?? null ) : ( $r->$field ?? null ); }, $list );
	}
}
if ( ! function_exists( 'user_can' ) )        { function user_can( $uid, $cap ) { return false; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 7; } }

if ( ! defined( 'LGW_ENTRY_CPT' ) ) {
	require_once __DIR__ . '/../../lgw-entry.php';
}

final class EntryTest extends TestCase {

	protected function setUp(): void {
		WpStubs::resetOptions();
	}

	// ── Discipline → player count ─────────────────────────────────────────────
	public function test_player_count(): void {
		$this->assertSame( 1, lgw_entry_player_count( 'singles' ) );
		$this->assertSame( 2, lgw_entry_player_count( 'pairs' ) );
		$this->assertSame( 3, lgw_entry_player_count( 'triples' ) );
		$this->assertSame( 4, lgw_entry_player_count( 'fours' ) );
		$this->assertSame( 1, lgw_entry_player_count( 'nonsense' ) );
	}

	// ── Entry-string builder ──────────────────────────────────────────────────
	public function test_build_string_singles(): void {
		$this->assertSame( 'J. Smith, Ards', lgw_entry_build_string( array( 'J. Smith' ), 'Ards' ) );
	}

	public function test_build_string_pairs_joins_with_ampersand(): void {
		$this->assertSame( 'A. One & B. Two, Bangor', lgw_entry_build_string( array( 'A. One', 'B. Two' ), 'Bangor' ) );
	}

	public function test_build_string_strips_commas_from_names(): void {
		// A comma in a name would corrupt the "first comma = club" invariant the draw relies on.
		$out = lgw_entry_build_string( array( 'Smith, John' ), 'Ards' );
		$this->assertSame( 'Smith John, Ards', $out );
		$this->assertSame( 1, substr_count( $out, ',' ) );
	}

	public function test_build_string_collapses_whitespace(): void {
		$this->assertSame( 'A B, Club', lgw_entry_build_string( array( '  A   B  ' ), '  Club ' ) );
	}

	// ── Normalisation / dedup ─────────────────────────────────────────────────
	public function test_norm_is_case_and_whitespace_insensitive(): void {
		$this->assertSame(
			lgw_entry_norm( 'J. Smith,  Ards' ),
			lgw_entry_norm( 'j. smith, ards' )
		);
	}

	// ── Per-club policy resolution ────────────────────────────────────────────
	public function test_policy_defaults_to_open(): void {
		$this->assertSame( 'open', lgw_entry_policy_for_club( 'Ards' ) );
	}

	public function test_policy_default_option_respected(): void {
		update_option( 'lgw_entry_default_policy', 'club_admin' );
		$this->assertSame( 'club_admin', lgw_entry_policy_for_club( 'Ards' ) );
	}

	public function test_policy_per_club_overrides_default(): void {
		update_option( 'lgw_entry_default_policy', 'club_admin' );
		update_option( 'lgw_clubs', array(
			array( 'name' => 'Ards', 'entry_policy' => 'open' ),
			array( 'name' => 'Bangor' ), // no override → falls back to default
		) );
		$this->assertSame( 'open', lgw_entry_policy_for_club( 'Ards' ) );
		$this->assertSame( 'club_admin', lgw_entry_policy_for_club( 'Bangor' ) );
	}

	public function test_policy_champ_override_wins(): void {
		update_option( 'lgw_clubs', array( array( 'name' => 'Ards', 'entry_policy' => 'open' ) ) );
		update_option( 'lgw_entry_cfg_champ1', array( 'policy_override' => 'club_admin' ) );
		$this->assertSame( 'club_admin', lgw_entry_policy_for_club( 'Ards', 'champ1' ) );
	}

	// ── Entry window (open / deadline / capacity) ─────────────────────────────
	public function test_window_closed_when_not_open(): void {
		update_option( 'lgw_entry_cfg_c', array( 'open' => false ) );
		list( $open, $reason ) = lgw_entry_window( 'c' );
		$this->assertFalse( $open );
		$this->assertNotSame( '', $reason );
	}

	public function test_window_closed_after_deadline(): void {
		update_option( 'lgw_entry_cfg_c', array( 'open' => true, 'deadline' => time() - 100 ) );
		list( $open ) = lgw_entry_window( 'c' );
		$this->assertFalse( $open );
	}

	public function test_window_open_when_configured(): void {
		update_option( 'lgw_entry_cfg_c', array( 'open' => true, 'deadline' => '', 'capacity' => 0 ) );
		list( $open ) = lgw_entry_window( 'c' );
		$this->assertTrue( $open );
	}

	// ── Projection into gchamp (the textarea's own output shape) ──────────────
	public function test_projection_appends_string_and_keys_preferences_by_string(): void {
		// A pre-draw championship that collects date + location preferences.
		update_option( 'lgw_gchamp_c1', array(
			'title'             => 'Senior Pairs 2026',
			'entries'           => array( 'Existing One, Ards' ),
			'preference_fields' => array( 'date', 'location' ),
		) );
		// The ledger entry (post 42).
		update_post_meta( 42, LGW_ENTRY_META_CHAMP,  'c1' );
		update_post_meta( 42, LGW_ENTRY_META_STRING, 'A. New & B. New, Bangor' );
		update_post_meta( 42, LGW_ENTRY_META_PREFS,  array( 'date' => '12/07/26', 'location' => 'Bangor' ) );

		$res = lgw_entry_project( 42 );
		$this->assertTrue( $res === true );

		$champ = get_option( 'lgw_gchamp_c1' );
		$this->assertContains( 'A. New & B. New, Bangor', $champ['entries'] );
		$this->assertContains( 'Existing One, Ards', $champ['entries'] );
		// Preferences keyed by the ENTRY STRING, not an md5.
		$this->assertSame(
			array( 'date' => '12/07/26', 'location' => 'Bangor' ),
			$champ['entry_preferences']['A. New & B. New, Bangor']
		);
		$this->assertSame( '1', get_post_meta( 42, LGW_ENTRY_META_PROJECTED, true ) );
	}

	public function test_projection_is_idempotent_on_duplicate(): void {
		update_option( 'lgw_gchamp_c1', array( 'title' => 'X', 'entries' => array( 'Dup, Ards' ) ) );
		update_post_meta( 5, LGW_ENTRY_META_CHAMP,  'c1' );
		update_post_meta( 5, LGW_ENTRY_META_STRING, 'Dup, Ards' );
		lgw_entry_project( 5 );
		$champ = get_option( 'lgw_gchamp_c1' );
		$this->assertSame( array( 'Dup, Ards' ), $champ['entries'] ); // not duplicated
	}

	public function test_projection_deferred_when_draw_complete(): void {
		update_option( 'lgw_gchamp_c1', array( 'title' => 'X', 'entries' => array(), 'draw_complete' => true ) );
		update_post_meta( 9, LGW_ENTRY_META_CHAMP,  'c1' );
		update_post_meta( 9, LGW_ENTRY_META_STRING, 'Late, Ards' );
		lgw_entry_project( 9 );
		$champ = get_option( 'lgw_gchamp_c1' );
		$this->assertNotContains( 'Late, Ards', $champ['entries'] );
		$this->assertSame( 'needs_placement', get_post_meta( 9, LGW_ENTRY_META_PROJECTED, true ) );
	}

	public function test_unprojection_removes_string_pre_draw(): void {
		update_option( 'lgw_gchamp_c1', array(
			'title'   => 'X',
			'entries' => array( 'Keep, Ards', 'Drop, Bangor' ),
			'entry_preferences' => array( 'Drop, Bangor' => array( 'date' => 'x' ) ),
		) );
		update_post_meta( 3, LGW_ENTRY_META_CHAMP,  'c1' );
		update_post_meta( 3, LGW_ENTRY_META_STRING, 'Drop, Bangor' );
		lgw_entry_unproject( 3 );
		$champ = get_option( 'lgw_gchamp_c1' );
		$this->assertSame( array( 'Keep, Ards' ), array_values( $champ['entries'] ) );
		$this->assertArrayNotHasKey( 'Drop, Bangor', $champ['entry_preferences'] );
	}

	// ── Stripe webhook signature verification ─────────────────────────────────
	public function test_stripe_sig_valid(): void {
		$secret  = 'whsec_test';
		$payload = '{"id":"evt_1"}';
		$ts      = time();
		$sig     = hash_hmac( 'sha256', $ts . '.' . $payload, $secret );
		$header  = "t={$ts},v1={$sig}";
		$this->assertTrue( lgw_entry_stripe_verify_sig( $payload, $header, $secret ) );
	}

	public function test_stripe_sig_wrong_secret_fails(): void {
		$payload = '{"id":"evt_1"}';
		$ts      = time();
		$sig     = hash_hmac( 'sha256', $ts . '.' . $payload, 'whsec_real' );
		$header  = "t={$ts},v1={$sig}";
		$this->assertFalse( lgw_entry_stripe_verify_sig( $payload, $header, 'whsec_wrong' ) );
	}

	public function test_stripe_sig_stale_timestamp_fails(): void {
		$secret  = 'whsec_test';
		$payload = '{"id":"evt_1"}';
		$ts      = time() - 10000; // outside default 300s tolerance
		$sig     = hash_hmac( 'sha256', $ts . '.' . $payload, $secret );
		$header  = "t={$ts},v1={$sig}";
		$this->assertFalse( lgw_entry_stripe_verify_sig( $payload, $header, $secret ) );
	}

	public function test_stripe_sig_malformed_header_fails(): void {
		$this->assertFalse( lgw_entry_stripe_verify_sig( '{}', '', 'whsec_test' ) );
		$this->assertFalse( lgw_entry_stripe_verify_sig( '{}', 'garbage', 'whsec_test' ) );
	}

	// ── Money formatting ──────────────────────────────────────────────────────
	public function test_format_money(): void {
		$this->assertSame( '£5.00',  lgw_entry_format_money( 500, 'gbp' ) );
		$this->assertSame( '£12.50', lgw_entry_format_money( 1250, 'gbp' ) );
	}
}
