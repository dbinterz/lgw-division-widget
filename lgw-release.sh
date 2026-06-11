#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# lgw-release.sh  —  Commit, tag, and push a new lgw-division-widget release.
#
# Usage (from inside ~/nipgl-division-widget/):
#   ./lgw-release.sh "Fix: shots defeat shown as L in fixture modal"
#   ./lgw-release.sh   ← omit message to be prompted
#
# What it does:
#   1. Reads LGW_VERSION from lgw-division-widget.php
#   2. Checks all 4 version locations are in sync
#   3. Runs php -l on all .php files
#   4. Stages all changes (git add -A)
#   5. Commits with a conventional message
#   6. Tags vX.X.X
#   7. Pushes branch + tag  →  GitHub Actions builds the release zip
# ──────────────────────────────────────────────────────────────────────────────
set -euo pipefail

# ── Colours ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
ok()   { echo -e "${GREEN}✅ $*${NC}"; }
warn() { echo -e "${YELLOW}⚠️  $*${NC}"; }
err()  { echo -e "${RED}❌ $*${NC}"; exit 1; }
info() { echo -e "${CYAN}ℹ️  $*${NC}"; }

# ── Must be run from repo root ─────────────────────────────────────────────────
[[ -f "lgw-division-widget.php" ]] || err "Run this script from inside the lgw-division-widget repo root."

# ── Read version ──────────────────────────────────────────────────────────────
VERSION=$(grep -m1 "define('LGW_VERSION'" lgw-division-widget.php | sed "s/.*'LGW_VERSION', *'\([^']*\)'.*/\1/" | tr -d '\r\n')
[[ -n "$VERSION" ]] || err "Could not read LGW_VERSION from lgw-division-widget.php"
TAG="v${VERSION}"
info "Version: ${VERSION}  →  tag: ${TAG}"

# ── Check version is not already tagged ───────────────────────────────────────
if git rev-parse "$TAG" >/dev/null 2>&1; then
  err "Tag $TAG already exists. Bump the version before releasing."
fi

# ── Verify all 4 version locations match ──────────────────────────────────────
HEADER_VER=$(grep -m1 "^ \* Version:" lgw-division-widget.php | awk '{print $NF}' | tr -d '\r\n')
CONST_VER=$(grep -m1 "define('LGW_VERSION'" lgw-division-widget.php | sed "s/.*'LGW_VERSION', *'\([^']*\)'.*/\1/" | tr -d '\r\n')
README_STABLE=$(grep -m1 "^Stable tag:" readme.txt | awk '{print $NF}' | tr -d '\r\n')
README_CHANGELOG=$(grep -m1 "^= ${VERSION} =" readme.txt || true)

[[ "$HEADER_VER" == "$VERSION" ]] || err "Plugin header version ($HEADER_VER) != LGW_VERSION ($VERSION)"
[[ "$README_STABLE" == "$VERSION" ]] || err "readme.txt stable tag ($README_STABLE) != LGW_VERSION ($VERSION)"
[[ -n "$README_CHANGELOG" ]] || err "No changelog entry '= ${VERSION} =' found in readme.txt"
ok "All 4 version locations match: ${VERSION}"

# ── PHP syntax check ──────────────────────────────────────────────────────────
if command -v php &>/dev/null; then
  SYNTAX_FAIL=0
  while IFS= read -r -d '' f; do
    if ! php -l "$f" &>/dev/null; then
      echo -e "${RED}  Syntax error: $f${NC}"
      php -l "$f" || true
      SYNTAX_FAIL=1
    fi
  done < <(find . -maxdepth 1 -name "lgw-*.php" -print0)
  [[ $SYNTAX_FAIL -eq 0 ]] && ok "PHP syntax clean" || err "Fix PHP syntax errors before releasing."
else
  warn "php not found — skipping syntax check"
fi

# ── Check for uncommitted changes (anything to commit?) ───────────────────────
if git diff --quiet && git diff --cached --quiet && [[ -z "$(git status --porcelain)" ]]; then
  warn "Nothing to commit — working tree is clean."
  read -rp "Tag and push anyway? [y/N] " CONFIRM
  [[ "${CONFIRM,,}" == "y" ]] || { info "Aborted."; exit 0; }
fi

# ── Commit message ────────────────────────────────────────────────────────────
if [[ -n "${1:-}" ]]; then
  SUBJECT="$1"
else
  echo ""
  read -rp "Commit message (summary line): " SUBJECT
  [[ -n "$SUBJECT" ]] || err "Commit message cannot be empty."
fi

# Build conventional commit body from readme.txt changelog for this version
CHANGELOG_BODY=$(awk "/^= ${VERSION} =/{found=1; next} found && /^= /{exit} found && NF{print}" readme.txt | sed 's/^\* /- /' || true)

COMMIT_MSG="release: v${VERSION}

${SUBJECT}
$([ -n "$CHANGELOG_BODY" ] && echo -e "\nChangelog:\n${CHANGELOG_BODY}")"

# ── Stage, commit, tag, push ──────────────────────────────────────────────────
echo ""
info "Staging all changes..."
git add -A

info "Committing..."
git commit -m "$COMMIT_MSG" || { warn "Nothing new to commit (all changes already staged)."; }

info "Tagging ${TAG}..."
git tag "$TAG"

info "Pushing branch + tag..."
BRANCH=$(git rev-parse --abbrev-ref HEAD)
git push origin "$BRANCH"
git push origin "$TAG"

echo ""
ok "Released ${TAG} — GitHub Actions will build the zip."
echo -e "${CYAN}   https://github.com/dbinterz/lgw-division-widget/actions${NC}"
echo -e "${CYAN}   https://github.com/dbinterz/lgw-division-widget/releases/tag/${TAG}${NC}"
