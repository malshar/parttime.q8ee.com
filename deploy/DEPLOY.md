# DEPLOY.md — First-time production setup for parttime.q8ee.com

Target: the same Ubuntu + Apache server that hosts help.q8ee.com
(SSH: `root@alsharidah.shop`). Routine updates afterwards use
`./deploy/deploy.sh`.

**Status:** milestone 1 (intake) and milestone 2 (committee workflow,
sections import, assignments) are merged on `main` and pushed
(`php artisan test` green, 147 tests). Nothing below has been run against
the real server yet — this is the checklist for the first deploy.

## Fast path (2026-09-29)

Steps 2-7 and 9 are automated in **`./deploy/first-deploy.sh`** (run once
from the Mac; idempotent). It generates the DB password
(`/root/.parttime-db-pass`) and the admin's initial password
(`/root/parttime-admin-initial.txt`), points mail at this server's mailcow
as `mail.q8ee.com:587` (see step 11 and `deploy/mail-hostname.sh`; sender
`noreply@q8ee.com`, notifications to `mishal@q8ee.com`) and issues a Let's Encrypt certificate with certbot,
like help.q8ee.com. Afterwards do steps 8 (Turnstile keys), 11 (the
mailcow mailbox password) and 9a (firewall) by hand. Step 0 was verified
on 2026-09-29: PHP 8.4.25, all extensions, Composer 2.8.5, MySQL 8.0.46,
`php8.4-fpm.sock`. The sections below remain as the manual reference.

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

## 3. Directory + first sync

`deploy.sh` cannot do the very first sync end-to-end: its ssh block runs
`composer install && migrate --force && db:seed ... && caches && mkdir
storage dirs && chown/chmod` as one `set -e` chain, and `migrate --force`
needs a real `.env` (APP_KEY, DB credentials) on the server — which doesn't
exist yet. Without it, `migrate --force` fails and the chain aborts before
it ever reaches `db:seed`, the caches, `mkdir -p storage/app/private/...`,
or `chown`/`chmod`. So the first sync is done by hand, once; every deploy
after this section uses `./deploy/deploy.sh` normally.

```bash
ssh root@alsharidah.shop 'mkdir -p /srv/www/parttime.q8ee.com/app'
```

From the Mac, review what would be synced (safe — dry-run only, no ssh
commands run): `./deploy/deploy.sh --dry`.

Then do the actual first copy by hand, with the same flags `deploy.sh` uses
(copy them from `deploy/deploy.sh` if this drifts):

```bash
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
  --exclude 'deploy/.env.production.example' \
  --exclude 'deploy/.env.production' \
  --exclude 'database/*.sqlite' \
  --exclude '.superpowers' \
  --exclude '.phpunit.result.cache' \
  --exclude 'docs' \
  --exclude 'bootstrap/cache/*' \
  ./ root@alsharidah.shop:/srv/www/parttime.q8ee.com/app/
```

## 4. Environment

On the server:

```bash
ssh root@alsharidah.shop
cd /srv/www/parttime.q8ee.com/app
cp deploy/.env.production.example .env
nano .env                                # DB password, MAIL_*, ADMIN_NOTIFY_EMAIL, TURNSTILE_* (see step 8)
composer install --no-dev --optimize-autoloader --no-interaction
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

## 7. Permissions + storage layout

```bash
mkdir -p storage/app/private/applications storage/app/private/generated
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

(`deploy.sh` also does this on every deploy, so from here on it's kept in
sync automatically.)

## 8. Cloudflare Turnstile

In the Cloudflare dashboard → Turnstile: create a new widget for
`parttime.q8ee.com`, mode **Managed**. Copy the site key and secret key into
`.env`:

```
TURNSTILE_SITE_KEY=...
TURNSTILE_SECRET=...
```

Then `php artisan config:cache` (or just re-run `./deploy/deploy.sh`, which
caches config on every deploy).

## 9. Apache vhost + Cloudflare SSL mode

```bash
scp deploy/apache-vhost.conf root@alsharidah.shop:/etc/apache2/sites-available/parttime.q8ee.com.conf
ssh root@alsharidah.shop
a2enmod ssl rewrite proxy proxy_fcgi headers
a2ensite parttime.q8ee.com
apache2ctl configtest && systemctl reload apache2
```

`apache-vhost.conf` is the HTTP vhost only, on the origin IP like the
other sites on this server. The certificate comes from Let's Encrypt via
certbot's apache plugin, which writes the `*-le-ssl.conf` twin and the
HTTPS redirect (Cloudflare proxies the ACME HTTP challenge to the origin):

