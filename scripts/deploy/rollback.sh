#!/usr/bin/env bash
# One command to put the previous release back (or a named one): scripts/deploy/rollback.sh [release]
# On the server directly: bash ~/domains/<domain>/app/current/scripts/deploy/remote.sh rollback
set -euo pipefail
cd "$(dirname "$0")/../.."
ENV_FILE="${DEPLOY_ENV_FILE:-scripts/deploy/.deploy.env}"   # staging: DEPLOY_ENV_FILE=scripts/deploy/.deploy.env.staging
set -a; . "$ENV_FILE"; set +a
ssh -p "${SSH_PORT}" "${SSH_USER}@${SSH_HOST}" \
    "APP_ROOT='${DEPLOY_PATH}' PHP_BIN='${PHP_BIN:-php}' WEB_ROOT='${WEB_ROOT:-}' WEB_ROOT_MODE='${WEB_ROOT_MODE:-none}' bash '${DEPLOY_PATH}/current/scripts/deploy/remote.sh' rollback ${1:-} && bash '${DEPLOY_PATH}/current/scripts/deploy/remote.sh' list"
