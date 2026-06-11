# League Game Widget

A WordPress plugin that renders mobile-friendly league tables and fixtures for lawn bowls and other league sports, powered by published Google Sheets CSV data.

---

## Features

- 📱 **Mobile responsive** — sticky Pos and Team columns, compact fixture layout on small screens
- 🏆 **Promotion & relegation zones** — colour coded with ▲/▼ symbols for accessibility
- ✅ **Clinched detection** — automatic shading when promotion/relegation is mathematically confirmed
- 🏅 **Club badges** — upload logos via WordPress Media Library, mapped to team names
- 🖱 **Team modal** — click any team name to see their full record and fixture list
- 🖨 **Print views** — print button on league table, fixtures, and team modal
- 🌙 **Dark mode** — auto-follows device/OS setting with manual toggle, preference remembered per device
- 💰 **Sponsor logos** — primary sponsor above title, additional sponsors rotate randomly below table
- ⚡ **Server-side caching** — configurable cache duration to speed up page loads
- 🔄 **GitHub auto-updates** — WordPress update notifications direct from GitHub releases
- 🔍 **Team name validation** — scorecard form checks team names and fixture pairings against the division CSV, with one-click correction for swapped home/away or missing suffixes (e.g. "Belmont" → "Belmont A")

---

## Installation

