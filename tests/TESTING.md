# LGW Test Suite — Local Development Guide

## Overview

The test suite has two layers:

| Layer | Tool | What it tests | Needs WP? |
|---|---|---|---|
| **Unit tests** | PHPUnit | PHP cache/CSV logic in isolation | No |
| **E2E tests** | Playwright | Full widget in browser against real WP | Yes |

---

## Prerequisites

### PHP Unit Tests

```bash
# In repo root — install PHP deps (one-time)
composer install

# Run
cd tests && ../vendor/bin/phpunit --configuration phpunit.xml --testdox
```

Requirements: `php-xml`, `php-mbstring`, `php-dom` installed.  
On Crostini: `sudo apt install php-xml php-mbstring php-dom`

### Playwright E2E Tests

1. **Docker WP running** — `nipgl-local_wordpress_1` container up
2. **Plugin activated** in that WP instance
3. **Test page exists** — a published page at `/test-division/` with shortcode:
   ```
   [lgw_division csv="..." title="Division 1" promote="1" relegate="1" max_points="7"]
   ```
4. **`WP_ENVIRONMENT_TYPE=local`** set in `wp-config.php` (enables `lgw_seed_test_clubs()`)
5. **Node.js** installed (v18+)

---

## First-time Setup

```bash
# From repo root
cd tests

# Copy and edit env (defaults work for the standard Docker stack)
cp .env.example .env

# Install Node deps
npm install

# Install Playwright browsers (one-time, ~200MB)
npx playwright install chromium
```

---

## Running the Tests

### All E2E tests (headless)
```bash
cd tests
npx playwright test
```

### P1 (critical path) only
```bash
npx playwright test --grep @P1
```

### Single spec file
```bash
npx playwright test e2e/division-widget.spec.js
npx playwright test e2e/cache-sync.spec.js
npx playwright test e2e/scorecard-submit.spec.js
npx playwright test e2e/scorecard-modal.spec.js
npx playwright test e2e/admin-settings.spec.js
```

### Headed mode (see the browser)
```bash
npx playwright test --headed
```

### Interactive UI mode
```bash
npx playwright test --ui
```

### View HTML report after a run
```bash
npx playwright show-report
```

### PHP unit tests
```bash
cd tests && ../vendor/bin/phpunit --configuration phpunit.xml --testdox
```

---

## Environment Variables

Override defaults via `tests/.env` or shell environment:

| Variable | Default | Description |
|---|---|---|
| `LGW_BASE_URL` | `http://localhost:8080` | WordPress site URL |
| `LGW_TEST_PAGE` | `/test-division/` | Page with `[lgw_division]` shortcode |
| `WP_CONTAINER` | `nipgl-local_wordpress_1` | Docker container name for WP-CLI |
| `WP_USER` | `admin` | WP admin username |
| `WP_PASS` | `password` | WP admin password |

---

## Test Data

- **CSV fixtures** live in `tests/e2e/fixtures/` and are intercepted via `page.route()` — no real Google Sheets calls in tests.
- **Test clubs** are seeded into the WP DB via `lgw_seed_test_clubs()` (PHP) called from `seedTestClubs()` in `wp-login.js`. This function only registers when `WP_ENVIRONMENT_TYPE === 'local'`.
- **Scorecards** are cleaned up with `deleteAllScorecards()` in `beforeEach` hooks.
- **Division cache** is managed per-test — tests that need a warm cache call `lgw_cache_sync_all()` via WP-CLI; tests that need a cold cache call `clearDivisionCache()`.

---

## Spec Map

| File | Tests | Description |
|---|---|---|
| `division-widget.spec.js` | DW-01–10 | Widget rendering, SSR path, filters, fallback |
| `cache-sync.spec.js` | CS-01–06 | Cache population, invalidation, TTL behaviour |
| `scorecard-submit.spec.js` | SS-01–09 | Login gate, submission, two-club confirmation |
| `scorecard-modal.spec.js` | SM-01–04 | Modal display for played/disputed fixtures |
| `admin-settings.spec.js` | AS-01–04 | Cache health panel, sync button |

---

## Troubleshooting

**`lgw_seed_test_clubs is undefined`**  
Check `WP_ENVIRONMENT_TYPE=local` is set in `wp-config.php` inside the container:
```bash
docker exec nipgl-local_wordpress_1 wp config get WP_ENVIRONMENT_TYPE --allow-root
```
If missing:
```bash
docker exec nipgl-local_wordpress_1 wp config set WP_ENVIRONMENT_TYPE local --allow-root
```

**Test page not found (404)**  
Create it:
```bash
docker exec nipgl-local_wordpress_1 wp post create \
  --post_type=page \
  --post_title="Test Division" \
  --post_name="test-division" \
  --post_status=publish \
  --post_content='[lgw_division csv="" title="Division 1" promote="1" relegate="1" max_points="7"]' \
  --allow-root
```

**`data-prerendered` never "1" (SSR not triggering)**  
The cache may be empty or stale. Prime it:
```bash
docker exec nipgl-local_wordpress_1 wp eval 'lgw_cache_sync_all();' --allow-root
```

**`Cannot connect to Docker container`**  
Verify the container name matches `WP_CONTAINER` in `.env`:
```bash
docker ps --format '{{.Names}}'
```

**PHPUnit: `LGW_CACHE_HARD_TTL already defined`**  
The constant is defined in `lgw-div-cache.php` at line 21. Do **not** redefine it in `tests/unit/bootstrap.php`.

---

## CI

PHPUnit runs automatically on every push to `main` via `.github/workflows/test.yml`.  
Playwright is disabled in CI (`if: false`) — run locally only.
