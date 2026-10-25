#!/usr/bin/env bash
#
# Deploy from your own computer (when GitHub Actions isn't available). Same steps as the workflow:
# tests → build → upload a new release → prepare → activate → health check → roll back on failure.
#
#   scripts/deploy/deploy.sh            # deploy
#   scripts/deploy/deploy.sh --dry-run  # show what would happen (rsync --dry-run, no changes)
#   scripts/deploy/deploy.sh --skip-tests
#   scripts/deploy/rollback.sh          # put the previous release back
#
# Settings in scripts/deploy/.deploy.env (git-ignored; copy .deploy.env.example):
#   SSH_HOST, SSH_PORT, SSH_USER, DEPLOY_PATH (…/domains/<domain>/app), APP_URL, HEALTH_TOKEN,
#   OPCACHE_RESET_TOKEN, PHP_BIN, WEB_ROOT, WEB_ROOT_MODE
set -euo pipefail

cd "$(dirname "$0")/../.."
ENV_FILE="${DEPLOY_ENV_FILE:-scripts/deploy/.deploy.env}"   # staging: DEPLOY_ENV_FILE=scripts/deploy/.deploy.env.staging
[ -f "$ENV_FILE" ] || { echo "Create $ENV_FILE (see .deploy.env.example)"; exit 1; }
set -a; . "$ENV_FILE"; set +a

DRY=0; TESTS=1
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY=1 ;;
        --skip-tests) TESTS=0 ;;
    esac
done

RELEASE="$(date -u +%Y%m%d-%H%M%S)-$(git rev-parse --short HEAD)"
SSH=(ssh -p "${SSH_PORT}" -o BatchMode=yes "${SSH_USER}@${SSH_HOST}")
REMOTE_ENV="APP_ROOT='${DEPLOY_PATH}' PHP_BIN='${PHP_BIN:-php}' KEEP='${KEEP:-5}' WEB_ROOT='${WEB_ROOT:-}' WEB_ROOT_MODE='${WEB_ROOT_MODE:-none}' DRY_RUN='${DRY}'"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "You have uncommitted changes. Commit them first so the release matches git ($RELEASE)."; exit 1
fi

if [ "$TESTS" = "1" ]; then
    say "Tests"
    php artisan test
fi

say "Build assets"
npm ci --no-audit --no-fund
npm run build
echo "$RELEASE" > RELEASE

say "Upload release $RELEASE"
RSYNC_FLAGS=(-az --delete --exclude-from=scripts/deploy/rsync-exclude.txt -e "ssh -p ${SSH_PORT}")
[ "$DRY" = "1" ] && RSYNC_FLAGS+=(--dry-run --itemize-changes)
"${SSH[@]}" "mkdir -p '${DEPLOY_PATH}/releases'"
# Start from the live release's files so only changes travel.
"${SSH[@]}" "[ -d '${DEPLOY_PATH}/current/' ] && [ '$DRY' = '0' ] && cp -a '${DEPLOY_PATH}/current/.' '${DEPLOY_PATH}/releases/${RELEASE}/' 2>/dev/null || true; mkdir -p '${DEPLOY_PATH}/releases/${RELEASE}'"
rsync "${RSYNC_FLAGS[@]}" ./ "${SSH_USER}@${SSH_HOST}:${DEPLOY_PATH}/releases/${RELEASE}/"

say "Prepare and activate"
"${SSH[@]}" "${REMOTE_ENV} bash '${DEPLOY_PATH}/releases/${RELEASE}/scripts/deploy/remote.sh' prepare '${RELEASE}' && ${REMOTE_ENV} bash '${DEPLOY_PATH}/releases/${RELEASE}/scripts/deploy/remote.sh' activate '${RELEASE}'"

[ "$DRY" = "1" ] && { say "Dry run finished: nothing was changed."; exit 0; }

say "OPcache reset and health check"
curl -fsS -X POST -H "X-Deploy-Token: ${OPCACHE_RESET_TOKEN}" "${APP_URL}/deploy/opcache-reset" || echo "(OPcache reset skipped)"

for i in 1 2 3 4 5; do
    if curl -fsS -H "X-Health-Token: ${HEALTH_TOKEN}" "${APP_URL}/health?scope=deploy" >/dev/null; then
        say "Deployed $RELEASE and healthy."
        exit 0
    fi
    sleep 5
done

say "Health check FAILED: rolling back"
"${SSH[@]}" "${REMOTE_ENV} bash '${DEPLOY_PATH}/current/scripts/deploy/remote.sh' rollback"
exit 1
