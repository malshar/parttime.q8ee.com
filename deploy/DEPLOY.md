# DEPLOY.md — First-time production setup for parttime.q8ee.com

Target: the same Ubuntu + Apache server that hosts help.q8ee.com
(SSH: `root@alsharidah.shop`). Routine updates afterwards use
`./deploy/deploy.sh`.

**Status:** milestone 1 (intake) is implemented on branch `milestone-1-intake`
(`php artisan test` green). Nothing below has been run against the real
server yet — this is the checklist for when Dr. Mishal is ready to do the
first deploy.

## 0. Prerequisites to verify on the server

```bash
ssh root@alsharidah.shop
php -v                 # need PHP >= 8.2
php -m | grep -E 'mbstring|xml|curl|zip|gd|pdo_mysql|intl|bcmath'  # required extensions
composer --version     # composer 2.x
mysql --version
ls /run/php/           # confirm the php-fpm socket name (matches apache-vhost.conf)
```

Install anything missing (`apt install php8.x-{mbstring,xml,curl,zip,gd,mysql,intl,bcmath} composer`).

## 1. DNS (Cloudflare)

Add a record for `parttime.q8ee.com` in the Cloudflare dashboard: type A →
the origin server IP (same as help.q8ee.com's origin), proxied.

## 2. Database

```bash
mysql -u root -p
CREATE DATABASE parttime CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'parttime'@'localhost' IDENTIFIED BY '<STRONG PASSWORD>';
GRANT ALL ON parttime.* TO 'parttime'@'localhost';
FLUSH PRIVILEGES;
```

## 3. Directory + code

```bash
mkdir -p /srv/www/parttime.q8ee.com/app
```

Then from the Mac: `./deploy/deploy.sh --dry` to review, then without
`--dry` (the first run will fail at the health check — vhost not up yet —
that's fine; it still syncs and runs composer/migrate).

## 4. Environment

```bash
cd /srv/www/parttime.q8ee.com/app
cp deploy/.env.production.example .env   # or scp the template from the Mac
nano .env                                # DB password, MAIL_*, ADMIN_NOTIFY_EMAIL, TURNSTILE_* (see step 7)
php artisan key:generate --force
```

⚠️ **APP_KEY**: generated once, never changed or lost — civil IDs and other
encrypted instructor fields (see `SESSION_ENCRYPT=true` and the encrypted
Instructor columns) are unreadable if it's lost or rotated. Back it up (e.g.
in a password manager) immediately after `key:generate`.

## 5. Migrate + seed

```bash
php artisan migrate --force
php artisan db:seed --class=ChecklistItemSeeder --force   # the 12 official checklist items
```

## 6. Create the admin account

```bash
php artisan app:create-admin malshar@gmail.com "د. مشعل الشريده"
# prompts for a password (min 12 chars) — choose a strong one, store it in a password manager
```

## 7. Cloudflare Turnstile

In the Cloudflare dashboard → Turnstile: create a new widget for
`parttime.q8ee.com`, mode **Managed**. Copy the site key and secret key into
`.env`:

```
TURNSTILE_SITE_KEY=...
TURNSTILE_SECRET=...
```

Then `php artisan config:cache` (or just re-run `./deploy/deploy.sh`, which
caches config on every deploy).

## 8. Apache vhost

```bash
scp deploy/apache-vhost.conf root@alsharidah.shop:/etc/apache2/sites-available/parttime.q8ee.com.conf
ssh root@alsharidah.shop
a2enmod ssl rewrite proxy proxy_fcgi headers
a2ensite parttime.q8ee.com
apache2ctl configtest && systemctl reload apache2
```

`apache-vhost.conf` expects a Cloudflare **origin certificate** at
`/etc/ssl/cloudflare/parttime.q8ee.com.{pem,key}` (generate it in Cloudflare
→ SSL/TLS → Origin Server, paste the cert and key into those two files on
the server) and hands `.php` requests to PHP-FPM — confirm the socket path
in the `<FilesMatch \.php$>` block matches `ls /run/php/` from step 0.

Cloudflare SSL/TLS mode: set to **Full (strict)** for parttime.q8ee.com,
matching help.q8ee.com.

## 9. Permissions + storage layout

```bash
cd /srv/www/parttime.q8ee.com/app
mkdir -p storage/app/private/applications storage/app/private/generated
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

(`deploy.sh` also does this on every deploy, so this step matters mainly for
verifying the first run.)

## 10. Verify

- https://parttime.q8ee.com/up → 200
- Homepage in Arabic (RTL), login page shows the Turnstile widget
- Register a test instructor account, complete a profile, upload a document,
  submit an application end-to-end
- Log in as the admin created in step 6 → review the test application →
  approve/reject a document → generate the Check List (Word download)
- Check `storage/logs/laravel.log` for unexpected errors

## 11. SMTP

Set in `.env`: `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, then `php artisan config:cache`. Submit a
test application to confirm `ADMIN_NOTIFY_EMAIL` receives the notification.

## 12. Backups

Mirror help.q8ee.com's setup (`/etc/cron.d/help-q8ee-backup`): a nightly cron
job that dumps the database and rsyncs applicant files to the same backup
host/location help.q8ee.com uses, with the same retention.

```bash
# /etc/cron.d/parttime-q8ee-backup (adapt from /etc/cron.d/help-q8ee-backup)
# nightly: mysqldump parttime + .env (APP_KEY) + storage/app/private
#          -> /srv/backups/parttime.q8ee.com, 14-day retention
```

`storage/app/private` holds uploaded applicant documents (civil IDs, degree
certificates, etc.) and generated Check List files — back it up like
help.q8ee.com backs up its attachments, not just the database.

## Routine updates

```bash
./deploy/deploy.sh        # tests → rsync → composer → migrate → seed checklist → caches → health
```

`storage/app` (applicant uploads) is excluded from the rsync on every
deploy, so files on the server are never touched by a code deploy.
