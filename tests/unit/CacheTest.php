<?php
/**
 * tests/unit/CacheTest.php
 * PHPUnit tests for lgw-div-cache.php cache read/write functions.
 * Phase 4.3 — LGW Test Suite
 */

use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase {

    protected function setUp(): void {
        WpStubs::resetOptions();
    }

    // ── Cache key format ───────────────────────────────────────────────────────

    public function test_cache_key_format(): void {
        // lgw_cache_get_division('2026','Division 1') must look up key lgw_div_cache_2026_division-1
        $result = lgw_cache_get_division( '2026', 'Division 1' );
        $this->assertNull( $result, 'Missing option must return null' );

        // Now seed the option directly and confirm get returns it
        $data = $this->freshData();
        WpStubs::$options['lgw_div_cache_2026_division-1'] = $data;
        $got = lgw_cache_get_division( '2026', 'Division 1' );
        $this->assertIsArray( $got );
    }

    // ── lgw_cache_set_division writes to expected option key ──────────────────

    public function test_set_division_writes_to_correct_key(): void {
        lgw_cache_set_division( '2026', 'Division 1', $this->freshData() );
        $this->assertArrayHasKey( 'lgw_div_cache_2026_division-1', WpStubs::$options );
    }

    // ── lgw_cache_get_division returns null when option missing ───────────────

    public function test_get_division_returns_null_when_missing(): void {
        $result = lgw_cache_get_division( '2026', 'Division 1' );
        $this->assertNull( $result );
    }

    // ── lgw_cache_get_division returns null when synced_at exceeds hard TTL ───

    public function test_get_division_returns_null_when_stale_beyond_hard_ttl(): void {
        $stale        = $this->freshData();
        $stale['synced_at'] = time() - ( LGW_CACHE_HARD_TTL + 3600 ); // 25h ago
        WpStubs::$options['lgw_div_cache_2026_division-1'] = $stale;

        $result = lgw_cache_get_division( '2026', 'Division 1' );
        $this->assertNull( $result, 'Data older than hard TTL must return null' );
    }

    // ── lgw_cache_get_division returns data when within hard TTL ──────────────

    public function test_get_division_returns_data_when_fresh(): void {
        $fresh = $this->freshData();
        WpStubs::$options['lgw_div_cache_2026_division-1'] = $fresh;

        $result = lgw_cache_get_division( '2026', 'Division 1' );
        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'teams', $result );
        $this->assertCount( 1, $result['teams'] );
    }

    // ── lgw_cache_invalidate deletes the correct option ───────────────────────

    public function test_invalidate_deletes_correct_option(): void {
        WpStubs::$options['lgw_div_cache_2026_division-1'] = $this->freshData();
        lgw_cache_invalidate( '2026', 'Division 1' );
        $this->assertArrayNotHasKey( 'lgw_div_cache_2026_division-1', WpStubs::$options );
    }

    // ── lgw_cache_set_division stores synced_at timestamp ────────────────────

    public function test_set_division_updates_synced_at(): void {
        $before = time();
        lgw_cache_set_division( '2026', 'Division 1', [
            'teams'    => [],
            'fixtures' => [],
            'csv_url'  => 'https://example.com/csv',
        ] );
        $written = WpStubs::$options['lgw_div_cache_2026_division-1'] ?? null;
        $this->assertNotNull( $written );
        $this->assertArrayHasKey( 'synced_at', $written );
        $this->assertGreaterThanOrEqual( $before, $written['synced_at'] );
    }

    // ── lgw_cache_get_all_status returns expected shape ───────────────────────

    public function test_get_all_status_returns_correct_shape(): void {
        // Seed seasons option so lgw_cache_get_all_status can find the division
        WpStubs::$options['lgw_seasons'] = [ [
            'id'        => '2026',
            'active'    => true,
            'divisions' => [ [ 'division' => 'Division 1', 'csv_url' => 'https://example.com/csv' ] ],
        ] ];

        $data = [
            'teams'     => array_fill( 0, 8, [ 'team' => 'X', 'pl' => 0, 'pts' => 0 ] ),
            'fixtures'  => array_fill( 0, 14, [ 'homeTeam' => 'A', 'awayTeam' => 'B' ] ),
            'synced_at' => time() - 600,
            'csv_url'   => 'https://example.com/csv',
            'season_id' => '2026',
            'division'  => 'Division 1',
        ];
        WpStubs::$options['lgw_div_cache_2026_division-1'] = $data;

        $all = lgw_cache_get_all_status();

        $this->assertIsArray( $all );
        $this->assertNotEmpty( $all );
        $status = $all[0];
        $this->assertArrayHasKey( 'synced_at',     $status );
        $this->assertArrayHasKey( 'fixture_count', $status );
        $this->assertArrayHasKey( 'team_count',    $status );
        $this->assertSame( 8,  $status['team_count'] );
        $this->assertSame( 14, $status['fixture_count'] );
        $this->assertSame( 'ok', $status['status'] );
    }

    // ── lgw_cache_invalidate on missing key is a no-op ────────────────────────

    public function test_invalidate_missing_key_is_noop(): void {
        // Should not throw
        lgw_cache_invalidate( '2026', 'Nonexistent Division' );
        $this->assertTrue( true );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function freshData(): array {
        return [
            'teams'     => [ [ 'team' => 'Test Club A', 'pl' => 6, 'pts' => 10 ] ],
            'fixtures'  => [],
            'synced_at' => time() - 3600, // 1h ago — within TTL
            'csv_url'   => 'https://example.com/csv',
            'season_id' => '2026',
            'division'  => 'Division 1',
        ];
    }
}
