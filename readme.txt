=== League Game Widget ===
Contributors: dbinterz
Tags: bowls, sports, league table, fixtures, google sheets
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 2026.30.6
License: GPLv2 or later

Mobile-friendly league tables, fixtures, and scorecard submission for bowls leagues. Powered by Google Sheets CSV.

== Description ==

A full league management widget for bowls clubs and leagues. Displays live league tables and fixtures fetched from a published Google Sheet, and includes an optional scorecard submission system with two-party verification and player tracking.

= League Table & Fixtures =

* Mobile-responsive tabbed widget with sticky team name column
* Club badge support via WordPress Media Library
* Promotion and relegation zone highlighting with clinched-position shading
* All / Results / Upcoming fixture filter tabs
* Sponsor logo display with per-division override
* Server-side caching to minimise Google Sheets requests
* Dark mode toggle and print view

= Scorecard Submission =

* Per-club passphrase authentication — each club gets a private passphrase to submit scores (what3words address recommended)
* Two-party verification — both home and away clubs must confirm before a scorecard is marked confirmed
* Dispute resolution — admin can view side-by-side versions and accept either
* Score entry via typed input, Excel file upload, or photo (AI-parsed via Claude)
* Submitted scorecards visible inline when clicking a played fixture

= Player Tracking =

* Appearances automatically logged from confirmed scorecards
* Grouped by club, showing which teams each player has appeared for
* Season date range filtering
* Merge tool for duplicate player names
* Export to Excel — one sheet per club

= Shortcodes =

League table and fixtures:
`[lgw_division csv="URL" title="Division 1"]`

All parameters:
* `csv` — required. Published Google Sheets CSV URL
* `title` — division name shown above the widget
* `promote` — number of promotion places to highlight (default 0)
* `relegate` — number of relegation places to highlight (default 0)
* `sponsor_img` — override primary sponsor image URL for this division
* `sponsor_url` — override primary sponsor link URL for this division
* `sponsor_name` — override primary sponsor alt text for this division

Scorecard submission form:
`[lgw_submit]`

Cup bracket:
`[lgw_cup id="senior-cup-2025" title="Senior Cup 2025"]`

Parameters:
* `id` — required. The cup ID set in LGW → Cups admin page
* `title` — optional override for the cup title displayed in the widget header



1. Upload the plugin zip via Plugins > Add New > Upload Plugin
2. Activate the plugin
3. Go to Settings > LGW Widget to configure club badges and cache settings
4. Add the shortcode to each division page

== Changelog ==

