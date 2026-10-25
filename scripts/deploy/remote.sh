#!/usr/bin/env bash
#
# Release management ON THE SERVER (Hostinger shared hosting, no root):
#
#   $APP_ROOT/releases/<release>/   one folder per deploy
#   $APP_ROOT/shared/.env           server settings (never in git)
#   $APP_ROOT/shared/storage/       logs, uploads, sessions, cache, backups
#   $APP_ROOT/current -> releases/<release>
#
# Usage (from the release that was just uploaded):
#   bash releases/<release>/scripts/deploy/remote.sh prepare  <release>
#   bash releases/<release>/scripts/deploy/remote.sh activate <release>
#   bash current/scripts/deploy/remote.sh rollback [release]   # default: the one before current
#   bash current/scripts/deploy/remote.sh list
#   bash current/scripts/deploy/remote.sh prune
#
# Settings (environment, or $APP_ROOT/deploy.env):
#   APP_ROOT       default: two levels above this script's release (…/app)
#   PHP_BIN        PHP 8.4 CLI, e.g. /opt/alt/php84/usr/bin/php (default: php)
#   COMPOSER_BIN   default: composer (run with PHP_BIN)
#   KEEP           releases to keep (default 5)
#   WEB_ROOT       the domain's web folder, e.g. $HOME/domains/<domain>/public_html (optional)
#   WEB_ROOT_MODE  symlink | forward | none (default none) — see docs/DEPLOYMENT.md §6
#   DRY_RUN=1      print what would happen, change nothing
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"  # physical path, also when run via current/
DEFAULT_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
APP_ROOT="${APP_ROOT:-$DEFAULT_ROOT}"
[ -f "$APP_ROOT/deploy.env" ] && set -a && . "$APP_ROOT/deploy.env" && set +a

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
KEEP="${KEEP:-5}"
WEB_ROOT="${WEB_ROOT:-}"
WEB_ROOT_MODE="${WEB_ROOT_MODE:-none}"
DRY_RUN="${DRY_RUN:-0}"

RELEASES="$APP_ROOT/releases"
SHARED="$APP_ROOT/shared"
CURRENT="$APP_ROOT/current"

log() { printf '\033[1m[deploy]\033[0m %s\n' "$*"; }
run() { if [ "$DRY_RUN" = "1" ]; then printf '  (dry-run) %s\n' "$*"; else "$@"; fi; }
die() { printf '\033[31m[deploy] %s\033[0m\n' "$*" >&2; exit 1; }
artisan() { local dir="$1"; shift; run "$PHP_BIN" "$dir/artisan" "$@"; }

# Point $2 (a symlink) at $1 in one step: GNU mv -T is atomic; BSD/macOS falls back to ln -sfh.
switch_link() {
    local target="$1" link="$2"
    if mv --version >/dev/null 2>&1; then
        run ln -sfn "$target" "$link.new"
        run mv -fT "$link.new" "$link"
    else
        run ln -sfh "$target" "$link"
    fi
}

current_release() { [ -L "$CURRENT" ] && basename "$(readlink "$CURRENT")" || true; }

ensure_shared() {
    run mkdir -p "$RELEASES" "$SHARED/storage/app/public" "$SHARED/storage/app/private" "$SHARED/storage/app/backups" \
        "$SHARED/storage/framework/cache/data" "$SHARED/storage/framework/sessions" "$SHARED/storage/framework/views" "$SHARED/storage/logs"
    [ -f "$SHARED/.env" ] || [ "$DRY_RUN" = "1" ] || die "Missing $SHARED/.env — create it first (docs/DEPLOYMENT.md §7)."
}

cmd_prepare() {
    local release="${1:?release name}" dir="$RELEASES/${1}"
    [ -d "$dir" ] || die "Release $dir not found (upload it first)."
    ensure_shared
    log "Preparing $release"

    # Shared .env and storage; public/storage → shared uploads (relative, so it works in every release).
    run rm -rf "$dir/storage" "$dir/.env" "$dir/public/storage" "$dir/public/hot"
    run ln -s "../../shared/storage" "$dir/storage"
    run ln -s "../../shared/.env" "$dir/.env"
    run ln -s "../storage/app/public" "$dir/public/storage"
    run mkdir -p "$dir/bootstrap/cache"

    log "composer install --no-dev"
    (cd "$dir" && run "$PHP_BIN" "$(command -v "$COMPOSER_BIN" || echo "$COMPOSER_BIN")" install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --prefer-dist --no-progress)

    # Fail before switching if the server .env is unsafe (ProductionGuard) or the code doesn't boot.
    artisan "$dir" config:cache
    artisan "$dir" route:cache
    artisan "$dir" view:cache
    artisan "$dir" event:cache
    log "Prepared $release"
}

