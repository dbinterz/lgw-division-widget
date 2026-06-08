<?php
/**
 * tests/unit/MergeResultTest.php
 * PHPUnit tests for lgw_cache_merge_result().
 * Phase 4.3 — LGW Test Suite
 */

use PHPUnit\Framework\TestCase;

class MergeResultTest extends TestCase {

    protected function setUp(): void {
        WpStubs::resetOptions();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedScorecard( int $postId, array $overrides = [] ): void {
        $sc = array_merge( [
            'home_team'   => 'Test Club A',
            'away_team'   => 'Carrickfergus BC',
            'division'    => 'Division 1',
            'date'        => '25/04/2026',
            'home_total'  => '21',
            'away_total'  => '14',
            'home_points' => '2',
            'away_points' => '0',
        ], $overrides );

        WpStubs::$postMeta[$postId]['lgw_scorecard_data'] = $sc;
        WpStubs::$postMeta[$postId]['lgw_sc_season']      = '2026';
        WpStubs::$postMeta[$postId]['lgw_fixture_date']   = '';
    }

    private function seedCache( array $fixtureOverrides = [] ): void {
        $fixture = array_merge( [
            'date'      => 'Sat 25-Apr-2026',
            'homeTeam'  => 'Test Club A',
            'awayTeam'  => 'Carrickfergus BC',
            'shotsHome' => '',
            'shotsAway' => '',
            'ptsHome'   => '',
            'ptsAway'   => '',
            'played'    => false,
            'status'    => 'unplayed',
        ], $fixtureOverrides );

        WpStubs::$options['lgw_div_cache_2026_division-1'] = [
            'teams'     => [],
            'fixtures'  => [ $fixture ],
            'synced_at' => time() - 600,
            'csv_url'   => 'https://example.com/csv',
            'season_id' => '2026',
            'division'  => 'Division 1',
        ];
    }

    private function writtenCache(): ?array {
        return WpStubs::$options['lgw_div_cache_2026_division-1'] ?? null;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_merge_result_sets_status_to_confirmed(): void {
        $this->seedScorecard( 42 );
        $this->seedCache();

        lgw_cache_merge_result( 42 );

        $cache = $this->writtenCache();
        $this->assertNotNull( $cache );
        $this->assertSame( 'confirmed', $cache['fixtures'][0]['status'] );
        $this->assertTrue( $cache['fixtures'][0]['played'] );
    }

    public function test_merge_result_writes_correct_scores(): void {
        $this->seedScorecard( 43, [ 'home_total' => '21', 'away_total' => '14',
                                    'home_points' => '2', 'away_points' => '0' ] );
        $this->seedCache();

        lgw_cache_merge_result( 43 );

        $fx = $this->writtenCache()['fixtures'][0];
        $this->assertSame( '21', $fx['shotsHome'] );
        $this->assertSame( '14', $fx['shotsAway'] );
        $this->assertSame( '2',  $fx['ptsHome'] );
        $this->assertSame( '0',  $fx['ptsAway'] );
    }

    public function test_merge_result_is_noop_when_no_matching_fixture(): void {
        $this->seedScorecard( 44, [
            'home_team' => 'Team That Does Not Exist',
            'away_team' => 'Another Unknown Team',
        ] );
        $this->seedCache(); // fixture is Test Club A vs Carrickfergus BC

        $before = $this->writtenCache();
        lgw_cache_merge_result( 44 );
        $after = $this->writtenCache();

        // Cache should be unchanged (update_option not called for unmatched)
        $this->assertSame( $before['fixtures'][0]['status'],
                           $after['fixtures'][0]['status'] );
        $this->assertSame( 'unplayed', $after['fixtures'][0]['status'] );
    }

    public function test_merge_result_is_noop_when_cache_empty(): void {
        $this->seedScorecard( 45 );
        // No cache seeded

        lgw_cache_merge_result( 45 );

        // Should not have written anything
        $this->assertArrayNotHasKey( 'lgw_div_cache_2026_division-1', WpStubs::$options );
    }

    public function test_merge_result_is_noop_when_meta_missing(): void {
        // Post 46 has no meta at all
        lgw_cache_merge_result( 46 );
        $this->assertArrayNotHasKey( 'lgw_div_cache_2026_division-1', WpStubs::$options );
    }

    public function test_merge_result_is_noop_when_home_team_empty(): void {
        $this->seedScorecard( 47, [ 'home_team' => '' ] );
        $this->seedCache();

        $before = WpStubs::$options['lgw_div_cache_2026_division-1']['fixtures'][0]['status'];
        lgw_cache_merge_result( 47 );
        $after = WpStubs::$options['lgw_div_cache_2026_division-1']['fixtures'][0]['status'];

        $this->assertSame( $before, $after );
    }

    public function test_merge_result_handles_date_format_mismatch(): void {
        // Scorecard: dd/mm/yyyy; fixture: "Sat 25-Apr-2026" — both April 25 2026
        $this->seedScorecard( 48, [ 'date' => '25/04/2026' ] );
        $this->seedCache( [ 'date' => 'Sat 25-Apr-2026' ] );

        lgw_cache_merge_result( 48 );

        $this->assertSame( 'confirmed', $this->writtenCache()['fixtures'][0]['status'] );
    }

    public function test_merge_result_uses_fixture_date_meta_when_present(): void {
        // Played date is wrong (Jan 1) but lgw_fixture_date meta is correct (Apr 25)
        $this->seedScorecard( 49, [ 'date' => '01/01/2026' ] );
        WpStubs::$postMeta[49]['lgw_fixture_date'] = '25/04/2026';
        $this->seedCache( [ 'date' => 'Sat 25-Apr-2026' ] );

        lgw_cache_merge_result( 49 );

        $this->assertSame( 'confirmed', $this->writtenCache()['fixtures'][0]['status'] );
    }

    public function test_merge_result_matches_teams_case_insensitively(): void {
        $this->seedScorecard( 50, [
            'home_team' => 'test club a',
            'away_team' => 'CARRICKFERGUS BC',
        ] );
        $this->seedCache(); // stored as "Test Club A" / "Carrickfergus BC"

        lgw_cache_merge_result( 50 );

        $this->assertSame( 'confirmed', $this->writtenCache()['fixtures'][0]['status'] );
    }

    public function test_merge_result_does_not_corrupt_other_fixtures(): void {
        $this->seedScorecard( 51 ); // targets Test Club A vs Carrickfergus BC

        // Two fixtures in cache
        WpStubs::$options['lgw_div_cache_2026_division-1'] = [
            'teams'    => [],
            'fixtures' => [
                [
                    'date' => 'Sat 25-Apr-2026', 'homeTeam' => 'Test Club A',
                    'awayTeam' => 'Carrickfergus BC', 'shotsHome' => '',
                    'shotsAway' => '', 'ptsHome' => '', 'ptsAway' => '',
                    'played' => false, 'status' => 'unplayed',
                ],
                [
                    'date' => 'Sat 25-Apr-2026', 'homeTeam' => 'Test Club B',
                    'awayTeam' => 'Larne BC', 'shotsHome' => '',
                    'shotsAway' => '', 'ptsHome' => '', 'ptsAway' => '',
                    'played' => false, 'status' => 'unplayed',
                ],
            ],
            'synced_at' => time() - 600,
            'csv_url'   => 'https://example.com/csv',
            'season_id' => '2026',
            'division'  => 'Division 1',
        ];

        lgw_cache_merge_result( 51 );

        $fixtures = $this->writtenCache()['fixtures'];
        $this->assertCount( 2, $fixtures );
        $this->assertSame( 'confirmed', $fixtures[0]['status'] ); // matched
        $this->assertSame( 'unplayed',  $fixtures[1]['status'] ); // untouched
        $this->assertSame( '',          $fixtures[1]['shotsHome'] );
    }
}
