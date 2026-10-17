#!/usr/bin/env bash
#
# Does a change leave the design as it was? Snapshots every block before and after, and compares them.
#
# For moving a block from a stylesheet of its own to utilities, or changing a shared class or a token in a
# way that should look the same. Run it from the site's root folder, with the site served locally.
#
#   bash vendor/avocadesign/statamic-tools/scripts/design-check.sh before
#   ...make the change...
#   bash vendor/avocadesign/statamic-tools/scripts/design-check.sh after
#   bash vendor/avocadesign/statamic-tools/scripts/design-check.sh compare before after
#
# A run builds the assets and serves the site from that build: with the Vite dev server on, public/hot is
# set aside until the run ends, and only one run at a time is allowed. It then snapshots every page builder
# block and layout partial (the header, the page header, the footer) on the pages `php please
# avoca:site:urls` names, at a phone and a desktop
# width: every element's computed styles, matched by its place in the DOM rather than its class, and a
# screenshot of the block alone. Fonts are loaded first, and timers of a second or more never fire, so
# a carousel is taken on the slide it loads on. A run of the whole site replaces an earlier run of the
# same name. Serve the site with Herd or Valet: php artisan serve needs PHP_CLI_SERVER_WORKERS=8 or more.
#
# One block in each of its states, into the same run after the whole site:
#
#   design-check.sh before --block @proof_strip --state default \
#       --state "slide2=Alpine.\$data(root.querySelector('[x-data]')).go(1)" \
#       --state "dothover=hover:[aria-roledescription=carousel] button:nth-of-type(2)"
#
# Options for a run:
#   --url <path>        this page only; repeat for more. Default: the pages avoca:site:urls names.
#   --block <block>     one block: @<handle> for a page builder block, @layout/_header for any other
#                       template, or a CSS selector. Taken from the first page that has it.
#   --index <n>         the nth of that block on its page, counting from 0.
#   --state <state>     with --block: name=<js> or name=hover:<selector>, found by ARIA, data attributes
#                       or structure, never by the classes being changed. Repeat for each state.
#   --widths 390,1440   and --heights 844,900, the viewports.
#   --reduced           prefers-reduced-motion: reduce.
#   --scroll <how>      top (the default), silent (in view, before a scroll-triggered reveal), none or <px>.
#   --base <url>        the site's address. Default: APP_URL from .env.
#   --jobs <n>          how many blocks to take at once, each on a page of its own. Default: 1. Four
#                       is about three times quicker, but two runs of the same code agree less often.
#   --no-build          don't run npm run build first.
#   Also --name, --pad, --dpr, --no-png, --mask-images: see design-check/snapshot.mjs.
#
#   design-check.sh compare <before> <after> [<page>/<block>] [--threshold <n>] [--raw] ...
#
# Compare prints one line per block, SAME or DIFFERENT. Name a block, such as home/proof_strip, for
# every difference: each property as before -> after with the elements it touches, and a diff image
# for each screenshot that differs. A pixel differs when a channel moves more than 2 levels, which leaves
# out how Chrome resamples a photo between runs; --threshold 0 counts every level.
#
# Runs go in storage/design-check/<name>, which keeps itself out of git.
# Exit codes: 0 done, or the same; 1 a block failed, or differs; 2 could not run.

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
runs="storage/design-check"

fail() { echo; echo "FAILED: $1" >&2; exit "${2:-1}"; }
usage() { sed -n '3,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

[ $# -gt 0 ] || usage 2
case "$1" in -h|--help) usage ;; esac

[ -f artisan ] && [ -f package.json ] || fail "Run this from the site's root folder." 2
command -v node >/dev/null || fail "No node on the PATH." 2

# compare <before> <after> [<page>/<block>] [diff options]
if [ "$1" = compare ]; then
    [ $# -ge 3 ] || fail "compare needs two runs: design-check.sh compare before after" 2
    before="$runs/$2"
    after="$runs/$3"
    shift 3
    [ -d "$before" ] || fail "No run called $(basename "$before") in $runs." 2
    [ -d "$after" ] || fail "No run called $(basename "$after") in $runs." 2

    if [ $# -gt 0 ] && [[ "$1" != --* ]]; then
        block="$1"
        shift
        [ -d "$before/$block" ] || fail "No $block in $before. Its blocks are listed by design-check.sh compare." 2
        node "$here/design-check/diff.mjs" "$before/$block" "$after/$block" "$@"
        exit $?
    fi

    describe() {
        node -e '
            const r = JSON.parse(require("fs").readFileSync(process.argv[1] + "/run.json", "utf8"))
            console.log(`${process.argv[2]}: ${r.taken?.slice(0, 16).replace("T", " ") ?? "?"} UTC, ${r.commit ?? "no commit"}, CSS from the ${r.assets}, ${r.chrome}`)
        ' "$1" "$2" 2>/dev/null || echo "$2: $1"
    }
    describe "$before" "$(basename "$before")"
    describe "$after" "$(basename "$after")"
    echo

    blocks() { (cd "$1" && find . -mindepth 3 -maxdepth 3 -name meta.json | sed 's|^\./||; s|/meta\.json$||' | LC_ALL=C sort); }

    total=0
    different=0
    while IFS= read -r block; do
        [ -n "$block" ] || continue
        total=$((total + 1))
        if [ ! -d "$after/$block" ]; then
            echo "$block: DIFFERENT, missing from $(basename "$after")"
            different=$((different + 1))
            continue
        fi
        output="$(node "$here/design-check/diff.mjs" "$before/$block" "$after/$block" "$@" 2>&1 || true)"
        case "$output" in
            *"RESULT: SAME"*) echo "$block: SAME" ;;
            *"RESULT: DIFFERENT"*)
                where="$(printf '%s\n' "$output" | sed -n 's/^## \([^ ]*\) .*DIFFERENT.*/\1/p' | paste -sd, - | sed 's/,/, /g')"
                echo "$block: DIFFERENT${where:+ at $where}"
                different=$((different + 1)) ;;
            *)
                echo "$block: could not compare: $(printf '%s\n' "$output" | tail -1)"
                different=$((different + 1)) ;;
        esac
    done <<< "$(blocks "$before")"
    while IFS= read -r block; do
        [ -n "$block" ] || continue
        [ -d "$before/$block" ] && continue
        total=$((total + 1))
        echo "$block: DIFFERENT, new in $(basename "$after")"
        different=$((different + 1))
    done <<< "$(blocks "$after")"

    echo
    if [ "$different" -eq 0 ]; then
        echo "All $total blocks are the same."
        exit 0
    fi
    echo "$different of $total blocks differ. For what changed: design-check.sh compare $(basename "$before") $(basename "$after") <page>/<block>"
    exit 1
