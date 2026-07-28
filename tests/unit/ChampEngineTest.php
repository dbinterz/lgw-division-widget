<?php
/**
 * tests/unit/ChampEngineTest.php
 * Unit tests for the common championship engine abstraction (lgw-champ-engine.php):
 * engine detection/resolution, normalised get(), draw-started detection per engine,
 * append/remove pre-draw + post-draw deferral, preference handling (gchamp only),
 * and enumeration filtering.
 *
 * Pure-PHP-stub style (see bootstrap.php).
 */

use PHPUnit\Framework\TestCase;

// ── Extra stubs the engine module needs beyond the shared bootstrap ────────────
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $v ) { return is_string( $v ) ? ( @unserialize( $v ) ?: $v ) : $v; }
}

/** Minimal $wpdb backed by the in-memory WpStubs option store (for list()). */
if ( ! class_exists( 'LGW_Test_Wpdb' ) ) {
	class LGW_Test_Wpdb {
		public string $options = 'wp_options';
		public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
		public function prepare( $q, ...$args ) {
			foreach ( $args as $a ) {
				$q = preg_replace( '/%s/', "'" . addslashes( (string) $a ) . "'", $q, 1 );
			}
			return $q;
		}
		/** Parse the LIKE literal out of the prepared query and scan WpStubs::$options. */
		public function get_results( $query ) {
			if ( ! preg_match( "/LIKE '([^']*)'/", $query, $m ) ) return array();
			$like    = stripslashes( $m[1] );
			$prefix  = rtrim( $like, '%' );
			$prefix  = str_replace( array( '\\_', '\\%', '\\\\' ), array( '_', '%', '\\' ), $prefix );
			$rows    = array();
			foreach ( WpStubs::$options as $name => $val ) {
				if ( strpos( $name, $prefix ) === 0 ) {
					$rows[] = (object) array( 'option_name' => $name, 'option_value' => $val );
				}
			}
			return $rows;
		}
	}
}

if ( ! function_exists( 'lgw_champ_engine' ) ) {
	require_once __DIR__ . '/../../lgw-champ-engine.php';
}

final class ChampEngineTest extends TestCase {

	protected function setUp(): void {
		WpStubs::resetOptions();
		$GLOBALS['wpdb'] = new LGW_Test_Wpdb();
	}

	private function seed_gchamp( string $id, array $extra = array() ): void {
		update_option( 'lgw_gchamp_' . $id, array_merge( array(
			'title'             => 'GChamp ' . $id,
			'discipline'        => 'pairs',
			'entries'           => array( 'Alice, Falls', 'Bob, Willowfield' ),
			'preference_fields' => array( 'date', 'location' ),
		), $extra ) );
	}

	private function seed_champ( string $id, array $extra = array() ): void {
		update_option( 'lgw_champ_' . $id, array_merge( array(
			'title'      => 'Champ ' . $id,
			'discipline' => 'singles',
			'entries'    => array( 'Carol, Dunmurry' ),
		), $extra ) );
	}

	// ── Resolution / detection ────────────────────────────────────────────────
	public function test_resolves_gchamp(): void {
		$this->seed_gchamp( 'x' );
		$e = lgw_champ_engine( 'x' );
		$this->assertNotNull( $e );
		$this->assertSame( 'gchamp', $e->name() );
	}

	public function test_resolves_legacy_champ(): void {
		$this->seed_champ( 'y' );
		$e = lgw_champ_engine( 'y' );
		$this->assertNotNull( $e );
		$this->assertSame( 'champ', $e->name() );
	}

	public function test_absent_champ_resolves_null(): void {
		$this->assertNull( lgw_champ_engine( 'nope' ) );
	}

	public function test_gchamp_wins_on_id_clash(): void {
		$this->seed_gchamp( 'dup' );
		$this->seed_champ( 'dup' );
		$this->assertSame( 'gchamp', lgw_champ_engine( 'dup' )->name() );
	}

	// ── Normalised get() ──────────────────────────────────────────────────────
	public function test_get_shape(): void {
		$this->seed_champ( 'y' );
		$c = lgw_champ_engine( 'y' )->get( 'y' );
		$this->assertSame( 'y', $c['id'] );
		$this->assertSame( 'champ', $c['engine'] );
		$this->assertSame( 'Champ y', $c['title'] );
		$this->assertSame( 'singles', $c['discipline'] );
		$this->assertSame( array( 'Carol, Dunmurry' ), $c['entries'] );
		$this->assertSame( array(), $c['preference_fields'] );
	}

	// ── Draw-started detection ────────────────────────────────────────────────
	public function test_gchamp_draw_started_flag(): void {
		$this->seed_gchamp( 'x', array( 'draw_complete' => 1 ) );
		$this->assertTrue( lgw_champ_engine( 'x' )->draw_started( 'x' ) );
	}

	public function test_champ_draw_started_section_version(): void {
		$this->seed_champ( 'y', array( 'section_0_draw_version' => 3 ) );
		$this->assertTrue( lgw_champ_engine( 'y' )->draw_started( 'y' ) );
	}

	public function test_champ_draw_started_in_progress(): void {
		$this->seed_champ( 'y', array( 'section_1_draw_in_progress' => true ) );
		$this->assertTrue( lgw_champ_engine( 'y' )->draw_started( 'y' ) );
	}

	public function test_champ_draw_started_final(): void {
		$this->seed_champ( 'y', array( 'final_draw_version' => 1 ) );
		$this->assertTrue( lgw_champ_engine( 'y' )->draw_started( 'y' ) );
	}

	public function test_champ_undrawn(): void {
		$this->seed_champ( 'y', array( 'section_0_draw_version' => 0 ) );
		$this->assertFalse( lgw_champ_engine( 'y' )->draw_started( 'y' ) );
	}

