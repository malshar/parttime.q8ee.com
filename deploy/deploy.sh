#!/bin/bash
# deploy.sh — نظام المنتدبين (parttime.q8ee.com)
# Modeled on help.q8ee.com's deploy script.
#
# Usage:
#   ./deploy/deploy.sh           rsync app + composer install + migrate + caches
#   ./deploy/deploy.sh --dry     show what would be synced, change nothing
#
# First-time server setup (vhost, DB, .env, DNS) is documented in DEPLOY.md.

set -e

SERVER="root@alsharidah.shop"
REMOTE_BASE="/srv/www/parttime.q8ee.com"
APP_DIR="$REMOTE_BASE/app"

RSYNC_FLAGS="-az --delete"
if [[ "$1" == "--dry" ]]; then
  RSYNC_FLAGS="$RSYNC_FLAGS --dry-run -v"
  echo "🔍 DRY RUN — nothing will change."
fi

cd "$(dirname "$0")/.."

echo "🧪 Running tests before deploying..."
if [[ "$1" != "--dry" ]]; then
  php artisan test --compact || { echo "❌ Tests failed — aborting deploy."; exit 1; }
fi

echo "🚀 Syncing application to $SERVER:$APP_DIR ..."
rsync $RSYNC_FLAGS \
  --exclude '.git' \
  --exclude '.env' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude 'storage/app' \
  --exclude 'storage/logs' \
  --exclude 'storage/framework/cache' \
  --exclude 'storage/framework/sessions' \
  --exclude 'storage/framework/views' \
  --exclude 'tests' \
  --exclude 'database/imports' \
  --exclude 'deploy/.env.production.example' \
  ./ "$SERVER:$APP_DIR/"

[[ "$1" == "--dry" ]] && exit 0

echo "⚙️  Composer install + migrate + caches..."
ssh "$SERVER" "set -e; cd $APP_DIR && \
  composer install --no-dev --optimize-autoloader --no-interaction && \
  php artisan migrate --force && \
  php artisan db:seed --class=ChecklistItemSeeder --force && \
  php artisan config:cache && php artisan route:cache && php artisan view:cache && \
  mkdir -p storage/app/private/applications storage/app/private/generated && \
  chown -R www-data:www-data storage bootstrap/cache && \
  chmod -R ug+rwX storage bootstrap/cache"

echo "✅ Verifying health..."
STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://parttime.q8ee.com/up)
if [ "$STATUS" = "200" ]; then
  echo "✅ Health check: OK (200)"
else
  echo "❌ Health check returned $STATUS — inspect: ssh $SERVER 'tail -50 $APP_DIR/storage/logs/laravel.log'"
  exit 1
fi

echo ""
echo "🌐 Live at: https://parttime.q8ee.com"
