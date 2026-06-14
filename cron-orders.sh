#!/bin/bash
set -e

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$APP_DIR"

mkdir -p var/log var/cache

APP_ENV=prod /usr/bin/php bin/console messenger:consume orders_high \
    --limit=30 \
    --time-limit=55 \
    >> var/log/orders_high.log 2>&1

APP_ENV=prod /usr/bin/php bin/console messenger:consume orders_medium \
    --limit=20 \
    --time-limit=55 \
    >> var/log/orders_medium.log 2>&1

APP_ENV=prod /usr/bin/php bin/console messenger:consume orders_low \
    --limit=10 \
    --time-limit=55 \
    >> var/log/orders_low.log 2>&1