= 2026.30.6 =
* New: Finals Week draw is available before the knockouts finish. The Finals Week tab now appears as soon as a group championship is drawn (previously it stayed hidden until at least one day's knockout was complete). Every expected qualifier is present from the start as a source-labelled placeholder — "21 June Ards Winner", "Winner of QF1" — so the whole draw can be arranged and dates/rinks set in advance. Placeholders resolve to real names automatically as each day's knockout confirms them.
* New: Finals Week now builds a proper seeded bracket sized to the qualifier count — e.g. 6 qualifiers (3 days × finalist + runner-up) form an 8-slot knockout with the top two seeds byed straight to the semi-finals (QF → SF → Final). 2- and 4-qualifier championships are unchanged (Final, or SF + Final).
* New: Manual Finals Week seeding. Each first-round draw position has an admin pencil dropdown to choose which qualifier source takes it; picking a source swaps it with whatever held that position, so you can arrange the draw however you like. Winners propagate through the rounds as scores are entered.
* New: The [lgw_finals] page has a sort toggle — "By competition" (the existing grouped view) or "By date & rink" (a flat, chronological schedule across every competition, unscheduled matches last). The choice is remembered per season.

= 2026.30.5 =
* Fix: Manual knockout seeding rejected valid qualifiers with "That entry is not a qualifier for this day", even though the dropdown only offered that day's qualifiers. Entry names contain runs of whitespace (e.g. "Name,    Club"), but sanitize_text_field() collapses those to a single space before the value reaches the validation check — so a strict comparison against the stored qualifier list never matched. The handler now compares whitespace-insensitively and stores the exact canonical entry string.

= 2026.30.4 =
* Fix: Seeding a group-championship knockout bracket no longer fails with "Unexpected token '<' … is not valid JSON". When the total qualifier count is not a power of two (e.g. 12 qualifiers across three days → a 16-slot bracket with 4 byes), each bye slot carries a null name. The bracket builder passes every slot name through the club-extraction callback lgw_gchamp_entry_club(), whose strict string type hint threw a fatal TypeError on the null byes — aborting the AJAX response before JSON could be sent, so the browser received WordPress's HTML critical-error page. lgw_gchamp_entry_club() (and the shared lgw_champ_entry_club()) now accept null/empty and return an empty club, matching the cup builder. This also unblocks the per-day knockout score inputs, which the seed failure had disabled.
* Fix: Clearing or editing a knockout score now keeps the next round in sync. Clearing a played KO match left the winner it had advanced sitting in the following round (e.g. a semi-final cleared back to blank while the finalist it produced stayed in the Final), and editing a result to a different winner did not update the downstream slot. Score saves now sync the next round: a cleared or drawn result vacates the slot, a changed winner replaces it and cascades — invalidating any now-impossible later-round result — while an edit that keeps the same winner leaves an already-played later round untouched.
* New: Manual knockout seeding for admins. Each first-round knockout slot now has a pencil control (admin only) to reassign it to any of that day's qualifiers, or clear it to TBD. Moving an entry that already sits in another slot vacates the old one automatically, and changing a participant resets that match and cascades the change down the bracket. Lets you override the automatic 1-vs-N seeding (walkovers, late changes, corrections) without editing the database.

= 2026.30.3 =
* Fix: Concession penalty now respects the division's max points. When a game is conceded, the concession scorecard was always built with the global max-points option (7), so in a division with a max of 6 (the 12-player division) the conceding team was docked 7 and the winner given 7 instead of ±6. The concession save now uses the division's max points sent from the widget (clamped 6–7), falling back to the global option only if absent. The standings render already used the per-division value; this aligns the stored scorecard with it.

= 2026.30.2 =
* Fix: Unplayed fixtures no longer show as 0-0 draws. Fixtures were flagged "played" whenever any shots/points cell was non-zero, but some divisions leave the away-points column blank on unplayed rows ('' is not '0'), and a kickoff time (e.g. 17:30) can land in a points column — both false-flagged upcoming 0-0 fixtures as played draws. A result is now recognised only when a shots or points value is present AND non-zero; blank and '0' are treated the same, and a time value in a points column is read as the kickoff time, not a score. Fixed in both the PHP cache parser and the JS CSV parser.

= 2026.30.1 =
* Fix: The Edit fixture panel now names what is attached to a locked fixture instead of the vague "has a result or overlay". A postponed fixture reads "has a postponement / reschedule attached" (not a phantom score), a conceded one "a concession", and so on — so a postponement is no longer mistaken for a recorded 0-0 result. Behaviour is unchanged; teams/date stay locked while an overlay/scorecard is set (clear it in the relevant panel first).

= 2026.30.0 =
* Feature: Admins can now edit fixtures directly in WordPress data-source mode. In CSV mode you'd fix a fixture by editing the Google Sheet, but WP-authoritative mode had no equivalent — this adds an "Edit fixture" panel to the fixture modal (admins only, WordPress mode only). You can correct the home/away teams, one-click Swap them (scores and points swap with them), and change the date/time. Edits to the teams or date are blocked when the fixture has a confirmed result or an admin overlay (concession/postponement/null-void/override) — clear that first. Standings recompute automatically. The panel only appears on widgets actually backed by the WordPress cache, and targets the exact season the cache was rendered from.
* Fix: Scorecards AND score overrides are now matched to fixtures by date as well as teams. Previously a scorecard was identified by home + away + division only and an override by home + away only, so two meetings of the same pairing in the same orientation (e.g. after swapping a fixture's home/away) could surface — or at submit time overwrite — the wrong occurrence, and the "has a result" guard false-flagged the other leg. Lookups, the guard and the clear action now disambiguate by date (legacy date-less records still match; date-less lookups are unchanged).
* Feature: Clear scorecard. When a fixture has a scorecard attached, the Edit fixture panel shows a "Clear scorecard" button that trashes it (recoverable), removes any score override, and returns the fixture to unplayed — so the teams/date can then be edited.
* Feature: Fixture-edit auditing. Every edit, swap and undo is written to an append-only log (last 500), viewable read-only under LGW → Fixture Audit. The first time a division is edited, its pristine fixtures are snapshotted as a baseline (also captured at seed time) so a future hard reset has a known-good state to return to.
* Feature: Per-fixture Undo. The edit panel has an "Undo last edit" button that restores the fixture from its most recent logged state.

= 2026.27.31 =
* Feature: Cup matches can now be recorded as a walkover / concession. In the admin score-entry popover on a cup fixture, a "Walkover — team conceded" control names which team conceded; the opponent advances to the next round with no score stored (cup ties carry no points). The bracket shows the advancing team with a "W" and the conceding side "w/o". A "Clear walkover" button reverts it, cascading the downstream slot clear as a normal score reset does. Mirrors the existing league-fixture concession control.

= 2026.27.30 =
* Fix: The request-access page ([lgw_club_access_request]) is now readable in dark mode. On dark-themed sites the card background was inverting while the text stayed dark, giving dark-on-dark. The page now pins the light palette (as the fixture modal does) across all states — logged-out, pending, approved and the request form.

= 2026.27.29 =
* Fix: The release zip now bundles lgw-club-access.php. It was missing from the build manifest, so sites installed/updated from the zip showed "modules could not be loaded — lgw-club-access.php" and the whole club-access feature (login-based submission, request/approve, settings) was unavailable. The manifest verification step now also checks for it so the omission can't recur.

= 2026.27.28 =
* Add: Unapproved signed-in users now get a "Request access" button in the fixture submission box (login-only mode) instead of a dead-end "contact an administrator" message. It links to the page carrying the [lgw_club_access_request] shortcode.
* Add: LGW → Club Access → Settings gains a "Request-access page" field to pin that page; left blank it auto-detects the first published page containing the shortcode.
* Add: After submitting a request from the fixture link, the user is returned to the fixture they came from (validated same-site redirect; a "Return to fixture" button is also shown).
* Change: The [lgw_club_access_request] page now uses the scorecard card/button/notice styling so it matches the fixture modal and the rest of the plugin.

= 2026.27.27 =
* Add: Scorecard submission now works with a logged-in club account (Slice C). When a submission mode allowing login is active, the fixture submission box recognises an approved, signed-in club admin and takes them straight to the scorecard form — no passphrase needed. Admins of more than one club in a fixture are asked which club they are submitting for. In "Login only" mode the passphrase box is replaced by a "Log in to submit" button; in "Both" mode a "Log in instead" link sits alongside the passphrase box.
* Fix: Multi-club submissions are authorised server-side against the signed-in user's approved clubs (an explicit club choice is only honoured if the account is approved for it).

= 2026.27.26 =
* Add: Club-admin registration and approval (Slice B). Club officials can now request submission access from a page carrying the new **[lgw_club_access_request]** shortcode — they log in, pick their club(s), and add a note. A league administrator reviews requests under **LGW → Club Access → Requests**, then approves (granting specific clubs), rejects, or revokes access under **Members**. Email notifications go to admins on each new request and to the user on every decision.
* Note: Registration currently uses standard WordPress login; Google sign-in is added in the next update by installing a social-login plugin (no further plugin changes needed).

= 2026.27.25 =
* Add: Foundation for identity-based scorecard submission (Slice A). Clubs will be able to log in and submit under an approved account instead of a shared passphrase. This release adds the behind-the-scenes plumbing only — a new "Club Admin" role, a per-user approved-clubs model, and a new **LGW → Club Access** settings page with a submission-mode switch (Passphrase only / Both / Login only). The passphrase still works exactly as before; nothing changes for clubs yet.

= 2026.27.24 =
* Change: Season Progress chart is taller (340px → 480px) so the lines have more vertical breathing room and are easier to read when several teams are close together.
* Add: "Select None" button on the Season Progress chart hides every team at once, clearing the graph. Use "Clear Filter" to bring them all back (or tap individual teams in the legend as before).

= 2026.27.23 =
* Fix: Cup scorecard modal text is now readable for visitors whose device is in dark mode. The modal box is white, but it was inheriting the dark colour palette, so the scorecard text rendered light-grey on white with almost no contrast. The modal now pins the light palette locally so the scorecard always shows as dark text on a white background.

= 2026.27.22 =
* Fix: Cup scorecards submitted by an admin (via "submit for both teams" or "confirm on behalf of the other club") are no longer mis-tagged as league games. The submission's league/cup context was only read on the club-submitted path; the admin paths defaulted to league, so those cup scorecards lost the Cup badge and re-triggered the false "Unresolved" warning. Context is now applied consistently on every save path, with a data-based fallback that recognises a cup scorecard from its division (a configured cup title) even if the context is missing from the request.

= 2026.27.21 =
* Fix: Cup scorecards no longer show a false "⚠️ Unresolved — sheet writeback blocked" warning on the Scorecards list and edit modal. A cup scorecard tagged (or defaulted) as a league game was flagged because a cup title never maps to a league sheet tab. Cup scorecards are now recognised from their own data (their division is a configured cup title), so they are treated as cup even when the stored context is stale — no false warning, and the sheet writeback is correctly skipped. Saving a mis-tagged cup scorecard in the admin now self-heals its context to "cup" instead of cementing it as league. Adds lgw_backfill_cup_contexts() to repair existing records in bulk (run once via WP-CLI: wp eval 'echo lgw_backfill_cup_contexts();').

= 2026.27.20 =
* Fix: Cup bracket — preliminary-round winners now display in the correct Round 2 slot. The bracket view mapped prelim matches to R2 slots by scanning for empty team names, so as each winner was filled in the remaining "winner of…" placeholders shifted by one — making a winner appear to face its own team while the real opponent seemed to drop to the next fixture. Placement now keys on the stored slot structure (bye slots carry a draw number, prelim-fed slots do not), so the mapping is stable no matter how many results are entered. Stored data and advancement were already correct; this was display-only. The PHP winner-advancement helper was aligned to the same structural rule for robustness against legacy/hand-edited draws.

= 2026.27.19 =
* Feature: Player Tracking → Club Summary now has an "Email Tracked Players to Secretaries" button. On demand, it sends each club an individual email addressed to their Secretary (from the Club Directory) with a CSV attachment listing the players currently tracked for that club — name, gender, appearances, teams, W/D/L and shots — scoped to the season being viewed. Clubs with no Secretary email on file are skipped and reported back in an admin notice.

= 2026.27.14 =
* Fix: Round-robin fixture generation now avoids scheduling two teams from the same club at home on the same day. Sibling teams (e.g. DUNBARTON A and DUNBARTON B, which share a green) can't both host in the same round — the generator flips one fixture's home/away so each club hosts at most once per date. Club identity is derived by stripping a trailing team letter/number from the name. Note: divisions are still scheduled independently, so this applies within a division; if two divisions are given the same dates a club with a team in each could still host both.

= 2026.27.13 =
* Fix: The Setup Wizard no longer touches the live league until you hit "Finish". Team rosters, divisions, data source, and Google Sheet settings are now staged and applied only on the final confirm — going Back from the Review step (or abandoning the wizard there) leaves your existing league fully intact. Previously step 2 wrote and overwrote before confirmation.

= 2026.27.18 =
* Feature: Club Directory now has a "Import CSV" tool. Upload a CSV (club_name, address, website, contact_role, contact_name, contact_phone, contact_email — one row per contact) to bulk-load or refresh club contact details. Clubs are matched by name: existing clubs have their address, website and contacts updated while passphrase, badge, colours, facilities and submit flag are preserved; new names are added. Ships with lgw-club-contacts.csv pre-populated from the NIPGL club-contacts directory (33 clubs).

= 2026.27.17 =
* Fix: The experimental Setup Wizard module is now loaded optionally — if the file is missing or fails to load it is silently skipped instead of showing a "modules could not be loaded" admin notice. Core plugin behaviour is unchanged.

= 2026.27.16 =
* Change: Recomputed WP-mode standings now decide W/D/L by aggregate shots (the overall match result) rather than by match points, matching how the league tables are compiled. Points totals are unaffected.

= 2026.27.15 =
* Fix: In WordPress-authoritative mode the league table was frozen at the values seeded from the spreadsheet — results confirmed after seeding showed in the fixtures but never moved the table (e.g. Ballymena A stuck on 41 while their fixtures totalled 46). The standings are now rebuilt from the fixtures on every render, so each confirmed (or seeded) result is counted. Pending and disputed scorecards are excluded until final. Google Sheets mode is unchanged (it keeps reading the sheet's own LEAGUE TABLE block).

= 2026.27.12 =
* Feature: First-run Setup Wizard (LGW ▸ 🚀 Setup). Auto-launches on activation and guides a fresh install through picking a data source — Google published CSV, uploaded spreadsheet, or WordPress database — then bootstraps divisions and team rosters accordingly. Upload accepts a two-column Division,Team CSV and seeds the WP cache; the WordPress-DB path lets you type divisions and rosters directly. Re-runnable any time from the menu.
* Feature: Optional round-robin fixture generator in the upload and WordPress-DB steps — single or double round (home & away), configurable first-round date and weeks between rounds. Odd team counts get a bye each round. Generated fixtures are written into each division as unplayed.
* Feature: The Review step now previews the generated fixtures per division (round-by-round) and lets you set the first-round date, interval, and single/double round per division — or tick a group of divisions and bulk-apply a date/interval to all of them at once. "Regenerate" refreshes the preview; "Finish" writes the fixtures.
* Feature: Overwrite guard — when a league already exists, step 1 warns and requires an explicit confirm before the wizard replaces the active season's divisions and data source. Enforced server-side.
* Feature: "Start fresh" reset — wipes the active season's divisions, seeded standings, and data-source setting to begin from a clean slate. Leaves sponsors, theme, players, and scorecards untouched.
* Validation: duplicate team names within a single division are now rejected (case-insensitive) in the wizard and in the team seeder, with a clear message asking you to rename or remove the duplicate. Same name across different divisions/competitions remains valid.

= 2026.27.11 =
* Change: Scorecard photo analysis now uses the current Claude Sonnet 4.6 model (claude-sonnet-4-6), up from the legacy 4.5. Verified working against the configured API key.

= 2026.27.10 =
* Fix: Scorecard photo "Read with AI" could spin forever with no error if the server was slow or could not reach api.anthropic.com. The upload now has a 90s client timeout, guards against non-JSON (HTTP 504/500) responses, and shows a clear error (including HTTP status and a hint about API reachability) instead of hanging. Applies to both the standalone and in-modal photo tabs.

= 2026.27.9 =
* Feature: Seed a division from a plain team list (Division Cache panel) — for starting a new season with no spreadsheet. Paste team names, one per line, and a zeroed table (P/W/D/L/F/A/Pts = 0) is written to WordPress; scorecards fill it in. Existing fixtures are preserved.
* Tweak: In WordPress data-source mode, the Google Sheets Writeback section is now flagged as optional (keeps the sheet mirrored as a backup; standings no longer depend on it).

= 2026.27.8 =
* Feature: Data source "WordPress DB (Google Sheets backup)" is now selectable. Standings/fixtures are served from the WordPress database and kept current by confirmed scorecards + concessions; Google Sheets CSV seeds an empty division once and acts as an automatic per-division fallback. Switching is safe/reversible — divisions with no WP data fall back to live CSV. In WP mode the CSV cron no longer overwrites WP standings (seed-only) and WP data does not age-expire to CSV.

= 2026.27.7 =
* Feature: "View details" changelog popup now renders GitHub release notes as a proper heading + bulleted list with clickable links, instead of a flat escaped block.

= 2026.27.6 =
* Fix: "View version details" popup showed "Plugin not found" — the plugins_api handler now matches the plugin's actual installed folder slug instead of a hardcoded one, checks the GitHub API HTTP status, and includes last-updated. Details/changelog now render from the GitHub release notes.

= 2026.27.5 =
* Feature: Google auth failures during scorecard→Drive writeback are no longer silent — the last OAuth/service-account token error is stored and shown as an admin notice, with an `invalid_grant` hint pointing to reconnecting the account / moving the OAuth consent screen to production.
* Feature: Division cache CSV sync failures are now recorded per division, shown inline in the Division Cache health table and summarised in an admin notice, so stale/empty league tables report *why* they failed to refresh.

= 2026.27.4 =
* fix(scorecard): scope scorecard identity by division so competitions that
  share team names (e.g. Midweek vs a Saturday division) no longer cross-match.
  Fixes a Midweek fixture showing a Division 2 scorecard.

= 2026.27.3 =
* fix(scorecard): replace native datalist with custom JS dropdown for
  player name suggestions — fixes Firefox Android where datalist is unreliable.

= 2026.27.2 =
* fix(progress): badge circles no longer rendered for hidden datasets — use
  chart.isDatasetVisible() instead of meta.hidden check.

= 2026.27.1 =
* feat(progress): retain team filter across Points/Position tab switches; add
  Clear Filter button (hidden until at least one team is hidden).

= 2026.26.25 =
* fix(widget): progress chart CSS — play button hover state and flex-wrap on
  controls bar.

= 2026.26.24 =
* fix(widget): progress chart Play/Pause/Resume/Replay button labels now plain
  text — removed Unicode symbols (▶ ⏸ ↺) that rendered as boxes on some hosts.

= 2026.26.23 =
* feat(widget): auto-distinct progress chart colours and shapes — golden-ratio
  HSL colour generation replaces hardcoded palette; POINT_STYLES array cycles
  through 8 Chart.js shapes (circle/triangle/rectRot/star/rect/cross/…); club
  admin colour pickers still override auto colours per team.

= 2026.26.20 =
* fix(widget): progress chart uses actual played date, not scheduled fixture
  date — brought-forward games now appear at the correct position on the
  x-axis. Badge overlay uses SVG-only images; raster badges fall back to
  coloured initials circle. Chart tab hidden on screens ≤520px (UX, not
  data — chart is lazy-loaded on click so mobile payload is unchanged).

= 2026.26.19 =
* feat(widget): season progress chart — new 📈 Progress tab on division
  widget. Line chart (Chart.js, lazy CDN load) showing cumulative points
  or league position over the season. Toggle between Points/Position views.
  Data from WP scorecards via lgw_ajax_season_progress. Handles null &
  void exclusion, multi-date grouping, and null gaps for late-joining teams.

= 2026.26.18 =
* fix(admin): Table Compare coverage gaps now match against scorecard data
  directly — lgw_match_key format differs between regular scorecards
  (sanitize_title, no date) and concessions (pipe-separated with date).
  Gap lookup rebuilt from lgw_scorecard_data home/away + lgw_fixture_date
  meta; bare home||away fallback covers pre-fixture_date scorecards.

= 2026.26.17 =
* fix(admin): Table Compare now correctly includes regular scorecards — division
  was stored inside lgw_scorecard_data not as lgw_sc_division meta, so only
  concessions/null-voids were matching. Filter moved to PHP post-load.

= 2026.26.16 =
* feat(admin): Table Compare page — side-by-side diff of WP-calculated league
  table vs live Sheets CSV per division. Highlights mismatched cells (gold),
  WP-only teams (green), Sheets-only teams (red). Coverage gaps section lists
  played results in Sheets with no corresponding WP scorecard.

= 2026.26.15 =
* feat(league): null & void workflow — admin checkbox on fixture modal marks a
  game null & void; creates a confirmed scorecard (0–0, 0 pts both teams) with
  lgw_sc_null_void flag; grey pill displayed on fixture rows; admin updates
  Google Sheets table separately. Clears the scorecard on cancel.

= 2026.26.14 =
* fix(modal): concede/postpone admin panels side-by-side with matching bordered
  style; single HR separator below; stacks on narrow screens (<520px).

= 2026.26.13 =
* feat(cup): rename entry tool on cup admin edit page — corrects a team name
  across the entries list, bracket slots, and draw pairs without affecting
  scores. Matches the championship rename mechanism.
* note: Midweek Cup player appearances automatically get " MW" appended to
  team names (division field contains "midweek") once scorecards are confirmed.

= 2026.26.12 =
* fix(cup): scorecard fetch now scoped to cup context — league scorecards for
  same teams no longer appear when clicking a cup bracket match. Two fixes:
  lgw_ajax_cup_get_scorecard passes 'cup' context; lgwFetchScorecardOrSubmit
  forwards opts.context in the GET request.

= 2026.26.11 =
* fix(cup): pending scorecard icon (⚠️) now appears inline next to the team
  who has not yet submitted, rather than in the top-right corner of the card.
  Confirmed icon (✅) remains in the top-right corner.

= 2026.26.10 =
* fix(cup): prelim round winner now advances to the correct R2 slot — replaced
  naive floor(match_idx/2) formula with the evenly-distributed winner_positions
  mapping that the draw builder uses; fixes all three code paths (save_score
  advancement, cascade reset, JS placeholder).

= 2026.26.9 =
* feat(cup): pending (⚠️) and confirmed (✅) scorecard icons on cup bracket
  match cards — PHP shortcode builds a status map at render time, JS reads
  it when drawing the bracket.

= 2026.26.8 =
* fix(scorecards): suppress "Division name wasn't recognised" warning for cup
  context scorecards — cups have no Sheets tab to resolve against.

= 2026.26.7 =
* fix(fixtures): exclude past Bye fixtures from the Upcoming filter in both
  JS-rendered and SSR modes.

= 2026.26.6 =
* fix(players): appearances modal now correctly season-filters — the AJAX
  handler was passing lgw_season_where() through prepare(), which corrupted
  the STR_TO_DATE '%d/%m/%Y' format string. Switched to get_results() with
  intval-safe player_id, matching the pattern used by all other appearance
  queries.

= 2026.26.5 =
* feat(players): club filter dropdown on [lgw_player_stats] — filters visible
  rows in the active tab; row numbers re-sequence after filtering.
* feat(players): appearances modal header shows club badge (resolves via
  lgw_club_badges option, same fuzzy prefix logic as team modal).

= 2026.26.4 =
* feat(players): player names in [lgw_player_stats] link to a modal showing
  their game-by-game league appearances (date, opponent, SF, SA, +/-, result).
  Division tab links pre-filter to that division; All tab shows all divisions.

= 2026.26.3 =
* feat(players): add [lgw_player_stats] shortcode — per-division player league
  table with wins/draws/shot-difference/shots-for sorting and an All tab.
* feat(players): store division on appearance rows; back-fill existing records
  from scorecard meta on first run.
* fix(enqueue): load CSS/JS on pages with only the lgw_player_stats shortcode.

= 2026.26.2 =
* fix(fixtures): time pill now correctly detected when time column has no header
  — scan range extended to include the column immediately after away points.
* fix(fixtures): time values stored as HH:MM no longer have minutes stripped
  when cached by PHP (seconds-strip guard added).

= 2026.26.1 =
* feat(ticker): animation speed normalised to a constant 80 px/s so all
  divisions scroll at the same rate regardless of how many results they have.
* fix(ticker): recent results now fetched per-division (up to 30 each) before
  merging, so divisions with fewer results are no longer crowded out by busier
  ones in the global top-30 pool.
* fix(ticker): top border-radius removed when ticker is not the first element
  in its container (sits flush under a division title).
* chore: switched versioning scheme to yyyy.ww.n (year · ISO week · release).

= 7.6.59 =
* feat(fixtures): filter button "Results" renamed to "Latest Results".
* feat(fixtures): Latest Results tab regroups played fixtures by actual date
  played (using the rescheduled played-on date where present) rather than
  the original scheduled date, and sorts newest first.
* fix(fixtures): Upcoming filter shows all unplayed fixtures regardless of
  date, not just future-dated ones (catches postponed matches).
* fix(fixtures): active filter button highlight is now restored correctly
  after a page refresh when a non-default filter was previously selected.

= 7.6.58 =
* feat(clubs): per-club scorecard submission toggle. A new "Scorecard
  Submission" section in the club edit form adds a "can submit" checkbox.
  When ticked, that club can submit scorecards even when the global
  submission mode is Admin Only. Global Disabled still overrides all clubs.
  The flag is visible on the clubs grid card (📋 icon). Affects both
  division widget and cup bracket submission paths.

= 7.6.57 =
* feat(scorecard): female player toggle (♀ button) on every player row;
  appends * to the name so lgw_log_appearances() sets the female flag in the
  players table automatically on confirmation.
* feat(scorecard): import review panel fires after photo/Excel parse; lists
  every unrecognised player name with fuzzy suggestions (initial+surname,
  nickname aliases, Levenshtein ≤ 2) scoped to the club's own player list.
  Each row has Accept Suggestion, Keep, and ♀ buttons. Panel auto-dismisses
  when all entries are resolved.
* feat(scorecard): opposing team player restriction — on blur of any player
  input on the opposing side, lgwBlockOpposingPlayer() checks the name
  against the opposing team's registered datalist; if not found the field is
  cleared and a red inline warning is shown for 5 s. Prevents a submitting
  club from adding unknown players to the other side.

= 7.6.56 =
* feat(scorecard): new-player fuzzy check on player name blur. If a typed
  name does not match any player in the club's registered list, an inline
  dialog appears offering: similar names (initial+surname match, nickname
  aliases, Levenshtein ≤ 2) with an Accept Suggestion button; or a simple
  Yes/No if no similar names are found. Accepting a suggestion fills the
  slot and advances focus; declining returns focus to the cell.

= 7.6.55 =
* fix(sheets): normalise spaces around hyphens in team name matching so
  "CI - KNOCK B" in the sheet matches "CI-Knock B" in the scorecard.

= 7.6.54 =
* fix(players): normalise match_date to dd/mm/yyyy on write in lgw_log_appearances().
  AI photo and Excel parsers were storing dates as "Sat 18-Apr-2026", which
  STR_TO_DATE(..., '%d/%m/%Y') cannot parse, silently excluding those appearances
  from season filtering. New lgw_normalise_match_date() helper handles the day-name
  prefix format, ISO yyyy-mm-dd, and passes through dd/mm/yyyy unchanged.

= 7.6.53 =
* fix(players): correct STR_TO_DATE format string corrupted by prepare() fragment reuse.
  lgw_season_where() and 4 inline duplicates were building season date WHERE clauses via
  $wpdb->prepare(), which replaces % with an internal token that is never resolved when
  the fragment is concatenated into a larger query. Replaced with esc_sql() so the
  %d/%m/%Y format string reaches MySQL intact.

= 7.6.52 =
* feat: scorecard entry now uses a positional player grid instead of comma-separated
  textareas. Each rink has named Lead/Second/Third/Skip slots for both sides.
  Short rinks (3 players) populate Lead, Second, and Skip with Third left empty.
  Player names autocomplete from the registered player list for each team via a
  new lgw_get_team_players AJAX endpoint.

= 7.6.51 =
* feat: pending scorecard view now shows a "View & confirm / Submit my own" mode
  toggle so the opposing club can choose to either confirm the existing scorecard
  or submit their own independent version from the same fixture modal.

= 7.6.50 =
* fix: unplayed fixture modal now checks for an existing pending scorecard before
  showing the submission form. If the other club has already submitted, the opposing
  club sees the scorecard with agree/amend options (or a login gate) instead of a
  blank entry form.

= 7.6.49 =
* feat: player export now respects active filters — club, team, and name filters
  applied in the admin list are passed through to the Excel export, so the
  downloaded file contains only the filtered players and their per-match columns.

= 7.6.48 =
* fix: orphaned appearances cleanup now catches scorecards moved to the WordPress bin.
  The LEFT JOIN previously matched trashed posts, so binned scorecards were not flagged.
  Added post_status != 'trash' to the join condition so both deleted and binned scorecards
  surface their orphaned appearance records in the preview.

= 7.6.47 =
* fix: orphaned appearances cleanup — added scorecard_id > 0 guard so only appearances
  with an explicit (now-missing) scorecard post are flagged. Prevents false positives
  for old league records logged before scorecard IDs were tracked.

= 7.6.46 =
* feat: form guide — date played now takes priority over fixture date in form pips (league table,
  fixtures modal, scorecard modal). Tooltip and data-sc-date now reflect the actual played date
  when it differs from the scheduled fixture date. Results are also re-sorted by effective played
  date before the last-5 slice so a rescheduled fixture appears in its correct chronological
  position. Affects both SSR (lgw-div-cache.php) and client-side (lgw-widget.js) form map builders.

= 7.6.45 =
* fix: lgw-div-cache — replace all calls to undefined lgw_get_option_array() with get_option()
  using an empty array default. Affected lgw_seasons, lgw_drive, lgw_score_overrides, lgw_badges,
  and lgw_club_badges (8 call sites). Resolves test failure "Call to undefined function".

= 7.6.44 =
* fix: lgw-gchamp move/withdraw — "Entry not found in group" resolved. sanitize_text_field()
  was collapsing internal whitespace (e.g. double spaces) in the received entry name, causing
  a strict-comparison mismatch against the stored string. Now uses trim(wp_unslash()) and
  resolves the canonical stored entry via lgw_gchamp_norm_entry() (same approach as the
  rename function), ensuring all downstream array_filter operations use the exact stored string.

= 7.6.43 =
* feat: lgw-gchamp — Manual Group Adjustments panel: move an entry between groups on the same day
  (removes their old fixtures, generates new fixtures vs all target-group members) or withdraw
  a dropped entry entirely (removes from group, day entries, and top-level entries list). Both
  actions reset the day KO bracket which must be re-seeded afterwards. Scored fixtures for the
  moved/withdrawn entry require a second confirmation before deletion.
* feat: lgw-gchamp — Copy Championship: duplicate any group championship to a new ID and title
  (preserves draw, scores, and all settings). Available from both the championships list and the
  edit page. Useful for testing group adjustments on a copy before touching a live competition.

= 7.6.42 =
* chore: update .github/workflows/release.yml to include lgw-clubs.php in zip build and manifest
  verification steps.

= 7.6.41 =
* feat: add Facilities section to Club Directory — greens count, rinks (auto-defaults to greens × 6
  but can be overridden), floodlights, bar, changing rooms (boolean checkboxes), and car parking
  (none / on street / private). Stored as facilities key on each lgw_clubs record.

= 7.6.40 =
* Fix: fatal TypeError on Scorecards admin page (and all LGW admin pages) when lgw_drive.sheets_tabs
  is stored as a JSON string rather than a decoded array — array_filter() received '{}' and crashed,
  taking down the entire LGW menu. sheets_tabs is now decoded defensively at all three read sites.

= 7.6.38 =
* feat: add Club Directory admin page (lgw-clubs.php) — card-based index of all clubs with
  inline per-club edit panel; stores address, website, and contacts (Secretary, President,
  Green Keeper plus freeform extras) as additional keys on the lgw_clubs option. Passphrase
  and badge management unified into the same panel. AJAX save/delete per club — no full-page
  reload required.

= 7.6.37 =
* Fix: player name "A. Other" is now treated as the same player as "A Other" (and any dotted-initial variant) by routing `lgw_clean_player_name()` through `lgw_normalise_player_name()`. Affects new-player detection, appearance logging, and all other call sites consistently.

= 7.6.36 =
* Fix: gchamp Rename Entry tool updated the top-level entries list but not group entries, fixtures, per-day knockout brackets or qualifiers — caused by `foreach ( $champ['days'] ?? array() as &$day )` taking a reference into a temporary copy produced by the ?? operator, so writes through $day/$group never reached $champ['days']. Restructured to check existence with !empty()/is_array() and iterate the real array directly.

= 7.6.35 =
* Fix: orphaned-appearances tidy-up now excludes championship appearances by default (championship appearances always have scorecard_id=0, so they always looked orphaned even when correct). Added Include game types filter (League / Cup / Championships) to the preview, with Championships unchecked by default.

= 7.6.34 =
* Fix: Group Championship Rename Entry tool reporting "not found" for entries visible in drawn groups — dropdown now built from every entry-name occurrence (entries list, group entries/fixtures, brackets, qualifiers), not just the top-level entries list. Matching is also whitespace-tolerant (lgw_gchamp_norm_entry) so minor formatting drift between the entries list and a drawn group no longer causes a silent mismatch.

= 7.6.33 =
* Feature: orphaned-appearances tidy-up now has a dry-run preview — Players > Merge shows a table of affected appearances (player, club, team, match, date, rink, result, type) with per-row checkboxes plus Select all/none, before any deletion. Removing selected rows runs the same prune-and-cleanup as before, scoped to the chosen rows.

= 7.6.32 =
* Feature: Rename Entry tool added to Group Championships (mirrors the existing champ.php tool) — corrects spelling across the entries list, all per-day groups and fixtures, per-day and top-level knockout brackets, and qualifiers lists. New AJAX handlers lgw_ajax_gchamp_rename_entry and lgw_ajax_gchamp_get_entries.

= 7.6.31 =
* Feature: one-off cleanup tool for orphaned player appearances — Players > Merge tab now has a "Tidy up" button that removes appearance records for scorecards deleted before automatic cleanup existed, and prunes players left with zero appearances.
* Fix: removed duplicate appearance-cleanup call in lgw_scorecard_on_delete (already handled by lgw_on_scorecard_deleted in lgw-players.php).

= 7.6.30 =
* Feature: deleting (trashing) a confirmed scorecard now reverses everything confirmation wrote — clears the score/points/online cells in the Google Sheet, removes the matching lgw_score_overrides entry, restores the cached fixture to unplayed (lgw_cache_wipe_fixture_result) so the league table updates immediately, and deletes player appearance records for that scorecard.

= 7.6.29 =
* Fix: cross-division concession contamination — applyConcessionsToTable (JS) and lgw_apply_concessions_to_teams (PHP) now require BOTH teams to exist in the current division before applying a concession. Previously a concession in Division A could corrupt standings in Division B if one team appeared in both.

= 7.6.28 =
* Fix: conceded fixtures with auto-created scorecards were double-counted in standings — fixture overlay loop and lgw_apply_concessions_to_teams now skip entries that have a scorecard_id (already handled by lgw_cache_merge_result via the scorecard pipeline).

= 7.6.27 =
* Feature: Conceded Fixtures management panel added to Scorecards admin page — lists all entries in lgw_concessions with Clear button per row; clearing fires the full wipe pipeline (overlay, cache, score overrides, scorecard post).

= 7.6.26 =
* Fix: clearing a concession now fully restores the fixture to unplayed — removes score overrides for the home/away pair, wipes the cached fixture result (played=false, scores cleared) via new lgw_cache_wipe_fixture_result(), and trashes the auto-created scorecard post.

= 7.6.25 =
* Fix: SyntaxError missing ) — chk.addEventListener closing }); dropped during noChkMode refactor.