cmd_activate() {
    local release="${1:?release name}" dir="$RELEASES/${1}" previous
    [ -d "$dir" ] || die "Release $dir not found."
    previous="$(current_release)"

    # Maintenance mode only while migrations run (and only if there are any).
    # A new database (no migrations table yet) makes migrate:status fail: that counts as pending.
    local pending=0 status
    if [ "$DRY_RUN" = "1" ]; then
        pending=1
    elif ! status="$("$PHP_BIN" "$dir/artisan" migrate:status --pending --no-ansi 2>&1)" || echo "$status" | grep -q "Pending"; then
        pending=1
    fi

    if [ "$pending" = "1" ]; then
        log "Migrations pending: maintenance mode"
        [ -n "$previous" ] && artisan "$CURRENT" down --retry=15 --refresh=15 --render="errors::503" || true
        artisan "$dir" migrate --force
    fi

    log "Switching current → $release"
    switch_link "releases/$release" "$CURRENT"
    [ -n "$previous" ] && [ "$previous" != "$release" ] && run sh -c "echo '$previous' > '$APP_ROOT/.previous'"

    artisan "$CURRENT" up || true
    artisan "$CURRENT" queue:restart
    web_root
    cmd_prune
    log "Live: $release"
}

cmd_rollback() {
    local target="${1:-}" now
    now="$(current_release)"

    if [ -z "$target" ]; then
        # The newest release older than the current one.
        target="$(ls -1 "$RELEASES" | sort | awk -v cur="$now" '$0 < cur' | tail -n 1)"
    fi

    [ -n "$target" ] && [ -d "$RELEASES/$target" ] || die "No release to roll back to."
    [ "$target" = "$now" ] && die "$target is already live."

    log "Rolling back $now → $target (database migrations are not undone)"
    switch_link "releases/$target" "$CURRENT"
    artisan "$CURRENT" up || true
    artisan "$CURRENT" queue:restart
    web_root
    log "Live: $target"
}

cmd_prune() {
    local live
    live="$(current_release)"
    ls -1 "$RELEASES" 2>/dev/null | sort -r | tail -n +"$((KEEP + 1))" | while read -r old; do
        [ "$old" = "$live" ] && continue
        log "Removing old release $old"
        run rm -rf "${RELEASES:?}/$old"
    done
}

cmd_list() {
    local live
    live="$(current_release)"
    ls -1 "$RELEASES" 2>/dev/null | sort -r | while read -r r; do
        if [ "$r" = "$live" ]; then echo "* $r (live)"; else echo "  $r"; fi
    done
}

# Make the domain's web folder serve current/public (docs/DEPLOYMENT.md §6).
web_root() {
    [ -z "$WEB_ROOT" ] || [ "$WEB_ROOT_MODE" = "none" ] && return 0

    case "$WEB_ROOT_MODE" in
        symlink)
            # public_html itself is a symlink to current/public (set up once; kept correct here).
            if [ -L "$WEB_ROOT" ]; then
                [ "$(readlink "$WEB_ROOT")" = "$CURRENT/public" ] || switch_link "$CURRENT/public" "$WEB_ROOT"
            elif [ -e "$WEB_ROOT" ]; then
                die "$WEB_ROOT is a real folder. Move it away once (docs §6) or use WEB_ROOT_MODE=forward."
            else
                switch_link "$CURRENT/public" "$WEB_ROOT"
            fi
            ;;
        forward)
            # public_html keeps a tiny index.php + .htaccess that load current/public; static files
            # come through symlinks (or copies if the host refuses symlinks).
            run mkdir -p "$WEB_ROOT"
            run cp "$CURRENT/scripts/deploy/public_html/index.php" "$WEB_ROOT/index.php"
            run cp "$CURRENT/public/.htaccess" "$WEB_ROOT/.htaccess"
            # Every other top-level file in public/ (favicon, robots.txt, service workers,
            # PWA manifest and icons, offline page) is copied; index.php stays the forwarder.
            for file in "$CURRENT"/public/*; do
                [ -f "$file" ] || continue
                case "$(basename "$file")" in index.php|hot) continue ;; esac
                run cp "$file" "$WEB_ROOT/"
            done
            for name in build storage; do
                local target="$CURRENT/public/$name"
                [ "$name" = "storage" ] && target="$SHARED/storage/app/public"
                if [ -L "$WEB_ROOT/$name" ] || [ ! -e "$WEB_ROOT/$name" ]; then
                    switch_link "$target" "$WEB_ROOT/$name"
                elif [ "$name" = "build" ]; then
                    run rsync -a --delete "$CURRENT/public/build/" "$WEB_ROOT/build/"
                fi
            done
            ;;
        *) die "Unknown WEB_ROOT_MODE=$WEB_ROOT_MODE" ;;
    esac
}

case "${1:-}" in
    prepare) shift; cmd_prepare "$@" ;;
    activate) shift; cmd_activate "$@" ;;
    rollback) shift; cmd_rollback "$@" ;;
    prune) cmd_prune ;;
    list) cmd_list ;;
    *) echo "Usage: $0 prepare|activate <release> | rollback [release] | list | prune"; exit 1 ;;
esac
