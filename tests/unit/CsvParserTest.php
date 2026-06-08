<?php
/**
 * tests/unit/CsvParserTest.php
 * PHPUnit tests for lgw-div-cache.php CSV parsing functions.
 * Phase 4.3 — LGW Test Suite
 */

use PHPUnit\Framework\TestCase;

class CsvParserTest extends TestCase {

    protected function setUp(): void {
        WpStubs::resetOptions();
    }

    // ── Fixture CSV strings ───────────────────────────────────────────────────
    // Dates are on their own rows; fixture data rows have an empty col 0.
    // Header row has an empty col 0 and HomePts in col 1.

    private function standardCsv(): string {
        return implode( "\n", [
            ',,,,,,,,,,,,,,,,',
            'LEAGUE TABLE,,,,,,,,,,,,,,,,',
            ',,,,,,,,,,,,,,,,',
            'POS,TEAM,,,,,PL,PTS,+/-,W,L,D,FOR,,,AGAINST',
            '1,Test Club A,,,,,6,10,,5,1,0,42,,,28',
            '2,Test Club B,,,,,6,9,,4,1,1,38,,,30',
            '3,Ballymena BC,,,,,6,8,,3,1,2,35,,,28',
            '4,Larne BC,,,,,6,7,,3,2,1,31,,,30',
            '5,Antrim BC,,,,,6,6,,2,2,2,28,,,29',
            '6,Carrickfergus BC,,,,,6,5,,2,3,1,27,,,33',
            '7,Whitehead BC,,,,,6,3,,1,4,1,24,,,36',
            '8,Greenisland BC,,,,,6,0,,0,6,0,18,,,49',
            ',,,,,,,,,,,,,,,,',
            'FIXTURES,,,,,,,,,,,,,,,,',
            ',,,,,,,,,,,,,,,,',
            // Header: empty col0, then HomePts, Home, ..., Home Shots, , Away Shots, Away, ..., AwayPts
            ',HomePts,Home,,,,,Home Shots,,Away Shots,Away,,,,,AwayPts',
            // Date row (col 0 = date, rest empty)
            'Sat 14-Mar-2026,,,,,,,,,,,,,,,,',
            // Fixture rows (col 0 empty, col1=ptsH, col2=home, col7=scoreH, col9=scoreA, col10=away, col15=ptsA)
            ',2,Test Club A,,,,,21,,14,Test Club B,,,,,0',
            ',1,Ballymena BC,,,,,18,,18,Larne BC,,,,,1',
            'Sat 21-Mar-2026,,,,,,,,,,,,,,,,',
            ',0,Antrim BC,,,,,12,,21,Test Club A,,,,,2',
            'Sat 25-Apr-2026,,,,,,,,,,,,,,,,',
            ',,,,,,,,,,,,,,,,'  // unplayed: Test Club A vs Carrickfergus BC — no pts/scores
            . "\nSat 25-Apr-2026,,,,,,,,,,,,,,,,",  // won't work as array element; split below
        ] );
    }

    // Build the standard CSV properly as a heredoc
    private function csv(): string {
        return <<<CSV
,,,,,,,,,,,,,,,,
LEAGUE TABLE,,,,,,,,,,,,,,,,
,,,,,,,,,,,,,,,,
POS,TEAM,,,,,PL,PTS,+/-,W,L,D,FOR,,,AGAINST
1,Test Club A,,,,,6,10,,5,1,0,42,,,28
2,Test Club B,,,,,6,9,,4,1,1,38,,,30
3,Ballymena BC,,,,,6,8,,3,1,2,35,,,28
4,Larne BC,,,,,6,7,,3,2,1,31,,,30
5,Antrim BC,,,,,6,6,,2,2,2,28,,,29
6,Carrickfergus BC,,,,,6,5,,2,3,1,27,,,33
7,Whitehead BC,,,,,6,3,,1,4,1,24,,,36
8,Greenisland BC,,,,,6,0,,0,6,0,18,,,49
,,,,,,,,,,,,,,,,
FIXTURES,,,,,,,,,,,,,,,,
,,,,,,,,,,,,,,,,
,HomePts,Home,,,,,Home Shots,,Away Shots,Away,,,,,AwayPts
Sat 14-Mar-2026,,,,,,,,,,,,,,,,
,2,Test Club A,,,,,21,,14,Test Club B,,,,,0
,1,Ballymena BC,,,,,18,,18,Larne BC,,,,,1
Sat 25-Apr-2026,,,,,,,,,,,,,,,,
,,Test Club A,,,,,,,,Carrickfergus BC,,,,,
Sat 2-May-2026,,,,,,,,,,,,,,,,
,,Test Club B,,,,,,,,Larne BC,,,,,
CSV;
    }

    private function singleTeamCsv(): string {
        return <<<CSV
LEAGUE TABLE
,,,,,,,,,,,,,,,,
POS,TEAM,,,,,PL,PTS,+/-,W,L,D,FOR,,,AGAINST
1,Test Club A,,,,,0,0,,0,0,0,0,,,0
,,,,,,,,,,,,,,,,
FIXTURES
,,,,,,,,,,,,,,,,
,HomePts,Home,,,,,Home Shots,,Away Shots,Away,,,,,AwayPts
CSV;
    }

    // ── Teams parsing ─────────────────────────────────────────────────────────

    public function test_parse_teams_returns_correct_count(): void {
        $teams = lgw_cache_parse_teams( $this->csv() );
        $this->assertCount( 8, $teams );
    }