= 7.6.24 =
* Fix: bindConcedePanel crashes on null chk.addEventListener when opened from showFixtureModal (no-checkbox panel) — guard now uses noChkMode flag set before any chk access, preventing the TypeError that also blocked scorecard loading.

= 7.6.23 =
* Fix: concession Clear button missing — after a concession is saved the fixture becomes a played row (lgw_cache_merge_result sets played=true) so showFixtureModal opens instead of showUnplayedFixtureModal. Concession clear panel now also rendered and bound in showFixtureModal for admin users. Clearing removes the panel and trashes the auto-created scorecard.

= 7.6.22 =
* Fix: Clear concession button not bound after save — bindConcedePanel now binds the Clear button in the already-conceded (no-checkbox) state by deferring the no-chk branch until after doSave is defined.

= 7.6.21 =
* Fix: concede panel does not update after saving — panel now swaps to conceded-notice state immediately showing the conceding team and a Clear button; clearing restores the original unchecked panel without a page reload.

= 7.6.20 =
* Fix: ReferenceError division is not defined in bindConcedePanel — division parameter added to function signature and both call sites.

= 7.6.19 =
* Debug: added error_log at entry to lgw_ajax_save_concession to diagnose silent failure.

= 7.6.18 =
* Fix: concession save AJAX returning no response — do_action(lgw_scorecard_confirmed) now wrapped in try/catch so a Sheets/Drive exception no longer kills the response. Added missing return after wp_send_json_error on wp_insert_post failure.

= 7.6.17 =
* Fix: scorecard admin page fatal — array_filter() called on string when lgw_score_overrides/lgw_drive stored as JSON string instead of PHP array. Added lgw_get_option_array() helper that decodes JSON strings transparently; applied to all lgw_drive, lgw_score_overrides, lgw_concessions, lgw_postponements, lgw_seasons, lgw_badges reads.

= 7.6.16 =
* Fix: scorecard listing foreach wrapped in try/catch — errors now logged to PHP error log with post ID, exception message, file/line, and scorecard data snapshot. Broken rows show an inline error notice instead of crashing the page.

= 7.6.15 =
* Fix: scorecard admin listing page critical error — $sc meta can be false on new or malformed scorecard posts (including auto-created concession scorecards); added is_array() guard before array key access.

= 7.6.14 =
* Fix: concession workflow now auto-creates a confirmed scorecard (50-0 / ±max_pts) and fires lgw_scorecard_confirmed — triggering Sheets writeback and cache merge via the standard pipeline. The lgw_concessions overlay is retained for backwards-compat and pill display.
* Fix: clearing a concession voids the auto-created scorecard post.

= 7.6.13 =
* Fix: confirmed scorecard scores now survive a CSV re-sync — lgw_cache_overlay_scorecard_statuses now writes scores (shots/points) from confirmed scorecards into the cache at sync time, so the Sync button no longer overwrites results with stale CSV data.

= 7.6.12 =
* Fix: form guide tooltip right-aligned via data-tip attribute; browser native title tooltip removed to prevent double-up.

= 7.6.11 =
* Fix: fixture scorecard modal now shows team stats and form guide on the SSR path — data-teams JSON attr passes sorted standings to JS.

= 7.6.10 =
* Fix: form guide pips on the scorecard modal are not clickable — prevents accidental navigation to a different scorecard.

= 7.6.9 =
* Feature: form guide pips are now clickable on standings table and team modal — opens the scorecard for that match.
* Feature: fixture scorecard modal now shows summary stats (Pl/Pts/W/D/L) and last-5 form for both teams.

= 7.6.8 =
* Fix: shots-defeat shown as L not D in fixture modal — shots now primary result decider.
* Feature: form guide — last 5 results per team on standings table (hidden ≤600px) and team modal with hover tooltip.
* Fix: form guide missing on SSR path — PHP cache render (lgw_cache_render_table) now outputs form pips.
* Fix: README.md now packaged inside lgw-division-widget/ folder in release zip.

* Feature: Concession support — admin can mark any unplayed fixture as conceded via the fixture modal, specifying which team concedes; winner receives max_points pts + 50-shot victory; conceding team receives a -max_points deduction + 50 shots against; both adjustments reflected in standings table, shot difference, W/L record and Pl count; fixture row shows a purple 🏳️ Conceded pill; non-admins see a notice in the fixture modal

= 7.6.2 =
* Fix: wpAdminLogin() uses LGW_BASE_URL env var instead of private Playwright browser._options API
* Fix: deleteAllScorecards() safe when no scorecards exist
* Fix: playwright.config.js loads tests/.env via dotenv so env vars are available in helper modules
* Add: tests/.env.example and tests/TESTING.md local development runbook

= 7.6.1 =
* Fix: SSR (DB-primary) path now renders postponed pill on fixture rows, matching XHR path behaviour
* Fix: Postponed fixtures now show 🚫 Postponed / Rescheduled pills in both notes column and pill bar when served from cache


= 7.6.0 =
* Test: Phase 4.3 — Playwright E2E test suite added (tests/ directory)
* Test: PHPUnit unit tests for cache read/write, CSV parsing, and result merging
* Test: GitHub Actions test.yml CI workflow (runs on push to main)
* Test: lgw_seed_test_clubs() helper registered under WP_ENVIRONMENT_TYPE=local
* Build: GitHub Actions release.yml already excludes tests/ from release ZIP


= 7.6.1 =
* Diagnostic: log played/unplayed row counts after binding to identify fixture click issue.


= 7.5.4 =
* Fix: Restore bindTeamLinks to original logic. Fix lgwCachedGroupsToRows to return empty array (safe for parseTableRows). Add console diagnostics to SSR path to identify fixture click issue.


= 7.5.3 =
* Fix: Fixture submission modal now reliably opens on click anywhere on an unplayed fixture row, including team name areas. Team link clicks on unplayed rows now bubble up to the row handler rather than being swallowed.


= 7.5.2 =
* Fix: Fixture submission modal now opens correctly when clicking an unplayed fixture row. Team name clicks inside unplayed rows stop propagation (preventing double modal) and defer to the row click handler.


= 7.5.1 =
* Fix: SSR filter bar now uses same .fix-filter/data-f markup as XHR path so existing CSS applies correctly.
* Fix: Unplayed fixture row clicks now open the submission modal correctly — team name link clicks inside unplayed rows no longer stopPropagation, so the row click handler fires as expected.


= 7.5.0 =
* New: [lgw_division] shortcode now renders standings table and fixtures list server-side from the DB cache (Phase 4.2). No XHR or loading spinner when cache is warm — content appears instantly on page load.
* New: data-prerendered and data-cached attributes allow lgw-widget.js to skip the initial CSV fetch and bind click handlers directly to pre-rendered HTML.
* New: Filter bar (All / Results / Upcoming) works on server-rendered fixtures via DOM show/hide rather than re-rendering.
* Graceful fallback: if cache is empty or stale beyond 24h the widget falls back to the existing XHR path transparently.


= 7.4.1 =
* Fix: Division cache sync and status panel now correctly unpack season division entries (array of {division, csv_url} objects) rather than expecting plain strings, resolving "Array to string conversion" warning on the Settings page.


= 7.4.0 =
* New: DB-primary division cache layer (lgw-div-cache.php). Division standings and fixtures are now stored in WP options and served instantly on page load — no outbound HTTP request required.
* New: Background WP-Cron sync keeps the cache fresh automatically (configurable: 15 min / 30 min / 1 hour / 4 hours).
* New: Confirmed scorecard results merge into the fixture cache immediately via lgw_scorecard_confirmed hook.
* New: Division Cache health panel in Settings shows last-synced time, fixture count, and team count per division with per-division and bulk Sync buttons.
* Updated: "Clear Cache Now" button in Settings also clears the new DB cache entries.

= 7.3.62 =
* Fix: ★/♀ flag checkboxes now restore filter state after update (native form.submit() bypasses submit event; fixed with change event delegation).


= 7.3.62 =
* Fix: Player admin POST actions (rename, flags, delete) moved to admin_init hook to prevent headers-already-sent warning on redirect.


= 7.3.62 =
* Enhancement: Player admin — rename, starred/female flag changes, and delete restore active filters after the page reloads, so you return to the same filtered view.


= 7.3.62 =
* Enhancement: Player admin — new per-team league stats breakdown with ▾ expand toggle for multi-team players.
* Fix: Popover team filter chips now recompute W/D/L/Played to show stats for the selected team only.


= 7.3.62 =
* Fix: Saving an admin edit on a disputed scorecard now resolves the dispute — status updated to Confirmed, away version cleared, audit entry logged.
* Enhancement: Disputed edit form shows a warning banner and relabels the save button to signal that saving will resolve the dispute.


= 7.3.62 =
* Enhancement: Pending scorecard pill now identifies the awaiting team — e.g. "Pending (Hilden)" indicates Ards submitted and Hilden's confirmation is outstanding.


= 7.3.62 =
* Enhancement: Championship bracket displays only the skip for triples/fours entries; a ▾ toggle expands to show all team members as clickable player-stats links (stats-eligible championships only).


