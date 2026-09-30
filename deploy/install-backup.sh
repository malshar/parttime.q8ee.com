#!/bin/bash
# install-backup.sh — install the nightly backup script + cron on the server
# (mirrors /etc/cron.d/help-q8ee-backup). Idempotent; runs one backup now.
set -euo pipefail
SERVER="root@alsharidah.shop"
cd "$(dirname "$0")"
scp -q parttime-q8ee-backup.sh "$SERVER:/usr/local/bin/parttime-q8ee-backup.sh"
ssh "$SERVER" 'chmod 700 /usr/local/bin/parttime-q8ee-backup.sh
printf "%s\n" "45 2 * * * root /usr/local/bin/parttime-q8ee-backup.sh >> /srv/backups/parttime.q8ee.com/backup.log 2>&1" > /etc/cron.d/parttime-q8ee-backup
chmod 644 /etc/cron.d/parttime-q8ee-backup
mkdir -p /srv/backups/parttime.q8ee.com
/usr/local/bin/parttime-q8ee-backup.sh && ls -l /srv/backups/parttime.q8ee.com'
