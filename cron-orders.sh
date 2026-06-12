#!/bin/bash
cd /home/u629736858/domains/acheireviews.com.br/public_html
mkdir -p var/log
APP_ENV=prod /usr/bin/php bin/console messenger:consume orders_high orders_medium orders_low --limit=10 --time-limit=50 2>> var/log/orders.log