= 7.3.62 =
* Fixed: rename handler was attempting to update non-existent player_name column in lgw_appearances (names are resolved via player_id join — no column update needed)
* Fixed: renaming to an existing player name now correctly merges the two records — appearances are re-pointed to the existing player_id and the old record is deleted — rather than hitting a duplicate key DB error


= 7.3.62 =
* Player rename now cascades to lgw_appearances: all appearance records for the player are updated to the new name; success notice reports how many records were updated


= 7.3.62 =
* Player Tracking admin page now displays the LGW page header (logo + version number) consistent with other admin pages


= 7.3.62 =
* Fixed: rename button onclick was not firing; replaced inline onclick attributes on Rename and player-name buttons with data attributes + event delegation via addEventListener, eliminating any CSP or inline-handler blocking issues


= 7.3.62 =
* Fixed: Rename button on Players page was missing type="button" — without it the browser treats it as type="submit" and swallows the click before the onclick handler fires


= 7.3.62 =
* Added null guard on rename modal elements in DOMContentLoaded to surface any missing-element errors in browser console rather than silently breaking


= 7.3.62 =
* Fixed: Rename button on Players admin page did not work — browser prompt() is suppressed in WP admin context; replaced with a proper inline modal
* Rename modal: duplicate name check via AJAX before submitting — if the new name matches an existing player for the same club, a warning is shown and a second click ("Yes, merge") is required to confirm the merge intent


= 7.3.62 =
* Fixed: fatal error in lgw_ajax_confirm_scorecard — replaced call to lgw_user_can_manage_scores() with inline capability check (manage_options or edit_others_posts) to avoid load-order dependency


= 7.3.62 =
* Admin confirm for pending scorecards: when viewing a pending scorecard as a logged-in admin, the club passphrase gate is replaced with a direct "Confirm on behalf of [club]" button; no passphrase required
* PHP: lgw_ajax_confirm_scorecard now allows admin bypass — confirms as the other team with a distinct audit log entry


= 7.3.62 =
* Fixed: admin "confirm on behalf of other club" now also works when a scorecard has already been submitted by one team — admin can confirm the pending card without needing to log in as the other club


= 7.3.62 =
* Admin scorecard submission: new "Also confirm on behalf of the other club" checkbox available when submitting for home or away team; scorecard is immediately marked confirmed with a distinct audit log entry; checkbox is hidden when "Both teams" is selected


= 7.3.62 =
* Fix: Multi-champ widget font — removed fragile dependency on lgw_font_options() and lgw-font handle registration; now reads lgw_theme option directly, enqueues its own lgw-mc-font Google Fonts handle, and sets font-family directly on .lgw-mc-widget and all children

= 7.3.43 =
* Fix: Multi-discipline championship widget now correctly inherits the chosen font from the font picker — lgw-multichamp enqueue now depends on lgw-font and applies the same --lgw-font CSS variable; previously fell back to sans-serif instead of Saira

= 7.3.42 =
* Feature: Font picker in Settings > Theme — choose from 10 curated Google Fonts (Saira default, plus Inter, Roboto, Oswald, Barlow, Nunito Sans, Raleway, Exo 2, Titillium Web, DM Sans); live preview in admin; applied via CSS variable across all widgets
* Fix: Start game button now posts to correct AJAX action lgw_mc_frontend_score

= 7.3.41 =
* Fix: Start game button was posting to non-existent action lgw_mc_frontend_save instead of lgw_mc_frontend_score

= 7.3.40 =
* Feature: Ends counter added to admin and frontend — +/- buttons with current/max display (e.g. End 7/21); only shown for ends-mode disciplines (not target-score)
* Feature: End indicator shown in discipline label row when game is in progress (e.g. End 7/21); amber pill badge visible without unlocking
* Feature: Start game button on not-started rows when score entry unlocked — enter player names and click Start to begin; replaced with full score entry form
* Feature: ends_played stored per game and included in all AJAX responses
* Fix: Frontend status select now includes Not started as first option; saving with Not started re-collapses the row

= 7.3.38 =
* Fix: Fixture-level status badge now correctly reads both saved status field AND presence of shots — games saved before status tracking existed (or with status still not_started) are no longer miscounted, resolving admin showing Not started while webpage showed In progress
* Feature: Manual expand/collapse toggle button (▲/▼) added to each fixture card — collapses games grid independently of auto-expand logic
* Feature: Not-started games collapse to compact label-only row; fixture card collapses entirely for not-started fixtures; expands automatically when any game is in progress or complete
* Carries forward: bonus points on shots, symmetric score layout, drag-handle-as-bar, score entry for fresh fixtures

= 7.3.33 =
* Fix: Frontend tab switching not working — tab nav elements changed from <button> to <div role="button"> to avoid form-submit interference and WordPress button resets; JS wrapped in DOMContentLoaded guard; keyboard navigation (Enter/Space) added

= 7.3.33 =
* Feature: Clear scores buttons added to Multi-Discipline Championship scores tab — each game card has a "Clear" button (clears that game only); each fixture accordion has a "Clear all" button (clears all games in the pairing); scorecard links preserved on clear
* Feature: Shortcode reference displayed on championship edit page once an ID exists — copy-ready code snippet with usage note

= 7.3.33 =
* Fix: Player name fields in the scores tab are now clearly visible — moved out of the shots column into a dedicated two-column Players row below each game's score grid, with explicit labelled inputs for each side

= 7.3.33 =
* Fix: Multi-Discipline Championship scores tab layout replaced — wide 9-column table swapped for a card-per-game layout with a two-column home/away grid; shots inputs, player name fields, pts display, save button, and scorecard link all sit cleanly within the admin panel width

= 7.3.33 =
* Fix: Multi-Discipline Championship admin JS (discipline builder, fixture builder, auto-draw, score save, scorecard create) was never loading on wp-admin pages — lgw-multichamp.js and lgw-multichamp.css were only enqueued on the frontend; admin_enqueue_scripts hook added, scoped to the lgw-multichamp page

= 7.3.17 =
* Feature: Multi-Discipline Championship scorecard integration — "+  Full scorecard" button in the Scores tab creates a scorecard CPT entry (context=multichamp, lgw_multichamp_game_id meta); edit screen shows a green info banner; scorecard list shows context badges
* Feature: Scorecard list context badges (Cup / Multi-champ / Champ); division-unresolved warning suppressed for non-league scorecards

= 7.3.16 =
* Fix: Multi-Discipline Championship disciplines now support a Scoring mode field — "Ends" (play N ends) or "Target score" (first to N shots); Singles defaults to Target score; frontend fixture cards show the mode and value (e.g. "First to 21 shots") alongside results
* Fix: Time limit field added per discipline — optional free text (e.g. "75 mins"), displayed in fixture card game rows; scoring mode label dynamically updates in admin when mode select changes

= 7.3.33 =
* Feature: Multi-Discipline Championship scorecard integration — "+ Full scorecard" button in the Scores tab creates a scorecard CPT entry (context=multichamp, lgw_multichamp_game_id meta) and opens the edit screen directly; edit screen shows a green info banner linking back to the championship scores tab
* Feature: Scorecard list now shows context badges (Cup, Multi-champ, Champ) beside the scorecard title, plus a discipline/game ref for multichamp entries; division-unresolved warning suppressed for non-league scorecards

= 7.3.33 =
* Feature: Multi-Discipline Championship — new [lgw_multichamp] shortcode; admin pages for setup, fixtures, and score entry; overall and per-discipline standings tables; fixture result cards; integrates with existing scorecard CPT and appearances tracking

= 7.3.14 =
* Fix: Time pill (e.g. 5:30) now centres correctly over the fixture columns on widescreen — grid-column changed from 1/-1 to 1/6 to exclude the notes column

= 7.3.13 =
* Fix: Save Changes button on the scorecard post edit screen (post.php?post=X&action=edit) now correctly saves — the AJAX handler was missing from lgw-admin.js which is the only LGW script loaded on that screen; the inline handler in the scorecards admin page was not available there
* Fix: Edit form styles (fields, grid, message feedback, audit log) now load correctly on the post edit screen via lgw-admin.css — previously they were only in an inline style block on the scorecards admin page
* New: Quick Score Entry — date jump filter; select a specific fixture date to focus the table on that date's matches only
* New: Submitted Scorecards — division filter dropdown to narrow the list by division; status filter dropdown (Pending / Confirmed / Disputed / Admin resolved) for quick triage
* Feature: On widescreen, postponed pill splits into two stacked pills in the notes column — red Postponed pill and blue Rescheduled pill separately; mobile keeps the single combined pill

= 7.3.10 =
* Fix: Postponed pill moved into the notes column on widescreen (was awkwardly left-aligned as a full-width row); notes column header added to the date bar; Notes label hidden on mobile

= 7.3.9 =
* Fix: Postponed pill not appearing on widescreen after notes column change — was using the fx-pills class which is hidden on widescreen; now uses a dedicated fx-postponed-row class that is always visible

= 7.3.8 =
* Feature: On widescreen, fixture row pills (played date, scorecard status) move into a notes column to the right of each row instead of adding row height; postponed pill stays full-width spanning on all screen sizes; on mobile pills revert to the previous full-width behaviour

= 7.3.7 =
* Fix: Main widget tab (League Table / Fixtures & Results) now persists on page refresh via sessionStorage — page no longer reverts to the League Table tab

= 7.3.6 =
* Fix: Fixtures & Results filter tab (All / Upcoming / Results) now persists across page loads using sessionStorage, keyed per division — navigating away and back stays on the active tab

= 7.3.5 =
* Fix: Played date pill no longer appears when the scorecard date and fixture date are the same calendar day but in different formats (e.g. 25/4/2026 vs Sat 25-Apr-2026); date comparison now normalises both strings to a timestamp before comparing

= 7.3.4 =
* Feature: Postponed fixtures — admin can mark any unplayed fixture as postponed via the fixture modal, with an optional rescheduled date; a red pill appears on the fixture row; non-admins see a notice when clicking the fixture; no spreadsheet changes are made
* Fix: Fixture date pill and scorecard status pill CSS re-applied (lost in working copy refresh)

= 7.3.3 =
* Feature: Postponed fixtures — admin can mark any unplayed fixture as postponed via the fixture modal, with an optional rescheduled date; a red pill appears on the fixture row; non-admins see a notice when clicking the fixture; no spreadsheet changes are made
* Fix: Fixture date pill and scorecard status pill CSS re-applied (lost in working copy refresh)

= 7.3.2 =
* Feature: Fixture rows now show a blue pill with the date the game was played when it differs from the scheduled date (replaces the previous italic text annotation)
* Feature: Fixture rows now show a scorecard submission status pill — 📋 Pending (amber), ✅ Confirmed (green), or ⚠️ Disputed (orange) — whenever a scorecard has been submitted for that fixture

= 7.3.1 =
* Feature: Clipboard paste support for photo scorecard submission — on mobile a "📋 Paste from clipboard" button appears in the photo upload area; on desktop Ctrl+V / paste works anywhere on the form. Allows WhatsApp photos to be submitted by copying in WhatsApp and pasting directly, without saving to the gallery first.

= 7.3.0 =
* Fix: Mobile scorecard photo submission — file picker now shows a camera / gallery choice popup on touch devices instead of silently failing; removed `capture="environment"` attribute from the modal photo input which was locking mobile browsers to camera-only with no way to switch to gallery or files.
* Championships: Final Stage now carries section qualifiers through directly instead of re-shuffling into a new random draw. Qualifiers are cross-paired by section (4-section: A vs D, B vs C; 2-section: A winner vs B runner-up, B winner vs A runner-up; 1-section: semi-finalists in bracket order) so players from the same section cannot meet until the Final.
* Championships: Added 'Rebuild Final Stage from Sections' admin button — visible when all qualifiers are known and a Final Stage draw already exists. Replaces the old draw with the carry-over layout without requiring a full reset.

= 7.2.15 =
* New: Finals Week tab in lgw_gchamp widget — appears as soon as any day's KO is complete.
* New: Finals matches auto-built from ko_qualifiers (4 qualifiers → SF+Final; 2 → Final; other → auto-paired).
* New: Finals match cards reuse lgw-finals.css/js rendering — date/time/rink setting, end-by-end live scoring, final score entry.
* New: lgw_gchamp_finals_save_datetime/save_end/save_score AJAX handlers store finals data on lgw_gchamp_* options.
* New: lgw-finals.js patched to route isGchamp matches to new AJAX actions.

= 7.2.15 =
* New: Tab underline colour picker in admin — preset swatches (Gold, Red, White, Bright Green, Sky Blue, Amber) plus custom. Defaults to gold to match PGL badge.
* New: Group score saves on an already-complete day now reseed the KO bracket automatically (if no KO scores exist), so fixing a group result updates the draw.
* New: Group score saves are blocked server-side if the day's KO bracket has any results entered, with a clear error message.
* Fix: Reload now triggers correctly whenever KO bracket is newly seeded or reseeded after a group edit.

= 7.2.15 =
* Fix: Dark mode media query removed entirely — widget always renders in light theme. Added color-scheme:light to prevent browser dark mode override.
* Fix: KO score entry "Missing parameters" error — context check now happens before group_id validation so KO saves (which send group_id=-1) are accepted.
* Improved: Colour scheme admin now shows preset swatches (PGL Red, Navy, Green, Orange, Purple, Blue, Charcoal) plus a custom colour picker.
* Style: Group card headers now use the accent colour (same as main header) rather than fixed navy, so colour scheme affects all headers consistently.

