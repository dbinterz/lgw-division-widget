#!/usr/bin/env bash
#
# bump-version.sh — bump the LGW plugin version using the yyyy.ww.n scheme.
#
#   yyyy = ISO year, ww = ISO week (both from today), n = release-within-week.
#   When the ISO year+week has rolled over since the last release, n resets to 0;
#   otherwise n increments. This stops the counter drifting (e.g. staying on
#   week 27 into week 30).
#
# Usage:
#   bin/bump-version.sh              # auto: new week -> .0, same week -> n+1
#   bin/bump-version.sh --set 2026.30.4   # force an explicit version
#   bin/bump-version.sh --dry-run    # print what it would do, change nothing
#
# Updates, in lockstep:
#   - lgw-division-widget.php  "Version:" header + LGW_VERSION constant
#   - readme.txt               "Stable tag:"
# It does NOT write changelog prose — add the "= <version> =" (readme.txt) and
# "## [<version>]" (README.md) entries yourself so the notes stay meaningful.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="$ROOT/lgw-division-widget.php"
README_TXT="$ROOT/readme.txt"

DRY_RUN=0
FORCE_VERSION=""
while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --set)     FORCE_VERSION="${2:-}"; shift 2 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

current="$(grep -oP "define\('LGW_VERSION', '\K[^']+" "$MAIN")"
[ -n "$current" ] || { echo "Could not read current LGW_VERSION from $MAIN" >&2; exit 1; }

if [ -n "$FORCE_VERSION" ]; then
    new="$FORCE_VERSION"
else
    yw="$(date +%G.%V)"          # ISO year.week, e.g. 2026.30
    cur_yw="${current%.*}"        # strip trailing .n  -> yyyy.ww
    cur_n="${current##*.}"        # trailing n
    if [ "$yw" = "$cur_yw" ]; then
        new="${yw}.$((cur_n + 1))"
    else
        new="${yw}.0"
    fi
fi

# Validate shape yyyy.ww.n
if ! [[ "$new" =~ ^[0-9]{4}\.[0-9]{1,2}\.[0-9]+$ ]]; then
    echo "Computed version '$new' is not yyyy.ww.n" >&2; exit 1
fi

echo "current: $current"
echo "new:     $new"

if [ "$DRY_RUN" = "1" ]; then
    echo "(dry run — no files changed)"
    exit 0
fi

# Portable in-place sed (GNU and BSD)
sed_i() { if sed --version >/dev/null 2>&1; then sed -i "$@"; else sed -i '' "$@"; fi; }

sed_i "s/^\( \* Version: \).*/\1${new}/"                         "$MAIN"
sed_i "s/define('LGW_VERSION', '[^']*')/define('LGW_VERSION', '${new}')/" "$MAIN"
sed_i "s/^Stable tag: .*/Stable tag: ${new}/"                    "$README_TXT"

echo "Updated:"
grep -n "Version: \|LGW_VERSION" "$MAIN" | head -2
grep -n "Stable tag:" "$README_TXT"
echo
echo "Next: add changelog entries for ${new}:"
echo "  readme.txt : = ${new} ="
echo "  README.md  : ## [${new}]"
