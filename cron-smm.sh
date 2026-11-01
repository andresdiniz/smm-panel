#!/bin/bash
set -e

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$APP_DIR"

mkdir -p var/log var/cache

heartbeat="var/log/scheduler_smm.heartbeat"
printf '%s started\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$heartbeat"

if APP_ENV=prod /usr/bin/php bin/console messenger:consume scheduler_smm \
    --limit=5 \
    --time-limit=55 \
    >> var/log/scheduler_smm.log 2>&1; then
    printf '%s completed\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$heartbeat"
else
    status=$?
    printf '%s failed (exit %s)\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$status" > "$heartbeat"
    exit "$status"
fi
