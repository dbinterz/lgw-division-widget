<?php
/**
 * tests/unit/CsvParserTest.php
 * PHPUnit tests for lgw-div-cache.php CSV parsing functions.
 * Phase 4.3 — LGW Test Suite
 *
 * Tests lgw_cache_parse_teams() and lgw_cache_parse_fixtures() against
 * fixture CSV strings that match the actual LGW Google Sheets export format.
 */

use PHPUnit\Framework\TestCase;

class CsvParserTest extends TestCase {

    // ── Fixture CSV data ──────────────────────────────────────────────────────

    /**
     * A standard 8-team fixture CSV using the HomePts/AwayPts column format
     * as exported by the real LGW Google Sheets template.
     */
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
            ',HomePts,Home,,,,,Home Shots,,Away Shots,Away,,,,,AwayPts',
            'Sat 14-Mar-2026,2,Test Club A,,,,,21,,14,Test Club B,,,,,0',
            'Sat 14-Mar-2026,1,Ballymena BC,,,,,18,,18,Larne BC,,,,,1',
            'Sat 21-Mar-2026,0,Antrim BC,,,,,12,,21,Test Club A,,,,,2',
            'Sat 25-Apr-2026,,Test Club A,,,,,,,,,Carrickfergus BC,,,,,',
            'Sat 2-May-2026,,Test Club B,,,,,,,,,Larne BC,,,,,',
        ] );
    }

    private function singleTeamCsv(): string {
        return implode( "\n", [
            'LEAGUE TABLE',
            '',
            'POS,TEAM,,,,,PL,PTS,+/-,W,L,D,FOR,,,AGAINST',
            '1,Test Club A,,,,,0,0,,0,0,0,0,,,0',
            '',
            'FIXTURES',
            '',
            ',HomePts,Home,,,,,Home Shots,,Away Shots,Away,,,,,AwayPts',
        ] );
    }

    // ── Teams parsing ─────────────────────────────────────────────────────────

    public function test_parse_teams_returns_correct_count(): void {
        $teams = lgw_cache_parse_teams( $this->standardCsv() );
        $this->assertCount( 8, $teams, 'Standard CSV should parse 8 teams' );
    }

    public function test_parse_teams_returns_correct_team_names(): void {
        $teams = lgw_cache_parse_teams( $this->standardCsv() );
        $names = array_column( $teams, 'team' );
        $this->assertContains( 'Test Club A', $names );
        $this->assertContains( 'Test Club B', $names );
        $this->assertContains( 'Ballymena BC', $names );
        $this->assertContains( 'Greenisland BC', $names );
    }

    public function test_parse_teams_returns_correct_points(): void {
        $teams = lgw_cache_parse_teams( $this->standardCsv() );
        $teamA = null;
        foreach ( $teams as $t ) {
            if ( $t['team'] === 'Test Club A' ) { $teamA = $t; break; }
        }
        $this->assertNotNull( $teamA, 'Test Club A must be found in parsed teams' );
        $this->assertSame( 10.0, floatval( $teamA['pts'] ) );
        $this->assertSame( 6,    intval( $teamA['pl'] ) );
        $this->assertSame( '5',  (string) $teamA['w'] );
        $this->assertSame( '1',  (string) $teamA['l'] );
        $this->assertSame( '0',  (string) $teamA['d'] );
    }

    public function test_parse_teams_returns_correct_for_against(): void {
        $teams = lgw_cache_parse_teams( $this->standardCsv() );
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
        $this->assertEmpty( $teams, 'Empty CSV should return empty teams array' );
    }

    public function test_parse_teams_handles_missing_league_table_header(): void {
        // CSV with no LEAGUE TABLE header
        $csv   = "POS,TEAM,PL\n1,Some Team,0\n";
        $teams = lgw_cache_parse_teams( $csv );
        $this->assertIsArray( $teams );
        // Parser looks for LEAGUE TABLE header; without it returns empty
        $this->assertEmpty( $teams );
    }

    public function test_parse_teams_handles_single_team(): void {
        $teams = lgw_cache_parse_teams( $this->singleTeamCsv() );
        $this->assertIsArray( $teams );
        $this->assertCount( 1, $teams );
        $this->assertSame( 'Test Club A', $teams[0]['team'] );
    }

    // ── Fixtures parsing ──────────────────────────────────────────────────────

    public function test_parse_fixtures_returns_correct_count(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->standardCsv() );
        // Standard CSV has 5 fixture rows (3 played + 2 unplayed)
        $this->assertCount( 5, $fixtures, 'Standard CSV should parse 5 fixtures' );
    }

    public function test_parse_fixtures_returns_correct_home_away_teams(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->standardCsv() );
        $this->assertSame( 'Test Club A', $fixtures[0]['homeTeam'] );
        $this->assertSame( 'Test Club B', $fixtures[0]['awayTeam'] );
    }

    public function test_parse_fixtures_extracts_scores_for_played(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->standardCsv() );
        // First fixture: Test Club A 21 : 14 Test Club B
        $this->assertSame( '21', (string) $fixtures[0]['shotsHome'] );
        $this->assertSame( '14', (string) $fixtures[0]['shotsAway'] );
    }

    public function test_parse_fixtures_extracts_points_for_played(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->standardCsv() );
        // First fixture: 2 home pts, 0 away pts
        $this->assertSame( '2', (string) $fixtures[0]['ptsHome'] );
        $this->assertSame( '0', (string) $fixtures[0]['ptsAway'] );
    }

    public function test_parse_fixtures_unplayed_has_empty_scores(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->standardCsv() );
        // 4th fixture (index 3): Test Club A vs Carrickfergus BC — unplayed
        $unplayed = null;
        foreach ( $fixtures as $f ) {
            if ( $f['homeTeam'] === 'Test Club A' && $f['awayTeam'] === 'Carrickfergus BC' ) {
                $unplayed = $f;
                break;
            }
        }
        $this->assertNotNull( $unplayed, 'Unplayed fixture Test Club A vs Carrickfergus BC must be found' );
        $this->assertEmpty( (string) $unplayed['shotsHome'] );
        $this->assertEmpty( (string) $unplayed['shotsAway'] );
    }

    public function test_parse_fixtures_date_parsing(): void {
        $fixtures = lgw_cache_parse_fixtures( $this->standardCsv() );
        // First fixture date: Sat 14-Mar-2026
        $this->assertStringContainsString( '14-Mar-2026', $fixtures[0]['date'] );
    }

    public function test_parse_fixtures_handles_empty_csv(): void {
        $fixtures = lgw_cache_parse_fixtures( '' );
        $this->assertIsArray( $fixtures );
        $this->assertEmpty( $fixtures );
    }

    public function test_parse_fixtures_handles_missing_fixtures_header(): void {
        // CSV with teams only, no FIXTURES section
        $csv = implode( "\n", [
            'LEAGUE TABLE',
            '',
            'POS,TEAM,,,,,PL,PTS',
            '1,Test Club A,,,,,0,0',
        ] );
        $fixtures = lgw_cache_parse_fixtures( $csv );
        $this->assertIsArray( $fixtures );
        $this->assertEmpty( $fixtures );
    }

    public function test_parse_fixtures_handles_drawn_game(): void {
        // Ballymena BC 18:18 Larne BC — drawn, 1 pt each
        $fixtures = lgw_cache_parse_fixtures( $this->standardCsv() );
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