```bash
certbot --apache -d parttime.q8ee.com --redirect
```

The vhost hands `.php` requests to PHP-FPM — confirm the socket path in the
`<FilesMatch \.php$>` block matches `ls /run/php/` from step 0.

Cloudflare SSL/TLS mode: **Full (strict)** for parttime.q8ee.com, matching
help.q8ee.com.

Once the vhost is live and `.env` is in place, `./deploy/deploy.sh` (no
`--dry`) works end-to-end, including its own health check.

## 9a. Restrict the origin to Cloudflare

`bootstrap/app.php` trusts every proxy (`trustProxies(at: '*')`), which is
needed behind Cloudflare. But if the origin answers on 80/443 for anyone who
knows its IP, a client can connect directly, send its own `X-Forwarded-For`,
and so defeat the per-IP rate limits (registration, login) and forge the IP
recorded in `audit_log`. Only Cloudflare may reach the web ports: allow
Cloudflare's published ranges and deny everything else (SSH stays open).
If help.q8ee.com's origin already has these rules (same server), just confirm
with `ufw status` that they are present and skip the commands.
If ufw is currently inactive, enabling it denies all other incoming ports
by default: check `ss -tlnp` first and `ufw allow` anything else this shared
server must keep serving before running `ufw enable`.

```bash
ssh root@alsharidah.shop
ufw allow OpenSSH
for ip in $(curl -s https://www.cloudflare.com/ips-v4) $(curl -s https://www.cloudflare.com/ips-v6); do
  ufw allow proto tcp from "$ip" to any port 80,443 comment 'cloudflare'
done
ufw deny 80/tcp
ufw deny 443/tcp
ufw enable
ufw status numbered        # the Cloudflare allow rules must be listed before the deny rules
```

Cloudflare changes these ranges rarely; re-run the loop when
https://www.cloudflare.com/ips/ announces a change.

## 10. Verify

- https://parttime.q8ee.com/up → 200
- Homepage in Arabic (RTL), login page shows the Turnstile widget
- Register a test instructor account, complete a profile, upload a document,
  submit an application end-to-end
- Log in as the admin created in step 6 → review the test application →
  approve/reject a document → generate the Check List (Word download)
- Upload one real scanned PDF and one real .docx through the instructor
  form; both must be accepted (checks libmagic's MIME detection on the server)
- Check `storage/logs/laravel.log` for unexpected errors

## 11. SMTP (mailcow on this server)

Outgoing mail goes through the server's own mailcow, addressed as
`mail.q8ee.com` (submission port 587, STARTTLS), authenticated as the
mailbox `noreply@q8ee.com` (create it in the mailcow UI: Mailboxes → Add,
domain `q8ee.com`). Put its password in `.env` as `MAIL_PASSWORD`, then
`php artisan config:cache`.

`mail.q8ee.com` needs an A record → `74.207.252.122` with the Cloudflare
proxy **off** (SMTP cannot go through the proxy), then
`./deploy/mail-hostname.sh` once: it adds an Apache HTTP vhost that
forwards the ACME challenge to mailcow, sets `SKIP_IP_CHECK=y` and
`ADDITIONAL_SAN=mail.q8ee.com` in `mailcow.conf`, and lets mailcow renew its
certificate (which had been expired since 2024-10-10 because the primary
name is proxied). For deliverability q8ee.com needs, in Cloudflare DNS:
SPF `v=spf1 a:mail.q8ee.com -all`, the DKIM TXT for selector `dkim`
(mailcow → domain → DNS tab; one line, no line breaks), and DMARC
`v=DMARC1; p=quarantine; rua=mailto:mishal@q8ee.com`; MX → `mail.q8ee.com`
only if q8ee.com should also receive mail. Submit a test application to
confirm `ADMIN_NOTIFY_EMAIL` receives the notification.

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
`composer install` (run by `deploy.sh` on every deploy) also installs
`phpoffice/phpspreadsheet`, needed by the sections importer below — no
separate step required.

## Routine per-term setup

Each new term, after creating the term at `/admin/terms`:

Export the term's timetable from jadawil (CSV or XLSX) and import it at
`/admin/sections/import`; re-import whenever the timetable changes —
assigned sections are never deleted automatically. Read the preview's
warnings and errors before confirming, and always re-import a term using the
same format (CSV or XLSX) you used first.
