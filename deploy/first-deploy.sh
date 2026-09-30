#!/bin/bash
# first-deploy.sh — one-time production setup for parttime.q8ee.com.
#
# Run ONCE from the Mac (needs ssh root@alsharidah.shop). Idempotent: every
# step checks before it changes anything, so it can be re-run after a
# failure. Routine deploys afterwards: ./deploy/deploy.sh
#
# What it does on the server (DEPLOY.md steps 2-7 and 9):
#   database + user (password generated, kept in /root/.parttime-db-pass)
#   code sync, .env from the template with DB + mail + notify settings
#   composer install, APP_KEY, migrate, seed, admin account
#   (initial password generated, kept in /root/parttime-admin-initial.txt)
#   permissions, Apache vhost, certbot certificate + HTTPS redirect
#
# What it leaves for you (printed at the end):
#   TURNSTILE_SITE_KEY / TURNSTILE_SECRET and MAIL_PASSWORD in .env,
#   then `php artisan config:cache`; and DEPLOY.md step 9a (firewall).

set -euo pipefail

SERVER="root@alsharidah.shop"
REMOTE_BASE="/srv/www/parttime.q8ee.com"
APP_DIR="$REMOTE_BASE/app"
ADMIN_EMAIL="mishal@q8ee.com"
ADMIN_NAME="د. مشعل الشريده"
NOTIFY_EMAIL="mishal@q8ee.com"
MAIL_HOST="mail.q8ee.com"          # this server's mailcow, via deploy/mail-hostname.sh
MAIL_USER="noreply@q8ee.com"       # mailbox to create in mailcow (domain q8ee.com)

cd "$(dirname "$0")/.."

echo "🧪 Tests before first deploy..."
php artisan test --compact

echo "📁 Server directories..."
ssh "$SERVER" "mkdir -p $APP_DIR && ls -d $APP_DIR"

echo "🚀 First code sync..."
rsync -az --delete \
  --exclude '.git' \
  --exclude '.env' \
  --exclude 'node_modules' \
  --exclude 'vendor' \
  --exclude 'storage/app' \
  --exclude 'storage/logs' \
  --exclude 'storage/framework/cache' \
  --exclude 'storage/framework/sessions' \
  --exclude 'storage/framework/views' \
  --exclude 'storage/framework/testing' \
  --exclude 'tests' \
  --exclude 'database/imports' \
  --exclude 'deploy/.env.production' \
  --exclude 'database/*.sqlite' \
  --exclude '.superpowers' \
  --exclude '.phpunit.result.cache' \
  --exclude 'docs' \
  --exclude 'bootstrap/cache/*' \
  ./ "$SERVER:$APP_DIR/"

echo "⚙️  Server setup..."
ssh "$SERVER" bash -s <<REMOTE
set -euo pipefail
cd $APP_DIR
umask 077

# --- 2. Database ---
if [ ! -f /root/.parttime-db-pass ]; then
  openssl rand -base64 30 | tr -d '/+=' | cut -c1-32 > /root/.parttime-db-pass
fi
DBP=\$(cat /root/.parttime-db-pass)
# MySQL root needs a password on this server; the Debian maintenance account
# (root-only /etc/mysql/debian.cnf) has CREATE USER + GRANT rights.
MYSQL="mysql --defaults-file=/etc/mysql/debian.cnf"
\$MYSQL -e "CREATE DATABASE IF NOT EXISTS parttime CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          CREATE USER IF NOT EXISTS 'parttime'@'localhost' IDENTIFIED BY '\$DBP';
          ALTER USER 'parttime'@'localhost' IDENTIFIED BY '\$DBP';
          GRANT ALL ON parttime.* TO 'parttime'@'localhost'; FLUSH PRIVILEGES;"
mysql -u parttime -p"\$DBP" -e 'SELECT 1' parttime >/dev/null && echo "  db ok"

# --- 4. Environment ---
if [ ! -f .env ]; then
  cp deploy/.env.production.example .env
  echo "  .env created from template"
fi
setenv() { grep -q "^\$1=" .env && sed -i "s|^\$1=.*|\$1=\$2|" .env || echo "\$1=\$2" >> .env; }
setenv DB_PASSWORD "\$DBP"
setenv MAIL_MAILER smtp
setenv MAIL_HOST "$MAIL_HOST"
setenv MAIL_PORT 587
setenv MAIL_USERNAME "$MAIL_USER"
setenv MAIL_FROM_ADDRESS "$MAIL_USER"
setenv ADMIN_NOTIFY_EMAIL "$NOTIFY_EMAIL"
chmod 640 .env; chown root:www-data .env
rm -f deploy/.env.production.example

composer install --no-dev --optimize-autoloader --no-interaction --quiet
if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
  echo "  APP_KEY generated — back it up: grep ^APP_KEY $APP_DIR/.env"
fi

# --- 5. Migrate + seed ---
php artisan migrate --force
php artisan db:seed --class=ChecklistItemSeeder --force

# --- 6. Admin account ---
if [ ! -f /root/parttime-admin-initial.txt ]; then
  openssl rand -base64 24 | tr -d '/+=' | cut -c1-20 > /root/parttime-admin-initial.txt
fi
php artisan app:create-admin "$ADMIN_EMAIL" "$ADMIN_NAME" --password-file=/root/parttime-admin-initial.txt

# --- 7. Permissions + storage ---
mkdir -p storage/app/private/applications storage/app/private/generated storage/framework/{cache,sessions,views} storage/logs
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# --- 9. Apache vhost + certbot ---
cp deploy/apache-vhost.conf /etc/apache2/sites-available/parttime.q8ee.com.conf
a2enmod -q rewrite proxy proxy_fcgi headers ssl
a2ensite -q parttime.q8ee.com
apache2ctl configtest
systemctl reload apache2
if [ ! -d /etc/letsencrypt/live/parttime.q8ee.com ]; then
  certbot --apache -d parttime.q8ee.com --redirect --non-interactive --agree-tos -m "$ADMIN_EMAIL"
else
  echo "  certificate already present"
fi
echo "  server setup done"
REMOTE

echo "✅ Health check..."
STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://parttime.q8ee.com/up)
echo "   https://parttime.q8ee.com/up → $STATUS"

cat <<EOT

Remaining, by hand (DEPLOY.md steps 8, 9a, 11):
  1. Turnstile keys and the mailcow password for $MAIL_USER go into
     $APP_DIR/.env on the server, then: php artisan config:cache
       ssh $SERVER "cd $APP_DIR && sed -i 's|^TURNSTILE_SITE_KEY=.*|TURNSTILE_SITE_KEY=<site key>|; s|^TURNSTILE_SECRET=.*|TURNSTILE_SECRET=<secret>|; s|^MAIL_PASSWORD=.*|MAIL_PASSWORD=<mailbox password>|' .env && php artisan config:cache"
  2. Admin login: $ADMIN_EMAIL, initial password:
       ssh $SERVER cat /root/parttime-admin-initial.txt
     Change it via "forgot password" once mail works, then delete that file.
  3. Firewall (Cloudflare-only origin): DEPLOY.md step 9a.
EOT