fi

# A run: <name> [options]
name="$1"
shift
[[ "$name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || fail "Name the run with letters, numbers, dots, dashes and underscores, such as before." 2

pages=()
pass=()
base=""
build=true
block=false
while [ $# -gt 0 ]; do
    case "$1" in
        --url) [ $# -ge 2 ] || fail "--url needs a path" 2; pages+=("$2"); shift 2 ;;
        --base) [ $# -ge 2 ] || fail "--base needs an address" 2; base="$2"; shift 2 ;;
        --no-build) build=false; shift ;;
        --block) block=true; pass+=("$1"); shift ;;
        *) pass+=("$1"); shift ;;
    esac
done

# A page from the static cache shows what the site looked like when it was cached, not the change.
strategy="$(grep -E '^STATAMIC_STATIC_CACHING_STRATEGY=' .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"'"'" || true)"
case "$strategy" in
    ''|null) ;;
    *) fail "The static cache is on (STATAMIC_STATIC_CACHING_STRATEGY=$strategy), so pages would come from the cache rather than the change. Set it to null in .env while you check." 2 ;;
esac

if [ -z "$base" ]; then
    base="$(grep -E '^APP_URL=' .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"'"'" || true)"
    [ -n "$base" ] || fail "No APP_URL in .env: give the site's address with --base." 2
fi
base="${base%/}"
curl -sS -o /dev/null --max-time 30 "$base/" 2>/dev/null || fail "Nothing answers at $base. Serve the site (Herd, Valet or php artisan serve), or give its address with --base." 2

if [ ${#pages[@]} -eq 0 ]; then
    command -v php >/dev/null || fail "No php on the PATH." 2
    list="$(php please avoca:site:urls --no-ansi)" || fail "php please avoca:site:urls" 2
    while IFS= read -r url; do
        [ -n "$url" ] && pages+=("$url")
    done <<< "$list"
fi
[ ${#pages[@]} -gt 0 ] || fail "No pages to check." 2

mkdir -p "$runs"
[ -f "$runs/.gitignore" ] || printf '*\n' > "$runs/.gitignore"

# One run at a time: a run sets public/hot aside and puts it back, and a second would put it back early.
lock="$runs/.running"
if [ -f "$lock" ] && kill -0 "$(cat "$lock" 2>/dev/null)" 2>/dev/null; then
    fail "Another design check is running (process $(cat "$lock")). Wait for it to finish." 2
fi
echo $$ > "$lock"
stash="$runs/hot"
# A run killed outright leaves the dev server's address in the stash: put it back, unless the dev server
# has written a new one since.
if [ -f "$stash" ]; then
    if [ -f public/hot ]; then rm -f "$stash"; else mv "$stash" public/hot; fi
fi
finish() {
    if [ -f "$stash" ]; then
        mv "$stash" public/hot
        echo "==> public/hot put back: the Vite dev server serves the site again"
    fi
    rm -f "$lock"
}
trap finish EXIT
trap 'exit 130' INT TERM

# Pages are served from the build this run makes, never from the Vite dev server. With public/hot present,
# every stylesheet and font comes from Vite, which with several pages open delivered fonts late: two runs
# of the same code came out different. So public/hot is set aside until the run ends.
if [ -f public/hot ]; then
    mv public/hot "$stash"
    echo "==> Served from the build: public/hot set aside until the run ends"
fi

# The build is what every page is drawn from, so a stale one would hide the change.
if [ "$build" = true ]; then
    echo "==> npm run build"
    log="$(mktemp)"
    npm run build >"$log" 2>&1 || { cat "$log" >&2; rm -f "$log"; fail "npm run build"; }
    rm -f "$log"
fi

out="$runs/$name"
# A run of the whole site is complete in itself, so nothing from an earlier one can stand in for a block
# that failed this time. A block's states are added to whatever run is there.
if [ "$block" = false ] && [ -d "$out" ]; then
    echo "==> Replacing the run called $name"
    rm -rf "$out"
fi

commit="$(git rev-parse --short HEAD 2>/dev/null || true)"
if [ -n "$commit" ] && [ -n "$(git status --porcelain --untracked-files=no 2>/dev/null)" ]; then
    commit="$commit with uncommitted changes"
fi

args=(--base "$base" --out "$out")
for page in "${pages[@]}"; do args+=(--page "$page"); done
[ -n "$commit" ] && args+=(--commit "$commit")

echo "==> Snapshots of $base into $out"
node "$here/design-check/snapshot.mjs" "${args[@]}" ${pass[@]+"${pass[@]}"}