= 7.2.15 =
* Fix: KO bracket now appears for days that were already complete — page load auto-seeds via new lgw_gchamp_seed_day_ko AJAX endpoint (admin only).
* New: Per-competition accent colour picker in admin edit page — overrides the header and interactive element colour on the front end.
* New: Group lock/unlock button (admin only, shown on completed days) — locked groups disable score entry. Cannot unlock if KO bracket has scores.
* Fix: Score entry now available on completed days for admin when group is unlocked.
* Fix: All widget backgrounds are now white/near-white (#f4f5f9 for structural areas).

= 7.2.15 =
* Fix: Score auto-refresh was firing on every save (not just on state change). Now only reloads when the day first completes or KO bracket is newly seeded.
* Fix: Group cards now use a CSS grid (repeat(2, 1fr)) enforcing a 2x2 layout on wider screens rather than allowing 3 side-by-side.
* Fix: All internal backgrounds changed to white (#ffffff) or near-white (#f4f5f9). No more grey panels.

= 7.2.15 =
* Fix: Standings table now updates live after each score save (was ignored).
* Fix: Page reload now also triggers when day_complete fires (not only on first KO seed).
* Fix: Groups grid is now 2-column CSS grid — maintains 2x2 format on widescreen.
* Fix: Background lightened — groups grid now uses white bg, bg-alt adjusted.

= 7.2.15 =
* Fix: KO bracket now seeds correctly for days that were already complete before v7.2.9 (removed was_complete guard).
* Fix: Fixture team names now truncate with ellipsis instead of overflowing into the score column.
* Fix: .lgw-gchamp-wrap now has display:block ensuring full-width layout in all themes.
* Style: Primary colour changed to red (#c0202a) to match PGL association badge — header, accent elements, pts column.

= 7.2.15 =
* New: Per-day knockout bracket — each day now has its own KO bracket, seeded automatically when the day's group fixtures are complete.
* New: Top-level day tabs (Day 1 | Day 2 | …) with sub-tabs (Groups | Knockout) per day.
* New: KO bracket score entry — same inline save/clear as group scores, advances winner to next round on save.
* New: Finals Week qualifier rule — 1 day: semi-finalists (4), 2 days: finalists (2 per day), 4 days: winner (1 per day). Always 4 total Finals Week qualifiers.
* New: Finals Week qualifiers strip shown at bottom of each day's KO pane when ko_complete.
* New: Lighter, cleaner CSS theme — off-white backgrounds, blue accent, gold Finals Week strip.
* Fix: lgw-gchamp-wrap now has width:100% to stretch full screen width.

= 7.2.15 =
* Fix: Score entry "Missing parameters" error — day_id was not being sent in the JS AJAX request.
* Fix: Group cards no longer capped at 420px — they now stretch to fill available screen width.
* Fix: Entry names in standings tables no longer truncated at 160px on wider screens.
* Fix: Fixture team names no longer truncated at 120px on wider screens.
* New: Day section header and qualifiers strip CSS added (was missing from v7.2.7).

= 7.2.15 =
* Revised: Days-as-sections data model — each competition day is now an independent section with its own groups, qualification rules, and qualifier output.
* New: Admin Days table with per-day: name, date, target group size, winners/group, best runners-up, auto-calculated group count and qualifier total.
* New: Draw algorithm distributes entries across days (date preferences respected), then within each day calculates num_groups = ceil(entries/target_group_size) and distributes into groups.
* New: Per-day qualification — each day computes its own qualifiers once all fixtures scored; group_stage_complete when all days done.
* New: Qualifiers strip shown on front end per day when day_complete.
* Removed: Flat groups_config / num_groups model replaced entirely.
* Changed: has_ko_bracket is now optional (defaults off for clubs using external Finals Week).

= 7.2.15 =
* Fix: Literal newlines inside JS confirm() strings in the Run Draw admin script caused SyntaxError: Invalid or unexpected token, preventing the draw button from working.

= 7.2.15 =
* New: Qualification logic — top N per group (automatic) + best R runners-up selected by pts/diff/sf with random tie-break and admin notice.
* New: lgw_gchamp_build_knockout() — builds KO bracket using lgw_draw_build_bracket() with same-club separation and bye auto-fill.
* New: lgw_gchamp_seed_knockout AJAX endpoint — admin-triggered, requires group_stage_complete.
* New: "Seed Knockout Bracket" admin button on edit page with qualifier summary and re-seed option.
* New: Knockout pane now renders full bracket using lgw-champ-wrap / lgw-champ.js. Phase tab auto-switches to KO when bracket is seeded.
* New: lgw-champ.js loaded as dependency of lgw-gchamp.js for bracket rendering.

= 7.2.15 =
* New: Front-end inline score entry on group fixture rows (editor/admin role). + Score / edit / clear controls per fixture. Enter key saves.
* New: Admin Group Scores panel on edit page — full table of all fixtures per group with inline save/clear and Enter key support.
* New: lgw_gchamp_save_score AJAX handler — validates scores, saves to WP option, returns updated standings.
* New: lgw_gchamp_get_standings AJAX endpoint — returns all group standings + fixture scores (polling base for Step 8).
* New: lgw_gchamp_all_fixtures_played() — sets group_stage_complete flag when last fixture is scored.

= 7.2.15 =
* New: [lgw_gchamp] shortcode now renders full front-end group stage view: all groups side by side with standings tables, fixtures lists, and qualification highlights.
* New: lgw-gchamp.css — full CSS for group cards, standings, fixtures, phase tabs, qualification row highlights, dark-mode support, and responsive layout.
* New: lgw-gchamp.js — phase tab switching, fixture collapse/expand, club badge injection from lgwData.clubBadges.
* New: lgw_gchamp_compute_standings() — points/diff/h2h sort. lgw_gchamp_short_name() for compact display.
* New: lgw_gchamp_enqueue() — correctly chains lgw-saira → lgw-widget → lgw-champ → lgw-gchamp assets.

= 7.2.15 =
* New: Group draw algorithm — allocates entries to groups respecting date preferences (3-pass), 50% club cap (soft constraint), and even distribution across groups.
* New: Round-robin fixture generation using standard rotation algorithm. Odd-sized groups receive a silent BYE. Games-per-opponent setting reverses home/away on even repetitions.
* New: Run Draw button on admin edit page — instant reveal with per-group entry list and fixture count. Re-draw requires confirmation if scores exist.
* New: Draw warnings surfaced on admin page (oversubscription, club cap violations, missing dates).

= 7.2.15 =
* New: Entry date preference field on group championship admin page.
* New: Bulk-set and clear-all controls for managing preferences across all entries.
* Preferences stored as entry_preferences keyed by entry string; preserved across draw resets.


= 7.2.15 =
* New: Group Stage into Knockout championship type added (Step 1 — data model, admin config panel, group naming, qualification settings, bracket size suggestion).
* New: [lgw_gchamp id="..."] shortcode registered (full front-end display coming in v7.2.15).
* New: Group championships listed alongside knockout championships on the Championships admin page with "Group + KO" format badge.

= 7.2.15 =
* Admin scorecard edit: renamed "Date" label to "Date Played"; widened date input so full date is always visible.

= 7.2.15 =
* Fix: Scorecard post edit screen (post.php?post=X&action=edit) now shows the full scorecard editor and audit log as meta boxes — previously it showed a blank WordPress post form with no scorecard data visible

= 7.2.15 =
* Fix: Championship player stats — entering a score in a later round no longer overwrites/deletes the player's earlier round appearance records; the pre-insert delete in lgw_log_champ_appearance is now scoped to the specific match position (match_key) rather than wiping all champ rows for that player across the entire championship

= 7.1.136 =
* New: Club Summary table — sortable columns (click any header to sort asc/desc, with direction indicator)
* New: Club Summary table — per-column filter inputs: text search on Club, numeric min/max range on all stat columns
* New: Club Summary table — live totals bar above the table updates dynamically as filters are applied, showing visible-row sums for Players, Apps, Ladies, Paid, and Balance; tfoot row also updates to match filtered rows
* Fix: Paid input changes in Club Summary now immediately update the Balance cell and totals without a page reload

= 7.1.135 =
* New: Player stats popover games list now shows competition (division name or championship title) instead of rink number
* New: Team chips in the stats popover are now clickable — tap a team to filter the games list to that team; tap "All" to reset
* New: lgw_get_player_stats AJAX response now includes competition field on each game record

= 7.1.132 =
* Fix: championship appearance delete now correctly wipes all rows for player+champ_id — resolves duplicate appearances on re-save and failed clears caused by match_key format inconsistencies across earlier versions
* Removed all temporary debug logging

= 7.1.126 =
* Fix: championship appearance delete now clears both new (match_key) and legacy (match_key IS NULL) rows — prevents duplicates on re-save for existing data
* lgw_clear_champ_appearances_by_key() now accepts optional match_title to wipe legacy rows in the same query
* lgw_log_champ_appearance() delete condition covers both key and title in one query

= 7.1.125 =
* Fix: championship appearances now use a stable positional key (section:round:match) for delete — prevents duplicates on re-save and ensures clear actually removes the row
* New match_key column added to appearances table (auto-migrated)
* lgw_clear_champ_appearances_by_key() added; lgw_champ_cascade_clear_appearances() updated to use positional keys
* lgw_log_champ_appearance() stores match_key and deletes by it when available

= 7.1.124 =
* Championship appearance dates normalised to dd/mm/yyyy regardless of admin input format
* Added lgw_normalise_date_dmy() helper handling dd/mm/yyyy, d/m/yy, yyyy-mm-dd, and natural language dates

= 7.1.123 =
* Player Tracker: "Division" column renamed to "Competition" throughout
* Championship appearances now show the championship title in the Competition column
* History modal stats summary now includes a Championships row alongside League/Cup
* CHAMP pill added to match title column for championship appearances

= 7.1.122 =
* Championship stats tracking: new `Stats Eligible` flag on championship admin enables W/L logging to Player Tracking
* Player stats popover now has a tab switcher — League/Cup | Championships | Total — when data exists across multiple types
* Championship bracket entries are now clickable player-name links that open the stats popover (when stats eligible)
* `lgw-scorecard.js` popover now resolves nonce/ajaxUrl from `lgwChampData` so it works on champ-only pages
* DB: `champ_id` column added to appearances table for championship appearance attribution
* `lgw_log_champ_appearance()` and `lgw_clear_champ_appearances()` helpers added to lgw-players.php
* `lgw_ajax_get_player_stats` returns `stats_by_type` breakdown (league/cup/champ/total)

= 7.1.121 =
* Fix: Copy as Text — away fixture scores now shown in display order (matched player score first) instead of always home–away order

= 7.1.120 =
* Fix: Section and Round columns now correctly hidden on Chromium mobile browsers — explicit th/td element selectors with !important override Chromium table layout defaults

= 7.1.119 =
* New: Admin search rows are clickable — clicking a result closes the modal, switches to the correct section tab, scrolls the bracket to the match, and flashes a gold highlight on the card
* New: Admin sees a "Click a row to go to that match in the draw" hint beneath search results
* Fix: section_idx now returned by search AJAX so JS can target the correct section pane
* Fix: game_num stored on match card dataset during bracket render to support lookup

= 7.1.118 =
* Fix: Search modal in landscape orientation — entire modal is now a single scroll container; header and export bar remain visible via sticky positioning so screen space is not wasted

= 7.1.117 =
* Fix: Search result table now shows matches as "A vs B" inline; wraps vertically only when screen space requires it
* Fix: Section and Round column headers are now also hidden on mobile (previously only the cells were hidden)

= 7.1.116 =
* Fix: Mobile search — input box resized and constrained to screen width; iOS zoom prevented
* Fix: Mobile search — Section and Round columns hidden in results table to eliminate horizontal overflow
* Fix: Mobile search action buttons wrap neatly at small widths

= 7.1.115 =
* Improved: Championship search results now split into Home Fixtures and Away Fixtures groups, each sorted by date
* Improved: Matched entry is highlighted in yellow; date acts as a row divider within each group
* New: Copy as Text button — copies fixtures/results to clipboard in plain text format suitable for pasting into social media or messaging
* New: Export PDF button — opens a print-ready popup with sponsor banner included; save as PDF from browser print dialog
* Changed: Export CSV now includes a H/A column indicating whether the matched entry is home or away

= 7.1.114 =
* New: Championship search modal — search fixtures or results by player name or club across all sections and the Final Stage
* New: Search results highlight the matched entry, group by section, and sort by date
* New: Fixtures mode shows upcoming/undated matches; Results mode shows scored matches; future-dated matches with results appear in both
* New: Print and CSV export for search results
* New: 🔍 Search tab button in championship section header

= 7.1.113 =
* Fix: Scorecard modal stuck on "Loading scorecard..." — lgwFetchScorecard referenced opts.context which is undefined in that function scope, throwing a ReferenceError and preventing the AJAX request from firing; removed the stray reference

= 7.1.112 =
* Fix: Player stats popup now correctly finds players with apostrophes in their names (e.g. K O Neill) — WordPress magic-quotes were stripping apostrophes before the DB lookup; fixed by applying wp_unslash() before sanitize_text_field() in all relevant POST handlers
* Fix: Player name passed to stats lookup now stripped of trailing asterisk (female marker) via lgw_clean_player_name(), preventing lookup failures for female-flagged players

= 7.1.111 =
* New: Players admin screen — Club filter dropdown (defaults to All Clubs), Team filter dropdown (cascading — options narrow based on other active filters), and Name search field with live client-side filtering; player count and Clear filters button included

= 7.1.109 =
* New: Player stats popover now includes full games list for the current season — match title, date, rink, score, and W/D/L badge per game, ordered newest first
* Fix: Popover uses fixed positioning with viewport-aware placement (flips above button if insufficient space below); scrollable inner body with dynamic max-height prevents overflow off-screen

= 7.1.108 =
* New: Player name links in scorecard modal — clicking any player name opens a stats popover showing their current-season W/D/L record, games played, and which teams they have appeared for
* New: Public AJAX endpoint lgw_get_player_stats returns current-season stats keyed by player name and club (no auth required, nonce-protected)
* CSS: Player stats popover with club badge, W/D/L colour tiles, teams-this-season chips; dark mode support

= 7.1.107 =
* Fix: Division name no longer blank in scorecard modal after shortcode title change — widget now reads divisionTitle from data-division attribute instead of previousElementSibling (which was broken by the ticker element being inserted between the title and the widget)

= 7.1.106 =
* New: CSV reference row detection — parser now reads 'homepts','home','home shots','away shots','away','awaypts','time' labels to map columns directly; 'time' column read at explicit index with no scanning
* Fix: Legacy fallback scan (no reference row) breaks on first match found and uses 0.333–0.938 serial range guard

= 7.1.103 =
* Fix: Results ticker now shows only scores for the current division (filtered by division name) and only for the current season — ticker hidden if no matching results
* Fix: Results ticker positioned inside the widget wrap, below the sponsor banner, full-width and inline with the rest of the widget
* Fix: Removed redundant division label from ticker items (already division-scoped)
* New: Added data-division attribute to lgw-w element so JS can match results to the correct division


= 7.1.101 =
* Fix: Scorecards admin season backfill now correctly reassigns cards that are tagged to the wrong season (not just untagged ones) — banner shows on all seasons with date ranges, counts scorecards whose match date falls in the season but are tagged differently, and "Reassign to this season" button retags all of them via the existing date-range strategy


= 7.1.100 =
* Fix: Scorecards admin season filter no longer shows previous-season cards in the active season view — removed NOT EXISTS fallback from the main query (untagged cards from any season were bleeding in); untagged count now comes from a separate dedicated query; warning banner still appears prompting backfill


= 7.1.99 =
* New: Scorecards admin page now splits by season — season switcher bar defaults to the active season; archived seasons accessible via buttons; list filtered by lgw_sc_season meta (active season also shows untagged cards); untagged card warning banner with one-click "Tag all to this season" backfill button; new lgw_backfill_sc_seasons AJAX handler uses dual-strategy (tag + date-range fallback)


= 7.1.98 =
* New: Player tracking auto-merges dotted-initial name variants (e.g. "D. Bintley" == "D Bintley") — lgw_normalise_player_name() strips dots from single-letter initials before DB lookup so new scorecards never create duplicates; Merge Duplicates tab now shows a preview table of detected pairs with a one-click "Auto-merge" button; keep rule: most appearances wins, ties prefer the non-dotted (normalised) form


= 7.1.97 =
* Fix: "Skip Google writeback" checkbox now also suppresses Google Drive PDF upload (not just Sheets); uses a short-lived post meta flag so Drive's anonymous action hooks are correctly bypassed; checkbox label updated to "Skip Google Drive & Sheets writeback"


= 7.1.96 =
* Improvement: Excel/xlsx parse errors now return actionable diagnostic messages instead of generic "Could not read" — ZipArchive error codes, missing worksheet entries, empty grid details (sheet name, KB size, shared string count), and rink-mapping failures now include row samples and field detection summary

= 7.1.95 =
* New: Skip Google Sheets writeback option — admin scorecard form now includes a "Skip Google Sheets writeback" checkbox (visible to admins only); use when backfilling historical scorecards to avoid overwriting the live sheet


= 7.1.95 =
* New: Skip Google Sheets writeback option — admin scorecard form now includes a "Skip Google Sheets writeback" checkbox (visible to admins only); use when backfilling historical scorecards to avoid overwriting the live sheet


= 7.1.94 =
* Feature: Player history modal — each appearance row now shows the scorecard ID as a direct link to the WP admin edit screen (opens in new tab), making it easy to inspect, edit or trash test/duplicate scorecards

= 7.1.93 =
* Fix: Backfill missed scorecards tagged to a different/wrong season ID — Strategy 2 now scans ALL scorecards by match date against the season date range, not just untagged ones; tagged-to-wrong-season records now correctly included

= 7.1.92 =
* Fix: Backfill not picking up scorecards for previous seasons — query relied solely on lgw_sc_season meta which was never stamped on older records; backfill now also matches untagged scorecards by date range against the season's start/end dates

= 7.1.91 =
* Fix: Player stats not recorded when re-saving a scorecard — rink scores were stored as 0.0 (not null) when empty, causing false 0–0 draws; now stored as null when the field is blank
* Fix: lgw_log_appearances() zero-guard added — legacy scorecards where all rink scores are 0 (floatval artifact) treated as score-absent; real 0-scores honoured when match totals are non-zero
* Fix: lgw_sc_context (league/cup) now preserved on admin edits; missing context explicitly defaulted to league

= 7.1.90 =
* Feature: Player statistics — Wins, Draws, Losses, Shots For and Shots Against now tracked per appearance (rink level) for both League and Cup games
* Feature: Player stats shown in admin player list table (total W/D/L, SF–SA, League W/D/L, Cup W/D/L columns per player)
* Feature: Player history modal upgraded — stats summary table at top with Total/League/Cup breakdown; per-game rink score, W/D/L result badge, and Cup label badge on each row
* Feature: Excel export gains a new Stats sheet with full per-player stats breakdown; per-club matrix sheets gain W/D/L/SF/SA columns
* Improvement: DB migration auto-adds shots_for, shots_against, result, game_type columns to existing installations; game_type back-filled from scorecard context meta
* Improvement: lgw_log_appearances() now reads rink-level scores and sc_context meta to store stats atomically with each appearance

= 7.1.87 =
* Fix: Fixture time note (e.g. 5:30) now correctly displayed for all divisions; scan range extended past APts column and HH:MM:SS format normalised to HH:MM

= 7.1.86 =
* Fix: Player tracking — female status from confirmed scorecards (asterisk-marked players) now correctly saved to player record; lgw_ensure_female_flag() upgrades false→true only, never resets manual edits
* Fix: Player tracking — toggling the female checkbox no longer incorrectly sets the starred flag; update_flags handler now reads actual field values instead of using isset()
* Feature: Player tracking — new Club Summary tab showing per-club player count, appearances, ladies count, and admin-editable Players Paid field with balance column; exportable as spreadsheet (XLS) or print-ready PDF

= 7.1.84 =
* Feature: Championship — Rename Entry tool on the edit page lets you correct spelling mistakes in entries after a draw has been done, without resetting the draw or any scores

= 7.1.82 =
* Fix: Live points hint in scorecard modal used parseInt — half-point values (e.g. 2.5+4.5) showed total as 6 instead of 7; fixed to parseFloat with tolerance comparison

= 7.1.81 =
* Fix: Rink score inputs (modal and standalone form) now have step="0.5" so browsers accept half-scores without rounding
* Fix: Auto-sum of rink scores rounds to 1 decimal to prevent float accumulation noise
* Fix: Scorecard admin page stripped half-points — all scores, totals and points now use floatval; admin number inputs gain step="0.5"
* Fix: Points validation uses parseFloat and tolerance comparison throughout

= 7.1.80 =
* Fix: Drive upload now respects submitted_for — PDF saved to that team's folder only when submitting for one team
* Fix: Resubmitting a scorecard replaces existing PDF in Drive rather than creating a duplicate; admin edits still produce versioned copies

= 7.1.78 =
* Feature: Cup and Championship bracket draws on mobile now support horizontal swipe scrolling — all rounds are visible side-by-side with scroll-snap for clean swiping between them
* Feature: Tapping a round header in the bracket scrolls forward to the next round (wraps to first), and the tab bar stays in sync as you swipe via IntersectionObserver

= 7.1.77 =
* Feature: Championship bracket draws now show potential opponents in TBD slots — displays the last player's surname and abbreviated club name (e.g. "Hinds, Sha/Maxwell, Nor") matching the cup bracket style

= 7.1.76 =
* Feature: Championship draws now enforce strict same-club separation using a multi-pass algorithm — players from the same club are guaranteed not to be drawn against each other in the first round wherever mathematically possible (graceful fallback only when all entries are from a single club)
* Feature: Admin draw editor — after a championship section is drawn, an "✏️ Edit Draw" button appears on the admin edit page; clicking it reveals a bracket table where any first-round match participant can be swapped via dropdown; saving an edit clears that match's score and cascades resets through all downstream rounds, and unseeds the Final Stage if applicable so it can be redrawn once corrected results are entered

= 7.1.75 =
* Fix: scorecard photo camera option on Chromium browsers (Chrome, Brave etc) now uses the browser's native camera API (getUserMedia) instead of a capture="environment" file input — which Chromium locks to camera-only with no way to switch to gallery/files; both options now work correctly across all browsers

= 7.1.73 =
* Scorecard photo upload on mobile now prompts the user to choose between "📷 Take a photo" (camera) or "🖼️ Choose from gallery / files" instead of immediately launching the camera — desktop behaviour (file picker) unchanged

= 7.1.72 =
* Settings: Merged "Clubs & Passphrases" and "Club Badges" into a single "Clubs & Badges" table — passphrase and badge fields now on one row per club

= 7.1.70 =
* Feature: Archived seasons now support start/end date fields — set via the Seasons admin edit form or when adding a historical season
* Feature: Each archived season row in Seasons admin now has a "👥 Players" link (opens Player Tracking filtered to that season) and a "🔄 Backfill Players" button (re-runs appearance logging for all confirmed scorecards tagged to that season)
* Feature: Player Tracking admin now accepts a ?season=ID URL param — loads that season's date range for all appearance counts, the export, and the Season Settings tab summary
* Feature: Season switcher bar added above the tabs in Player Tracking — pill buttons for every season; active season marked with ●
* Feature: Page title reflects the archived season being viewed (e.g. "Player Tracking — 2025 Season")
* Feature: Export to Excel respects the currently viewed season and passes the season ID through so the downloaded file matches what is on screen

= 7.1.69 =
* Feature: Season start/end dates moved from Player Tracking admin to Seasons admin — one place to manage season label, dates, and divisions
* Change: lgw_get_season() in lgw-players.php now reads label/start/end from the active season in lgw_seasons; falls back to legacy lgw_season option for existing installs
* Change: Player Tracking "Season Settings" tab replaced with a read-only summary and a link to Seasons admin

= 7.1.68 =
* Fix: Sheets writeback now finds the fixture row even when the match was played on a different date to scheduled — tries the fixture date first, then falls back to team-name-only search
* Fix: Same date-fallback applied to the override sync so both the spreadsheet write and the widget override use the correct row

= 7.1.68 =
* Fix: Override key now uses the fixture date read directly from the published CSV (by finding the home/away team pair row), not the played date stored on the scorecard — fixes cases where a match was played on a different date to scheduled (e.g. 12/05 played instead of 09/05 fixture)
* Fix: lgw_sync_get_fixture_date_from_csv() helper added — fetches the division CSV and returns the exact date string the widget will use as a key for that fixture row
* Fix: Confirmed scorecards now update the widget immediately — lgw_sync_override_from_scorecard() was silently bailing when the division had no csv_url in sheets_tabs; now falls back to the active season division config
* Fix: Override key now uses the fixture date (lgw_fixture_date post meta) rather than the played date, so it correctly matches the CSV fixture row even when a match was played on a different date to scheduled
* Fix: lgw_sync_override_from_scorecard() now logs success and failure to the per-scorecard sheets log, visible in the History panel
* Feature: "Force sync widget override" button added to the Sheets Writeback Log on every scorecard's History panel — allows admin to manually re-push any confirmed scorecard's score to the override table without re-saving
* Fix: Deleting or trashing a scorecard now removes all associated player appearance records and prunes orphaned player entries
* Fix: Player re-save (same club resubmitting a previously confirmed scorecard) now fires the sheets writeback action so Google Sheets is updated correctly
* Feature: Player names on the Player Tracking page are now clickable — opens a modal showing every game the player appeared in, with date, match, division, rink, team, score, and scorecard status
* Fix: lgw_sheets_find_row now normalises dates (strips leading zeros, lowercases) and trims/lowercases team names before comparing — fixes "row not found" caused by "05-Apr" vs "5-Apr" day padding or whitespace differences
* Fix: lgw_sheets_format_date now omits the leading zero from the day number to match the typical sheet format ("Sat 5-Apr-2025" not "Sat 05-Apr-2025")
* Fix: OAuth redirect URI was hardcoded to lgw-league-setup; introduced LGW_SETUP_PAGE constant so the redirect URI is always self-consistent and matches what Google Cloud Console expects
* Fix: Google auth token scope now includes spreadsheets — OAuth and service account JWTs were only requesting drive scope, causing auth_failed on all Sheets writeback and score override writes


= 7.1.52 =
* Fix: season switcher now matches archived divisions to the shortcode title even when the title includes a trailing year (e.g. "Division 1 2026" matches archived "Division 1" or "Division 1 2025") — year suffix is stripped from both sides before comparison
* Fix: Seasons admin — editing an existing archived season no longer triggers "season already exists" error; Edit form now correctly updates in place

= 7.1.50 =
* Cup admin: added "Download Draw (.xlsx)" export button on cup edit page — downloads the full bracket as an Excel spreadsheet matching the reference cup draw format (draw number, round columns, dates)
* Championship admin: added "Download Draw (.xlsx)" export button on championship edit page — downloads all drawn sections as separate sheets, plus a Final Stage sheet if drawn, matching the reference championship draw format
* New module: lgw-export.php handles all xlsx generation in pure PHP (ZipArchive), no server-side dependencies required

= 7.1.48 =
* Scorecard modal: Date Played field now displays in the same format as the fixture date (e.g. "Sat 9-May-2026") after blur, making it easier to confirm the correct day was entered
* Date is normalised back to dd/mm/yyyy internally on save so storage format remains consistent
* Code cleanup: consolidated duplicate lgwClubMatchesTeamStr into lgwClubMatchesTeam (null guard added); removed redundant typeof normaliseDate defensive check in populateModalForm

= 7.1.46 =
* Fix: points auto-suggest now updates correctly after every rink score change, not just the first — programmatic input events no longer incorrectly cleared the auto-fill flag
* Fix: same isTrusted guard applied to totals auto-sum to prevent similar edge cases
* Scorecard modal: Date Played field now normalises to dd/mm/yyyy format on blur, matching the fixture date display

= 7.1.45 =
* Scorecard submission: rink scores now auto-suggest home/away points as you type, based on configurable points-per-rink-win and overall-match-win values
* Points calculation: 1 per rink win, 3 overall win by default (0.5/1.5 for draws); totals to 7 for 4-rink, 6 for 3-rink matches
* League Setup: new Points System section to configure points-per-rink and overall-match points (live preview of max points per match)
* If user manually overrides auto-suggested points, a mismatch warning is shown but submission is not blocked
* Points auto-suggest also fires after photo AI parse and Excel import

= 7.1.44 =
* Scorecard submission: rink scores now auto-sum into the Home/Away Total Shots fields as you type
* Totals are updated silently when auto-filled; if the user manually enters a total that doesn't match the rink sum, an inline warning is shown (submission is not blocked)
* Auto-sum also fires after photo AI parse and Excel import so totals are always in sync with populated rink scores

= 7.1.43 =
* Fix: Cup scorecard modal now shows the round date (e.g. 01/05/2025) as the fixture date — passed from the bracket's dates[] array at card-click time
* Fix: Cup name (Senior Cup / Junior Cup / Midweek Cup etc.) now shown as the division label in the scorecard form instead of the generic "Cup"
* Fix: Cup scorecard modal header changed from red to navy to match the league scorecard style; modal body always renders in light mode regardless of device dark-mode setting

= 7.1.43 =
* Fix: Cup scorecard submission now fully works — login gate, submission form, and confirm/amend flow all appear correctly when clicking a cup bracket match
* Fix: Root cause was that lgw_get_scorecard matched on team names only, so a league scorecard for the same two clubs was found instead of returning "no scorecard yet"; fixed by adding a context field (league/cup) stored as lgw_sc_context post meta, passed through the full fetch/submit/amend chain
* Fix: Admin clicking a cup match now sees the submission form directly (no login gate) matching league behaviour
* Fix: Amend flow in cup now correctly skips points validation (maxPts: 0 preserved through amend path)

= 7.1.43 =
* Fix: maxPts: 0 (cup mode) was being overridden to 7 by a JS falsy fallback (0 || 7) in both lgwFetchScorecardOrSubmit and lgwOpenSubmitInModal — fixed with an explicit undefined/null check; cup scorecard login gate and submission form now appear correctly
* Fix: Admin on cup page now sees the submission form directly without a login gate, same as the league widget

= 7.1.43 =
* Fix: Cup page now loads lgw-scorecard.js (and its CSS) as a dependency — previously lgw-cup.js had no dependency on it, so lgwFetchScorecardOrSubmit and lgwOpenSubmitInModal were undefined on cup-only pages, causing the scorecard modal to always fall through to the quick-view fallback with no login gate and no submission form
* Fix: lgwSubmit (clubs list, nonce, authClub) now localised on cup pages so the login gate can populate the club dropdown and authenticate correctly
* Fix: Admin on a cup-only page now goes directly to the scorecard form without a login gate (isAdmin from lgwCupData flows correctly into lgwOpenSubmitInModal)

= 7.1.43 =
* New: Cup scorecards now fully submittable via the bracket — clicking a match with both teams opens the same modal as the league (login gate, rink scores, player names, submission and confirmation flow); points fields are hidden for cup matches (not applicable) and points validation is skipped
* Fix: Totals row in scorecard form switches to a 2-column layout when points fields are hidden

= 7.1.43 =
* Fix: Cup bracket card routing rebuilt — clicking any match with both teams known now opens the full scorecard modal (with login gate and submission) rather than the score-entry popover; the popover is now accessible via an ✏️ Score button in the modal header (admin only)
* Fix: Matches with only one team set (TBD slot) continue to open the quick score popover directly as before

= 7.1.43 =
* Fix: Club users logged in via passphrase no longer see the scorecard submission form for fixtures that don't involve their club — those fixtures now show "No scorecard submitted yet" as a read-only visitor would see
* Fix: Cup bracket full scorecard now accessible via a "Full Scorecard" button inside the score-entry popover — previously the scorecard viewer was unreachable when a draw passphrase was set (the editable card path always won the routing decision)

= 7.1.43 =
* Fix: lgw-sheets.php syntax error — invalid PHP template block inside echo-mode function (lgw_render_sheets_log) replaced with echo statements
* Fix: Scorecard submission modal — JSON.parse now guarded with try/catch so PHP notices/warnings prepended to AJAX response no longer silently kill the flow
* Fix: Login gate condition broadened from `mode === open` to exclude disabled/admin_only — future-proof and handles edge cases
* Fix: Sub-container ID collision in lgwFetchScorecardOrSubmit replaced with class selector
* Fix: Orphaned player records (misspelled names after admin corrections) now pruned after each admin scorecard save
* New: Cup bracket scorecard viewer now uses full lgwFetchScorecardOrSubmit modal (with submission + login gate) when lgw-scorecard.js is loaded; falls back to cup quick-view
* New: submissionMode and authClub added to lgwCupData so cup page has correct submission context
* New: Admin/visitor view toggle button in widget tab bar — admins can preview the widget as a regular visitor without logging out

= 7.1.27 =
* New: Season management — new 📅 Seasons admin page to manage the active season, archive past seasons, and backload historical seasons (CSV URLs per division)
* New: Season switcher on the front-end widget — add seasons="2025,2024" or seasons="all" to any [lgw_division] shortcode to show a pill bar above the tabs; clicking a past season loads that season's data read-only
* New: Scorecard season tagging — new scorecards stamped with lgw_sc_season post meta; archiving a season back-fills all untagged scorecards
* New file: lgw-seasons.php

= 7.1.31 =
* Photo and Excel parse handlers now allow WP admins without a passphrase session — previously only passphrase-authenticated club users could trigger the parse; admins using the modal form were getting a silent "Not authorised" error
* Improved auth error message: "Not authorised — please log in with your club passphrase first." shown instead of bare "Not authorised" when a non-admin, non-authenticated user attempts to parse

= 7.1.30 =
* Modal submission form now includes Photo, Excel and Manual entry tabs — same three input methods as the standalone [lgw_submit] form; photo and Excel parse results populate the pre-filled modal form
* "Submitting on behalf of" radio text made smaller (12px, no bold team names) to reduce visual weight
* Season tagging confirmed working: both normal and admin-both submission paths call lgw_get_active_season_id() and stamp lgw_sc_season on every new scorecard post; backfill fires when a season is archived

= 7.1.29 =
* Played fixtures with no scorecard now also show the submission form — clicking any played fixture checks for an existing scorecard first; if none, the submit form is offered inline (respects submission mode setting)
* New shortcode attribute max_points="7" (default) on [lgw_division] — set to 6 for the 12-player division; points validation enforces home + away = max_points
* Submission form: Date Played field added (optional, with hint text "enter only if different to fixture date"); when blank, the fixture date is used as the match date
* Submission form: Submitted by field added (submitter's name, stored on the scorecard and displayed in the public scorecard view)
* Points validation: live running total shown as you type (green when correct, red when off); save blocked if points do not sum to the division max

= 7.1.28 =
* Scorecard submission mode toggle in Settings: Disabled / Admin only / Open — lets admin test the workflow before releasing it to clubs
* Fixture modal now opens for unplayed fixtures when submission is enabled — click any upcoming fixture to submit a scorecard; form is pre-filled with division, date, home team and away team
* Admin submission now includes a "Submitting on behalf of" radio: Home team, Away team, or Both teams — selecting "Both" skips the two-party flow and immediately confirms the scorecard
* Club list exposed in modal login gate — clubs with passphrases are shown in a select dropdown when logging in from the fixture modal

= 7.1.27 =
* Finals Week: fix home end scores left-aligning in ends table — stray CSS class was overriding right-align; home scores and running totals now correctly right-align toward the centre End column

= 7.1.25 =
* Finals Week: ends table defaults to collapsed; click header to expand
* Finals Week: ends table now shows 5 columns — end score | running total | end number | running total | end score — so scores and totals are centred near the end number rather than pushed to the outer edges

= 7.1.24 =
* Finals Week: player name and club name now displayed separately in the match card — player name(s) bold on top, club name smaller and muted below; badge aligns to the full name block

= 7.1.23 =
* Finals Week: fix score display showing "undefined–undefined" after adding an end (undefined homeScore/awayScore now treated as null, showing live running total instead)
* Finals Week: rink number added to match display alongside date/time; admin can set/clear it in the date & time popover; stored as finals_rink on the match object; polled live for public viewers

= 7.1.21 =
* Finals Week: colour scheme fixed — widget now uses forced light theme like other widgets; live match state uses a subtle warm amber tint instead of dark red; dark mode only activates on explicit manual toggle
* Finals Week: Complete game button added — shown in the ends panel, pre-filled with the running total from ends entered, lets admin confirm or adjust before saving; validates that scores are not equal (no draws in bowls)
* Finals Week: final score edit button (✏️) remains accessible during live end-by-end scoring, not just after completion
* Finals Week: ends table is now collapsible (click the Ends header to toggle) and scrollable up to a fixed height, so viewers can focus on the score without scrolling through a long ends list

= 7.1.20 =
* New shortcode [lgw_finals season="2026"] — Finals Week schedule page showing all championship SF+Final matches across a season; displays date/time, team names with badges, live end-by-end scoring, and final scores
* Admin can set date/time per match via a popover (📅 button), enter end-by-end scores live (+ Add end / Remove last end), and save the final aggregate score
* Public viewers see live scores updated automatically every 30 seconds without page refresh
* 1-section competitions surface the last 2 rounds (SF + Final) of the section bracket; 2/4-section competitions use the Final Stage bracket
* Winner propagation and cascade reset work the same as the main bracket
* New files: lgw-finals.php, lgw-finals.js, lgw-finals.css

= 7.1.19 =
* New shortcode [lgw_finalists season="2025"] displays all finalists/semi-finalists across every championship in a given season on a single page — 1-section competitions show 4 semi-finalists, 2-section shows both finalists per section, 4-section shows each section winner; pending draws shown with a placeholder
* Season field added to championship admin — set on the edit page, visible in the championships list, used by [lgw_finalists] to filter by season
* Score reset now cascades through all subsequent rounds (not just one step forward) for both cup and championship brackets; resetting a section result also unsets the auto-seeded final stage so it re-seeds correctly when results are corrected
* Championship score save/reset now updates the final stage panel live in the browser without requiring a page refresh

= 7.1.18 =
* Dark/light mode fix extended to modals: fixture modal, cup score entry popover, cup/champ draw login box, and cup/champ scorecard modal all had hardcoded white backgrounds — replaced with CSS vars so they render correctly in dark mode
* Calendar widget now remembers the selected month across page refreshes, persisted per calendar instance in localStorage

= 7.1.17 =
* Calendar widget now defaults to grid (table) view instead of list view; user preference still saved on toggle
* Dark/light mode fix: league table, fixtures and widget panels now explicitly declare text and background colours so WordPress theme styles can no longer bleed in and cause text to blend into the background
* Colour customisation extended to cup and championship widgets: [lgw_cup] and [lgw_champ] shortcodes now accept color_primary, color_secondary, and color_bg attributes; site-wide theme colours from League Setup also apply
* Cup and championship CSS overhauled: all hardcoded hex colours replaced with CSS variables, making every tint — headers, round labels, draw login, score popover, scorecard modal — respond to colour overrides
* Championship header colour now follows --lgw-navy (was hardcoded teal #1a4e6e)

= 7.1.16 =
* Added calendar widget: [lgw_calendar xlsx="..."] renders a monthly event calendar from a Google Sheets xlsx export; supports list and grid views, month navigation, colour-coded event categories, and a colour legend

= 7.1.15 =
* Plugin modules now loaded with file-existence checks and try/catch — a missing or broken module file no longer brings the whole site down; admin notice shown instead
* Fixed: workflow was missing lgw-draw.php and lgw-logo.svg from release zip

= 7.1.14 =
* Test release — verified auto-update download via GitHub API asset URL

= 7.1.13 =
* Fixed: plugins_api (info popup / View Details) was still using direct download URL for download_link, causing 404 on update; now uses GitHub API asset URL to match the update checker

= 7.1.12 =
* Auto-update download fix: switched to GitHub API asset URL to avoid auth header being stripped on CDN redirect
* Auth injection filter restricted to api.github.com and github.com only
* Accept: application/octet-stream header added for asset downloads
* Test Download URL diagnostic added to Settings page

= 7.1.11 =
* Added Test Download URL diagnostic button to Settings page — tests HEAD request to release zip with and without auth, follows redirect and reports HTTP status at each step to diagnose auto-update download failures

= 7.1.10 =
* Cup bracket: TBD slots in future rounds now show abbreviated predecessor team names (e.g. "Sal A/B'mena B") instead of plain TBD
* Abbreviation format: first 3 chars of main name + suffix (A/B/etc), e.g. "Ballymena B" -> "B'mena B", "Salisbury A" -> "Sal A"
* Placeholder text styled in muted italic to distinguish from confirmed teams

= 7.1.9 =
* Fixed: GitHub release transient was caching stale release data (e.g. v7.1.1) even after newer versions were installed, preventing the auto-updater from offering updates
* Version-aware cache bust: if the cached release tag is <= the installed version, the transient is cleared automatically on next WP update check
* Cache TTL reduced from 6 hours to 1 hour so stale data clears faster
* upgrader_process_complete hook now also busts the transient after any plugin update
* Force Update Check confirmation notice added to Settings page

= 7.1.8 =
* Fixed: Green Usage table date sort was sorting lexicographically (12/5 before 28/4); dates now parsed from DD/MM/YY or DD/MM/YYYY format before comparison

= 7.1.7 =
* Green Usage table: sort by Date or Club via link controls
* Rows merged by primary sort key (date or club) using rowspan so each group appears once
* Championship titles merged into a single cell per club/date group when multiple competitions share the same date
* Full indicator shown in red when a club's green is at capacity

= 7.1.6 =
* Green bookings backfill: lgw_rebuild_green_bookings() scans all existing drawn brackets and rebuilds the cross-championship green bookings register from scratch
* Auto-backfill runs on init if lgw_green_bookings has never been built — upgrades from pre-7.1.5 are handled silently
* Manual "Recalculate from All Drawn Brackets" button added to Championship Management page for resyncing if bookings get out of step

= 7.1.5 =
* Cross-championship green capacity: when multiple championships share the same round date, home green slots are shared and allocated by priority
* Draw priority order — drag-to-reorder list on Championship Management page; manual order takes precedence, unordered championships fall back to draw timestamp order
* Hard-block enforcement: lower-priority championships cannot exceed remaining green slots on a date already partially booked by a higher-priority championship
* Green usage table on Championship Management page shows home slots used per date and club across all championships
* Capacity warning shown on edit page before drawing a section if another higher-priority championship has reduced available slots on the same dates
* Draw timestamp stamped on first draw for tiebreaking
* Reset draw now releases green bookings for that championship

= 7.1.4 =
* Added League Game Widget logo — hexagon badge in brand colours (#072a82 blue, #138211 green)
* Logo registered as WordPress admin menu icon replacing dashicons-clipboard
* Logo header added to all admin pages (Scorecards, Settings, League Setup, Cups, Championships, Import Passphrases)
* lgw-logo.svg added to plugin files

= 7.1.3 =
* GitHub Personal Access Token and Plugin Updates diagnostic moved from League Setup to Settings page
* League Setup form no longer redirects to Settings on save
* Force Update Check button now correctly stays on Settings page

= 7.1.2 =
* Fixed: GitHub PAT auth header was dropped when WordPress followed GitHub's CDN redirect during plugin zip download, causing "Not Found" on update; filter now also matches objects.githubusercontent.com and codeload.github.com

= 7.1.1 =
* Fixed: editing round dates after a draw now correctly updates the displayed dates on the live bracket page (bracket dates were previously frozen at draw time)
* Fix applies to both cup and championship section/final stage brackets

= 7.1.0 =
* League Setup page restructured into clear sections: Data Source, Photo Analysis, Google Integration, Plugin
* Data source selector added (Google Sheets active; Upload and WordPress DB placeholders for future)
* Photo analysis provider selector added (Claude/Anthropic active; OpenAI and Gemini placeholders for future)
* Google OAuth credentials consolidated into a single Google Integration section covering both Drive and Sheets
* Plugin Updates and Clear Cache moved from Settings page to League Setup
* Settings page now focused on appearance and branding only

= 7.0.0 =
* Rebranded: all internal references renamed from nipgl_ to lgw_ prefix
* Plugin display name updated to League Game Widget
* One-time DB migration on upgrade: all nipgl_* options and post meta automatically renamed to lgw_*
* Shortcodes (lgw_division, lgw_cup, lgw_champ, lgw_submit) and plugin slug unchanged for drop-in compatibility

= 6.4.51 =
* Merged Quick Score Entry into the Scorecards admin page — removed separate Scores submenu
* Both sections are collapsible with state remembered in sessionStorage; scorecards expanded by default, score entry collapsed
* Section headers show live badge counts (overrides active, pending/disputed scorecards)


= 6.4.51 =
* Simplified auto-updater to construct release asset URL directly from tag name rather than parsing API assets array — more reliable

= 6.4.34 =
* Fixed auto-updater to use GitHub release asset zip (correct folder structure) instead of raw source zipball
* Check for Updates button now forces an immediate WP update check rather than waiting for next scheduled check

= 6.4.33 =
* Fixed sponsor_img shortcode override on Cup pages (lost in v6.4.32 merge)
* Restored pre-draw entry list on Cup pages (lost in v6.4.32 merge)
* Added score update audit log — records time, match, teams, score, updated-by and IP; visible in Cups admin

= 6.4.32 =
* Added passphrase-gated score entry for Cup brackets — non-admin users can enter scores after authenticating with the draw passphrase; token held in memory for the session

= 6.4.31 =
* Fixed pre-draw entry list badge lookup — added exact club-badge match and bidirectional prefix matching to cover cases where badge key is more specific than entry club name

= 6.4.30 =
* Show entry list (badge + name) before draw is performed on Cup and Championship pages

= 6.4.29 =
* Fixed sponsor bar dark background on Cup and Championship pages — moved primary bar inside scoped CSS variable context

= 6.4.28 =
* Fixed sponsor_img shortcode attribute not overriding global sponsor for Cup and Championship widgets

= 6.4.27 =
* Added sponsor branding to Cup and Championship widgets (primary bar above bracket, rotating secondary below status bar)

= 6.4.26 =
* Added emoji icons to admin submenu items (Scorecards, Players, Cups, League Setup, Settings)

= 6.4.25 =
* Final stage always has 4 entries: 4 sections contribute 1 qualifier each (section winner), 2 sections contribute 2 each (both finalists, seeded once SFs complete), 1 section contributes all 4 semi-finalists (seeded once QFs complete)
* Section bracket winner/qualifier label changed from "Champion" to "Qualifier" (with ✅ icon) since section winners simply progress to the Final Stage; Final Stage winner still shows "Champion" with 🏆

= 6.4.24 =
* Fixed score of 0 not displaying on match cards — escHtml used s||'' which treated 0 as falsy; changed to explicit null/undefined check
* Fixed final stage bracket showing "Preliminary Round / Final" instead of "Semi-Final / Final" — final draw now passes lgw_draw_default_rounds as stored_rounds so round names reflect the full bracket size

= 6.4.23 =
* Fixed 500 error when entering the last result in a championship section — lgw_champ_try_seed_final called undefined function lgw_champ_make_skeleton_bracket; replaced with lgw_champ_perform_final_draw which performs the full final stage draw automatically

= 6.4.22 =
* Fixed championship section tabs not switching — clicking a section tab now correctly shows that section's pane (the DOM switching was dropped when the inline script was removed in v6.4.20; initSectionTabs was only saving to sessionStorage, not updating active classes)

= 6.4.21 =
* Code quality: shared draw library extracted to lgw-draw.php — bracket geometry, animation pairs, and skeleton-round assembly now live in one place (lgw_draw_build_bracket, lgw_draw_default_rounds, lgw_draw_cup_club); cup and champ draw functions refactored to thin wrappers supplying their own club/home-limit callbacks

= 6.4.20 =
* Robustness: bracket size check added at draw time — rejects writes exceeding 800KB to prevent option corruption
* Code quality: inline admin JS moved to lgw-admin.js (cup draw, cup sync, champ draw buttons)
* Code quality: redundant inline tab-switching script removed from champ shortcode (handled by lgw-champ.js)
* Build: GitHub Actions version check extended to validate LGW_VERSION constant and readme.txt stable tag

= 6.3.0 =
* Fixed empty print/PDF — replaced body > * visibility approach with visibility:hidden on all + visibility:visible on cup wrap, which works at any nesting depth; all rounds forced visible before print dialog opens

= 6.2.9 =
* Print Draw button appears in the bracket header after the draw is complete — prints a clean draw sheet hiding UI chrome
* Clicking a completed match card shows the submitted scorecard in a modal (rink-by-rink with scores, players, winner highlighted, confirmation status)
* Cup scorecards already feed into player appearance records automatically via the existing [lgw_submit cup="..."] confirmation flow — no extra config needed

= 6.2.8 =
* Fixed draw stuck at "N-1 / N drawn" — round header entries in pairs_for_anim were included in the total count but never triggered an advance_cursor call; cursor never reached total so complete was never set; headers now advance the cursor on the draw master side (and on skip-to-end)

= 6.2.7 =
* Fixed viewer draw completion — server now returns a dedicated "complete" flag with the bracket whenever the draw is fully done; viewer polls on this flag rather than inferring from in_progress+bracket which had a race condition
* Viewer overlay now shows running total (X / Y drawn) and estimated time remaining, matching the draw master screen

= 6.2.6 =
* Fixed viewer draw not completing — waitForAnim interval could wait forever if the animating flag was still true when the poll returned the final bracket; now times out after 6s and force-clears the animating state

= 6.2.5 =
* Fixed initCupWidget not defined error — function was accidentally removed during the Python-based rewrite of startDrawPoll in 6.2.3; restored

= 6.2.4 =
* Fixed login button broken by 6.2.3 — drawMasterActive variable was declared after initAdminDraw causing a ReferenceError that broke the entire script; moved declaration to module scope alongside drawToken

= 6.2.3 =
* Fixed draw master seeing two overlays — viewer poll overlay suppressed when draw master animation is active on same page
* Fixed viewer overlay missing "View Bracket" close button
* Polling uses exponential backoff: 1s during active draw, backing off to 2s/4s/8s when idle — reduces mobile network requests

= 6.2.2 =
* Draw overlay now shows "✅ The draw is complete!" with a "View Bracket" button when the draw finishes — applies to draw master, skip-to-end, and live viewers

= 6.2.1 =
= 6.2.1 =
* Fixed draw animation replaying on page refresh after draw is complete — polling is now suppressed if a complete bracket is already present on page load; draw_in_progress is also auto-cleared server-side when cursor reaches the total pair count

= 6.2.0 =
* Live draw is now fully synchronised for all viewers — the draw master's animation drives a server-side cursor that advances match by match; viewers poll at 1s intervals and see each team revealed in lockstep; viewers who join mid-draw pick up from the current position immediately

= 6.1.9 =
* Removed passphrase hint text and format placeholder from the scorecard login form on division pages — input now shows generic "Enter passphrase" placeholder only

= 6.1.8 =
* Removed passphrase format hint and placeholder from the public draw login modal — no information about the passphrase format is shown to users

= 6.1.7 =
* Fixed "unexpected response" on mobile passphrase entry — check_ajax_referer replaced with wp_verify_nonce in the draw auth and perform draw handlers so nonce failures return proper JSON errors instead of plain -1; stale nonces (from page caching) now show a "session expired, please refresh" message

= 6.1.6 =
* Fixed "unexpected token" error on mobile after passphrase entry — ajaxUrl now always sourced from lgwCupData; post() helper parses response as text first so non-JSON server responses produce a readable error

= 6.1.5 =
* Fixed draw animation showing next match teams before the reveal — text is now set inside the timeout, not before it
* Draw animation speed configurable in LGW > Cups (0.5× fast to 2× slow); default 1× = 2.6s per match
* Server-side guard against double-draw from concurrent authenticated users

= 6.1.4 =
* Draw animation is now fully automatic — teams reveal on a timed sequence; Skip to End fast-forwards all remaining matches instantly
* "No draw performed" message hidden after draw completes
* Bracket columns flex to fill available width on wider screens
* Header bar changed to red; round name/date labels have yellow background; Final round header is navy with gold text

= 6.1.3 =
* Login to Draw and Perform Draw buttons are now hidden from the public page after the draw completes — both when the draw is triggered by the current user and when a watching visitor sees it via polling
* Draw reset remains wp-admin only (Cups edit page)

= 6.1.2 =
* Draw passphrase setting moved from Settings > LGW Widget to the Cups admin page (LGW > Cups)

= 6.1.1 =
* Draw passphrase gate now applies to everyone on the public page including WP admins — the 🔑 Login to Draw button is shown to all visitors; the wp-admin inline draw button retains direct access for admins

= 6.1.0 =
* Draw passphrase gate — a global draw passphrase can be set in Settings > LGW Widget; when set, the public cup page shows a "Login to Draw" button instead of the draw button; the user enters the passphrase in a modal and on success the draw is unlocked for their browser session; WP admins bypass the gate entirely

= 6.0.10 =
* Winner row: lighter green background (#e6f4e6) with dark green text (#1a5c1a)
* Loser row: light red background (#fdf0f0) with dark red text (#8b1a1a)
* Score popover team names hardcoded to #1a1a1a for reliable contrast regardless of page theme

= 6.0.9 =
* Fixed score input contrast — explicit white background and dark text on score popover inputs
* Draw numbers hidden when a score is present to avoid overlap with the score value
* Cup scorecard support — [lgw_submit cup="cup-id"] pre-fills the division with the cup name and shows a match selector from the drawn bracket

= 6.0.8 =
* Fixed undefined variable $drawn warning on line 104 — $drawn was used in the shortcode header before being defined

= 6.0.7 =
* Fixed blank vs TBD match in 17-team draw — round names had erroneous array_reverse causing an extra skeleton round
* Round names now correct for prelim-format cups: Preliminary Round, Round of 16, Quarter Final, Semi-Final, Final
* Edit button removed from public cup page
* Cup widget now sets explicit light-mode CSS variables for standalone use
* Score entry: admins can click any match card to enter scores via a popover; winner is automatically advanced to the next round on save

= 6.0.6 =
* "Perform Draw" button is now hidden on the public page once the draw has been completed — both server-side (PHP) and immediately in the browser after the draw animation finishes

= 6.0.5 =
* Draw animation now includes the full Round 2 draw for prelim-format cups — after the prelim matches are revealed, a section header separates them and all Round 2 pairings (including "Prelim Winner" placeholders) are drawn live in sequence

= 6.0.4 =
* Fixed byes logic — prelim round now contains only the overflow matches (n minus half), with remaining teams going straight to the main round; 17 teams gives 1 prelim then 8 main-round matches

= 6.0.3 =
* Fixed "headers already sent" warning when saving cup — POST handler and draw reset/delete actions moved to admin_init hook so redirects fire before any page output

= 6.0.2 =
* Draw now enforces club home-conflict rule — teams from the same club cannot both be the home team in Round 1 on the same date; home/away assignment is adjusted automatically after the random draw, with a same-club match (the one unavoidable exception) left in drawn order

= 6.0.1 =
* Cup bracket widget — new [lgw_cup] shortcode renders a single-elimination knockout bracket with mobile-friendly round tabs and team badges
* Live animated draw — admin triggers the draw from wp-admin or the public page; visitors watching at the time see an animated team-reveal sequence in real time via polling
* Cup management — LGW → Cups admin page to create and configure cups: name, entries, round names, dates, optional Google Sheets CSV URL for result sync
* Results from Google Sheets — cup results can be synced from a published CSV matching the existing bracket spreadsheet format
* Draw reset — admin can clear and redo the draw at any time before results are recorded
* Dark mode and theme CSS variable support inherited from division widget

= 5.18.3 =
* Import Passphrases tool — upload the club passphrases xlsx directly from wp-admin (LGW → Import Passphrases) to set all club passphrases in one go. Tool removes itself from the menu when dismissed.

= 5.18.1 =
* PIN authentication replaced with passphrase authentication — clubs now log in with a three-word passphrase instead of a numeric PIN
* what3words address for the clubhouse recommended as a default passphrase (e.g. filled.count.ripen)
* Passphrase input is case-insensitive and whitespace-tolerant — filled.count.ripen and Filled.Count.Ripen both work
* Admin settings updated with passphrase column, format hint, and what3words tip
* Login form updated with plain-text input, format hint, and autocapitalise disabled for mobile

= 5.17.10 =
* Fixed "headers already sent" error on theme reset — handler moved from lgw_settings_page() to admin_init hook so redirect runs before any output

= 5.17.9 =
* Fixed ReferenceError: widget is not defined in showTeamModal — widget element now passed as parameter through showTeamModal → openModal rather than assumed in scope

= 5.17.8 =
* Fixed ReferenceError: wEl is not defined — modal CSS variable propagation now correctly passes the widget element as a parameter to openModal rather than referencing an undeclared variable

= 5.17.7 =
* Fixed theme colour saves — colour picker sync JS was placed in the scorecard admin page instead of the settings page, so picking a colour never updated the hex field that gets submitted

= 5.17.6 =
* Fixed theme colours resetting on save — colour picker inputs had duplicate name attributes, causing the hex text field value to be overwritten. Name attribute removed from pickers; hex fields are the single submitted value.

= 5.17.5 =
* Fixed undefined array key warnings on theme colour inputs when no theme has been saved yet

= 5.17.4 =
* Customisable theme colours — primary, secondary (gold), and background colours can be set globally in widget settings and overridden per-shortcode via color_primary, color_secondary, color_bg attributes. Modal inherits widget theme.

= 5.17.3 =
* Sponsor bar width fix — moved max-width/margin constraints to outer wrapper so sponsor bar matches table width correctly

= 5.17.2 =
* League table column detection now reads header row dynamically — fixes half points (e.g. 76.5) being truncated to integers when sheet has variable empty columns between fields

= 5.17.1 =
* Sponsor bar now constrained to widget width via wrapper div — no longer stretches full page width

= 5.17.0 =
* Scorecard lookup now falls back to normalised team name matching when exact slug key doesn't match — fixes "No scorecard submitted yet" when CSV team name differs from submitted name (e.g. "U. Transport A" vs "Ulster Transport A")

= 5.16.0 =
* Fixed JS syntax error (missing closing brace) that broke tab switching and scorecard submission
* Team name validation now runs actively on submit — blocks club-name-only entries even without blurring fields
* Date field normalises freeform dates (e.g. "10th May 2025") to dd/mm/yyyy on blur and after AI parse
* AI photo parse prompt updated to request dd/mm/yyyy directly
* Fixed missing lgw_safe_filename() function causing Drive upload fatal after admin edit
* Drive folders now use full team name (e.g. "Dunbarton A") not stripped club prefix
* Drive API errors now surfaced in Drive log rather than silently failing
* Added OAuth 2.0 support for Drive uploads — works with personal Gmail accounts
* Service account JWT retained as fallback for Sheets writeback
* Admin edit handler wrapped in try/catch — Drive/Sheets errors no longer return HTTP 500
* Fixed variable name collision in lgw_ajax_get_division_teams

= 5.15.0 =
* Scorecard submission now allowed even when division name is unrecognised — admin can correct via wp-admin
* Admin scorecards list shows ⚠ Unresolved badge on affected scorecards
* Admin edit form highlights division field in red with known divisions listed when unresolved
* Clearing division to a valid value automatically retries Google Sheets writeback
* Meaningful save error messages — JSON parse detail, session expiry, network errors surfaced clearly
* Division → CSV URL mapping added to sheet tab settings (used for team name validation)
* Team name validation on scorecard form — checks each field against division team list from CSV
* Fixture pairing validation — detects unknown pairings, home/away swaps, and missing suffixes (e.g. "Belmont" → "Belmont A")
* Single-click correction offered for all fixable name issues

= 5.2 =
* Fixed photo parsing — model name corrected to claude-sonnet-4-5
* Added HTTP status check on API response — surfaces real error messages instead of generic failure
* Increased max_tokens to 2000 to avoid truncated responses
* Improved error messages include raw API response excerpt for easier diagnosis
* Increased API timeout to 40s

= 5.1 =
* Scorecard submission feature — new [lgw_submit] shortcode
* PIN-gated score entry form (no WordPress login needed)
* AI photo reading via Anthropic API — upload a photo, form pre-fills automatically
* Excel upload parsing — reads LGW scorecard template directly
* Manual entry form with 4 rinks, player names, scores, totals
* Scorecard storage as custom post type
* Played fixture rows clickable — shows full rink-by-rink scorecard in modal
* New Scorecards admin page for viewing and deleting submissions
* Score Entry PIN and Anthropic API key settings

= 5.0 =
* Club-level badge configuration — set a badge once for a club and it matches all teams with that prefix (e.g. MALONE covers MALONE A, B, C)
* Exact team badges still supported and always take priority over club prefix matches
* Longest matching prefix wins when multiple club entries could match
* Badge admin UI updated with Type column (Club prefix / Exact)

= 4.9 =
* Fixed modal header and buttons being clipped in Brave browser
* Replaced inset:0 shorthand with explicit top/right/bottom/left for cross-browser compatibility
* Switched modal from vertical centering to top-anchored with padding to prevent viewport clipping

= 4.8 =
* Fixed print speed — removed Google Fonts load, dialog now appears immediately
* Fixed modal print badge oversized
* Fixed modal print stats appearing vertically instead of horizontally

= 4.7 =
* Fixed modal window appearing transparent
* Fixed league table columns bleeding behind sticky team/pos columns on mobile scroll
* Fixed fixtures print preview not generating on mobile
* Dark mode now applied via :root CSS variables so all elements including modal inherit correctly

= 4.6 =
* Dark mode — auto follows device/OS, manual toggle button on widget, preference remembered per device
* Printer icon replaced with SVG (renders correctly on all mobile browsers)
* Team name added to modal header alongside badge
* Print layout fixed — logos constrained to sensible sizes
* Print button added to league table and fixtures tabs
* Accessibility — promotion/relegation zones now show ▲/▼ symbols alongside colour
* Modal results show W/D/L label alongside colour coding
* All colours refactored to CSS variables for consistency

= 4.5 =
* Team modal — click any team name in league table or fixtures to see their full record and fixture list
* Print button in modal header opens a clean print-friendly view

= 4.4 =
* Fixed Check for Updates Now button not appearing on settings page

= 4.3 =
* Added sponsor logos — primary sponsor above title, additional sponsors rotate randomly below league table
* Per-division sponsor override via shortcode parameters

= 4.2 =
* Version number now defined as single constant — only one place to update per release

= 4.1 =
* Added "Check for Updates Now" button to settings page

= 4.0 =
* Added GitHub auto-updater
* Font updated to Saira throughout

= 3.1 =
* Font updated to Saira
* Version tracking introduced

= 3.0 =
* Added promotion/relegation zones with clinched shading
* Added server-side caching with configurable duration and manual clear
* Added title shortcode parameter
* Added club badges via Media Library

= 2.0 =
* Moved to shortcode-based approach to avoid WordPress script stripping
* Added CSV proxy via WordPress ajax

= 1.0 =
* Initial release

= 7.1.32 =
* Preview confirmation popup before saving: clicking Save now shows a full scorecard preview; users can click "← Edit" to return to the form or "✅ Confirm & Save" to proceed
* New player highlighting in preview: players not yet in the database, or with no appearances this season, are shown in green with a NEW badge
* Ladies player highlighting: names entered with an asterisk (*) are shown in purple with a ♀ badge in the preview; the * is stripped before saving as before
* Player name boxes changed to auto-expanding textareas — they grow horizontally and vertically to show all names without clipping
* New AJAX endpoint lgw_check_new_players: checks a list of player names against the DB and season appearances before showing the preview

= 7.1.33 =
* Login dropdown in fixture modal now shows only the two clubs involved in that fixture — filters the full club list using the same prefix-matching logic as passphrase auth; falls back to showing all clubs if no match is found
* Team mismatch validation on save: if the submitted home/away team names don't match the fixture (in either order), save is blocked with a clear message — "This fixture is X v Y — the scorecard appears to be for a different game"
* Mismatch check tolerates case differences and club prefix variations (e.g. "U. Transport A" vs "Ulster Transport A") before rejecting

= 7.1.34 =
* Drive and Sheets writeback now skip silently for scorecards from archived seasons — logs a clear ℹ️ "Skipped — scorecard belongs to archived season X" info entry instead of OAuth/tab errors
* Sheets Retry button hidden when all log entries are informational (e.g. archived season skip); still shown for genuine warn/error entries that may be actionable
* Drive log renderer now correctly styles info (grey), warn (amber) and success (green) entries; previously only error/success were styled
* lgw_scorecard_is_active_season() helper added to lgw-seasons.php — returns true when the scorecard's lgw_sc_season tag matches the active season, or when no season system is in use

= 7.1.35 =
* Fixture modal now shows confirm/amend actions inline when a pending scorecard exists and the logged-in club is the second club (i.e. not the submitter)
* Confirm: marks the scorecard as confirmed immediately, updates the badge in place
* Amend: replaces the scorecard view with the submission form pre-filled with the existing scores — submitting different scores marks the result as disputed for admin review
* lgw_get_scorecard AJAX response now includes _id (post ID) so the confirm action can reference the correct record without a second lookup
* lgwClubMatchesTeamStr helper added (module-level) — mirrors PHP lgw_club_matches_team for submitted_by comparisons in JS

= 7.1.36 =
* Duplicate player name detection: if the same name appears more than once on the same team across any rinks, save is blocked with a message asking the user to use Sr/Jr suffix or enter the full name to distinguish the two individuals
* Live duplicate warning shown as names are typed — amber notice appears below the rink table without blocking input, so the user can see the issue as it develops
* Preview popup shows a DUP badge (amber) on any duplicated name and includes it in the legend
* Duplicate check is case-insensitive and strips asterisks before comparing, so "J Smith*" and "J Smith" are treated as the same name
