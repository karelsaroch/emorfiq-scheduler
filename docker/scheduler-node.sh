#!/usr/bin/env bash
# Simuluje systémový cron jednoho serveru:
#   * * * * * cd /app/e2e/app && php artisan scheduler:tick
# Všechny uzly tickají ve stejnou sekundu → skutečný souběh nad sdílenými zámky.
set -uo pipefail
cd /app/e2e/app

echo "[$(hostname)] scheduler node started, engine tick every minute"
while true; do
    sleep $(( 60 - $(date +%-S) ))
    started=$(date +%T)
    php artisan scheduler:tick > /dev/null
    echo "[$(hostname)] tick at ${started} -> exit $?"
done
