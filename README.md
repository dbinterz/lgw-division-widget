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

## [2026.31.7]
### Changed
- **Multi-competition bulk entry — one club, one basket, one payment.** `[lgw_champ_bulk_entry champ="a,b,c"]` now accepts a comma/space list of championships and renders a single form: one club selector, one contact field, a per-competition `entries[<champ>]` textarea (only competitions whose entry window is open are shown; closed ones listed as skipped), and one global **"Enter & pay"** button. `lgw_ajax_entry_bulk_submit` reads `champs` (with legacy single `champ`/scalar `entries` fallback), assigns **one shared batch id across all competitions**, creates ledger rows per comp (free → confirmed+projected+emailed immediately; paid → `pending_payment`), then opens a **single** Stripe Checkout over every paid entry via `lgw_entry_stripe_create_batch_session()`. That session now titles each line item by the entry's own championship (per-entry `title_cache`) so the receipt itemises across competitions. Guard: mixed currencies across paid comps are rejected (one Stripe session = one currency). Admin notification consolidated into `lgw_entry_notify_admins_bulk_multi()` — one email grouped by competition. Webhook confirmation (`lgw_entry_stripe_webhook_finish_batch`) was already batch-keyed and champ-agnostic, so it confirms the whole multi-comp batch unchanged. Single-championship usage is unchanged.
- **Live basket total on the bulk form.** Each competition textarea carries `data-fee`/`data-cur`/`data-need`; inline JS counts valid entries (mirroring `lgw_entry_parse_bulk` — newline units, singles comma-split, teams needing N `/`-separated members), renders a per-competition subtotal, sums fees per currency into a basket line, and reflects the grand total in the submit button label ("Enter & pay £43.00"). Recomputes on every keystroke; no server round-trip.

### Fixed
- **Entry-form theming.** Entry cards (`[lgw_champ_entry]` / `[lgw_champ_bulk_entry]`) now carry a `lgw-entry-card` class pinned to the light palette in `lgw-scorecard.css` (mirroring the existing `.lgw-ca-box` rule), so `@media(prefers-color-scheme:dark)` no longer flips only the card to `#1e1e2e` against a light page (which also left grey helper text unreadable). Entry cards are pinned to the plugin's **Saira** font (`.lgw-entry-card` selectors), and both entry shortcodes now enqueue the `lgw-saira` Google Font (and add it as a `lgw-scorecard` style dependency) so entry-only pages actually load it — previously the bulk textareas were `monospace` and the rest of the card fell back to the site theme font (Manrope).

## [2026.31.6]
### Added
- **Reactivate an archived season.** New `lgw_revert_season` POST handler in `lgw-seasons.php` + a "⏮ Make Active" button on each archived-season row. Demotes the current active season to archived (`active => false`) and promotes the chosen season (`active => true`), then re-sorts (active first, then descending by ID) and calls `lgw_seasons_sync_to_drive()` so Drive/Sheets writeback and Quick Score Entry follow the reactivated season's divisions. Guarded: the target must exist and be archived (never the active one); scorecard `lgw_sc_season` tags are left untouched (no un-stamping). Confirmation dialog on click; `reverted=1` success notice; `not_found` error path.

## [2026.31.5]
### Added
- **Bulk club entry (club admins only).** New `[lgw_champ_bulk_entry champ="..."]` shortcode + `lgw_entry_bulk_submit` AJAX. `lgw_entry_parse_bulk()` parses a textarea: entries newline-separated (singles also comma-separated); team members within an entry separated by `/` → mapped to the canonical `A & B, Club` via `lgw_entry_build_string()`. Gated by `lgw_entry_user_may_bulk()` (approved club admin via `lgw_user_can_submit_for()`, or `manage_options`); the club picker only lists clubs the user administers. Duplicates and wrong-member-count lines are skipped and reported. Free champs confirm + project immediately; **paid champs use one combined Stripe Checkout for the whole batch** — `lgw_entry_stripe_create_batch_session()` (one line-item per entry, tagged with a `lgw_entry_batch` id), and `lgw_entry_stripe_webhook_finish_batch()` confirms every pending row in the batch together after re-verifying the paid total against the **sum** of the batch's ledger amounts (idempotent, client-amount never trusted).
- **Player-list validation / capitation warning.** `lgw_entry_unknown_players()` checks each entered name against the club's tracked players (`lgw_players` table, matched via `lgw_clean_player_name()`); unmatched names are returned to the entrant as a non-blocking warning ("unregistered players may affect your club's capitation fees") and audit-logged on the entry. Applies to both single and bulk entry. Degrades to a no-op if the players module is absent, so it never blocks entry.

