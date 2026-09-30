#!/bin/bash
# Nightly backup for parttime.q8ee.com: DB + .env (APP_KEY — without it the
# encrypted civil IDs/IBANs are unreadable) + applicant documents and generated
# files under storage/app/private. Keeps 14 days. Installed by
# deploy/install-backup.sh to /usr/local/bin, run by /etc/cron.d/parttime-q8ee-backup.
set -e
D=/srv/backups/parttime.q8ee.com
TS=$(date +%Y%m%d)
mkdir -p "$D"; chmod 700 "$D"
mysqldump --defaults-file=/etc/mysql/debian.cnf --single-transaction parttime | gzip > "$D/db-$TS.sql.gz"
cp /srv/www/parttime.q8ee.com/app/.env "$D/env-$TS"
tar -czf "$D/private-$TS.tar.gz" -C /srv/www/parttime.q8ee.com/app/storage/app private 2>/dev/null || true
chmod 600 "$D"/*
find "$D" -type f -mtime +14 -delete
