#!/bin/bash
cd /home/u629736858/domains/acheireviews.com.br/public_html
mkdir -p var/log
APP_ENV=prod /usr/bin/php bin/console messenger:consume scheduler_default --limit=1 --time-limit=50 2>> var/log/scheduler_default.log
