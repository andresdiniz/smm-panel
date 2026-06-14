#!/bin/bash
set -e

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$APP_DIR"

mkdir -p var/log var/cache

APP_ENV=prod /usr/bin/php bin/console messenger:consume scheduler_default \
    --limit=5 \
    --time-limit=55 \
    >> var/log/scheduler_default.log 2>&1