	// ── Append (legacy champ) ─────────────────────────────────────────────────
	public function test_champ_append_pre_draw(): void {
		$this->seed_champ( 'y' );
		$res = lgw_champ_engine( 'y' )->append_entry( 'y', 'Dave, Belmont' );
		$this->assertTrue( $res );
		$opt = get_option( 'lgw_champ_y' );
		$this->assertContains( 'Dave, Belmont', $opt['entries'] );
		$this->assertCount( 2, $opt['entries'] );
	}

	public function test_champ_append_dedup_idempotent(): void {
		$this->seed_champ( 'y' );
		$e = lgw_champ_engine( 'y' );
		$this->assertTrue( $e->append_entry( 'y', 'Carol, Dunmurry' ) ); // already present
		$this->assertCount( 1, get_option( 'lgw_champ_y' )['entries'] );
	}

	public function test_champ_append_post_draw_defers(): void {
		$this->seed_champ( 'y', array( 'section_0_draw_version' => 2 ) );
		$res = lgw_champ_engine( 'y' )->append_entry( 'y', 'Dave, Belmont' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'draw_started', $res->get_error_code() );
		$this->assertCount( 1, get_option( 'lgw_champ_y' )['entries'] ); // untouched
	}

	public function test_champ_append_does_not_write_sections_or_prefs(): void {
		$this->seed_champ( 'y' );
		lgw_champ_engine( 'y' )->append_entry( 'y', 'Dave, Belmont', array( 'date' => '2026-08-01' ) );
		$opt = get_option( 'lgw_champ_y' );
		$this->assertArrayNotHasKey( 'sections', $opt );
		$this->assertArrayNotHasKey( 'entry_preferences', $opt );
	}

	// ── Append (gchamp) preserves preference behaviour ────────────────────────
	public function test_gchamp_append_writes_prefs_keyed_by_string(): void {
		$this->seed_gchamp( 'x' );
		lgw_champ_engine( 'x' )->append_entry( 'x', 'Eve, Cliftonville', array( 'date' => '2026-08-02', 'location' => 'Belmont' ) );
		$opt = get_option( 'lgw_gchamp_x' );
		$this->assertContains( 'Eve, Cliftonville', $opt['entries'] );
		$this->assertSame( array( 'date' => '2026-08-02', 'location' => 'Belmont' ), $opt['entry_preferences']['Eve, Cliftonville'] );
	}

	public function test_gchamp_append_drops_unconfigured_pref_field(): void {
		$this->seed_gchamp( 'x', array( 'preference_fields' => array( 'date' ) ) );
		lgw_champ_engine( 'x' )->append_entry( 'x', 'Eve, Cliftonville', array( 'date' => 'D', 'location' => 'L' ) );
		$opt = get_option( 'lgw_gchamp_x' );
		$this->assertSame( array( 'date' => 'D' ), $opt['entry_preferences']['Eve, Cliftonville'] );
	}

	// ── Remove ────────────────────────────────────────────────────────────────
	public function test_champ_remove_pre_draw(): void {
		$this->seed_champ( 'y' );
		$this->assertTrue( lgw_champ_engine( 'y' )->remove_entry( 'y', 'Carol, Dunmurry' ) );
		$this->assertSame( array(), get_option( 'lgw_champ_y' )['entries'] );
	}

	public function test_champ_remove_post_draw_defers(): void {
		$this->seed_champ( 'y', array( 'final_draw_in_progress' => true ) );
		$res = lgw_champ_engine( 'y' )->remove_entry( 'y', 'Carol, Dunmurry' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertCount( 1, get_option( 'lgw_champ_y' )['entries'] );
	}

	public function test_gchamp_remove_clears_prefs(): void {
		$this->seed_gchamp( 'x', array( 'entry_preferences' => array( 'Alice, Falls' => array( 'date' => 'D' ) ) ) );
		lgw_champ_engine( 'x' )->remove_entry( 'x', 'Alice, Falls' );
		$opt = get_option( 'lgw_gchamp_x' );
		$this->assertNotContains( 'Alice, Falls', $opt['entries'] );
		$this->assertArrayNotHasKey( 'Alice, Falls', $opt['entry_preferences'] );
	}

	// ── Enumeration ───────────────────────────────────────────────────────────
	public function test_list_spans_both_engines_and_filters_collisions(): void {
		$this->seed_gchamp( 'aaa' );
		$this->seed_champ( 'bbb' );
		update_option( 'lgw_champ_priority', 5 );                     // not a champ
		update_option( 'lgw_champ_foo_settings', array( 'x' => 1 ) ); // not a champ (no title)
		update_option( 'lgw_champ_bar_settings', array( 'title' => 'trap' ) ); // _settings suffix excluded
		$ids = array_column( lgw_champ_engine_list(), 'id' );
		sort( $ids );
		$this->assertSame( array( 'aaa', 'bbb' ), $ids );
	}

	public function test_list_dedupes_id_clash_gchamp_first(): void {
		$this->seed_gchamp( 'dup' );
		$this->seed_champ( 'dup' );
		$list = array_values( array_filter( lgw_champ_engine_list(), fn( $r ) => $r['id'] === 'dup' ) );
		$this->assertCount( 1, $list );
		$this->assertSame( 'gchamp', $list[0]['engine'] );
	}

	// ── Normaliser ────────────────────────────────────────────────────────────
	public function test_norm_collapses_ws_and_lowercases(): void {
		$this->assertSame( lgw_champ_engine_norm( '  Alice,   Falls ' ), lgw_champ_engine_norm( 'alice, falls' ) );
	}
}