## [2026.31.4]
### Added
- **Common championship engine abstraction.** New module `lgw-champ-engine.php` introduces the `LGW_Champ_Engine` interface with two adapters — `LGW_Gchamp_Engine` (new group-knockout store `lgw_gchamp_<id>`) and `LGW_Champ_Engine_Legacy` (section-bracket store `lgw_champ_<id>`) — plus a resolver `lgw_champ_engine($id)`, `lgw_champ_engine_list()` and shared `lgw_champ_engine_norm()`. Callers speak one vocabulary (`exists`/`get`/`draw_started`/`append_entry`/`remove_entry`/`list`) and never branch on the storage back-end. Adding a future engine = one new class, zero caller edits.
### Changed
- **Entry form now supports the legacy champ engine, not just gchamp.** `lgw-entry.php` was hard-wired to `lgw_gchamp_*`; the real NIPGL championships run on the legacy `lgw_champ_*` engine, so `[lgw_champ_entry champ="..."]` returned "Championship not found" for all of them (#28). `lgw_entry_get_champ()`, `lgw_entry_project()`, `lgw_entry_unproject()`, `lgw_entry_norm()` and the admin championship picker now route through `lgw-champ-engine.php`. Legacy engine: appends to `entries[]` pre-draw only (draw-started detected via `section_*_draw_version` / `*_draw_in_progress` / `final_*`); sections are **not** rebuilt on append (the champ draw step rebuilds from `entries[]`, and `lgw_champ_build_sections()` shuffles); no preferences (old engine has none). `lgw-champ.php`/`lgw-gchamp.php` are unchanged — the adapters wrap their storage from outside.

## [2026.31.3]
### Added
- **Championship entry form + ledger.** New module `lgw-entry.php` introduces the `lgw_entry` CPT (the ledger: players, club, discipline, status `pending_payment|paid|confirmed|withdrawn|refunded`, amount, payment ref, audit log) and the `[lgw_champ_entry champ="..."]` front-end shortcode. Players self-enter (login-gated) instead of an admin pasting into the gchamp textarea. A confirmed/paid entry is **projected** into the existing `lgw_gchamp_<id>.entries[]` (+ `entry_preferences` keyed by the entry string) by `lgw_entry_project()`, so the draw/bracket engine is untouched. Pre-draw only; post-draw entries are flagged for manual placement.
- **Per-club entry policy.** Each club record gains an `entry_policy` (`open` = players may self-enter | `club_admin` = approved club admins only), resolved by `lgw_entry_policy_for_club()` with precedence: per-champ override → per-club → league default option `lgw_entry_default_policy`. Approved-admin checks reuse `lgw_user_can_submit_for()`.
- **Stripe Checkout (raw REST, no SDK).** When a champ has a fee, `lgw_entry_stripe_create_session()` creates a Checkout Session via `wp_remote_post` and the entrant is redirected to hosted checkout. A `register_rest_route('lgw/v1','/stripe-webhook')` endpoint verifies the `Stripe-Signature` HMAC manually, re-checks amount/currency, and flips `pending_payment → paid` idempotently. Secrets via options or `LGW_STRIPE_SECRET_KEY` / `LGW_STRIPE_WEBHOOK_SECRET` constants. Free entries confirm immediately; admins can mark paid offline.
- **LGW → Entries admin screen.** Per-champ entries list with Confirm / Mark paid / Withdraw / Refund row actions, per-champ fee/deadline/capacity/policy config (option `lgw_entry_cfg_<champ>`, kept separate from the gchamp option), and a Stripe/policy settings box.

## [2026.31.2]
### Fixed
- **Orphaned concessions could not be cleared from the fixture modal.** The normal concession clear path is keyed on `home||away||date`; postponing + rescheduling a conceded fixture changes the row date, so the modal's computed key no longer matched the concession's stored key (original date). Result: the overlay entry (`lgw_concessions`), auto-created 50-0 scorecard (`lgw_sc_concession=1`), score override (`lgw_score_overrides`) and cached fixture were stranded with no UI to remove them (e.g. a phantom confirmed result showing 0-0).
### Added
- **Force-clear concession** admin button in the fixture modal (played and unplayed views). New AJAX `lgw_force_clear_concession` (nonce `lgw_submit_nonce`, `manage_options`) matches by team names across **all** dates and wipes every concession artifact for the pair — overlay entries, concession scorecards, score overrides — then wipes the cached fixture back to unplayed. Idempotent; page reloads on success.

## [2026.31.1]
### Added
- **Conditions of Play tab** on `[lgw_finals]` (📜). A fourth tab beside competition / date / scoreboard rendering admin-editable rich text, stored **per season** as option `lgw_finals_conditions_<season>` and seeded from `lgw_finals_conditions_default()` (IBA Championships Stage 1 & 2 Conditions of Play 2026). Admins get an inline edit toggle → textarea → AJAX `lgw_finals_save_conditions` (nonce `lgw_finals_nonce`, `manage_options`, `wp_kses` against `lgw_finals_conditions_allowed_html()` — `h3/h4/p/ul/ol/li/strong/em/a` etc.). Tab choice persists in `localStorage` alongside the existing sort.

## [2026.31.0]
### Changed
- **Ghost unlit-segment now visible on completed finals.** The `::before "88"` ghost applied to both live and final all along, but `color: inherit` at 13% washed out the green final digits. Switched to explicit rgba — amber `rgba(255,107,26,.16)` for live, green `rgba(72,224,138,.2)` for final — so finished games keep the lit-panel look.

## [2026.30.23]
### Added
- **DSEG7 seven-segment webfont on the LED scoreboard** (SIL OFL, bundled under `fonts/dseg/`; two `@font-face` weights, `url()` resolved relative to `lgw-finals.css`). Digits render as a real 7-seg display, with a **ghost unlit-segment** layer — a faint all-lit `88` via `::before` at 13% opacity behind the live/final numbers.
- **Club names on the scoreboard** — a sub-line under each player/team name (parsed from the `Name, Club` string), uppercase grey.
### Changed
- **Long team names wrap** on the board instead of one-line ellipsis. `<wbr>` break points injected after each `/`, so pairs/triples split player-per-line under a tight card; `.lgw-finals-led-team` uses `overflow-wrap: anywhere`.

## [2026.30.22]
### Added
- **LED scoreboard view on the Finals Week page.** New third tab (`📟 Scoreboard`) beside "By competition" / "By date & rink" renders an old-style dark-panel LED board of summary scores across every scheduled match — live matches first (amber glowing digits, pulsing `● LIVE · END n`), then upcoming, then finished (green `FINAL`). Score cells carry stable ids (`#lgw-led-<mid>-h/-a/-s`) and refresh through the same update path as the rest of the page: admin saves and the public 30s poll both flow through `updateScoreBlock()`, which now mirrors totals onto the board via `updateLed()`. Tab choice persists in `localStorage` alongside the existing sort. Pure-additive: no scoring/data-model changes.

## [2026.30.21]
### Added
- **Live finals scoring is now an ordered log of checkpoints + ends** (freely mixable). Two item kinds stored in `$match['ends']`:
  - **end** — a delta pair `[h, a]` (`+ Add end`); running += delta, end# = prev + 1.
  - **checkpoint** — `⚡ Update score`: an *absolute* score declared at end N, stored as the delta needed to reach it (`['0'=>dh,'1'=>da,'sum'=>1,'end'=>N]`), so `sum(item[0]/item[1])` is still the running total everywhere (poll, complete, finals-started). The running total *jumps* to a checkpoint's absolute; it is not a new base to re-add ends onto.
  - Example: Update 14-10@12 → Add 3-0 (17-10, end 13) → Update 18-14@16 (jump, new line) → Add 0-2 (18-16, end 17).
  - New helpers `lgw_finals_score_items()` (reads the log; migrates legacy 2026.30.20/.21 `live_home/away/ends` baseline into a leading checkpoint) and `lgw_finals_fold_items()` (folds to display rows + running totals + current end). `apply_end_action`/`scoring_response`/`render_ends_table`/`render_scoring_area` all rebuilt around them; `set_total` appends a checkpoint delta; `reset` clears the log.
### Changed
- **Rendering fully centralised server-side.** The scoring area (table + toolbar) is produced only by `lgw_finals_render_scoring_area()` and returned as an HTML fragment by the `save_end` handlers **and the live poll**; the client-side `renderEndsTable()` mirror is removed. `updateScoreBlock()` uses the server's authoritative totals. Poll now returns `html` + `homeTotal/awayTotal/curEnd/isLive`.
### Fixed
- Adding an end no longer drops earlier scoring from the displayed total (superseded by the log model + server-authoritative totals).

## [2026.30.20]
### Added
- **Summary (quick-score) live mode + Reset, for all finals competitions.** A match can be scored end-by-end (detailed) **or** by overall updates (⚡ Quick score, stored as `live_home`/`live_away` on the match slot, mutually exclusive with `ends`). New **↺ Reset** clears all ends / the summary score back to "not started" (schedule untouched). Toolbar: `+ Add end`, `⚡ Quick score`, `↺ Reset`, `✓ Complete game`.
- The scoring-area markup is now produced by one server helper `lgw_finals_render_scoring_area()` (three states: detailed / summary / not-started), and the `save_end` AJAX handlers (gchamp + standard) return that HTML fragment plus totals via shared helpers `lgw_finals_apply_end_action()` + `lgw_finals_scoring_response()`, so the browser swaps in the fragment instead of rebuilding it — no client/server divergence. `end_action` gains `reset` and `set_total`; the render/score-block/status-class use a unified `$is_live` (ends **or** summary total).

## [2026.30.19]
### Fixed
- **"Enter final score" button dead after starting live scoring.** In `saveEnd()` the order was: re-render ends table → `bindMatchButtons()` → `updateScoreBlock()`. But `updateScoreBlock()` replaces the score-block markup (including the edit-score ✏️ button), so the freshly-created button was never bound and looked disabled. Now `updateScoreBlock()` runs first and `bindMatchButtons()` binds the whole match element afterward.

## [2026.30.18]
### Fixed
- **Rename dropdown now surfaces names lingering only in the finals.** `lgw_gchamp_collect_all_entries()` (the source for the rename dropdown) didn't scan `finals_matches` or `days[*]['ko_qualifiers']`, so an entry renamed **before** 2026.30.17 — whose old spelling survived only in those finals structures — couldn't be selected to correct it. Both are now collected, so the old name reappears in the dropdown and a resave (old → correct) patches the leftover finals copies (2026.30.17 already renames those structures on save).

## [2026.30.17]
### Fixed
- **Group Championship entry rename now propagates to the Finals Week bracket.** `lgw_ajax_gchamp_rename_entry` renamed entries, group fixtures, per-day `ko_bracket`, `days[*]['qualifiers']`, and the top-level bracket/qualifiers — but not `days[*]['ko_qualifiers']` (the name source `lgw_gchamp_finals_slots()` seeds from) nor `finals_matches[*]['home'/'away']` (the finals snapshot the finals page reads directly). A corrected name therefore updated the group stage but still showed the old spelling in the finals. Both are now renamed (finals via the existing flat-matches helper). Verified: a rename of an over55-pairs entry updates 1 finals slot + 1 ko_qualifiers entry that previously stayed stale.

## [2026.30.16]
### Added
- **Per-match disc override.** Beyond the whole-championship convention, admins can set a per-side disc colour for a single match from that match's date/time editor. Each side has a select with a `— Default —` option (empty = inherit the convention). Stored as `finals_disc_home` / `finals_disc_away` on the individual match slot; the extended `save_datetime` handlers (both gchamp in `lgw-gchamp.php` and standard in `lgw-finals.php`) persist it and return the resolved effective colours, which the JS uses to swap the chips live.
### Changed
- **Disc convention + override now work on every finals competition, not just Group Championships.** The bulk header control and per-match overrides are available for standard championship finals (singles/pairs/triples/fours/…) too. The bulk control posts to a new generic `lgw_finals_set_discs` handler (verifies `lgw_finals_nonce`) that routes to `lgw_gchamp_<id>` or `lgw_champ_<id>` via an `is_gchamp` flag; the gchamp-only `lgw_gchamp_finals_set_discs` handler from 2026.30.15 is removed in favour of it.

## [2026.30.15]
### Added
- **Disc-colour chips on Finals Week matches.** Each side shows a coloured dot + label (e.g. 🔴 Red / 🟡 Yellow) so viewers can identify entries on the green. Convention is set per championship (bulk) from the Finals page header via an admin "Discs: Home […] Away […] — Apply to all" control, stored as `finals_disc_home` / `finals_disc_away` on the gchamp option and applied to every match. Defaults Red (home) / Yellow (away). Palette: red, yellow, blue, green, orange, brown, black, white, pink. New AJAX handler `lgw_gchamp_finals_set_discs` (verifies `lgw_gchamp_score` nonce, validates slugs against `lgw_finals_disc_palette()`).
### Fixed
- **Club badges now display on Finals Week matches.** The lookup lowercased the parsed club name but compared it against `lgw_club_badges` keys stored in original case (`N.I.C.S.`, `Ards`), so no badge ever resolved. A lowercased key map is now built once for a case-insensitive match.

## [2026.30.14]
### Fixed
- **"Match not found" (still) on gchamp finals edits + dead "by competition / by date" sort tabs — a syntax error introduced by 2026.30.13.** The 2026.30.13 edit wrapped the finals body inline script but left a **duplicate, unclosed `(function(){`** before the existing sort IIFE (double-open, single close). The whole `<script>` failed to parse (`Uncaught SyntaxError: Unexpected end of input`), so nothing in it ran — including the `window.__lgwFinalsBoot` assignment (the 2026.30.13 fallback) **and** the sort/view toggle wiring. Result: the match map fallback was never set (`__lgwFinalsBoot` undefined, `lgwFinalsData.matches === {}`), gchamp saves fell to the standard handler with the prefixed `champ_id` again, and the sort tabs stopped switching. Confirmed live via browser console (`boot: undefined | matches: 0`) and the real POST capture. Fix: removed the stray wrapper so the block parses; both the save-routing fallback and the sort toggle work again.

## [2026.30.13]
### Fixed
- **"Match not found" on gchamp finals edits — the operative bug (init-order clobber).** The match map was assigned to `lgwFinalsData.matches` from a body `<script>`, but `wp_localize_script` prints `var lgwFinalsData = {…}` in the footer, **redefining the object and wiping `.matches`** before `lgw-finals.js` captures it (`var matches = lgwFinalsData.matches`). With `matches === {}`, every lookup missed, so `m.isGchamp` was falsy and every gchamp save took the standard-champ branch — posting the mid-derived prefixed `champ_id` (`gchamp_<id>`) → "Match not found". Verified by capturing the real browser POST (`action=lgw_finals_save_datetime`, `champ_id=gchamp_nipgl-over55-pairs-2026`). Fix: also stash the map (+ nonce/isAdmin) on a dedicated `window.__lgwFinalsBoot` global that the footer localize never touches; `lgw-finals.js` falls back to it. Order-independent. (`wp_add_inline_script('before')` was tried first but the theme prints the footer script before the shortcode's late inline attaches.) The 2026.30.11 render-persist and 2026.30.12 routing-field fixes were correct but masked by this.

## [2026.30.12]
### Fixed
- **"Match not found" — root cause — on gchamp finals date/time/rink/score/end saves from `[lgw_finals]`.** The localized JS match map (`$all_js_data`) omitted the gchamp routing fields, so `lgw-finals.js` took the standard-champ branch and posted `champ_id` as the mid-derived `"gchamp_<id>"`. The gchamp AJAX handlers prepend `lgw_gchamp_`, yielding `lgw_gchamp_gchamp_<id>` — a non-existent option — hence the failed `match_idx` lookup. The map now emits `isGchamp`, the **bare** `_gchamp_id` as `champId`, and the `lgw_gchamp_score` nonce, so `m.isGchamp` routes to the gchamp handlers with the correct id/nonce. (The 2026.30.11 render-persist fix was necessary but not sufficient — this was the operative bug.) The localized JS match map (`$all_js_data`) omitted the gchamp routing fields, so `lgw-finals.js` took the standard-champ branch and posted `champ_id` as the mid-derived `"gchamp_<id>"`. The gchamp AJAX handlers prepend `lgw_gchamp_`, yielding `lgw_gchamp_gchamp_<id>` — a non-existent option — hence the failed `match_idx` lookup. The map now emits `isGchamp`, the **bare** `_gchamp_id` as `champId`, and the `lgw_gchamp_score` nonce, so `m.isGchamp` routes to the gchamp handlers with the correct id/nonce. (The 2026.30.11 render-persist fix was necessary but not sufficient — this was the operative bug.)

## [2026.30.11]
### Fixed
- **"Match not found" when setting date/time/rink on a gchamp final from the public `[lgw_finals]` page.** `lgw_finals_render()` rebuilds `finals_matches` on render when the qualifier count has grown, but only into the local `$val` — it was never persisted, so `lgw_ajax_finals_save_schedule()` (and the score/end handlers) re-read an empty/stale `finals_matches` from the stored option and failed the index lookup. The rebuild now stamps `finals_q_count_at_build` and persists via `update_option()` so stored and rendered brackets stay in sync.

## [2026.30.10]
### Added
- **Finals Week draw editable on the public `[lgw_finals]` page** for admins (was pane-only). `lgw-finals.php` renders the shared draw controls on pending gchamp slots; `lgw-finals.js` gained the seed + occupant handlers (using a localized `gchampNonce` for the gchamp AJAX actions).
- **Combined occupant control for mixed rounds (semi-finals/final).** Each such slot lists all its round's occupants in one dropdown — named byes AND "Winner of QFx" feeds — so byes and winner-feeds can be permuted freely (e.g. drop a bye into a "Winner of QF" slot). Backed by a general `finals_slotmap` (`"layoutIndex:side" => {seed:k|win:j}`) applied in `lgw_gchamp_build_finals_matches()`; `lgw_gchamp_finals_occupant_groups()` computes per-round token sets; new AJAX `lgw_gchamp_finals_set_occupant` swaps within a round. Pure-seed rounds (QFs) keep the qualifier-pool seed dropdown.

### Changed
- The 2026.30.9 winner-reroute (`finals_winlinks` / `set_winlink`) is superseded by the combined occupant control; `finals_winlinks` is still read for backward compatibility.

## [2026.30.9]
### Added
- **Manual Finals Week reroute (offline draws).** Each prev-linked slot ("Winner of QFx") gets an admin dropdown to choose which earlier match's winner feeds it. New AJAX `lgw_gchamp_finals_set_winlink` stores a `finals_winlinks` map (`"layoutIndex:side" => prevMatchIndex`); `lgw_gchamp_build_finals_matches()` applies the override when wiring prev-links. Swaps are constrained to slots fed from the same round (bracket stays a valid permutation) and rejected once any finals score/live end exists. `$winlink_ctrl` renders the control on placeholder slots; JS handler mirrors the seed one.

## [2026.30.8]
### Fixed
- **Day KO final scoreable on no-final days.** `ko_complete` goes true at the semi stage for `fq==2` no-final days, which was hiding every day-KO score box (final included) and locking the semis. Render now uses `$ko_locked = $ko_complete && ! $final_not_played`, so no-final days keep semis editable and expose an optional final score box (scoring it doesn't change the already-confirmed qualifiers).
- **Finals draw stays adjustable after names resolve.** The per-position seed dropdown was only emitted inside the `pending` branch, so once slots resolved to names (e.g. all 6 QF positions) they lost their move control. Extracted a `$seed_ctrl` closure rendered on both placeholder and resolved seed slots, gated on `! $finals_started` (no score/live end entered anywhere in the bracket). JS seed toggle now locates its form via the shared parent rather than `.lgw-finals-ph-slot`.
- **`[lgw_finals]` refreshes placeholders to names.** Front-end now rebuilds `finals_matches` when the live qualifier count differs from `finals_q_count_at_build` (not only when empty), so confirmed names replace source-label placeholders without waiting for an admin to open the Finals tab. Rebuild preserves entered scores/schedule.

### Changed
- Manual seeding dropdown options lead with the resolved qualifier name (`Name — Source label`) instead of the source label alone.

## [2026.30.7]
### Added
- **Setting: "Play a day-final for 2-qualifier days"** (`ko_play_day_final`, off by default). For days configured with 2 finals qualifiers, both finalists advance to Finals Week once the semi-finals are decided; the day final is a Finals-Week fixture, not a day game. Turning it on plays a ranking day-final (winner seeded first). Threaded through `lgw_gchamp_ko_qualifiers_complete()` and `lgw_gchamp_compute_ko_qualifiers()` via a `$play_final` argument; the day KO view marks the final "Played at Finals Week" and omits its score entry.

### Fixed
- **2-qualifier days now qualify the two finalists (both semi winners).** Previously `compute_ko_qualifiers()` required the day final to be *scored* (final winner + runner-up), so an unplayed final produced no qualifiers and a hand-set list could surface a semi-final loser as a qualifier. `finals_qualifiers === 2` with the setting off derives qualifiers from the resolved final slots and completes at the semi-final stage.

### Regression
- `tests/manual/gchamp-knockout-regression.php` Test 8: fq2 no-final completion/qualifiers (semi losers excluded), incomplete-until-slots-resolved, and legacy play-final-on behaviour.

## [2026.30.6]
### Added
- **Finals Week draw available before the knockouts finish.** The Finals Week tab now shows as soon as a group championship is drawn and uses an internal KO (was gated on `has_any_ko_complete`). `lgw_gchamp_finals_slots()` derives every expected qualifier from the day config, so all slots exist immediately as source-labelled placeholders ("21 June Ards Winner", "Winner of QF1") and resolve to real names as each day's KO confirms them.
- **Seeded, size-appropriate finals bracket.** `lgw_gchamp_build_finals_matches()` reworked to seed a single-elimination bracket rounded up to the next power of two with the top seeds byed to the later round (e.g. 6 qualifiers → 8-slot QF→SF→Final, seeds 1–2 bye to the semis). 2- and 4-qualifier champs still produce Final / SF+Final. Winners propagate via `lgw_gchamp_finals_propagate_matches()`.
- **Manual Finals Week seeding.** Per-position admin dropdown (`lgw_gchamp_finals_set_slot`) assigns which qualifier source occupies each first-round draw slot, swapping it with the current occupant; the draw is stored as `finals_draw` (a permutation of the sources) and the bracket rebuilt from it.
- **`[lgw_finals]` sort toggle.** "By competition" (grouped, as before) or "By date & rink" (flat chronological schedule across all competitions, unscheduled matches last), remembered per season in `localStorage`.

### Regression
- `tests/manual/gchamp-knockout-regression.php` extended: 8-slot finals build, placeholder resolution, manual source→position swap, QF→SF propagation, and 4-qualifier backward-compat.

## [2026.30.5]
### Fixed
- **Manual knockout seeding rejected valid qualifiers** with "That entry is not a qualifier for this day", despite the dropdown only offering that day's qualifiers. `lgw_gchamp_ko_set_slot` ran the posted value through `sanitize_text_field()`, which collapses the runs of whitespace in entry names (`"Name,    Club"` → `"Name, Club"`), so the strict `in_array()` check against the stored qualifier pool never matched. Validation now compares whitespace-insensitively and assigns the exact canonical stored string. Regression: `tests/manual/gchamp-knockout-regression.php`.

## [2026.30.4]
### Fixed
- **Group-championship knockout seeding no longer 500s with "Unexpected token '<' … is not valid JSON".** When the qualifier total is not a power of two (e.g. 12 qualifiers → a 16-slot bracket with 4 byes), bye slots have a `null` name. `lgw_draw_build_bracket()` runs every slot name through the `get_club` callback (`lgw_gchamp_entry_club()`), whose strict `string` type hint raised a fatal `TypeError` on the null byes, so the AJAX handler died and returned WordPress's HTML critical-error page instead of JSON. `lgw_gchamp_entry_club()` and the shared `lgw_champ_entry_club()` now accept `null`/empty and return an empty club (mirroring `lgw_draw_cup_club`). This also restores the per-day knockout score inputs, which the seed fatal had knocked out.
- **Clearing or editing a knockout score keeps the next round in sync.** `lgw_ajax_gchamp_save_score` (context `ko`) advanced a winner into the next round on save but never rolled it back: clearing a played match left its winner stranded in the following round, and editing a result to a different winner did not update the downstream slot. The save path now calls a new `lgw_gchamp_set_ko_advance()` that advances, rolls back, or replaces the downstream slot for the new result and cascades any now-invalid later-round result — while leaving an already-played later round untouched when a score edit keeps the same winner.

### Added
- **Manual knockout seeding (admin).** Each first-round knockout slot now has a pencil control (admin only) that reassigns it to any of that day's qualifiers or clears it to TBD, via the new `lgw_gchamp_ko_set_slot` AJAX handler. Reassigning an entry that already occupies another slot vacates the old slot automatically, and any participant change resets that match and cascades down the bracket. Overrides the automatic 1-vs-N seeding for walkovers, late changes, and corrections without touching the database. Regression coverage: `tests/manual/gchamp-knockout-regression.php`.

## [2026.30.3]
### Fixed
- **Concession penalty respects per-division max points.** `lgw_ajax_save_concession` built the concession scorecard's points from the global `lgw_max_points` option (7), so a division with a max of 6 (the 12-player division) docked the conceder 7 and awarded the winner 7 instead of ±6. The handler now reads `max_points` posted by the widget (clamped 6–7) and falls back to the global option only when absent; `lgw-widget.js` `doSave()` sends the in-scope `maxPts` (from `data-maxpts`). The SSR standings path already used `$atts['max_points']` — this aligns the stored scorecard with it.

## [2026.30.2]
### Fixed
- **Unplayed fixtures no longer render as 0-0 draws.** The `played` heuristic flagged a fixture played if any shots/points cell was non-zero, but `''` (blank away-pts, as some divisions leave on unplayed rows) is not `'0'`, and a kickoff time can leak into a points column — so upcoming 0-0 fixtures were shown as played draws. Now a result is recognised only when a shots/points value is present **and** non-zero (blank and `'0'` treated identically), and a time-formatted value in a points column is captured as the time note, not a score. Fixed in both `lgw-div-cache.php` (`lgw_cache_parse_fixtures`) and the `lgw-widget.js` CSV parser.

## [2026.30.1]
### Fixed
- **Edit-fixture warning names the actual overlay.** New `fixtureResultReasons()` in `lgw-widget.js` replaces the vague "has a result or overlay" with the specific attachment(s) — "a postponement / reschedule", "a concession", "a null & void", "a scorecard (status)", "a score override" — so a postponed fixture is no longer misread as a recorded 0-0 result. No behaviour change; teams/date remain locked while an overlay/scorecard is attached.

## [2026.30.0]
### Added
- **Fixture editing in WordPress-authoritative mode.** CSV mode edits fixtures by changing the sheet; WP mode had no equivalent. New admin-only "Edit fixture" panel in the fixture modal (both played + unplayed), gated on `lgwData.dataSource === 'wordpress'`. Corrects home/away, one-click **Swap** (transposes teams + scores + points), date and time. `lgw_ajax_edit_fixture` locates the fixture in `lgw_div_cache_{season}_{division}['fixtures']` by its old `home||away||date` key, applies, and re-saves; standings recompute from fixtures. Identity edits are blocked (client + server via `lgw_fixture_has_result()`) when a confirmed scorecard or concession/postponement/null-void/override exists.
- **Fixture-edit auditing + baseline.** `lgw_fixture_log()` appends every edit/swap/revert to `lgw_fixture_edit_log` (ring buffer, 500). `lgw_fixture_snapshot_baseline()` captures a division's pristine fixtures once — at seed time and lazily before the first edit — into `lgw_div_baseline_{season}_{division}`, giving a future hard reset a known-good state. Read-only **LGW → Fixture Audit** screen renders the log newest-first.
- **Per-fixture Undo.** `lgw_ajax_revert_fixture` restores a fixture from its most recent log entry; "Undo last edit" button in the panel.

- **Date-aware scorecard matching.** `lgw_get_scorecard()` now honours the `$date` it's passed (it was previously accepted but ignored): among same home+away+division candidates it returns the one whose `lgw_fixture_date` matches (exact or parsed within a day) via new `lgw_scorecard_fixture_date_matches()`; date-less legacy scorecards still match, and date-less lookups keep prior behaviour. Fixes a swapped fixture surfacing/overwriting the reverse leg's scorecard. The fixture-edit guard now passes date + division.
- **Clear scorecard.** `lgw_ajax_delete_scorecard` trashes the scorecard for a specific fixture, drops its score override, and wipes the cached result to unplayed. Surfaced as a "Clear scorecard" button in the Edit fixture panel when a scorecard is attached.
- **WP-cache gating.** The editor only renders on widgets server-rendered from the WP cache (`data-prerendered="1"`), sending the exact season via new `data-season` attribute (from `lgw_cache_render_division`).

### Deferred (phase 2)
- Division-wide hard reset from baseline (with confirmed-scorecard re-overlay).

## [2026.27.31]
### Added
- **Cup walkover / concession.** The admin score-entry popover on a cup fixture now has a "Walkover — team conceded" control naming which team conceded; the opponent advances to the next round with no score stored (cup ties carry no points). `lgw_ajax_cup_save_score()` accepts a `conceded` param (`home`/`away`/`''`), sets `match.conceded`, and advances the non-conceding team; entering a real score clears any prior walkover. The bracket shows the advancing team with `W` and the conceding side `w/o`. "Clear walkover" reverts it and cascades the downstream slot clear (`lgw_cup_cascade_reset()` now also nulls a downstream `conceded`). Mirrors the league-fixture concession control in `lgw-widget.js`.

## [2026.27.30]
### Fixed
- **Request-access page readable in dark mode.** `.lgw-submit-card`'s dark-mode CSS inverted only the card background while the shortcode's dark text stayed put (dark-on-dark). Added `.lgw-ca-box` overrides in `lgw-scorecard.css` that pin the light palette (card, headings, text, select/textarea, hint) for all shortcode states. Rules live in the stylesheet (not inline) so they also cover the logged-out/pending/approved branches, which return before any inline block.

## [2026.27.29]
### Fixed
- **Release zip now bundles `lgw-club-access.php`.** It was absent from the `release.yml` build `cp` list (and the manifest-verify `EXPECTED` list didn't check for it), so sites installed from the zip hit "modules could not be loaded — `lgw-club-access.php`" and the entire club-access feature was inactive. Added to both the build and the verification manifest.

## [2026.27.28]
### Added
- **Self-serve "Request access" from the fixture modal.** In login-only mode, a signed-in but unapproved user previously hit a dead-end "contact an administrator" message. The modal now shows a **Request access** button linking to the page carrying `[lgw_club_access_request]`. New `lgwSubmit.requestAccessUrl` field localized in all three enqueue sites (`lgw-cup.php`, `lgw-division-widget.php`, `lgw-scorecards.php`).
- **`lgw_request_access_url()` helper** (`lgw-club-access.php`): returns the `lgw_request_access_url` option if set, else auto-discovers (and caches for 12h) the first published page containing the shortcode.
- **"Request-access page" setting** under LGW → Club Access → Settings to pin the URL; blank = auto-detect.
- **Return-to-fixture after request.** The modal button appends `?lgw_return=<fixture-url>`; the request page validates it same-site (`wp_validate_redirect`, no open redirect) and, on success, shows a "Return to fixture" button and auto-redirects.

### Changed
- **`[lgw_club_access_request]` restyled** to the scorecard palette (`.lgw-submit-card`, `.lgw-btn`, `.lgw-form-row`, `.lgw-notice`) so it matches the fixture modal. The shortcode self-registers `lgw-scorecard.css` (the main enqueue only registers it on `[lgw_division]`/`[lgw_submit]` pages).

## [2026.27.27]
### Added
- **Identity-based submission in the fixture modal (Slice C).** `lgw-scorecard.js` gate now reads new `lgwSubmit` fields (`authMode`, `isLoggedIn`, `loginUrl`, `userClubs`). An approved signed-in user with exactly one fixture-matching club drops straight to the form; more than one shows a "Submit as which club?" picker; login-only mode with no approved club shows a "Log in to submit" button (or a "not approved" notice); both-mode adds a "Log in instead" link beside the passphrase box.
- New `lgwSubmit` client fields localized in all three enqueue sites (league, cup, widget); `userClubs` is omitted for administrators (they use the admin path).
### Changed
- **Multi-club submissions authorised server-side.** `lgw_get_auth_club()` now honours an explicit `submit_club` POST value, but only when the signed-in user is approved for it; the JS `post()` helper attaches the chosen club to every request (`submit_club`), covering save, photo/Excel parse, and confirm.

## [2026.27.26]
### Added
- **Club-admin registration + approval (Slice B).** Front-end `[lgw_club_access_request]` shortcode: logged-in users pick club(s) + note → AJAX `lgw_request_access` sets status `pending`, stores requested clubs, emails admins. Idempotent-safe (already-approved users are told to contact an admin; 30s per-user rate-limit transient).
- **Access Requests admin UI** on **LGW → Club Access**, now tabbed (Requests · Members · Settings). Requests tab: per-request card with a club-grant checklist (pre-ticked from the request) → Approve / Reject. Members tab: approved users + clubs + Revoke. All actions nonce + `manage_options` guarded; club grants are intersected against configured clubs server-side.
- **Email notifications.** Admins notified on each new request (recipients from `lgw_admin_notify_emails`, falling back to `admin_email`); users emailed on approve / reject / revoke.
### Changed
- Decision handlers `lgw_ca_approve/reject/revoke` manage the `lgw_club_admin` role via `add_role`/`remove_role` (never stripping a user's other roles) and keep `lgw_clubs` / status / audit meta in sync.

## [2026.27.25]
### Added
- **Club-admin access foundation (Slice A of OAuth-backed submission).** New module `lgw-club-access.php`: registers the `lgw_club_admin` role + `lgw_submit_scorecard` capability (idempotent, version-guarded on `init`); adds a user-meta model (`lgw_approval_status`, `lgw_clubs`, `lgw_requested_clubs`, note/audit fields) keyed by **club name** (clubs have no stable ID); and exposes authorization primitives `lgw_user_submit_clubs()`, `lgw_user_can_submit_for()`, `lgw_is_club_admin_submitter()`.
- **`lgw_auth_mode` control** (`passphrase` | `both` | `login`) with a self-contained **LGW → Club Access** settings page (submission mode radio + approval-notification emails). Passphrase issuers (`lgw_ajax_check_pin`, `lgw_ajax_cup_score_auth`) are disabled when mode is `login`.
### Changed
- **Submission gate now accepts verified identity.** `lgw_get_auth_club()` auto-resolves the club for a logged-in approved single-club admin (multi-club admins pick explicitly in a later slice; WP admins unaffected). Cup score saving (`lgw_ajax_cup_save_score`) accepts logged-in approved club admins and enforces that the user is approved for the match's home or away club; audit log records the submitting user. No OAuth code yet — Google login is added later via a social-login plugin.

## [2026.27.24]
### Changed
- **Season Progress chart given more vertical room.** Chart wrap height raised 340px → 480px (`lgw-widget.css`) so the plotted lines spread out and stay legible when several teams sit close together.
### Added
- **"Select None" control on the Season Progress chart.** New `.lgw-progress-none` button sets every team hidden in one click (clears the graph); "Clear Filter" restores all. Reuses the existing `progressHiddenTeams` map, so per-team legend toggling is unchanged.

## [2026.27.23]
### Fixed
- **Cup scorecard modal had unreadable text in OS dark mode.** The modal is appended to `document.body`, so in dark mode it inherited `:root`'s dark `--lgw-*` palette (`lgw-widget.css`). Its box is hard-coded white, but the injected scorecard content resolves `color: var(--lgw-text)` / `var(--lgw-navy)`, which then evaluated to light values — light-grey text on a white box, no contrast. `.lgw-cup-sc-modal-box` now re-asserts the light palette locally, so the scorecard always renders dark-on-white regardless of the visitor's colour-scheme preference.

## [2026.27.22]
### Fixed
- **Cup scorecards submitted by an admin were mis-tagged as `league`.** The submission payload's `context` (league/cup) was only read on the non-admin first-submission path. The admin "both teams" and "confirm on behalf of the other club" handlers read `$sc['context']`, which was never populated on the `$sc` array — so they silently defaulted to `league`, leaving cup scorecards without the 🏆 Cup badge and re-flagging the "Unresolved" warning fixed in 2026.27.21. `context` is now carried on `$sc`, so all three save paths tag it consistently. Added a data-based fallback (`lgw_scorecard_is_cup_by_data()`) on every path: a scorecard whose division is a configured cup title is forced to `cup` context even if the payload omits it. Resaving a mis-tagged card in the admin already self-healed it; new submissions are now tagged correctly at source.

## [2026.27.21]
### Fixed
- **Cup scorecards falsely flagged "Unresolved".** A cup scorecard tagged (or defaulted) as `league` tripped the division-unresolved / sheet-writeback-blocked warning on the Scorecards list (`lgw-division-widget.php`) and the edit modal (`lgw-sc-admin.php`), because its division is a cup title that never maps to a league sheet tab. New helpers `lgw_all_cup_titles()` / `lgw_scorecard_is_cup_by_data()` in `lgw-cup.php` recognise a cup scorecard from its own data, so it is treated as cup even when the context meta is stale — the warning no longer shows and writeback stays correctly skipped. Distinguishes a real league game between the same two clubs (its division is a genuine league division, not a cup title). The admin edit handler now **self-heals** a mis-tagged cup scorecard's context to `cup` (and clears the stale flag) instead of cementing it as `league` on first save. Adds `lgw_backfill_cup_contexts()` to repair existing records in bulk — run once via WP-CLI: `wp eval 'echo lgw_backfill_cup_contexts();'`.

## [2026.27.20]
### Fixed
- **Cup bracket prelim placeholder mapping (display).** `renderBracket()` in `lgw-cup.js` built the prelim→R2 feed map by enumerating slots with an empty **name**, so once a prelim winner was written into an R2 slot it dropped out of the list and every remaining TBD placeholder shifted by one — a winner appeared to face its own team while the expected opponent seemed to move to the next R2 fixture. Now enumerates prelim-fed slots **structurally** by absent `draw_num_home`/`draw_num_away` (bye slots always carry a draw number), keeping the mapping stable regardless of how many results are entered. Stored bracket data and winner advancement were already correct — this was display-only. `lgw_cup_next_slot()` in `lgw-cup.php` was switched to the same structural rule (reads real prelim-fed slots rather than recomputing the even-distribution formula) so it can never drop a winner onto a bye slot in legacy or hand-edited draws.

## [2026.27.19]
### Added
- **Email tracked players to club secretaries.** New "✉ Email Tracked Players to Secretaries" button on Player Tracking → Club Summary (`admin_post_lgw_email_tracked_secretaries` in `lgw-players.php`). For every club with players in the tracker it sends an individual `wp_mail` to the club's Secretary email (resolved from the `lgw_clubs` directory via new helper `lgw_club_secretary_email()`) with a per-club CSV attachment — Player, Gender, Appearances, Teams, Wins, Draws, Losses, Shots For, Shots Against — season-scoped to the view. CSVs are written to `uploads/lgw-tmp` and unlinked after send. Clubs without a Secretary email are skipped; a one-off transient notice reports sent / skipped / failed counts.

## [2026.27.18]
### Added
- **Club Directory CSV import.** New "⬆ Import CSV" tool on the Clubs admin screen (`admin_post_lgw_import_clubs` in `lgw-clubs.php`). Upload a CSV with header `club_name,address,website,contact_role,contact_name,contact_phone,contact_email` (one row per contact, `club_name` repeated). Clubs are matched by slug: existing clubs have address/website/contacts refreshed while passphrase, badge, chart colours, facilities and the can-submit flag are preserved; unseen names are added. Ships `lgw-club-contacts.csv` pre-populated from the NIPGL PG club-contacts directory (33 clubs). Clubhouse phone numbers are folded into the address field.

## [2026.27.17]
### Fixed
- **Setup Wizard loaded optionally.** `lgw-setup-wizard.php` moved to an optional-modules list in the loader — if the (in-flight) file is missing or throws on load it is silently skipped rather than raising a "modules could not be loaded" admin notice. Core modules still surface load failures.

## [2026.27.16]
### Changed
- **W/D/L decided by shots, not points.** `lgw_compute_teams_from_fixtures()` now derives Won/Drawn/Lost from aggregate shots (`shotsHome` vs `shotsAway`) — the overall match result — matching how the league tables are compiled. The Pts column still sums awarded match points; only the W/D/L counts change.

## [2026.27.15]
### Fixed
- **WordPress-authoritative standings no longer freeze at the seed.** The table was rendered straight from the seeded standings block, which `lgw_cache_merge_result` never updates — it merges confirmed scorecards into the *fixtures* only. So any result confirmed after seeding showed in the fixtures but not the table (Ballymena A on 41 while their fixtures summed to 46). `lgw_cache_render_division` now calls new `lgw_compute_teams_from_fixtures()` in WP mode, rebuilding Pl/W/D/L/For/Against/Diff/Pts from fixtures with a final result (`status` `csv_played` or `confirmed`); `pending`/`disputed`/`unplayed` are excluded. Concessions still layer on top via `lgw_apply_concessions_to_teams` with no double-count (manual concessions stay `unplayed`; auto-created ones are `confirmed`). Google Sheets mode is untouched.

## [2026.27.14]
### Fixed
- **Club-aware fixture scheduling.** `lgw_setup_generate_fixtures` now runs each round through `lgw_setup_balance_home_clubs`, which re-orients pairs (home/away swap only — always a valid fixture) so no club hosts more than one match per date. Club identity comes from `lgw_setup_club_of`, which strips a trailing single letter/digit designator (`DUNBARTON A`/`DUNBARTON B` → `DUNBARTON`) and normalises case/whitespace. Applied to single and double rounds; the review-step preview reflects it. Scheduling is still per-division, so cross-division same-date club clashes are not deduplicated.

## [2026.27.13]
### Fixed
- **Setup Wizard no longer overwrites the live league before confirmation.** Step 2 previously called `lgw_cache_seed_teams` / `lgw_setup_set_divisions` / `update_option('lgw_data_source')` / `lgw_drive` writes immediately, so going Back from Review (or abandoning there) had already destroyed the existing league. All of it is now staged in a single `lgw_setup_pending` option (choice, data_source, divisions, teams, sheet config) and applied only when the user clicks **Finish** on the review step, via `lgw_setup_ensure_season` + seed/set/data-source/drive/fixture-generation. The review preview and per-division team counts read from `lgw_setup_pending` instead of the live cache. `lgw_setup_reset` and Finish both clear the pending option.

## [2026.27.12]
### Added
- First-run **Setup Wizard** (`lgw-setup-wizard.php`, menu **LGW ▸ 🚀 Setup**). Auto-redirects on activation via a `lgw_setup_redirect` transient; re-runnable from the menu. Four steps: pick data source → configure → review → done. Form posts route through an `admin_post_lgw_setup_wizard` handler so redirects fire before admin headers.
- Three bootstrap paths: **Google published CSV** (enter division CSV URLs → `data_source=google_sheets`, writes active-season divisions + `lgw_drive` sheets_tabs); **Upload spreadsheet** (two-column `Division,Team` CSV parsed → `lgw_cache_seed_teams` → `data_source=wordpress`); **WordPress DB** (type divisions + rosters → seeded → `data_source=wordpress`).
- **Round-robin fixture generator** (`lgw_setup_round_robin` circle method + `lgw_setup_generate_fixtures`) offered on the upload and WP-DB steps: single/double round, first-round date, weeks between rounds, bye for odd counts. Fixtures written per division via `lgw_setup_store_fixtures` in the `lgw_cache` shape (`played=false`, `status=unplayed`).
- **Review step fixture editor** — step 2 now stores fixture choices as defaults (`lgw_setup_fx`); the review step generates a live per-division preview (round-by-round), with per-division first-round date / interval / double overrides (`lgw_setup_fx_div`, resolved by `lgw_setup_fx_for_division`) and a JS bulk-apply for a ticked group of divisions. "Regenerate" (`lgw_setup_action=regen`) refreshes the preview; "Finish" commits fixtures per division. Fixtures no longer generated at step 2.
- **Overwrite guard** (`lgw_setup_is_configured` + `lgw_setup_confirm_overwrite`) — step 1 warns and requires confirmation before replacing an existing league; enforced server-side.
- **Start fresh** reset (`admin_post_lgw_setup_reset`) — clears active-season divisions, seeded division caches, `lgw_data_source`, and wizard state; leaves sponsors/theme/players/scorecards intact.
- **Within-division duplicate guard** — `lgw_setup_dupes()` (case-insensitive) blocks the wizard's upload and WP-DB paths before any write; `lgw_cache_seed_teams()` gains a matching backstop returning `WP_Error dupe_team`. Same name across different divisions/competitions stays valid (caches are `season+division` keyed).
- Registered `lgw-setup-wizard.php` in the module loader.

## [2026.27.11]
### Changed
- Scorecard photo analysis model updated `claude-sonnet-4-5` → `claude-sonnet-4-6` (current Sonnet; 4.5 is legacy-but-active). Verified HTTP 200 against the configured key. `lgw-scorecards.php`.

## [2026.27.10]
### Fixed
- Scorecard photo "Read with AI" no longer hangs on an endless spinner when the Anthropic request is slow or the server can't reach `api.anthropic.com`. The `XMLHttpRequest` now sets `timeout = 90000` with an `ontimeout` handler, wraps `JSON.parse` in try/catch (non-JSON 504/500 pages previously threw silently inside `onload`), and surfaces a clear error with the HTTP status. Applied to both photo upload call sites (standalone tab + fixture-modal tab). Server/model code unchanged — the Anthropic call itself verified working (HTTP 200) against the stored key.

## [2026.27.9]
### Added
- **Manual team-roster seeder** for new seasons (no spreadsheet). In League Setup → Division Cache, "Seed a division from a team list": pick a division, paste team names (one per line), and `lgw_cache_seed_teams()` writes a zeroed standings table (P/W/D/L/F/A/Pts = 0) into the WP cache via the `lgw_cache_seed_teams` AJAX action. Existing fixtures / CSV URL on the division are preserved; confirmed scorecards + concessions fill the table in.

### Changed
- In WordPress data-source mode, the Google Sheets Writeback section is labelled optional (keeps the sheet mirrored as a backup; standings no longer depend on it). The Google integration (OAuth + Drive PDF archive) and per-division CSV URLs remain available in WP mode as seed/backup.

## [2026.27.8]
### Added
- **WordPress-authoritative data source.** The "WordPress DB (Google Sheets backup)" option in League Setup → Data Source is now selectable. Standings/fixtures are served from the WP DB (Division Cache) and maintained by confirmed scorecards + concessions; Google Sheets CSV seeds an empty division once and is the automatic per-division fallback.
  - `lgw_source_is_wordpress()` gates the new behaviour.
  - In WP mode `lgw_cache_sync_from_csv()` is **seed-only** — it never overwrites a division that already has WP standings (so scorecard-driven edits persist), while still refreshing the CSV fallback transients.
  - In WP mode `lgw_cache_get_division()` ignores the hard TTL, so WP data never ages out into the CSV fallback purely by timestamp.
  - Settings UI: WP-mode help panel; the CSV URL config stays visible in WP mode since CSV remains the seed/backup.
- Switching is non-destructive and reversible: divisions with no WP data fall back to live CSV, so nothing goes blank.

## [2026.27.7]
### Added
- `lgw_format_changelog()` renders the GitHub release body in the "View details" popup as safe HTML — first line as a heading, `•`/`*`/`-` lines as a `<ul>` bullet list, and bare URLs linkified — replacing the previous `nl2br(esc_html(...))` flat block.

## [2026.27.6]
### Fixed
- "View version details" popup returned **"Plugin not found"**. The `plugins_api` `plugin_information` handler guarded on a hardcoded slug (`lgw-division-widget`); WordPress passes the plugin's *installed folder* slug, so on any renamed install folder the filter fell through to wordpress.org. Now matches `lgw_plugin_slug()` (the real folder), also used for the update-transient slug. Added a GitHub API HTTP-200 check and `last_updated`; changelog/details render from the release notes.

## [2026.27.5]
### Added
- Google auth failures are no longer silent. The last OAuth / service-account token error from scorecard→Drive writeback is persisted (`lgw_drive_auth_error`) and shown as an admin notice, including an `invalid_grant` hint (revoked/expired refresh token — reconnect the account; move the OAuth consent screen to "In production" to stop 7-day expiry).
- Division cache CSV sync failures are recorded per division (`lgw_cache_sync_errors`), surfaced inline in the Division Cache health table and summarised in an admin notice — stale/empty league tables now explain *why* they failed to refresh (bad published URL, HTTP error, empty/zero-parse body).

### Notes
- League tables render from the WordPress **Division Cache** (`lgw-div-cache.php`, materialised standings/fixtures in WP options); Google Sheets CSV remains the authoritative source. This is separate from Google OAuth, which only powers scorecard Drive writeback and team-name validation.

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

### Fixed
- (previous fixes)

## [2026.27.4]
### Fixed
- **Cross-competition scorecard bleed** — scorecard identity is now scoped by division, so competitions that share team names (e.g. the Midweek league and a Saturday division) no longer cross-match. A Midweek fixture no longer shows a Division 2 scorecard. The match key includes the (normalised) division; lookups pass division and fall back to legacy keys only when the stored division agrees. Existing scorecards are upgraded to the new key on next save.

## [2026.27.3]
### Fixed
- **Scorecard player name suggestions** — replaced native `<datalist>` with a custom JS dropdown, fixing Firefox Android where datalist suggestions were not appearing.

## [2026.27.2]
### Fixed
- **Progress chart badge circles** — club badge/initials circles remained visible for hidden datasets. Fixed by using `chart.isDatasetVisible()` instead of `meta.hidden` check in badge plugin.

## [2026.27.1]
### Added
- **Progress chart team filter persistence** — hidden teams retained when switching between Points and Position tabs. Clear Filter button appears when any team is hidden.

## [2026.26.25]
### Fixed
- **Progress chart CSS** — play button hover state (`background:#a01820`), `white-space:nowrap`, and `flex-wrap:wrap` + `align-items:center` on controls bar.

## [2026.26.24]
### Fixed
- **Progress chart Play button renders as boxes on live** — removed Unicode symbols (▶ ⏸ ↺) from button labels; replaced with plain text Play/Pause/Resume/Replay. Symbols not present in server font stack.

## [2026.26.23]
### Changed
- **Progress chart: auto-distinct colours + varied point shapes** — replaced hardcoded colour palette with golden-ratio HSL generation (`idx × 137.508°`) giving maximally distinct colours. Added `POINT_STYLES` array cycling through 8 Chart.js shapes (circle, triangle, rectRot, star, rect, cross, crossRot, rectRounded). Club admin colour pickers still override auto colours. `buildTeamColorMap()` now populates both `progressTeamColorMap` and `progressTeamShapeMap`.

## [2026.26.20]
### Fixed
- **Progress chart: use played date not scheduled date** — brought-forward games were appearing at the original fixture date. Now uses `lgw_scorecard_data['date']` (actual played date) with `lgw_fixture_date` as fallback only.
- **Progress chart: SVG-only badge overlay** — raster badges were pixelated at canvas size; non-SVG URLs now fall back to a coloured initials circle.
- **Progress tab hidden on mobile** — chart is unreadable at ≤520px so tab is hidden via CSS. Lazy-load behaviour unchanged; no mobile payload cost.

## [2026.26.19]
### Added
- **Season Progress chart** — new "📈 Progress" tab on the division widget. Renders a Chart.js line chart (lazy-loaded from CDN) showing each team's cumulative points or league position per match date. Toggle between Points and Position views. Data sourced from WP scorecards via `lgw_ajax_season_progress`; null & void excluded; gaps handled for teams not yet in the table.

## [2026.26.18]
### Fixed
- **Table Compare: coverage gap detection** — `lgw_match_key` format is inconsistent between regular scorecards (`sanitize_title(home-away)`, no date) and concessions/null-voids (pipe-separated with date). Gap lookup now built from `lgw_scorecard_data` home/away fields + `lgw_fixture_date` meta; bare `home||away` fallback covers older scorecards missing fixture date.

## [2026.26.17]
### Fixed
- **Table Compare: regular scorecards now included** — division was stored inside `lgw_scorecard_data` array, not as a separate `lgw_sc_division` meta key (only concessions/null-voids set that meta). Division filter moved to PHP post-load; falls back to `lgw_sc_division` meta for special-case scorecards.

## [2026.26.16]
### Added
- **Table Compare admin page** — new "📊 Table Compare" submenu. Loads each division's Sheets CSV and compares the live table against a table calculated from WP scorecards. Gold cells = value mismatch; green +WP = team only in WP; red -WP = team only in Sheets. Coverage Gaps section lists every played result in Sheets that has no matching WP scorecard, making backfill gaps visible before switching data source to WP DB.

## [2026.26.15]
### Added
- **Null & void workflow** — admin can mark any league fixture as null & void via checkbox in the fixture modal (both played and unplayed). Creates a confirmed scorecard with 0–0 shots and 0 points for both teams, flagged `lgw_sc_null_void`. Displays a dark grey "❌ Null & Void" pill on fixture rows. Clearing trashes the scorecard. Admin must update the Google Sheets league table separately.

## [2026.26.14]
### Fixed
- **Scorecard modal: concede/postpone panels side-by-side** — both admin panels now have matching bordered-box style; rendered in a flex row with a single HR separator below. Stacks vertically on screens narrower than 520 px.

## [2026.26.13]
### Added
- **Cup admin: Rename Entry** — correct a team name across the entries list, all bracket slots, and draw pairs without touching scores. Mirrors the championship rename tool.

## [2026.26.12]
### Fixed
- **Cup scorecard context leak**: clicking a cup bracket match no longer shows a league scorecard for the same teams. `lgw_ajax_cup_get_scorecard` now passes `'cup'` context; `lgwFetchScorecardOrSubmit` forwards `opts.context` in the fetch URL (PHP handler already supported it).

## [2026.26.11]
### Fixed
- **Cup bracket pending icon**: ⚠️ now appears inline next to the team who hasn't submitted their scorecard, not in the top-right corner. ✅ confirmed icon remains corner-positioned.

## [2026.26.10]
### Fixed
- **Cup bracket prelim advancement**: winner from a preliminary round match now lands in the correct R2 slot. The naive `floor(match_idx/2)` formula assumed a perfect 2^n bracket and was placing winners over existing seeded teams. Fixed by mirroring the evenly-distributed `winner_positions` spacing the draw builder uses. Fixes score save, cascade reset, and JS TBD placeholder — no redraw needed.

## [2026.26.9]
### Added
- **Cup bracket**: ✅/⚠️ scorecard status icons on match cards — confirmed shows a green check, pending shows a yellow warning triangle. PHP builds a status map at shortcode render time; JS reads it when drawing the bracket.

## [2026.26.8]
### Fixed
- **Cup scorecards**: suppress "Division name wasn't recognised" warning for cup context scorecards — cups have no Google Sheets tab to resolve against.

## [2026.26.7]
### Fixed
- **Upcoming filter**: past fixtures where home or away team is "Bye" are now excluded from the Upcoming view (both JS-rendered and SSR modes).

## [2026.26.6]
### Fixed
- **Appearances modal season filter**: `lgw_get_player_appearances` was passing `lgw_season_where()` through `$wpdb->prepare()`, corrupting the `STR_TO_DATE('%d/%m/%Y')` format string. Switched to `get_results()` with an `intval`-safe player ID, consistent with every other appearance query in the codebase.

## [2026.26.5]
### Added
- **Club filter**: dropdown above `[lgw_player_stats]` tabs filters rows in the active panel to a single club; row numbers re-sequence after filtering.
- **Club badge in appearances modal**: modal header now shows the club badge (resolved via `lgw_club_badges` option using the same fuzzy prefix logic as the team modal).

## [2026.26.4]
### Added
- **Player appearances modal**: player names in `[lgw_player_stats]` are now clickable and open a modal listing every league game for that player (date, opponent, SF, SA, +/-, W/D/L badge). Division-tab clicks pre-filter to that division; the All tab shows all.

## [2026.26.3]
### Added
- **Player stats shortcode** `[lgw_player_stats]`: renders a per-division player league table with tabs (one per competition + All), sorted by wins → draws → shot difference → shots scored. Season-aware.
- **Division column on appearances**: new `division` DB column stores the competition name (Division 1, Midweek 1, etc.) on each appearance row; existing rows are back-filled from scorecard meta on first load.
### Fixed
- **Player stats CSS**: `lgw_enqueue()` now loads CSS/JS on pages containing only the `lgw_player_stats` shortcode (previously only fired for `lgw_division`).

## [2026.26.2]
### Fixed
- **Time pill (SSR mode)**: time column scan range extended to include the column immediately after away points, fixing pill not appearing when no `time` header exists in the sheet.
- **Time pill (HH:MM)**: seconds-strip regex now guarded so `HH:MM` values are no longer truncated to `HH` when cached by PHP.

## [2026.26.1]
### Added
- **Ticker speed normalisation**: animation duration is now computed from the actual rendered content width at 80 px/s, so all divisions scroll at a consistent speed.
### Fixed
- **Ticker per-division results**: recent results are now collected up to 30 per division before merging, preventing high-volume divisions from starving others in the global pool.
- **Ticker top corners**: border-radius on the top edges is removed when the ticker follows another element (e.g. a division title), so it sits flush without a visual gap.
### Changed
- **Versioning**: switched from `x.y.z` semver to `yyyy.ww.n` (year · ISO week · release number within the week).

## [7.6.59]
### Added
- **Latest Results tab**: filter button renamed from "Results" to "Latest Results". Played fixtures are now regrouped by their actual date played (using the rescheduled played-on date where present, rather than the original scheduled date) and sorted newest first. Date headers use the `ddd dd-MMM-YYYY` format to match the rest of the widget.
### Fixed
- **Upcoming filter**: now shows all unplayed fixtures regardless of date, not just future-dated ones (catches postponed matches with a past scheduled date).
- **Filter highlight on refresh**: active filter button is now correctly highlighted when the page is reloaded with a non-default filter restored from session storage.

## [7.6.58]
### Added
- **Per-club scorecard submission toggle**: a new "Scorecard Submission" section in the club edit form (Clubs admin page) adds a checkbox — "This club can submit scorecards". When enabled, that club bypasses the global `admin_only` restriction and can submit scorecards directly. Global `disabled` still prevents all submissions. The flag is stored in `lgw_clubs` as `can_submit` and surfaced as `clubCanSubmit` in `lgwData`, `lgwSubmit`, and `lgwCupData`. A 📋 icon appears on the club card in the grid when the flag is set.

## [7.6.57]
### Added
- **Female player toggle**: each player row now has a ♀ button. Clicking it marks the player as female (appends `*` to the name), which `lgw_log_appearances()` already uses to set the `female` flag on the player record.
- **Import review panel**: after photo AI or Excel parse, any player name not found in the club's registered list is collected into a review card. Each entry shows fuzzy suggestions (initial+surname match, nickname aliases, Levenshtein ≤ 2) with Accept Suggestion, Keep as-is, and ♀ buttons. The panel auto-dismisses when all entries are resolved.
- **Opposing team player restriction**: on blur of any player input on the opposing team's side, `lgwBlockOpposingPlayer()` checks the name against the opposing side's registered datalist. If the name is not found the field is cleared and a red inline notice is shown for 5 s. A submitting club cannot introduce unknown players on the other side.

## [7.6.56]
### Added
- **New-player fuzzy check**: when a typed player name doesn't match the club's registered list, an inline dialog appears below the input. If similar names are found (initial+surname match, nickname aliases, Levenshtein ≤ 2) they are offered with an Accept Suggestion button. If no match, a simple Yes/No prompt is shown. Accepting fills the slot and advances to the next cell; declining returns focus for correction.

## [7.6.55]
### Fixed
- **Sheets team name matching**: normalise spaces around hyphens before comparing team names, so `CI - KNOCK B` in the spreadsheet matches `CI-Knock B` from the scorecard.

## [7.6.54]
### Fixed
- **Match date normalisation**: `lgw_log_appearances()` now passes the scorecard date
  through a new `lgw_normalise_match_date()` helper before storing. AI photo and Excel
  parsers were writing `Sat 18-Apr-2026` style dates, which `STR_TO_DATE('%d/%m/%Y')`
  cannot parse, silently dropping those rows from season-filtered queries. Existing rows
  with non-standard dates need a one-time DB fix (run via WP-CLI or admin tool).

## [7.6.53]
### Fixed
- **Season date filter broken by `prepare()` fragment reuse**: `lgw_season_where()` and
  four inline duplicates built `STR_TO_DATE` WHERE clauses via `$wpdb->prepare()`, which
  replaces `%` with an internal hash token only resolved when the string is the *final*
  query — not when concatenated into a larger query. The format string `%d/%m/%Y` arrived
  at MySQL as garbage, silently excluding almost all rows. Fixed with `esc_sql()` across
  all six call sites (`lgw_season_where`, `lgw_players_admin_page`, `lgw_export_players_xlsx`,
  `lgw_export_club_summary_pdf`, `lgw_ajax_check_new_players`, and the appearances AJAX).

## [7.6.52]
### Added
- **Positional player grid**: scorecard entry now uses Lead/Second/Third/Skip input
  slots per rink side instead of a comma-separated textarea. Short rinks (3 players)
  are arranged Lead/Second/Skip with Third left empty.
- **Player autocomplete**: each player name slot offers autocomplete suggestions from
  the registered player list for that team, fetched via a new `lgw_get_team_players`
  AJAX endpoint — ideal for correcting OCR/Excel spelling variants.

## [7.6.51]
### Added
- **Mode toggle for pending scorecards**: when a pending scorecard is found in the
  fixture modal, a "📋 View & confirm / ✏️ Submit my own" toggle appears so the
  opposing club can choose to confirm the existing scorecard or enter their own
  independent version without first seeing the submitted scores.

## [7.6.50]
### Fixed
- **Unplayed fixture modal shows pending scorecard**: clicking an unplayed fixture
  now checks for an existing pending scorecard before showing the blank submission
  form. If the other club has already submitted, the opposing club sees the scorecard
  with agree/amend options and a login gate, rather than being shown an empty form.

## [7.6.49]
### Added
- **Filtered player export**: the Excel export button now passes the active club,
  team, and name filters through to the export handler. The downloaded spreadsheet
  contains only the filtered player set, so you can export a single club's list to
  send for duplicate review.

## [7.6.48]
### Fixed
- **Orphaned appearances — binned scorecards**: the LEFT JOIN previously matched
  trashed (`post_status = 'trash'`) scorecard posts, so appearance records linked
  to a binned scorecard were not flagged. Added `AND sp.post_status != 'trash'`
  to the join condition so both permanently deleted and binned scorecards surface
  their orphaned appearance records in the cleanup preview.

## [7.6.47]
### Fixed
- **Orphaned appearances cleanup**: added `scorecard_id > 0` guard to the detection
  query so only appearances with an explicit (now-missing) scorecard post are flagged.
  Prevents false positives for old league records logged before scorecard IDs were
  tracked (`scorecard_id = 0`).

## [7.6.46]
### Changed
- **Form guide date**: played date now overrides fixture date in form pips across the league
  table, fixtures modal, and scorecard modal. Both the tooltip text and the `data-sc-date`
  attribute (used for scorecard lookup on pip click) now reflect the actual date played when
  it differs from the scheduled fixture date. Results are also re-sorted by effective played
  date before the last-5 slice, so a rescheduled fixture appears in correct chronological
  order. Fixed in both `lgw_cache_build_form_map()` (SSR, `lgw-div-cache.php`) and
  `buildFormMap()` (client-side, `lgw-widget.js`).

## [7.6.45]
### Fixed
- **Undefined function `lgw_get_option_array()`**: replaced all 8 call sites in `lgw-div-cache.php`
  with `get_option( '...', [] )` — affects `lgw_seasons`, `lgw_drive`, `lgw_score_overrides`,
  `lgw_badges`, and `lgw_club_badges`. Resolves CI test failure "Call to undefined function".

## [7.6.44]
### Fixed
- **Move/Withdraw "Entry not found in group"**: `sanitize_text_field()` was collapsing internal
  whitespace in the received entry name (e.g. a double space becomes single), causing a strict
  mismatch against the string as stored. Both handlers now use `trim(wp_unslash())` and resolve
  the canonical stored entry via `lgw_gchamp_norm_entry()` before any comparison or removal —
  the same normalised-comparison approach used by the working rename function.

## [7.6.43]
### Added
- **Manual Group Adjustments** panel in `lgw-gchamp` admin: move an entry between groups on the
  same day (removes their old fixtures, generates new fixtures vs all target-group members) or
  withdraw a dropped entry entirely (removes from group, day, and top-level entries list). Both
  actions reset the day KO bracket, which must be re-seeded afterwards. Scored fixtures require
  a second confirmation before deletion.
- **Copy Championship** in `lgw-gchamp`: duplicate any group championship to a new ID and title,
  preserving the full draw, scores, and settings. Available from the championships list and the
  edit page — useful for testing group adjustments on a copy before touching a live competition.

## [7.6.42]
### Changed
- Updated release workflow to include `lgw-clubs.php` in zip build and manifest verification.

## [7.6.41]
### Added
- **Club Facilities** section in Club Directory: greens count, rinks (auto-fills as greens × 6,
  overridable), floodlights, bar, changing rooms (boolean toggles), and car parking (none /
  on street / private). Stored as `facilities` key on each `lgw_clubs` record — no migration needed.

## [7.6.40]
### Fixed
- Fatal `TypeError` on the Scorecards admin page (and all LGW admin pages): `lgw_drive.sheets_tabs`
  stored as a JSON string caused `array_filter()` to receive `'{}'`, crashing the top-level menu
  handler and collapsing the entire LGW admin menu. `sheets_tabs` is now decoded defensively at
  all three read sites in `lgw-division-widget.php`.

## [7.6.39]
### Fixed
- Fatal `TypeError` on the Scorecards admin page (and all LGW admin pages): `lgw_drive.sheets_tabs`
  stored as a JSON string caused `array_filter()` to receive `'{}'`, crashing the top-level menu
  handler and collapsing the entire LGW admin menu. `sheets_tabs` is now decoded defensively at
  all three read sites in `lgw-division-widget.php`.

## [7.6.38]
### Added
- **Club Directory** (`lgw-clubs.php`): new `🏢 Clubs` admin sub-menu with a card-based index of
  all clubs. Click any card to open an inline edit panel — no page reload needed.
- Each club panel covers: name, address, website, passphrase (hashed as before), badge image/type,
  and contacts (Secretary, President, Green Keeper always shown; extra roles can be added freely).
- Contact data stored as additional keys on existing `lgw_clubs` WP option — no migration needed.
- AJAX save and delete per club; grid updates in-place after save.

## [7.6.37]
### Fixed
- `A. Other` is now treated as the same player as `A Other` for any team. `lgw_clean_player_name()` now calls `lgw_normalise_player_name()` internally, so dotted-initial normalisation is applied consistently at every call site (appearance logging, new-player detection, admin lookups).

## [7.6.36]
### Fixed
- gchamp Rename Entry only updated the top-level entries list, not group entries/fixtures, per-day KO brackets, or qualifiers — `foreach ( $champ['days'] ?? array() as &$day )` referenced a temporary copy from the `??` operator, so all writes through `$day`/`$group` were discarded. Rewrote the per-day/group loop to iterate `$champ['days']` directly via `!empty()`/`is_array()` checks.

## [7.6.35]
### Fixed
- Orphaned-appearances tidy-up now defaults to excluding `game_type='champ'` — championship appearances always have `scorecard_id=0` and would always match the "orphaned" criteria even when valid. `lgw_get_orphaned_appearances()` accepts a `$game_types` filter; new UI checkboxes (League/Cup checked, Championships unchecked) control which types preview includes.

## [7.6.34]
### Fixed
- Group Championship Rename Entry "not found" for entries visible in drawn groups — new `lgw_gchamp_collect_all_entries()` populates the dropdown from every occurrence across entries list, group entries/fixtures, brackets, and qualifiers. Matching uses new `lgw_gchamp_norm_entry()` (whitespace-normalised) so minor formatting drift no longer causes a mismatch.

## [7.6.33]
### Added
- Dry-run preview for the orphaned-appearances tidy-up tool (Players > Merge): `lgw_get_orphaned_appearances()` lists affected rows with player/club/team/match/date/rink/result/type; admin selects individual rows (or all/none) before `lgw_prune_orphaned_appearances($ids)` removes only the chosen ones and prunes resulting zero-appearance players.

## [7.6.32]
### Added
- Rename Entry tool for Group Championships, mirroring the existing champ.php tool. New `lgw_ajax_gchamp_rename_entry` walks `entries`, per-day `groups[].entries`/`fixtures`, per-day and top-level `ko_bracket` (both flat-rounds and nested `[round][match]` shapes), and `qualifiers` lists. New `lgw_ajax_gchamp_get_entries` companion for the dropdown.

## [7.6.31]
### Added
- One-off cleanup tool for orphaned appearances: Players > Merge tab has a "🧹 Tidy up" button calling new `lgw_prune_orphaned_appearances()` — removes appearance records for scorecards deleted before automatic cleanup existed, then runs `lgw_prune_orphaned_players()`.
### Fixed
- Removed duplicate appearance-cleanup call in `lgw_scorecard_on_delete` (already handled by `lgw_on_scorecard_deleted` in `lgw-players.php`).

## [7.6.30]
### Added
- `lgw_scorecard_on_delete` hooked on `wp_trash_post` and `before_delete_post`: deleting a confirmed scorecard now clears the Google Sheet result cells, removes matching `lgw_score_overrides`, restores the cached fixture to unplayed via new `lgw_sheets_clear_result()`/`lgw_cache_wipe_fixture_result()`, and removes player appearance records.

## [7.6.29]
### Fixed
- Cross-division concession contamination: `applyConcessionsToTable` (JS) and `lgw_apply_concessions_to_teams` (PHP) now require both teams to exist in the current division. A team playing in two divisions would incorrectly receive concession credits from the other division.

## [7.6.28]
### Fixed
- Conceded fixtures double-counted in standings — fixture overlay loop and `lgw_apply_concessions_to_teams` now skip entries with a `scorecard_id` (already counted via the scorecard/cache-merge pipeline).

## [7.6.27]
### Added
- Conceded Fixtures panel in the Scorecards admin page — lists all `lgw_concessions` entries with home, away, date, conceding team and a Clear button that fires the full wipe pipeline.

## [7.6.26]
### Fixed
- Clearing a concession now fully restores the fixture to unplayed state: removes matching score overrides, wipes cached fixture scores/played flag via new `lgw_cache_wipe_fixture_result()`, and trashes the auto-created scorecard.

## [7.6.25]
### Fixed
- SyntaxError: missing `)` — `chk.addEventListener` closing `});` was dropped during the `noChkMode` refactor in v7.6.24.

## [7.6.24]
### Fixed
- `bindConcedePanel` TypeError on null `chk.addEventListener` — introduced `noChkMode` flag before any `chk` access; clears both the scorecard loading failure and the non-functional Clear button in the played fixture modal.

## [7.6.23]
### Fixed
- Concession Clear button missing after save — once the cache marks the fixture as played, `showFixtureModal` opens instead of `showUnplayedFixtureModal`. Concession clear panel now injected into `showFixtureModal` for admins. Clearing removes the panel and trashes the auto-created scorecard via the existing PHP clear handler.

## [7.6.22]
### Fixed
- Clear concession button not functional after save — `bindConcedePanel` now correctly binds the Clear button in the no-checkbox (already-conceded) panel state.

## [7.6.21]
### Fixed
- Concede panel does not update after saving — swaps to conceded-notice state immediately with Clear button; clearing restores the unchecked panel without a page reload.

## [7.6.20]
### Fixed
- `ReferenceError: division is not defined` in `bindConcedePanel` — `division` added to function signature and both call sites.

## [7.6.19]
### Changed
- Debug logging added to concession AJAX handler.

## [7.6.18]
### Fixed
- Concession save AJAX silent failure — `do_action(lgw_scorecard_confirmed)` now wrapped in `try/catch` so a Sheets/Drive exception no longer prevents the JSON response from being sent. Added missing `return` after `wp_send_json_error()` on `wp_insert_post` failure.

## [7.6.17]
### Fixed
- Scorecard admin page fatal: `array_filter()` on a JSON string — `lgw_score_overrides` and related options were stored as JSON strings rather than PHP arrays. New `lgw_get_option_array()` helper decodes JSON transparently; applied across `lgw_drive`, `lgw_score_overrides`, `lgw_concessions`, `lgw_postponements`, `lgw_seasons`, `lgw_badges` in both `lgw-division-widget.php` and `lgw-div-cache.php`.

## [7.6.16]
### Fixed
- Scorecard listing `foreach` wrapped in `try/catch` — exceptions now logged to PHP error log with post ID, message, file/line and a `sc_data` snapshot. Affected rows render an inline error notice instead of killing the page.

## [7.6.15]
### Fixed
- Scorecard admin listing page critical error — `$sc` post meta can be `false` on new/malformed posts (including auto-created concession scorecards); added `is_array()` guard before all array key accesses in the listing loop.

## [7.6.14]
### Fixed
- Concession workflow now auto-creates a confirmed `lgw_scorecard` post (50–0 shots, ±max_pts points) and fires `lgw_scorecard_confirmed`, triggering Sheets writeback and cache merge via the standard pipeline.
- Clearing a concession voids the auto-created scorecard (moves to trash).
- `division` is now passed in the concession AJAX call so the scorecard is correctly linked to its sheet tab.

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
