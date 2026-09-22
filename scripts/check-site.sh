#!/usr/bin/env bash
#
# Does this site still work? Installs from the lock files, builds, boots and renders it.
#
# Written for the updater to run after it changes a dependency, and for a developer to run by
# hand before pushing one. It reads the site; it changes no tracked file.
#
#   bash vendor/avocadesign/statamic-tools/scripts/check-site.sh [--audit] [--keep-env]
#
#   --audit     also report composer and npm advisories. Advisories never fail the check: a
#               site is not broken because a dependency has an advisory, and a failing check
#               that nobody can fix that morning is a check people learn to ignore.
#   --keep-env  leave .env.check behind, to poke at what the check saw.
#
# The site's own .env is never touched. The check writes .env.check and runs everything with
# APP_ENV=check, which Laravel loads instead, with Statamic Pro off: a runner has no licence
# key, and nothing the check does needs Pro.
#
# Exit codes: 0 the site renders, 1 it does not, 2 the check could not run.

set -euo pipefail

audit=false
keep_env=false
for arg in "$@"; do
    case "$arg" in
        --audit) audit=true ;;
        --keep-env) keep_env=true ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

fail() { echo; echo "FAILED: $1" >&2; exit "${2:-1}"; }
step() { echo; echo "==> $1"; }

# Quiet while it works, loud when it doesn't: a runner's log is only read when something broke.
output=$(mktemp)
run() { if ! "$@" >"$output" 2>&1; then cat "$output" >&2; return 1; fi; }

[ -f artisan ] && [ -f composer.lock ] || fail "Run this from the site's root folder." 2
command -v php >/dev/null || fail "No php on the PATH." 2
command -v composer >/dev/null || fail "No composer on the PATH." 2
command -v npm >/dev/null || fail "No npm on the PATH." 2

server_pid=""
cleanup() {
    [ -n "$server_pid" ] && kill "$server_pid" 2>/dev/null || true
    rm -f "$output"
    [ "$keep_env" = true ] || rm -f .env.check
}
trap cleanup EXIT

step "An environment of its own"
[ -f .env.example ] || fail "No .env.example to build the check's environment from." 2
cp .env.example .env.check
# Written after the copy so these win, whatever the example says.
cat >> .env.check <<'ENV'

APP_ENV=check
APP_DEBUG=false
STATAMIC_PRO_ENABLED=false
STATAMIC_LICENSE_KEY=
STATAMIC_STATIC_CACHING_STRATEGY=null
ENV
export APP_ENV=check
run php artisan key:generate --env=check --force || fail "Could not generate a key for the check." 2
echo "  .env.check, Statamic Pro off"

step "Dependencies from the lock files"
run composer install --no-interaction --prefer-dist --no-progress || fail "composer install"
run npm ci --no-audit --no-fund || fail "npm ci. A Node the site does not ask for is the usual reason: package.json says $(php -r 'echo json_decode(file_get_contents("package.json"), true)["engines"]["node"] ?? "no version";'), this is $(node -v)."
echo "  installed"

step "Assets"
run npm run build || fail "npm run build"
echo "  built"

# Flat file content needs no database. A site that has moved a driver to the database does.
if grep -qE '^(QUEUE_CONNECTION|CACHE_STORE|SESSION_DRIVER)=database' .env.check; then
    step "Database"
    db=$(grep -E '^DB_DATABASE=' .env.check | cut -d= -f2- | tr -d '"')
    [ -n "$db" ] || db="database/database.sqlite"
    [ -f "$db" ] || { mkdir -p "$(dirname "$db")"; : > "$db"; }
    run php artisan migrate --force || fail "migrate"
    echo "  migrated $db"
fi

step "The site's own checks"
run php please stache:refresh || fail "stache:refresh. The content did not load."
php please avoca:site:check --strict || fail "avoca:site:check --strict"

step "Rendering"
port=8391
while lsof -i ":$port" >/dev/null 2>&1; do port=$((port + 1)); done
php artisan serve --port="$port" --no-reload >/dev/null 2>&1 &
server_pid=$!
for _ in $(seq 1 40); do
    curl -fsS -o /dev/null "http://127.0.0.1:${port}/" 2>/dev/null && break
    sleep 0.5
done

urls=$(php -r '
    require "vendor/autoload.php";
    $file = "resources/site/updates.yaml";
    $urls = is_file($file) ? (Symfony\Component\Yaml\Yaml::parseFile($file)["urls"] ?? null) : null;
    foreach ($urls ?: ["/"] as $url) { echo $url, PHP_EOL; }
')
bad=0
while IFS= read -r url; do
    [ -n "$url" ] || continue
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${port}${url}")
    printf '  %s %s\n' "$code" "$url"
    [ "$code" = "200" ] || bad=$((bad + 1))
done <<< "$urls"
[ "$bad" -eq 0 ] || fail "$bad page(s) did not return 200."

if [ "$audit" = true ]; then
    step "Advisories, for the record"
    composer audit --format=summary || true
    npm audit --omit=dev || true
fi

echo
echo "The site installs, builds and renders."