    public function test_parse_teams_returns_correct_team_names(): void {
        $teams = lgw_cache_parse_teams( $this->csv() );
        $names = array_column( $teams, 'team' );
        $this->assertContains( 'Test Club A',     $names );
        $this->assertContains( 'Test Club B',     $names );
        $this->assertContains( 'Ballymena BC',    $names );
        $this->assertContains( 'Greenisland BC',  $names );
    }

    public function test_parse_teams_returns_correct_points(): void {
        $teams = lgw_cache_parse_teams( $this->csv() );
        $teamA = null;
        foreach ( $teams as $t ) {
            if ( $t['team'] === 'Test Club A' ) { $teamA = $t; break; }
        }
        $this->assertNotNull( $teamA );
        $this->assertSame( 10.0, floatval( $teamA['pts'] ) );
        $this->assertSame( 6,    intval(   $teamA['pl']  ) );
        $this->assertSame( '5',  (string)  $teamA['w']    );
        $this->assertSame( '1',  (string)  $teamA['l']    );
        $this->assertSame( '0',  (string)  $teamA['d']    );
    }

    public function test_parse_teams_returns_correct_for_against(): void {
        $teams = lgw_cache_parse_teams( $this->csv() );
        $teamA = null;
        foreach ( $teams as $t ) {
            if ( $t['team'] === 'Test Club A' ) { $teamA = $t; break; }
        }
        $this->assertNotNull( $teamA );
        $this->assertSame( '42', (string) $teamA['f'] );
        $this->assertSame( '28', (string) $teamA['a'] );
    }

    public function test_parse_teams_handles_empty_csv_gracefully(): void {
        $teams = lgw_cache_parse_teams( '' );
        $this->assertIsArray( $teams );
        $this->assertEmpty( $teams );
    }

    public function test_parse_teams_handles_missing_league_table_header(): void {
        $teams = lgw_cache_parse_teams( "POS,TEAM,PL\n1,Some Team,0\n" );
        $this->assertIsArray( $teams );
        $this->assertEmpty( $teams );
    }

    public function test_parse_teams_handles_single_team(): void {
        $teams = lgw_cache_parse_teams( $this->singleTeamCsv() );
        $this->assertCount( 1, $teams );
        $this->assertSame( 'Test Club A', $teams[0]['team'] );
    }

    // ── Fixtures parsing ──────────────────────────────────────────────────────

    public function test_parse_fixtures_returns_correct_count(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->csv() );
        // 2 played (14-Mar) + 2 unplayed (25-Apr, 2-May) = 4
        $this->assertCount( 4, $fixtures );
    }

    public function test_parse_fixtures_returns_correct_home_away_teams(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->csv() );
        $this->assertSame( 'Test Club A', $fixtures[0]['homeTeam'] );
        $this->assertSame( 'Test Club B', $fixtures[0]['awayTeam'] );
    }

    public function test_parse_fixtures_extracts_scores_for_played(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->csv() );
        $this->assertSame( '21', (string) $fixtures[0]['shotsHome'] );
        $this->assertSame( '14', (string) $fixtures[0]['shotsAway'] );
    }

    public function test_parse_fixtures_extracts_points_for_played(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->csv() );
        $this->assertSame( '2', (string) $fixtures[0]['ptsHome'] );
        $this->assertSame( '0', (string) $fixtures[0]['ptsAway'] );
    }

    public function test_parse_fixtures_unplayed_has_empty_scores(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->csv() );
        $unplayed = null;
        foreach ( $fixtures as $f ) {
            if ( $f['homeTeam'] === 'Test Club A' && $f['awayTeam'] === 'Carrickfergus BC' ) {
                $unplayed = $f; break;
            }
        }
        $this->assertNotNull( $unplayed, 'Unplayed fixture Test Club A vs Carrickfergus BC must be found' );
        $this->assertSame( '', (string) $unplayed['shotsHome'] );
        $this->assertSame( '', (string) $unplayed['shotsAway'] );
    }

    public function test_parse_fixtures_date_parsing(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->csv() );
        $this->assertStringContainsString( '14-Mar-2026', $fixtures[0]['date'] );
    }

    public function test_parse_fixtures_handles_empty_csv(): void {
        $fixtures = lgw_cache_parse_fixtures( '' );
        $this->assertIsArray( $fixtures );
        $this->assertEmpty( $fixtures );
    }

    public function test_parse_fixtures_handles_missing_fixtures_header(): void {
        $csv = "LEAGUE TABLE\n\nPOS,TEAM,,,,,PL,PTS\n1,Test Club A,,,,,0,0\n";
        $fixtures = lgw_cache_parse_fixtures( $csv );
        $this->assertIsArray( $fixtures );
        $this->assertEmpty( $fixtures );
    }

    public function test_parse_fixtures_handles_drawn_game(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->csv() );
        $drawn = null;
        foreach ( $fixtures as $f ) {
            if ( $f['homeTeam'] === 'Ballymena BC' && $f['awayTeam'] === 'Larne BC' ) {
                $drawn = $f; break;
            }
        }
        $this->assertNotNull( $drawn, 'Drawn fixture must be found' );
        $this->assertSame( '18', (string) $drawn['shotsHome'] );
        $this->assertSame( '18', (string) $drawn['shotsAway'] );
        $this->assertSame( '1',  (string) $drawn['ptsHome'] );
        $this->assertSame( '1',  (string) $drawn['ptsAway'] );
    }
}