1. Download the latest release zip from [Releases](https://github.com/dbinterz/lgw-division-widget/releases)
2. In WordPress go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **Settings → LGW Widget** to configure badges and sponsors

---

## Usage

Add a shortcode block to any page:

```
[lgw_division csv="YOUR_CSV_URL" title="Division 1" promote="2" relegate="2"]
```

### Shortcode Parameters

| Parameter | Description | Required |
|-----------|-------------|----------|
| `csv` | Published Google Sheets CSV URL | ✅ Yes |
| `title` | Heading shown above the widget | No |
| `promote` | Number of promotion places | No |
| `relegate` | Number of relegation places | No |
| `sponsor_img` | Override primary sponsor image for this division | No |
| `sponsor_url` | Override primary sponsor link for this division | No |
| `sponsor_name` | Override primary sponsor alt text for this division | No |

### Getting the CSV URL

1. In Google Sheets go to **File → Share → Publish to web**
2. Select the sheet, choose **CSV** format, click **Publish**
3. Copy the URL and use it as the `csv` parameter

---

## Settings

Go to **Settings → LGW Widget** to manage:

- **Sponsors** — add logos with links; first sponsor appears above the title, others rotate below the table
- **Club Badges** — map team names (as they appear in the sheet) to badge images
- **Cache Settings** — configure how long data is cached (default 5 minutes)
- **Plugin Updates** — force an immediate check for updates from GitHub
- **Clear Cache** — force all divisions to fetch fresh data on next load

---

## Google Sheet Structure

The plugin expects two sections in the sheet:

### League Table
A section with a `LEAGUE TABLE` header row, followed by column headers starting with `POS`, then team data rows.

### Fixtures
A section with a `FIXTURES` header row, followed by a column header row containing `HPts`, `HTeam`, `HScore`, `AScore`, `ATeam`, `APts`, then date rows and fixture rows.

---

## Scorecard Submission

The `[lgw_submit]` shortcode adds a scorecard entry page for clubs.

### Setup

1. Go to **Settings → LGW Widget** and add each club with a passphrase under **Score Entry** — the [what3words](https://what3words.com) address for the clubhouse makes a good default (e.g. `filled.count.ripen`)
2. Add an Anthropic API key under **API Settings** if you want AI photo parsing
3. Create a page with `[lgw_submit]` — clubs visit this page to submit scorecards

### How it works

- Clubs log in with their club name and passphrase (no WordPress account needed) — passphrase entry is case-insensitive
- Three entry methods: **photo** (AI reads the scorecard image), **Excel** (upload the LGW template), or **manual**
- First submission sets status to **Pending** — awaiting confirmation from the other club
- Second club can **Confirm** (scores agree → Confirmed ✅) or **Amend** (scores differ → Disputed ⚠️)
- League admin resolves disputes via **wp-admin → Scorecards**
- Confirmed scorecards appear when clicking a played fixture row in the league table

### Excel template

The plugin parses the standard LGW scorecard Excel template. Cells with unresolved formulas (total shots) are handled automatically by summing rink scores as a fallback.

---

## Changelog

## [7.6.7]
### Fixed
- Form guide column missing: PHP SSR path (`lgw_cache_render_table`) now generates form pips, matching JS `renderTable`.
- README.md now correctly placed inside `lgw-division-widget/` in the release zip.

## [7.6.6]
### Added
- Form guide: last 5 results per team shown as coloured W/D/L pips on the standings table (right-aligned, hidden ≤600px) and team modal. Hovering a pip shows result, score and date.

## [7.6.5]
### Fixed
- Fixture modal: shots-defeat now correctly shown as L, not D, even when team won 3 rinks.

## [7.6.4]
### Fixed
- (previous fixes)

## [7.6.13]
### Fixed
- Confirmed scorecard scores now survive a CSV re-sync. `lgw_cache_overlay_scorecard_statuses` now writes shots and points from confirmed scorecards into the cache at sync time, so the Settings Sync button no longer overwrites results with stale CSV data.

## [7.6.12]
### Fixed
- Form guide tooltip right-aligned via `data-tip` attribute; browser native `title` tooltip removed to prevent double-up.

## [7.6.11]
### Fixed
- Fixture scorecard modal now shows team stats and form guide on the SSR path — `data-teams` JSON attr passes sorted standings to JS.

## [7.6.10]
### Fixed
- Form guide pips on the scorecard modal are not clickable — prevents accidental navigation to a different scorecard.

## [7.6.9]
### Added
- Form guide pips are now clickable — opens the scorecard for that match.
- Fixture scorecard modal now shows summary stats (Pl/Pts/W/D/L) and last-5 form for both teams.

## [7.6.2]
### Fixed
- `wpAdminLogin()` helper no longer uses the private `browser()._options` Playwright API — base URL now read from `LGW_BASE_URL` env var (loaded via dotenv)
- `deleteAllScorecards()` no longer fails when no scorecard posts exist — IDs fetched first, delete skipped if result is empty
- `tests/playwright.config.js` now loads `tests/.env` via dotenv so env vars are available in helper modules at import time
- Added `dotenv` to `tests/package.json` devDependencies
- Added `tests/.env.example` and `tests/TESTING.md` local runbook

## [7.6.1]
### Fixed
- SSR (DB-primary cache) path now renders postponed pills on fixture rows — previously the `🚫 Postponed` and `Rescheduled` pills were missing when the widget was served from cache; only appeared on the XHR fallback path
- `lgw_cache_render_fixtures()` now accepts and applies `$postponements` map, mirroring JS `postponedPill`/`notesInner` logic exactly

## [7.6.0]
### Added
- **Phase 4.3 — Test Suite**: Playwright E2E suite in `tests/` directory covering all 29 spec tests across 5 files (DW, CS, SS, SM, AS)
- PHPUnit unit tests for `lgw-div-cache.php` — cache read/write/invalidate, CSV parsing, result merging (25 tests across 3 files)
- `.github/workflows/test.yml` — CI workflow: PHPUnit runs first, then Playwright E2E against a Docker WordPress service on every push to main
- `lgw_seed_test_clubs()` — deterministic test club seeding function, registered only when `WP_ENVIRONMENT_TYPE === 'local'`
- `tests/ci/setup-wp.sh` — CI bootstrap script: installs WP, activates plugin, creates test page, seeds test data
- Mock CSV fixtures in `tests/e2e/fixtures/` using real LGW Google Sheets export format

### Notes
- Tests directory excluded from release ZIP (already enforced in `release.yml`)
- `workers: 1` enforced in Playwright config — WP DB state must not interleave between tests
- PHPUnit bootstrap stubs all WordPress functions via WP_Mock; no full WP boot required for unit tests


### v7.6.1
- **Diagnostic:** Log played/unplayed row counts after binding.


### v7.5.4
- **Fix:** `bindTeamLinks` restored to original — no changes to team-link/row-click interaction.
- **Fix:** `lgwCachedGroupsToRows` returns `[]` instead of sentinel object — prevents `parseTableRows` throw in SSR path.
- **Diagnostic:** Console logs added to SSR detection block to confirm path taken and `submissionMode`.


### v7.5.3
- **Fix:** Clicking anywhere on an unplayed fixture row (including team names) now reliably opens the submission modal. Team link clicks on unplayed rows bubble up to the row handler; the team-link guard is removed from the unplayed row click handler.


### v7.5.2
- **Fix:** Clicking an unplayed fixture row reliably opens the submission modal. Team link clicks inside unplayed rows now `stopPropagation` and return early — the row click handler fires the modal as intended.


### v7.5.1
- **Fix:** SSR filter bar rendered with `.fix-filter`/`data-f` to match the XHR-path markup — restores filter button CSS styling.
- **Fix:** Clicking an unplayed fixture row now correctly opens the submission modal. Team link `stopPropagation` is now skipped for unplayed rows so the row click handler fires.


### v7.5.0
- **New:** `[lgw_division]` shortcode renders standings and fixtures server-side from the DB cache. No loading spinner when cache is warm — content is in the HTML from first byte.
- **New:** `data-prerendered` / `data-cached` attrs on the widget wrapper; `lgw-widget.js` detects these and skips the CSV XHR entirely, binding click handlers to the pre-rendered DOM.
- **New:** Filter bar (All / Results / Upcoming) operates on pre-rendered fixture rows via DOM visibility rather than a full re-render.
- **Fallback:** Cache miss or hard TTL expiry falls back to the existing XHR path — no visible difference to end users.


### v7.4.1
- **Fix:** `lgw_cache_sync_all()` and `lgw_cache_get_all_status()` now correctly unpack season division entries (`['division' => ..., 'csv_url' => ...]` arrays) — fixes "Array to string conversion" warning shown on Settings page before first sync.


### v7.4.0
- **New:** DB-primary division cache layer (`lgw-div-cache.php`). Division standings and fixtures are now stored in WP options and served instantly on page load — no outbound HTTP on the critical path.
- **New:** Background WP-Cron sync keeps cache fresh automatically (configurable interval: 15 min / 30 min / 1 hour / 4 hours).
- **New:** `lgw_cache_merge_result()` hooked to `lgw_scorecard_confirmed` — confirmed results appear in the fixture list immediately without waiting for the next CSV sync.
- **New:** Division Cache health panel in Settings shows last-synced time, fixture count, and team count per division; per-division and bulk Sync buttons trigger immediate refresh via AJAX.
- **Updated:** "Clear Cache Now" in Settings now also clears the new DB cache entries (`lgw_div_cache_*` options).

### v7.3.62
- Fix: Starred and female flag checkboxes now correctly preserve filter state after update. Native `form.submit()` called by `onchange` does not fire the submit event, so filter injection now uses a `change` event delegation handler instead.


### v7.3.62
- Fix: Player admin rename, flag, and delete actions now use an `admin_init` hook to redirect before any output, resolving the "headers already sent" warning introduced in v7.3.60.


### v7.3.62
- Enhancement: Player admin — rename, flag changes (★/♀), and delete now redirect back to the same filter state. Active club, team, and name filters are preserved across any form submission.


### v7.3.62
- Enhancement: Player admin table — "League (by team)" column replaces flat Lge W/D/L; for players on multiple teams a ▾ toggle expands per-team W/D/L and SF–SA rows with a total summary.
- Fix: Player stats popover team filter chips now also update the W/D/L/Played counters to reflect only the filtered team's games.


### v7.3.62
- Fix: Admin editing a disputed scorecard now correctly resolves the dispute — status is set to Confirmed, the away version is cleared, and the resolution is audit-logged.
- Enhancement: Disputed scorecards show a prominent warning banner in the edit form, and the save button is relabelled "⚖️ Save & Resolve Dispute" to make the outcome clear before committing.


### v7.3.62
- Enhancement: Pending scorecard pill now shows which team is awaited — e.g. "Pending (Hilden)" means Ards has submitted and Hilden's confirmation is outstanding. Shows plain "Pending" when both sides have submitted but confirmation is still due.


### v7.3.62
- Enhancement: Championship bracket shows only the skip's name for triples and fours entries; a ▾ toggle expands inline to reveal all team members as individual clickable stats links (stats-eligible championships only).


### 7.3.62
- Fixed: rename handler removed erroneous `player_name` update on `lgw_appearances` (column doesn't exist; names resolved via `player_id` join)
- Fixed: renaming to an existing name now performs a proper merge — appearances re-pointed to existing `player_id`, old record deleted — instead of hitting a duplicate key DB error


### 7.3.62
- Player rename cascades to `lgw_appearances`: all appearance records updated to new name; success notice reports row count


### 7.3.62
- Player Tracking admin page now shows the LGW page header (logo + version) consistent with other admin pages


### 7.3.62
- Fixed: rename (and player name) button `onclick` not firing — replaced inline `onclick` with `data-*` attributes wired via `addEventListener` event delegation


### 7.3.62
- Fixed: Rename button missing `type="button"` — browser was treating it as `type="submit"` and swallowing the click event before the `onclick` could fire


### 7.3.62
- Added null guard on rename modal element lookup — logs a console error if elements are missing rather than silently breaking


### 7.3.62
- Fixed: Rename button on Players admin page — `prompt()` suppressed in WP admin; replaced with inline modal
- Rename modal: AJAX duplicate-name check before submit; if name already exists for same club, warning shown and explicit "Yes, merge" confirmation required


### 7.3.62
- Fixed: fatal error in `lgw_ajax_confirm_scorecard` — replaced `lgw_user_can_manage_scores()` with inline capability check to avoid load-order dependency


### 7.3.62
- Admin confirm for pending scorecards: passphrase gate replaced with direct "Confirm on behalf of [club]" button for logged-in admins
- PHP: `lgw_ajax_confirm_scorecard` allows admin bypass — confirms as the other team with audit log entry


### 7.3.62
- Fixed: admin "confirm on behalf of other club" now also works when a scorecard has already been submitted by one team — admin can confirm the pending card without logging in as the other club


### 7.3.62
- Admin scorecard submission: new "Also confirm on behalf of the other club" checkbox when submitting for home or away team; scorecard is immediately marked confirmed with a distinct audit log entry; checkbox hidden when "Both teams" is selected


### v7.3.62
- **Fix:** Multi-champ font now always applied — enqueue reads font option directly, loads Google Font independently via  handle, sets  directly on  rather than relying on CSS variable propagation from another stylesheet

### v7.3.43
- **Fix:** Multi-champ widget now uses the font picker selection — enqueue depends on  and injects  variable on ; previously ignored the setting and fell back to 

### v7.3.42
- **Feature:** Font picker in Settings — 10 curated Google Fonts with live preview; selected font applied via `--lgw-font` CSS variable across all widgets
- **Fix:** Start game button AJAX action corrected

### v7.3.41
- **Fix:** Start game button posted to wrong AJAX action ( → )

### v7.3.40
- **Feature:** Ends counter (−/+) on admin game cards and frontend entry forms — shows current end / max ends, stored as `ends_played`; only visible for ends-mode disciplines
- **Feature:** End indicator badge in discipline label row during in-progress games — amber pill showing e.g. **End 7/21**, visible to all viewers
- **Feature:** ▶ Start game button on not-started rows — takes player names, marks game in_progress at 0–0, replaces itself with score entry form
- **Fix:** Frontend status select includes Not started as first default option; selecting it re-collapses the row

### v7.3.38
- **Fix:** Fixture status badge miscounting — "started" now requires shots saved OR status set, fixing mismatch between admin (Not started) and frontend (In progress) for games with shots but default status
- **Feature:** Manual ▲/▼ toggle on each fixture card to expand/collapse the games breakdown independently
- **Feature:** Not-started games render as compact label-only rows; entire fixture collapses when nothing started; auto-expands when any game begins

### v7.3.33
- **Fix:** `ReferenceError: scoresWrap is not defined` on frontend pages — club management, status patch, and unlock bar code was appended after the IIFE `})()` closing, outside its scope; all code moved back inside the single IIFE
- **Fix:** Browser DOM warning about password field not in a form — passphrase input changed from `type="password"` to `type="text" autocomplete="off"`

### v7.3.33
- **Fix:** Frontend unlock not working — `window.ajaxurl` is undefined on public pages (only set in wp-admin); `wp_localize_script` now provides `lgwMcData.ajaxurl` to the frontend script; all 7 fetch calls updated to use `lgwMcData.ajaxurl` with fallback chain
- **Fix:** No game rows rendered for score entry when all games are `not_started` — the display skip logic now only hides unstarted rows when score entry is disabled; when enabled, all game rows render so scorers can enter first results

### v7.3.33
- **Fix:** Widget colour picker now drives the full colour scheme — `--lgw-mc-primary` CSS variable wired through all 12 colour references (header, tab bar, standings headers, pts column, buttons); defaults to `var(--lgw-navy)` so existing installs are unchanged
- **Fix:** Club colours and badges now appear in per-discipline standings tabs — `$club_colour_map` and `$club_badge_map` were not being passed to the per-discipline `lgw_mc_render_standings_table()` calls

### v7.3.33
- **Fix:** Club names not saving — `mc_entries[]` field name conflicted with `mc_entries` hidden sentinel; all club name inputs renamed to `club_name[]` in PHP form, JS row builder, and save handler

### v7.3.33
- **Feature:** Club colours and badges per championship — colour picker and WP media badge per club in Setup; applied to standings row and fixture club names
- **Feature:** Widget primary colour picker in Setup
- **Feature:** Frontend score entry — enable toggle + per-championship passphrase; persistent unlock bar; inline score + status inputs on game rows after unlock; sessionStorage passphrase persistence
- **Feature:** Game status (Not started / In progress / Complete) — badge in frontend and admin; dropdown in admin scores tab
- **Feature:** Player names shown in frontend fixture card game rows
- **Feature:** Bold winning score replaces checkmark symbol in fixture breakdown
- **Feature:** Bonus-adjusted total shown in fixture card match header
- **Feature:** Club entries table (name + colour + badge) replaces plain textarea in Setup
- **Fix:** Discipline table narrowed to compact auto-width

### v7.3.33
- **Fix:** Cleared games counting as 0-0 draws in standings — `lgw_mc_compute_standings()` now skips game entries that have no `shots_home` key (scorecard-link-only entries left by the clear handler)
- **Feature:** Match bonus points — new Overall win / Overall draw / Overall loss fields in Setup; bonus pts added to overall standings after all game pts are aggregated; a note is shown in the frontend Overall standings panel when bonus points are configured

### v7.3.33
- **Fix:** PHP warnings `Undefined array key "pts_home"` / `"pts_away"` in `lgw-multichamp.php` — bare `$g['pts_home']` access replaced with `$g['pts_home'] ?? 0` in three locations: frontend fixture card game row, `lgw_mc_compute_standings()`, and admin scores tab game card header

### v7.3.33
- **Fix:** JavaScript syntax error (`Unexpected token '}'`) in `lgw-multichamp.js` — stray orphaned closing braces left from a prior refactor of the create-scorecard handler, preventing the entire script from loading

### v7.3.33
- **Fix:** Frontend tabs not responding to clicks — tab nav changed from `<button>` to `<div role="button">` to avoid form-submit interference from wrapping page forms and WordPress global button resets; JS initialisation wrapped in `DOMContentLoaded` guard; keyboard support (Enter/Space) added

### v7.3.33
- **Feature:** Clear scores buttons in Multi-Discipline Championship scores tab — per-game "✕ Clear" button on each game card; per-fixture "✕ Clear all" on the accordion header; both confirm before clearing; scorecard links preserved
- **Feature:** Shortcode reference block shown on championship edit page once an ID exists — copy-ready `[lgw_multichamp id="..."]` snippet

### v7.3.33
- **Fix:** Player name fields in the scores tab now clearly visible — moved out of the shots column into a dedicated two-column players row below each game score grid, with labelled inputs for home and away sides

### v7.3.33
- **Fix:** Multi-Discipline Championship scores tab layout — replaced 9-column table (which overflowed the admin panel) with a card-per-game layout; each game card shows a two-column home/away grid with shots input, player names, pts display, save button, and scorecard link all cleanly within panel width

### v7.3.33
- **Fix:** Multi-Discipline Championship admin JS was never loading on wp-admin pages — `lgw-multichamp.js` and `lgw-multichamp.css` were only hooked to `wp_enqueue_scripts` (frontend only); `admin_enqueue_scripts` hook added, scoped to the `lgw-multichamp` page — discipline builder, fixture builder, auto-draw, score saving, and scorecard creation now all work in admin

### v7.3.17
- **Feature:** Scorecard integration — `+ Full scorecard` button creates a `lgw_scorecard` CPT post tagged `context=multichamp` with `lgw_multichamp_game_id` meta; edit screen shows green info banner with back-link to championship scores tab; scorecard list shows context badges (🏅 Multi-champ, 🏆 Cup, 🎯 Champ); division-unresolved check and Drive/Sheets warning scoped to league context only

### v7.3.16
- **Fix:** Discipline config now includes **Scoring mode** — "Ends" or "Target score" (first to N shots); Singles defaults to target score; ends/shots label updates live in admin
- **Fix:** **Time limit** field added per discipline — optional free text shown in fixture card game rows

### v7.3.15
- **Feature:** Multi-Discipline Championship — new `[lgw_multichamp]` shortcode; `lgw-multichamp.php`, `lgw-multichamp.js`, `lgw-multichamp.css`; admin setup, fixtures, and score entry panels; overall and per-discipline standings; fixture result cards

### v7.3.14
- **Fix:** Time pill (e.g. ⏰ 5:30) now centres correctly over the fixture columns on widescreen — `grid-column` changed from `1/-1` to `1/6` to exclude the notes column

### v7.3.13
- **Fix:** Save Changes button on the scorecard post edit screen (`post.php?post=X&action=edit`) now correctly saves — AJAX handler added to `lgw-admin.js` which is the only LGW script loaded on that screen
- **Fix:** Edit form and audit log styles now load via `lgw-admin.css` on the post edit screen — previously they were only present as an inline `<style>` block on the scorecards admin page
- **New:** Quick Score Entry — date jump filter; select a specific fixture date to focus the table on that date's matches only
- **New:** Submitted Scorecards — division filter and status filter dropdowns for quick triage of the scorecard list
- **Feature:** On widescreen, postponed entry splits into two stacked pills in the notes column — **🚫 Postponed** (red) and **📅 Rescheduled [date]** (blue) on separate lines; mobile keeps the single combined pill

### v7.3.10
- **Fix:** Postponed pill moved into the notes column on widescreen instead of spanning full-width left; **Notes** header label added to the date bar aligned over the notes column; label hidden on mobile

### v7.3.9
- **Fix:** Postponed pill not appearing on widescreen — was using `fx-pills` class which is hidden on widescreen; now uses a dedicated `fx-postponed-row` class that is always visible

### v7.3.8
- **Feature:** On widescreen, fixture row pills (played date, scorecard status) move into a right-hand notes column instead of adding row height; postponed pill stays full-width on all sizes; on mobile pills revert to the previous below-row behaviour

### v7.3.7
- **Fix:** Main widget tab (League Table / Fixtures & Results) now persists on page refresh via `sessionStorage` — page no longer reverts to the League Table tab

### v7.3.6
- **Fix:** Fixtures & Results filter tab (All / Upcoming / Results) now persists across page loads using `sessionStorage`, keyed per division — navigating away and back stays on the active tab

### v7.3.5
- **Fix:** Played date pill no longer shows when the scorecard date and fixture date are the same day but different formats (e.g. `25/4/2026` vs `Sat 25-Apr-2026`); `lgw_parse_any_date()` normalises both to a timestamp before comparing

### v7.3.4
- **Feature:** Postponed fixtures — admin can mark any unplayed fixture as postponed via the fixture modal, optionally adding a rescheduled date; a red **🚫 Postponed** pill appears on the fixture row immediately; non-admins see an informational notice; stored in `lgw_postponements` WP option; no spreadsheet changes
- **Fix:** Fixture date pill and scorecard status pill CSS re-applied after working copy refresh

### v7.3.3
- **Fix:** Fixture date pill and scorecard status indicator not appearing — PHP map keys were not fully lowercased on the date component, causing a mismatch with the JavaScript lookup (which lowercases the entire key)

### v7.3.2
- **Feature:** Fixture rows now show a blue **📅 Played [date]** pill when the game was played on a different date to the scheduled one (replaces the previous italic text annotation)
- **Feature:** Fixture rows now show a scorecard submission status pill — **📋 Pending** (amber), **✅ Confirmed** (green), or **⚠️ Disputed** (orange) — whenever a scorecard has been submitted for that fixture

### v7.3.1
- **Feature:** Clipboard paste for photo scorecard submission — on mobile a **📋 Paste from clipboard** button appears in the photo tab; on desktop Ctrl+V works anywhere on the form. Allows WhatsApp photos to be submitted by copying in WhatsApp and pasting directly, with no need to save to gallery first. Graceful error messages if clipboard permission is denied or no image is found.

### v7.3.0
- **Fix:** Mobile scorecard photo submission — file picker now shows a camera / gallery choice popup on touch devices instead of silently failing; `capture="environment"` removed from the modal photo input which was locking mobile browsers to camera-only with no way to switch to gallery or files

### v7.2.59
- **Group Championships:** Added search function matching the individual championships. A Search button in the day tabs toolbar searches across group fixtures, KO rounds, and Finals Week by player name or club. Supports Fixtures / Results mode toggle, Copy as Text, and CSV export.

### v7.2.58
- **Championships (fix):** Final Stage admin block was hidden for single-section championships due to a `count($sections) > 1` guard. Removed — the block now shows for all championships. Single-section readiness check uses `lgw_champ_get_section_qualifiers($bracket, 4)` (all 4 semi-finalists known) rather than requiring the section final to be scored.
- **Championships (fix):** Rebuild Final Stage button no longer restricted to multi-section championships.

### v7.2.57
- **Championships (fix):** Final Stage qualifier pairing corrected to sequential order — qualifiers slot straight through in section order (Sec A vs Sec B, Sec C vs Sec D for 4-section; Sec A winner vs Sec A runner-up, Sec B winner vs Sec B runner-up for 2-section; q[0] vs q[1], q[2] vs q[3] for 1-section).

### v7.2.56
- **Championships (fix):** Undefined variable `$n_sections` on admin Final Stage panel — replaced with `$n_sec_admin` derived from `$sections` already in scope.

### v7.2.55
- **Championships:** Final Stage now carries section qualifiers through directly — no re-shuffle or re-draw. Qualifiers are cross-paired by section so the same-section clash is impossible before the Final: 4-section → Sec A vs Sec D, Sec B vs Sec C; 2-section → Sec A winner vs Sec B runner-up, Sec B winner vs Sec A runner-up; 1-section → semi-finalists in bracket order.
- **Championships:** Added admin 'Rebuild Final Stage from Sections' button. Shown when a Final Stage draw already exists and all qualifiers are confirmed. Replaces any previously random-drawn bracket with the section carry-over layout — corrects draws already in progress without requiring a full reset.

### v7.2.54
- **Group Championships:** Club crest displayed in standings table on wider screens. Resolved from `lgw_club_badges` / `lgw_badges` options using the same lookup pattern as the Finals Week render. Hidden below 480px via `.lgw-gs-hide-sm` media query.

### v7.2.53
- **Group Championships:** Added entry format validator. The entries textarea now shows a live inline warning for any lines missing a comma (required `Player Name(s), Club` format), blocks form submission until corrected, and scrolls/highlights the warning on submit attempt. A server-side check in the save handler provides a backstop, redirecting with a clear error notice if malformed entries get through.

### v7.2.52
- **Group Championships:** Fixed false 'draw algorithm failure' violations when entries don't include a comma-separated club (format `Player Name Club` instead of `Player Name, Club`). `distribute_to_groups` now detects the absence of club info and falls back to simple round-robin fill. The violation audit and retry loop also guard against empty club names.

### v7.2.51
- **Group Championships:** Draw result now stamped with `draw_version` (plugin version) and `draw_timestamp` so admins can confirm unambiguously which code produced the stored draw.
- Post-draw violation audit added inside `lgw_gchamp_run_draw` — any remaining same-club group violations are added to the warnings array with a ⚠ prefix.
- The draw AJAX handler now retries the full draw up to 5 times if violation warnings are present, keeping the best result across all attempts.

### v7.2.50
- **Group Championships (draw fix):** The inner `usort` closure in `distribute_to_groups` was capturing `$ge` by value instead of by reference. PHP closures with `use ($ge)` snapshot the variable at closure-definition time, so the 'prefer most remaining space' tiebreaker always computed against the initial empty group state rather than the current partially-filled state. This made placement effectively random within clean-group candidates, routinely putting same-club entries in the same group. Fixed by using `use ($sizes, &$ge)`.

### v7.2.49
- **Hotfix:** Day `Location` field not saving on admin page. `$day_locations_post` declaration was accidentally dropped from the days_config save block when `$day_fq_post` was added in v7.2.42.

### v7.2.48
- **Finals Week:** `[lgw_finals]` shortcode now includes Group Championship qualifiers alongside standard championships for the given season. Added `lgw_finals_get_gchamp_matches()` to map `finals_matches` into the existing match-list format. All AJAX handlers (`lgw_finals_save_datetime`, `lgw_finals_save_end`, `lgw_finals_save_score`) and the live poll handler now detect `bracket_key='gchamp'` and route reads/writes to `lgw_gchamp_*` options instead of `lgw_champ_*`. Scores edited from the `[lgw_finals]` page, the Group Championships shortcode, or the admin screen all write to the same data.

### v7.2.47
- **Group Championships (fix):** Finals Week bracket was built once (when the first day completed) and never rebuilt as subsequent days completed. Introduced `finals_q_count_at_build` to track the qualifier count at build time. Both the render and the KO score save handler now rebuild `finals_matches` whenever the current qualifier count differs from the count at last build.

### v7.2.46
- **Group Championships (fix):** `finals_qualifiers=4` was only showing 1 semi-finalist in the Finals Week strip. Root cause: the function was collecting only SF *losers*, but with byes or timing issues only 1 loser was available. For `per_day >= 4`, the function now collects all SF *participants* (both competitors in each SF match) — these are known as soon as the SFs are played, regardless of the final. The final can still be played but doesn't affect who qualifies.

### v7.2.45
- **Group Championships (fix):** The pending 'Finals Week qualifiers' label below the KO bracket was hardcoded to derive the count from `$num_days` instead of reading `$per_day_q`. Now correctly reads the per-day `finals_qualifiers` setting and shows a descriptive label (e.g. '2 qualifiers (finalist + runner-up)').

### v7.2.44
- **Group Championships:** KO bracket completion now triggers as soon as the rounds needed to confirm all Finals Week qualifiers have been played, rather than requiring every round to be scored. `finals_qualifiers=1/2` → complete when final is played; `finals_qualifiers=4` → complete when semi-finals are played. Added `lgw_gchamp_ko_qualifiers_complete()` helper. `compute_ko_qualifiers()` now handles `finals_qualifiers=3` (final winner + runner-up + one SF loser).

### v7.2.43
- **Group Championships:** Editable-post-draw day fields (`finals_qualifiers`, `name`, `date`, `location`, `ko_bracket_size`) are now synced from `days_config` back onto the drawn day records on every championship save. Previously changing e.g. `finals_qualifiers` after a draw had no effect until a reset+redraw.

### v7.2.42
- **Group Championships:** Finals Week qualifiers moved from championship-level to per-day. A 'Finals qualifiers' column (1–4 dropdown) now appears in the days table. Stored as `finals_qualifiers` on each day. Falls back to `finals_qualifiers_per_day` (championship level) or the previous auto-calculation for backward compatibility.

### v7.2.41
- **Group Championships:** Added 'Finals Week qualifiers per day' setting to the Knockout Stage section of the edit page. Admin selects 1–4; the auto-suggestion based on number of days is shown for guidance. Stored as `finals_qualifiers_per_day` on the championship and used in `lgw_gchamp_compute_ko_qualifiers()` and the bracket render. Backward compatible — existing championships default to the previous auto-calculation.

### v7.2.40
- **Group Championships:** 'Clear KO scores' button restyled using plugin CSS variables and design tokens to match the existing score entry buttons rather than plain WP admin button styling.

### v7.2.39
- **Group Championships:** Added 'Clear all KO scores' button to the KO bracket header (admin, visible when any score exists). Clears all match scores, resets winner advancement beyond round 0, re-fills TBD slots from currently confirmed qualifiers, and unlocks all group lock buttons on the day. Resolves 'no way to clear KO scores' UX gap.
- Group lock button tooltip updated to explain the per-group KO qualifier lock and direct admin to the new clear button.

### v7.2.38
- **Hotfix:** Fixed PHP syntax error in `lgw-gchamp.php` caused by the same orphaned-body pattern as v7.2.35 — `str_replace` anchor consumed a function declaration, leaving its body as loose code.

### v7.2.37
- **Group Championships:** Added `lgw_gchamp_fill_ko_tbd_slots()`. When a group completes after KO scores already exist, the new qualifier fills the next available TBD slot in round 0 of the bracket rather than being silently dropped. Played matches are left completely unchanged. Full reseed still happens when no KO scores exist.

### v7.2.36
- **Group Championships:** Group fixture lists now expanded by default.

### v7.2.35
- **Hotfix:** Fixed PHP syntax error in `lgw-gchamp.php` — the `lgw_gchamp_any_group_complete()` function declaration was accidentally dropped during the v7.2.34 refactor, leaving its body as orphaned code and causing a parse error on load.

### v7.2.34
- **Group Championships:** Group score editing is now blocked only when a qualifier from *that specific group* has already played in the KO bracket. Previously any KO score on the day blocked all group edits. Groups whose qualifiers are not yet in the KO (or whose KO matches are unplayed) remain fully editable.
- Group lock button now appears as soon as an individual group completes, not when the whole day does.
- `$score_open` now reflects per-group lock and per-group KO qualifier lock, independent of `$day_complete`.
- Added `lgw_gchamp_qualifiers_from_group()` and `lgw_gchamp_qualifiers_in_played_ko()` helpers.

### v7.2.33
- **Group Championships:** Partial KO bracket now uses consecutive pairing during progressive fill: confirmed qualifiers pair as 1v2, 3v4, 5v6… so match 1 is fully populated (and immediately playable) as soon as the first two groups complete, match 2 when four complete, etc. TBD matches cluster at the bottom. Once all groups complete the bracket is reseeded with standard 1-vs-N ordering.

### v7.2.32
- **Group Championships:** Progressive KO bracket now fills top-down. Confirmed qualifiers are sorted to the top of the `$numbered` array before seeding, so they occupy the top bracket positions and TBD stubs cluster at the bottom. As each subsequent group completes, its qualifiers fill in the next available top positions.

### v7.2.31
- **Group Championships:** Knockout tab now unlocks as soon as *any* group on a day completes, rather than waiting for all groups.
- **Group Championships:** Knockout bracket seeds progressively — confirmed qualifiers are placed immediately, with TBD stubs for groups not yet finished. As each group completes its qualifiers replace the TBD stubs and the bracket updates automatically.
- **Group Championships:** Score entry buttons appear on KO matches as soon as both participants are confirmed (existing behaviour, now works from first qualifier onwards).
- Added `lgw_gchamp_group_fixtures_all_played()`, `lgw_gchamp_any_group_complete()`, `lgw_gchamp_compute_partial_qualifiers()` helpers.

### v7.2.30
- **Group Championships:** Draw success message now explicitly shows "No warnings" when the draw is clean. Stale warning notices from the previous draw are cleared from the page immediately on success, before the reload, so they can't be mistaken for warnings from the new draw.

### v7.2.54
- **Group Championships:** Club crest displayed in standings table on wider screens. Resolved from `lgw_club_badges` / `lgw_badges` options using the same lookup pattern as the Finals Week render. Hidden below 480px via `.lgw-gs-hide-sm` media query.

### v7.2.53
- **Group Championships:** Added entry format validator. The entries textarea now shows a live inline warning for any lines missing a comma (required `Player Name(s), Club` format), blocks form submission until corrected, and scrolls/highlights the warning on submit attempt. A server-side check in the save handler provides a backstop, redirecting with a clear error notice if malformed entries get through.

### v7.2.52
- **Group Championships:** Fixed false 'draw algorithm failure' violations when entries don't include a comma-separated club (format `Player Name Club` instead of `Player Name, Club`). `distribute_to_groups` now detects the absence of club info and falls back to simple round-robin fill. The violation audit and retry loop also guard against empty club names.

### v7.2.51
- **Group Championships:** Draw result now stamped with `draw_version` (plugin version) and `draw_timestamp` so admins can confirm unambiguously which code produced the stored draw.
- Post-draw violation audit added inside `lgw_gchamp_run_draw` — any remaining same-club group violations are added to the warnings array with a ⚠ prefix.
- The draw AJAX handler now retries the full draw up to 5 times if violation warnings are present, keeping the best result across all attempts.

### v7.2.50
- **Group Championships (draw fix):** The inner `usort` closure in `distribute_to_groups` was capturing `$ge` by value instead of by reference. PHP closures with `use ($ge)` snapshot the variable at closure-definition time, so the 'prefer most remaining space' tiebreaker always computed against the initial empty group state rather than the current partially-filled state. This made placement effectively random within clean-group candidates, routinely putting same-club entries in the same group. Fixed by using `use ($sizes, &$ge)`.

### v7.2.49
- **Hotfix:** Day `Location` field not saving on admin page. `$day_locations_post` declaration was accidentally dropped from the days_config save block when `$day_fq_post` was added in v7.2.42.

### v7.2.48
- **Finals Week:** `[lgw_finals]` shortcode now includes Group Championship qualifiers alongside standard championships for the given season. Added `lgw_finals_get_gchamp_matches()` to map `finals_matches` into the existing match-list format. All AJAX handlers (`lgw_finals_save_datetime`, `lgw_finals_save_end`, `lgw_finals_save_score`) and the live poll handler now detect `bracket_key='gchamp'` and route reads/writes to `lgw_gchamp_*` options instead of `lgw_champ_*`. Scores edited from the `[lgw_finals]` page, the Group Championships shortcode, or the admin screen all write to the same data.

### v7.2.47
- **Group Championships (fix):** Finals Week bracket was built once (when the first day completed) and never rebuilt as subsequent days completed. Introduced `finals_q_count_at_build` to track the qualifier count at build time. Both the render and the KO score save handler now rebuild `finals_matches` whenever the current qualifier count differs from the count at last build.

### v7.2.46
- **Group Championships (fix):** `finals_qualifiers=4` was only showing 1 semi-finalist in the Finals Week strip. Root cause: the function was collecting only SF *losers*, but with byes or timing issues only 1 loser was available. For `per_day >= 4`, the function now collects all SF *participants* (both competitors in each SF match) — these are known as soon as the SFs are played, regardless of the final. The final can still be played but doesn't affect who qualifies.

### v7.2.45
- **Group Championships (fix):** The pending 'Finals Week qualifiers' label below the KO bracket was hardcoded to derive the count from `$num_days` instead of reading `$per_day_q`. Now correctly reads the per-day `finals_qualifiers` setting and shows a descriptive label (e.g. '2 qualifiers (finalist + runner-up)').

### v7.2.44
- **Group Championships:** KO bracket completion now triggers as soon as the rounds needed to confirm all Finals Week qualifiers have been played, rather than requiring every round to be scored. `finals_qualifiers=1/2` → complete when final is played; `finals_qualifiers=4` → complete when semi-finals are played. Added `lgw_gchamp_ko_qualifiers_complete()` helper. `compute_ko_qualifiers()` now handles `finals_qualifiers=3` (final winner + runner-up + one SF loser).

### v7.2.43
- **Group Championships:** Editable-post-draw day fields (`finals_qualifiers`, `name`, `date`, `location`, `ko_bracket_size`) are now synced from `days_config` back onto the drawn day records on every championship save. Previously changing e.g. `finals_qualifiers` after a draw had no effect until a reset+redraw.

### v7.2.42
- **Group Championships:** Finals Week qualifiers moved from championship-level to per-day. A 'Finals qualifiers' column (1–4 dropdown) now appears in the days table. Stored as `finals_qualifiers` on each day. Falls back to `finals_qualifiers_per_day` (championship level) or the previous auto-calculation for backward compatibility.

### v7.2.41
- **Group Championships:** Added 'Finals Week qualifiers per day' setting to the Knockout Stage section of the edit page. Admin selects 1–4; the auto-suggestion based on number of days is shown for guidance. Stored as `finals_qualifiers_per_day` on the championship and used in `lgw_gchamp_compute_ko_qualifiers()` and the bracket render. Backward compatible — existing championships default to the previous auto-calculation.

### v7.2.40
- **Group Championships:** 'Clear KO scores' button restyled using plugin CSS variables and design tokens to match the existing score entry buttons rather than plain WP admin button styling.

### v7.2.39
- **Group Championships:** Added 'Clear all KO scores' button to the KO bracket header (admin, visible when any score exists). Clears all match scores, resets winner advancement beyond round 0, re-fills TBD slots from currently confirmed qualifiers, and unlocks all group lock buttons on the day. Resolves 'no way to clear KO scores' UX gap.
- Group lock button tooltip updated to explain the per-group KO qualifier lock and direct admin to the new clear button.

### v7.2.38
- **Hotfix:** Fixed PHP syntax error in `lgw-gchamp.php` caused by the same orphaned-body pattern as v7.2.35 — `str_replace` anchor consumed a function declaration, leaving its body as loose code.

### v7.2.37
- **Group Championships:** Added `lgw_gchamp_fill_ko_tbd_slots()`. When a group completes after KO scores already exist, the new qualifier fills the next available TBD slot in round 0 of the bracket rather than being silently dropped. Played matches are left completely unchanged. Full reseed still happens when no KO scores exist.

### v7.2.36
- **Group Championships:** Group fixture lists now expanded by default.

### v7.2.35
- **Hotfix:** Fixed PHP syntax error in `lgw-gchamp.php` — the `lgw_gchamp_any_group_complete()` function declaration was accidentally dropped during the v7.2.34 refactor, leaving its body as orphaned code and causing a parse error on load.

### v7.2.34
- **Group Championships:** Group score editing is now blocked only when a qualifier from *that specific group* has already played in the KO bracket. Previously any KO score on the day blocked all group edits. Groups whose qualifiers are not yet in the KO (or whose KO matches are unplayed) remain fully editable.
- Group lock button now appears as soon as an individual group completes, not when the whole day does.
- `$score_open` now reflects per-group lock and per-group KO qualifier lock, independent of `$day_complete`.
- Added `lgw_gchamp_qualifiers_from_group()` and `lgw_gchamp_qualifiers_in_played_ko()` helpers.

### v7.2.33
- **Group Championships:** Partial KO bracket now uses consecutive pairing during progressive fill: confirmed qualifiers pair as 1v2, 3v4, 5v6… so match 1 is fully populated (and immediately playable) as soon as the first two groups complete, match 2 when four complete, etc. TBD matches cluster at the bottom. Once all groups complete the bracket is reseeded with standard 1-vs-N ordering.

### v7.2.32
- **Group Championships:** Progressive KO bracket now fills top-down. Confirmed qualifiers are sorted to the top of the `$numbered` array before seeding, so they occupy the top bracket positions and TBD stubs cluster at the bottom. As each subsequent group completes, its qualifiers fill in the next available top positions.

### v7.2.31
- **Group Championships:** Knockout tab now unlocks as soon as *any* group on a day completes, rather than waiting for all groups.
- **Group Championships:** Knockout bracket seeds progressively — confirmed qualifiers are placed immediately, with TBD stubs for groups not yet finished. As each group completes its qualifiers replace the TBD stubs and the bracket updates automatically.
- **Group Championships:** Score entry buttons appear on KO matches as soon as both participants are confirmed (existing behaviour, now works from first qualifier onwards).
- Added `lgw_gchamp_group_fixtures_all_played()`, `lgw_gchamp_any_group_complete()`, `lgw_gchamp_compute_partial_qualifiers()` helpers.

### v7.2.30
- **Group Championships:** Fixed false positive "duplicate club" warning. The swap simulation built `$vgi_new` (post-swap group) then immediately filtered the incoming entry back out before checking for conflicts — checking the wrong state. The check now correctly validates the fully post-swap arrays, so clean draws no longer trigger a spurious warning.

### v7.2.29
- **Group Championships:** Complete rewrite of `lgw_gchamp_distribute_to_groups`. Previous repair pass operated on stale array indices (group arrays mutated mid-loop invalidating stored `idx` values) and the swap validation used pre-mutation state. New approach: encapsulates placement+repair as a single attempt function, runs up to 20 attempts with different random seeds, and keeps the result with the fewest club violations. Each attempt uses constraint-first sorting, clean-group-first placement, then a swap-based repair that simulates the full post-swap state before committing.

### v7.2.28
- **Group Championships (draw fix):** The repair pass now iterates across all violations in each pass rather than aborting on the first one it can't immediately fix. The old `break` meant that if violation #1 was temporarily stuck (because violations #2 and #3 were blocking the swap candidates), the repair gave up entirely — leaving 3 Falls entries in one group even though a solution existed. The loop now continues to violations #2 and #3, fixing those first, then picks up violation #1 in the next pass.
- Swap safety check now correctly simulates group state after removal before testing for new conflicts.
- Removed redundant `shuffle()` before `distribute_to_groups` call.

### v7.2.27
- **Group Championships (draw fix):** Fixed the root cause of same-club entries landing in the same group. The placement phase now explicitly separates groups into *clean* (zero same-club entries) and *dirty* (one or more), and only considers dirty groups when no clean group with space exists. The previous sort-based approach ('fewest same-club, tiebreak by most space') would still route both entries from a 2-entry club into the largest group because the tiebreaker favoured the roomiest group, which was the same one the first entry had just entered.

### v7.2.26
- **Group Championships:** Rewrote `lgw_gchamp_distribute_to_groups` with a three-phase approach: (1) **constraint-first sort** — entries from the largest clubs are placed first to avoid painting into a corner; (2) **club-spread placement** — each entry goes into the group with the fewest same-club members already placed; (3) **repair pass** — after initial placement, any group with two entries from the same club is fixed by swapping one entry with a suitable entry from another group. The fallback cap-violation warning now only fires if violations remain after all repair attempts are exhausted (genuinely impossible to fix given the club distribution).

### v7.2.25
- **Group Championships (draw fix):** Club cap calculation changed from `ceil` to `floor`. For a group of 3, `ceil(3 * 0.5) = 2` was incorrectly allowing 2 entries from the same club (67%); `floor` gives 1 (33%), which is the correct ≤50% interpretation.
- **Group Championships (draw fix):** Added swap-based rescue before the capacity-only fallback. When an entry is stuck (club cap reached in all groups), the algorithm now tries to relocate an already-placed same-club entry to a different group to free a slot, rather than immediately falling back to a cap violation. Eliminates the '3 from one club in a group of 3' scenario in most cases.

### v7.2.24
- **Group Championships (draw fix):** Added date normalisation to the draw algorithm. `dd/mm/yy` and `dd/mm/yyyy` are now treated as identical during preference scoring and satisfaction checking — e.g. `21/6/26` and `21/6/2026` are the same date. Previously a mismatched year format between a day's stored date and an entry's preference date would cause the match to score 1 instead of 3, putting it on equal footing with a location-only match and making the combined date+location preference a coin flip.

### v7.2.23
- **Group Championships (draw fix):** Eliminated spurious "Venue preferences: 0 of N satisfied" warning. The satisfaction check was always emitting a warning whenever any location preference existed, regardless of outcome. It now only counts and reports when there are multiple distinct day locations (the only case where the preference can meaningfully affect placement), and only warns on genuine failures.

### v7.2.22
- **Group Championships (draw fix):** Rewrote the day-allocation algorithm. Previously, location preferences were only considered *after* date-based bucketing, so entries with no date preference (or with a date preference on a different day to their venue) had their location preference silently ignored. The new algorithm scores every entry against every day (date+location match scores highest, either alone scores next, no match scores zero), sorts by score so the most-constrained entries are placed first, then fills remaining slots randomly. Draw warnings now also report how many venue preferences were satisfied.

### v7.2.21
- **Group Championships:** Preferred venue in entry preferences is now a dropdown of the locations set on each competition day, replacing the free-text input. Values are exact matches, consistent with how date preferences work.

### v7.2.20
- **Group Championships:** Added `Location` field to each competition day — stored in `days_config` and carried through to drawn day data.
- **Group Championships:** New **Preference Settings** panel on the edit page — toggle which preference factors (Date, Location) are enabled for each championship. Only active fields appear in the entry preferences table.
- **Group Championships:** Entry preferences now support both `date` and `location` (venue name, plain text). Legacy date-only string preferences are automatically migrated to the new array format on next save.
- **Group Championships:** Draw algorithm extended to respect location preferences: after date allocation, entries are distributed across same-date days by matching their venue preference to each day's `location` field (fuzzy substring match). Unmatched entries fall through to random placement as before.

### v7.2.19
- Fix: Saving a scorecard via the WP post editor "Update" button (reached via the player admin modal link) now correctly syncs score overrides and re-logs appearances, matching the behaviour of the dedicated admin edit form.
- New: Custom round labels for championship brackets (Round Labels textarea in admin, synced to drawn brackets on save).
- Fix: Round Labels hint now shows per-section round count.
- New: Championship bracket highlights current round (▶ NOW); mobile auto-scrolls to current round; section tab switch resets to current round.
- Fix: Tab bar items render at equal height.

### v7.2.15
- New: Finals Week tab — appears when any day KO is done.
- New: Auto-built finals matches (SF+Final or Final depending on qualifiers).
- New: Full finals scoring (datetime, ends, final score) using finals JS/CSS.

### v7.2.15
- New: Tab underline colour picker (Gold, Red, White, etc.).
- New: Group edits on complete days reseed KO bracket (if no KO scores).
- New: Group saves blocked server-side when KO has scores.
- Fix: Reload on KO reseed.

### v7.2.15
- Fix: Dark mode removed — always light theme.
- Fix: KO score entry missing parameters error.
- Improved: Colour preset swatches + custom picker.
- Style: Group headers use accent colour.

### v7.2.15
- Fix: KO bracket auto-seeds on page load for already-complete days.
- New: Per-competition colour picker in admin.
- New: Group lock/unlock for post-completion score edits.
- Fix: Light backgrounds throughout.

### v7.2.15
- Fix: Score refresh only on state change (day complete / KO seeded).
- Fix: 2x2 grid layout for group cards.
- Fix: All backgrounds white/near-white.

### v7.2.15
- Fix: Live standings update after score save.
- Fix: Reload on day_complete.
- Fix: 2-column CSS grid for group cards.
- Fix: Lighter background.

### v7.2.15
- Fix: KO bracket seeds for already-complete days.
- Fix: Fixture team name truncation with ellipsis.
- Fix: Full-width wrap (display:block).
- Style: Red primary colour matching PGL badge.

### v7.2.15
- New: Per-day KO bracket — auto-seeded on day completion.
- New: Day tabs + Groups/Knockout sub-tabs per day.
- New: KO score entry with bracket advancement.
- New: Finals Week qualifier rule (1/2/4 days → 4/2/1 per day = 4 total).
- New: Lighter CSS theme, full-width wrap.

### v7.2.15
- Fix: `day_id` missing from JS score AJAX — caused "Missing parameters" error.
- Fix: Group cards stretch full width; entry names no longer truncated.
- New: Day section header + qualifiers strip CSS.

### v7.2.15
- Revised: Days-as-sections model — each day is an independent section.
- New: Per-day group size, winners/group, best runners-up configuration.
- New: Auto group count from target group size at draw time.
- New: Per-day qualification; qualifiers strip on front end.
- Removed: Old flat `groups_config` model.

### v7.2.15
- Fix: Literal newlines in JS `confirm()` strings broke the Run Draw button with a SyntaxError.

### v7.2.15
- New: Qualification logic — auto qualifiers + best runners-up with tie-break.
- New: `lgw_gchamp_build_knockout()` seeds bracket via `lgw_draw_build_bracket()`.
- New: `lgw_gchamp_seed_knockout` AJAX + admin Seed Knockout Bracket button.
- New: Knockout pane renders full bracket; tab auto-switches on seeding.

### v7.2.15
- New: Inline front-end score entry per fixture (editor/admin).
- New: Admin Group Scores panel with full fixture table per group.
- New: `lgw_gchamp_save_score` and `lgw_gchamp_get_standings` AJAX handlers.
- New: `lgw_gchamp_all_fixtures_played()` sets `group_stage_complete` flag.

### v7.2.15
- New: Full front-end group stage display via `[lgw_gchamp]` shortcode.
- New: `lgw-gchamp.css` and `lgw-gchamp.js` — group cards, standings, fixtures, phase tabs, dark mode, responsive.
- New: `lgw_gchamp_compute_standings()` with points / shots diff / head-to-head sort.
- New: Asset enqueue function `lgw_gchamp_enqueue()`.

### v7.2.15
- New: Group draw algorithm with 3-pass preference allocation and 50% club cap.
- New: Round-robin fixture generation (rotation algorithm, BYE support, home/away balancing).
- New: Run Draw admin button with instant reveal, re-draw confirmation, and warnings display.

### v7.2.15
- New: Entry date preference assignment on group championship admin page.
- New: Bulk-set / clear-all controls for preferences.
- Preferences keyed by entry string, preserved across draw resets.


### v7.2.15
- New: Group Stage into Knockout championship type (Step 1 of 9 — data model + admin config panel).
- New: `[lgw_gchamp id="..."]` shortcode registered.
- New: Group championships appear on Championships admin page with format badge.

### v7.2.15
- Admin scorecard edit: renamed "Date" label to "Date Played"; widened date input so full date is always visible.

### 7.2.15
- **Fix:** Scorecard post edit screen (`post.php?post=X&action=edit`) now shows the full scorecard editor and audit log as meta boxes — previously the `#NNN ↗` link from Player Tracking opened a blank WP post form

### 7.2.15
- **Fix:** Championship player stats — entering a score in a later round no longer overwrites earlier round appearance records; `lgw_log_champ_appearance` now deletes only the row for the specific match position (`match_key`) rather than all champ rows for that player across the entire championship

### 7.1.136
- **New:** Club Summary table — sortable columns (click any header; direction arrow shown)
- **New:** Club Summary table — per-column filter inputs: text search on Club, numeric min/max range on all stat columns
- **New:** Club Summary table — live totals bar above the table updates dynamically as filters/sort are applied, showing sums for Players, Apps, Ladies, Paid, and Balance; tfoot row also reflects filtered rows
- **Fix:** Paid input changes immediately update the Balance cell and totals bar without a page reload

### 7.1.135
- **New:** Player stats popover games list now shows competition (division or championship title) instead of rink number
- **New:** Team chips in the stats popover are clickable — tap a team to filter the games list; "All" resets the filter
- **New:** `lgw_get_player_stats` AJAX response now includes `competition` field on each game record

### 7.1.132
- Fix: championship appearance delete now wipes all rows for `player_id + champ_id` — resolves duplicates on re-save and failed clears from mismatched `match_key` values in earlier versions
- Removed debug logging

### 7.1.126
- Fix: appearance delete covers both `match_key` rows and legacy `match_key IS NULL` rows — no more duplicates from existing data
- `lgw_clear_champ_appearances_by_key()` accepts `$match_title` param for combined delete
- `lgw_log_champ_appearance()` uses combined `OR` condition in delete

### 7.1.125
- Fix: champ appearances use stable positional key (section:round:match) — no more duplicates on re-save, clears work reliably
- `match_key` column added to appearances table; `lgw_clear_champ_appearances_by_key()` helper added
- `lgw_champ_cascade_clear_appearances()` and `lgw_log_champ_appearance()` updated to use positional key

### 7.1.124
- Championship appearance dates normalised to dd/mm/yyyy via new `lgw_normalise_date_dmy()` helper

### 7.1.123
- Championship stats tracking: `Stats Eligible` flag on championship admin logs W/L to Player Tracking
- Player stats popover: 3-tab switcher (League/Cup | Championships | Total) when data spans multiple types
- Championship bracket entries are clickable player-name links opening the stats popover
- `lgw-scorecard.js` popover resolves nonce/ajaxUrl from `lgwChampData` for champ-only pages
- `champ_id` column added to appearances table; `lgw_log_champ_appearance()` / `lgw_clear_champ_appearances()` helpers
- `lgw_ajax_get_player_stats` returns `stats_by_type` (league/cup/champ/total)

### 7.1.121
- **Fix:** Copy as Text — away fixture scores now shown in display order (matched player score first) to match the name order

### 7.1.120
- **Fix:** Section and Round columns hidden correctly on Chromium mobile — `th`/`td` element selectors with `!important` fix Chromium table layout quirk (was already working in Firefox)

### 7.1.119
- **New:** Admin search rows are clickable — closes modal, switches section tab, scrolls bracket to match, flashes gold highlight on the card
- **New:** Admin hint beneath results: "Click a row to go to that match in the draw"
- **Fix:** `section_idx` returned by search AJAX handler for correct section pane targeting
- **Fix:** `game_num` stored on match card `dataset` during bracket render

### 7.1.118
- **Fix:** Search modal in landscape on mobile — collapses chrome, makes entire box scrollable; sticky header and sticky export bar preserve usability without eating screen height

### 7.1.117
- **Fix:** Match display changed to inline "A vs B" format; wraps to vertical only when needed
- **Fix:** Section and Round `<th>` headers now also hidden on mobile, matching their hidden `<td>` cells

### 7.1.116
- **Fix:** Mobile search — input box properly constrained; font-size 16px prevents iOS auto-zoom
- **Fix:** Mobile search — Section and Round columns hidden on small screens to eliminate horizontal scrolling; both remain in all exports
- **Fix:** Mobile search action buttons wrap cleanly at narrow widths

### 7.1.115
- **Improved:** Championship search results split into 🏠 Home Fixtures and ✈️ Away Fixtures groups, each sorted by date with date-row dividers
- **Improved:** Matched entry highlighted in yellow within each group; opponent shown alongside
- **New:** Copy as Text — copies results to clipboard, grouped and dated, ready for social media / WhatsApp
- **New:** Export PDF — opens a print-ready window with sponsor banner; user saves as PDF from browser print dialog
- **Changed:** Export CSV now has H/A column indicating home or away status of the matched entry

### 7.1.114
- **New:** Championship search modal — search fixtures or results by player name or club across all sections and the Final Stage
- **New:** Fixtures mode shows upcoming/undated matches; Results mode shows scored matches; future-dated matches with results appear in both
- **New:** Search results highlight matched entry, group by section, sort by date
- **New:** Print and CSV export for search results
- **New:** 🔍 Search tab button in championship shortcode header

### v7.1.113
- **Fix:** Scorecard modal stuck on "Loading scorecard…" — `lgwFetchScorecard` referenced `opts.context` which is undefined in that function scope, throwing a ReferenceError and preventing the AJAX request from firing; removed the stray reference (context is correctly handled in `lgwFetchScorecardOrSubmit` which is used for the played-fixture path)

### v7.1.112
- **Fix:** Player stats popup now correctly resolves players with apostrophes in their names (e.g. `K O'Neill`) — WordPress magic-quotes were stripping the apostrophe before the DB lookup; fixed with `wp_unslash()` wrapping all relevant `$_POST` reads in `lgw_ajax_get_player_stats`, `add_player`, and `rename_player` handlers
- **Fix:** Stats lookup now passes the name through `lgw_clean_player_name()` to strip any trailing `*` female marker before querying, preventing lookup failures for female-flagged players

### v7.1.111
- **New:** Players admin screen: Club filter (defaults to All Clubs), cascading Team filter dropdown, and Name search — all live client-side with match count and Clear button

### v7.1.110
- **New:** Player stats popover is draggable — a grab-handle bar at the top lets users reposition it freely by mouse or touch; once moved, automatic positioning is suppressed until the popover is closed and reopened

### v7.1.109
- **New:** Player stats popover now includes a full games list for the current season — each row shows the match title, date, rink number, score, and a colour-coded W/D/L badge, ordered newest first; cup games tagged with a type pill
- **Fix:** Popover switched to `position:fixed` with viewport-aware placement — flips above the button when space below is insufficient; inner body is `overflow-y:auto` with a dynamically calculated `max-height` so it never goes off-screen

### v7.1.108
- **New:** Player name links in scorecard modal — clicking a player's name opens a stats popover showing their current-season W/D/L record, total games played, and which teams they have appeared for this season
- **New:** Public AJAX endpoint `lgw_get_player_stats` — returns current-season stats by player name and club, nonce-protected, no authentication required
- **CSS:** Player stats popover with club badge, colour-coded W/D/L tiles (green/amber/red), played total tile, teams-this-season chips; full dark-mode support

### v7.1.107
- **Fix:** Division name missing in scorecard modal after shortcode title change — `divisionTitle` now read from `data-division` attribute instead of `previousElementSibling` (ticker insertion had broken the sibling lookup)

### v7.1.106
- **New:** CSV reference row support — parser detects `homepts`/`home`/`home shots`/`away shots`/`away`/`awaypts`/`time` labels and maps columns directly; time read from explicit index, no scanning
- **Fix:** Legacy fallback (no reference row) breaks on first time match and uses narrowed serial range

### v7.1.103
- **Fix:** Results ticker now shows only scores for the current division and current season; hidden if no matching results
- **Fix:** Ticker positioned inside the widget wrap, below the sponsor banner, full-width and inline with the rest of the widget
- **New:** Added `data-division` attribute to widget element for division-scoped result filtering

### v7.1.101
- **Fix:** Scorecards admin season backfill now correctly reassigns cards tagged to the wrong season (not just untagged ones) — banner appears on all seasons that have date ranges configured, counts scorecards whose match date falls in the season but carry a different season tag, and 'Reassign to this season' button retags them all via date-range matching

### v7.1.100
- **Fix:** Scorecards admin season filter no longer shows previous-season cards in the active season view — the `NOT EXISTS` fallback was pulling in untagged cards from any/all seasons; removed from the main query; untagged card count now comes from a separate dedicated count query; warning banner and backfill button still appear on the active season view

### v7.1.99
- **New:** Scorecards admin page now splits by season — season switcher bar defaults to the active season; archived seasons accessible via pill buttons; list filtered by `lgw_sc_season` meta; active season also shows untagged cards so nothing is accidentally hidden; untagged card warning banner with one-click "Tag all to this season" backfill button; new `lgw_backfill_sc_seasons` AJAX handler uses dual-strategy (tag + date-range fallback)

### v7.1.98
- **New:** Player tracking auto-merges dotted-initial name variants — `lgw_normalise_player_name()` strips dots from single-letter initials (`D. Bintley` → `D Bintley`) before DB lookup; new scorecards never create duplicates; Merge Duplicates tab shows a detected-pairs preview table with a one-click Auto-merge button; keep rule: most appearances wins, ties prefer the already-normalised (no-dot) form

### v7.1.97
- **Fix:** "Skip Google writeback" checkbox now also suppresses Google Drive PDF upload — uses a `lgw_skip_google` post meta flag so Drive's anonymous action hooks are correctly bypassed; checkbox label updated to "Skip Google Drive & Sheets writeback"

### v7.1.96
- **Improvement:** Excel/xlsx parse errors now return actionable diagnostic messages — ZipArchive error codes, missing worksheet details, empty grid diagnostics (sheet name, size, shared string count), and rink-mapping failures include row samples and field detection summary

### v7.1.95
- **New:** Admin scorecard form now includes a "Skip Google Drive & Sheets writeback" checkbox (visible to admins only); use when backfilling historical scorecards to avoid overwriting the live sheet

### v7.1.94
- **Feature:** Player history modal — each appearance row now shows the scorecard ID as a `#NNN ↗` link directly to the WP admin edit screen (opens in new tab), making it easy to inspect, edit or trash test/duplicate scorecards

### v7.1.93
- **Fix:** Backfill missed scorecards tagged to a different/wrong season ID — date-range strategy now scans ALL scorecards (not just untagged ones); match date is the authority, season tag is supplementary

### v7.1.92
- **Fix:** Backfill not picking up untagged scorecards for previous seasons — added date-range fallback scanning scorecards with no `lgw_sc_season` meta against the season's start/end dates

### v7.1.91
- **Fix:** Player stats not recorded when re-saving a scorecard via admin edit — rink scores were stored as `0.0` (not `null`) for empty fields, causing false 0–0 draws; now stored as `null` when blank
- **Fix:** `lgw_log_appearances()` zero-guard — legacy scorecards where all rink scores were `0` (floatval artifact) are treated as score-absent; real 0-scores still honoured when match totals are non-zero
- **Fix:** `lgw_sc_context` (league/cup) now preserved correctly on admin scorecard edits; missing context defaulted to `league` rather than empty string

### v7.1.90
- **Feature:** Player statistics — Wins, Draws, Losses, Shots For and Shots Against now tracked per appearance (rink level) for both League and Cup games
- **Feature:** Admin player list table gains W/D/L, SF–SA, League W/D/L and Cup W/D/L columns per player for the current season view
- **Feature:** Player history modal upgraded — stats summary table (Total / League / Cup) at top; per-game rows show rink score, coloured W/D/L badge, Cup label badge, and full match score
- **Feature:** Excel export gains a new **Stats** sheet with full per-player breakdown; per-club matrix sheets gain W/D/L, SF and SA columns
- **Improvement:** DB migration auto-adds `shots_for`, `shots_against`, `result`, `game_type` columns to existing installations; `game_type` back-filled from scorecard context meta
- **Improvement:** `lgw_log_appearances()` now reads rink-level scores and `lgw_sc_context` meta to store all stats atomically with each appearance row

### v7.1.87
- **Fix:** Fixture time note (e.g. 5:30) now correctly displayed for all divisions; scan range extended past APts column and `HH:MM:SS` format normalised to `HH:MM`

### v7.1.86
- **Fix:** Player tracking — female status from confirmed scorecards (asterisk-marked players) now correctly saved to player record; new `lgw_ensure_female_flag()` upgrades `false→true` only, never resets manual edits
- **Fix:** Player tracking — toggling the female checkbox no longer incorrectly sets the starred flag; `update_flags` handler now reads actual submitted field values instead of `isset()` check
- **Feature:** Player tracking — new **Club Summary** tab with per-club player count, appearances, ladies, and admin-editable Players Paid field with balance (paid − played); exportable as XLS spreadsheet or print-ready PDF

### v7.1.84
- **Feature:** Championship — Rename Entry tool on the edit page lets you correct spelling mistakes in entries after a draw has been done, without resetting the draw or any scores

### v7.1.82
- **Fix:** Live points hint in scorecard modal used `parseInt` — half-point values (e.g. 2.5 + 4.5) showed total as 6 instead of 7; fixed to `parseFloat` with tolerance comparison

### v7.1.81
- **Fix:** Rink score inputs (modal and standalone form) now have `step="0.5"` so browsers accept half-scores without rounding
- **Fix:** Auto-sum of rink scores rounds to 1 decimal to prevent float accumulation noise
- **Fix:** Scorecard admin page stripped half-points — all scores, totals and points now use `floatval`; admin number inputs gain `step="0.5"`
- **Fix:** Points validation uses `parseFloat` and tolerance comparison throughout

### v7.1.80
- **Fix:** Drive upload now respects `submitted_for` — PDF saved to that team's folder only when submitting for one team
- **Fix:** Resubmitting a scorecard replaces the existing PDF in Drive rather than creating a duplicate; admin edits still produce versioned copies

### v7.1.79
- **Feature:** Sponsor logo now appears bottom-right in the print/PDF output for both cup and championship draws
- **Fix:** Cup print layout on desktop Chrome/Chromebook now uses the same spreadsheet-style layout as championship — R0/R1 as side-by-side columns, later rounds compact below — eliminating clipped or missing matches in Chrome print preview

### v7.1.78
- **Feature:** Cup and Championship bracket draws on mobile now support horizontal swipe scrolling — all rounds sit side-by-side with `scroll-snap` for smooth swiping between them
- **Feature:** Tapping a round header in the bracket scrolls forward to the next round (wraps to first); the tab bar stays in sync as you swipe via `IntersectionObserver`

### v7.1.77
- **Feature:** Championship bracket draws now show potential opponents in TBD slots — displays the last player's surname and abbreviated club name (e.g. `Hinds, Sha/Maxwell, Nor`) matching the cup bracket style

### v7.1.76
- **Feature:** Championship draws now enforce strict same-club separation — a multi-pass algorithm guarantees players from the same club are never drawn against each other in the first round (graceful fallback only when separation is mathematically impossible)
- **Feature:** Admin draw editor — after a section is drawn, an **✏️ Edit Draw** button on the admin edit page reveals a bracket table; any first-round match participant can be swapped via dropdown; saving clears the match score and cascades resets through all downstream rounds, and unseeds the Final Stage so it can be redrawn

### v7.1.73
- Scorecard photo upload on mobile now prompts the user to choose between 📷 Take a photo (camera) or 🖼️ Choose from gallery / files instead of immediately launching the camera — desktop behaviour (file picker) unchanged

### v7.1.72
- **Settings:** Merged "Clubs & Passphrases" and "Club Badges" into a single "Clubs & Badges" table — passphrase and badge fields now on one row per club

### v7.1.71
- **Fix:** Duplicate season switcher bar removed from Player Tracking admin

### v7.1.70
- **Feature:** Archived seasons now support start/end date fields — set via the Seasons admin edit or Add Historical Season forms
- **Feature:** Each archived season row has a **👥 Players** link (opens Player Tracking filtered to that season) and a **🔄 Backfill Players** button (re-logs appearances for all confirmed scorecards tagged to that season)
- **Feature:** Player Tracking admin accepts `?season=ID` URL param — all appearance counts, the export, and the Season Settings tab reflect that season's date range
- **Feature:** Season switcher bar added above the Players tabs — pill buttons for every season, active season marked with ●
- **Feature:** Page title shows the archived season name when viewing one (e.g. "Player Tracking — 2025 Season")
- **Feature:** Export to Excel passes the season ID through so the downloaded file matches what is on screen

### v7.1.69
- **Feature:** Season start/end dates moved to Seasons admin — label, dates, and divisions now all managed in one place
- **Change:** `lgw_get_season()` reads from the active season in `lgw_seasons`; legacy `lgw_season` option used as fallback for existing installs
- **Change:** Player Tracking "Season Settings" tab is now a read-only summary with a link to Seasons admin

### v7.1.68
- **Fix:** Sheets writeback (`lgw_sheets_write_result`) now finds the fixture row even when the match was played on a rescheduled date — tries the fixture date first, then falls back to matching by team names only; logs a note when the fallback is used
- **Fix:** Override key now uses the fixture date read directly from the published CSV (by matching the home/away team pair in the fixture list), not the played date on the scorecard — fixes cases where a match was rescheduled

### v7.1.66
- **Fix:** Confirmed scorecards now update the widget score immediately — `lgw_sync_override_from_scorecard()` was silently bailing when the division had no `csv_url` in `sheets_tabs`; now falls back to the active season division config
- **Fix:** `lgw_sync_override_from_scorecard()` now logs success/failure to the per-scorecard sheets log; visible in the History panel
- **Feature:** "Force sync widget override" button added to every scorecard's History panel
- **Fix:** Deleting or trashing a scorecard removes all associated player appearance records and prunes orphaned player entries
- **Feature:** Player names on the Player Tracking admin page are now clickable — opens a modal showing every game the player appeared in

### v7.1.52
- **Fix:** Season switcher now matches archived divisions to the shortcode `title` even when the title includes a trailing year
- **Fix:** Seasons admin — editing an existing archived season no longer triggers "season already exists" error
- **New:** Cup admin: "Download Draw (.xlsx)" export button on cup edit page
- **New:** Championship admin: "Download Draw (.xlsx)" export button on championship edit page
- **New:** `lgw-export.php` — pure-PHP xlsx generation via ZipArchive, no server dependencies

### v7.1.48
- **Fix/Cleanup:** Consolidated duplicate `lgwClubMatchesTeamStr` into `lgwClubMatchesTeam` — null guard added, all call sites updated
- **Fix:** Scorecard modal: Date Played field now displays in the same format as the fixture date after blur; normalised back to dd/mm/yyyy on save

### v7.1.27
- Multi-season archive and front-end season switcher
- New Seasons admin page: manage active season, archive, backload historical seasons
- `[lgw_division seasons="2025,2024"]` or `seasons="all"` dropdown to switch between seasons
- Scorecards stamped with `lgw_sc_season` post meta; archive back-fills untagged scorecards
- New file: `lgw-seasons.php`

### v7.0.0
- Plugin rebranded from `nipgl-division-widget` to `lgw-division-widget`
- All option/meta prefixes migrated from `nipgl_` to `lgw_`
- All shortcodes renamed to `[lgw_*]`
- One-time DB migration with rollback capability

### v6.0.0
- **Cup bracket widget** — new `[lgw_cup id="…"]` shortcode renders a full single-elimination knockout bracket
- **Live animated draw** — admin triggers the draw; visitors see an animated team-reveal sequence live via polling
- **Cup management admin** — LGW → Cups page to create cups, enter team lists, set round names/dates
- New files: `lgw-cup.php`, `lgw-cup.js`, `lgw-cup.css`

### v5.9
- Player tracking system — appearances auto-logged from confirmed scorecards
- Players grouped by club, showing which teams they've played for and appearance count
- Season date range configuration, admin merge tool, export to Excel

### v5.1
- Full scorecard submission system — `[lgw_submit]` shortcode
- PIN-gated entry (no WordPress login needed)
- AI photo reading (Anthropic API) pre-fills form from photo
- Excel upload parsing for LGW scorecard template

---

## License

GPLv2 or later
