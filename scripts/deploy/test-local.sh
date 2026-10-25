#!/usr/bin/env bash
#
# Exercises scripts/deploy/remote.sh against a throw-away folder that mimics the Hostinger
# layout (releases/, shared/, current, public_html). Uses SQLite and APP_ENV=staging.
#   scripts/deploy/test-local.sh
set -euo pipefail
cd "$(dirname "$0")/../.."
REPO="$(pwd)"
TMP="$(mktemp -d)"
APP="$TMP/domains/example.test/app"
WEB="$TMP/domains/example.test/public_html"
WEB2="$TMP/domains/example.test/public_html_forward"
PHP_BIN="$(command -v php)"
export APP_ROOT="$APP" PHP_BIN KEEP=2
fail() { echo "FAIL: $*"; exit 1; }
ok() { echo "  ok: $*"; }

mkdir -p "$APP/shared" "$APP/releases"
cat > "$APP/shared/.env" <<ENV
APP_NAME="Retail ERP"
APP_ENV=staging
APP_KEY=base64:$(head -c 32 /dev/urandom | base64)
APP_DEBUG=false
APP_URL=http://127.0.0.1:8130
APP_TIMEZONE=Asia/Colombo
DB_CONNECTION=sqlite
DB_DATABASE=$APP/shared/database.sqlite
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
HEALTH_TOKEN=test-health-token
OPCACHE_RESET_TOKEN=test-opcache-token
FORCE_HTTPS=false
ENV
touch "$APP/shared/database.sqlite"

new_release() {
    local name="$1"
    mkdir -p "$APP/releases/$name"
    [ -L "$APP/current" ] && cp -a "$APP/current/." "$APP/releases/$name/"
    rsync -a --delete --exclude-from=scripts/deploy/rsync-exclude.txt "$REPO/" "$APP/releases/$name/"
    echo "$name" > "$APP/releases/$name/RELEASE"
    bash "$APP/releases/$name/scripts/deploy/remote.sh" prepare "$name" >/dev/null
}

echo "1. First release: prepare + activate (migrations run)"
new_release 20260101-000001
WEB_ROOT="$WEB" WEB_ROOT_MODE=symlink bash "$APP/releases/20260101-000001/scripts/deploy/remote.sh" activate 20260101-000001 | sed 's/^/     /'
[ "$(readlink "$APP/current")" = "releases/20260101-000001" ] || fail "current not switched"; ok "current → 20260101-000001"
[ -L "$APP/current/storage" ] && [ -L "$APP/current/.env" ] && [ -L "$APP/current/public/storage" ] || fail "shared links"; ok "shared .env, storage and public/storage linked"
[ -f "$APP/current/bootstrap/cache/config.php" ] && [ -f "$APP/current/bootstrap/cache/routes-v7.php" ] || fail "caches"; ok "config/route caches built"
[ ! -d "$APP/current/vendor/pestphp" ] || fail "dev packages installed"; ok "composer --no-dev (no pest in vendor)"
"$PHP_BIN" "$APP/current/artisan" migrate:status --pending --no-ansi | grep -q "Pending" && fail "migrations still pending"; ok "migrations applied"
[ "$(readlink "$WEB")" = "$APP/current/public" ] || fail "web root symlink"; ok "public_html → current/public (symlink mode)"

echo "2. Second release (no pending migrations: no maintenance mode)"
new_release 20260101-000002
out="$(bash "$APP/releases/20260101-000002/scripts/deploy/remote.sh" activate 20260101-000002)"
echo "$out" | grep -q "maintenance" && fail "maintenance mode without migrations"; ok "no maintenance mode"
[ "$(readlink "$APP/current")" = "releases/20260101-000002" ] || fail "switch 2"; ok "current → 20260101-000002"
[ "$(cat "$APP/.previous")" = "20260101-000001" ] || fail ".previous"; ok ".previous = 20260101-000001"

echo "3. Rollback"
bash "$APP/current/scripts/deploy/remote.sh" rollback >/dev/null
[ "$(readlink "$APP/current")" = "releases/20260101-000001" ] || fail "rollback"; ok "rolled back to 20260101-000001"
bash "$APP/current/scripts/deploy/remote.sh" rollback 20260101-000002 >/dev/null
[ "$(readlink "$APP/current")" = "releases/20260101-000002" ] || fail "roll forward"; ok "named rollback (forward) to 20260101-000002"

echo "4. Keep the last $KEEP releases"
new_release 20260101-000003
bash "$APP/releases/20260101-000003/scripts/deploy/remote.sh" activate 20260101-000003 >/dev/null
[ "$(ls "$APP/releases" | wc -l | tr -d ' ')" = "2" ] && [ ! -d "$APP/releases/20260101-000001" ] || fail "prune"; ok "oldest release removed, 2 kept"

echo "5. Dry run changes nothing"
before="$(readlink "$APP/current")"
dry="$(DRY_RUN=1 bash "$APP/current/scripts/deploy/remote.sh" activate 20260101-000002)"
echo "$dry" | grep -q "(dry-run)" || fail "dry-run output"
[ "$(readlink "$APP/current")" = "$before" ] || fail "dry-run switched"; ok "dry-run printed steps, current unchanged"

echo "6. Forward web root (no symlinked public_html) + serving through it"
WEB_ROOT="$WEB2" WEB_ROOT_MODE=forward bash "$APP/current/scripts/deploy/remote.sh" rollback 20260101-000002 >/dev/null
[ -f "$WEB2/index.php" ] && [ -L "$WEB2/build" ] && [ -f "$WEB2/.htaccess" ] || fail "forward files"; ok "public_html/index.php + .htaccess + build link"
[ -f "$WEB2/robots.txt" ] && [ -f "$WEB2/favicon.ico" ] || fail "forward: top-level public files missing"; ok "public_html has robots.txt and the favicon"
grep -q "require" "$WEB2/index.php" || fail "forward index.php was overwritten"; ok "forwarding index.php kept"
"$PHP_BIN" -S 127.0.0.1:8130 -t "$WEB2" "$WEB2/index.php" >"$TMP/server.log" 2>&1 &
SERVER=$!; sleep 1.5
health="$(curl -s -o /dev/null -w '%{http_code}' -H 'X-Health-Token: test-health-token' 'http://127.0.0.1:8130/health?scope=deploy')"
nohealth="$(curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:8130/health')"
login="$(curl -s -D - -o /dev/null 'http://127.0.0.1:8130/login')"
kill $SERVER
[ "$health" = "200" ] || fail "health $health ($(tail -5 "$TMP/server.log"))"; ok "/health?scope=deploy = 200 through the forwarding index.php"
[ "$nohealth" = "404" ] || fail "health without token $nohealth"; ok "/health without token = 404"
echo "$login" | grep -i "^cache-control:" | grep -qi "no-store" || fail "no-store header: $login"; ok "pages are Cache-Control: no-store"
echo "$login" | grep -qi "content-security-policy" || fail "csp header"; ok "Content-Security-Policy sent"

rm -rf "$TMP"
echo "All deploy script checks passed."
