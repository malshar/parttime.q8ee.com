# DEPLOY.md — First-time production setup for parttime.q8ee.com

Target: the same Ubuntu + Apache server that hosts help.q8ee.com
(SSH: `root@alsharidah.shop`). Routine updates afterwards use
`./deploy/deploy.sh`.

**Status:** first deploy done on 2026-09-30 (milestones 1 and 2):
https://parttime.q8ee.com is live, certbot certificate, mail through the
server's mailcow as `mail.q8ee.com`, admin `mishal@q8ee.com`. What remains
optional is listed under "After the first deploy" at the end. This file
stays as the reference for a rebuild.

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

`CIVIL_ID_CHECKSUM=false` (set in production on 2026-10-02): the civil ID is
validated for shape only. The check-digit formula in `App\Rules\KuwaitCivilId`
(weights 2,1,6,3,7,9,10,5,8,4,2, check = (11 − sum mod 11) mod 11) comes from
public sources, not PACI, and rejected a real ID; turn it on only once the
algorithm is verified against several real civil IDs.

⚠️ **APP_KEY**: generated once, never changed or lost — civil IDs and other
encrypted instructor fields (see `SESSION_ENCRYPT=true` and the encrypted
Instructor columns) are unreadable if it's lost or rotated. Back it up (e.g.
in a password manager) immediately after `key:generate`.

Multi-file uploads (5b): PHP-FPM must allow 10 files × 10 MB per request.
Check `/etc/php/8.4/fpm/php.ini`: `upload_max_filesize = 10M`,
`post_max_size = 110M`, `max_file_uploads = 20`; then
`systemctl reload php8.4-fpm`. Deploy = `./deploy/deploy.sh` (four
migrations, seeder re-run). The site sits behind Cloudflare, whose free and
Pro plans cap request bodies at 100 MB regardless of the PHP-FPM settings
above, so a full 10-file upload of 10 MB each may still be refused by the
proxy before it reaches PHP-FPM — keep uploads well under that limit in
practice, or raise the Cloudflare plan if larger uploads become routine.

Milestone 6: two migrations, no server steps; the renewal batch needs the
target year's first term to exist.

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

## 9a. Only trust Cloudflare as a proxy

`bootstrap/app.php` reads `TRUSTED_PROXIES` from `.env`. With `*` any client
that reaches the origin IP directly could send its own `X-Forwarded-For`
and defeat the per-IP rate limits (registration, login) or forge the IP in
`audit_log`. Production therefore lists Cloudflare's published ranges:

```bash
./deploy/cloudflare-trusted-proxies.sh     # fetches the ranges, writes .env, config:cache
```

Re-run it when https://www.cloudflare.com/ips/ announces a change. A direct
hit on the origin is still served, but seen with its real source IP.

The earlier idea of a ufw rule set allowing 80/443 from Cloudflare only was
dropped on 2026-09-30: this server is shared (help.q8ee.com, the 1xx sites,
mailcow's web UI at mail.alsharidah.me which must stay reachable directly for
ACME), so a server-wide firewall change is out of scope for this app.
Observation from the same review, for the server owner: ufw currently allows
3306/3307 (MySQL), 8000, 8080, 9090, 9443, 2368 and Samba from anywhere.

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
./deploy/install-backup.sh   # installs deploy/parttime-q8ee-backup.sh + /etc/cron.d/parttime-q8ee-backup, runs one backup
```

Nightly at 02:45: mysqldump `parttime` + `.env` (APP_KEY) +
`storage/app/private` → `/srv/backups/parttime.q8ee.com`, 14-day retention,
same shape as help.q8ee.com's job.

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

Export the term's timetable from jadawil (v2.4.13 or later, CSV or XLSX) and
import it at `/admin/sections/import`; the export must include the seat
columns `الحد الأقصى`, `مسجلة`, `متبقية` so the student count prints on (خ-3).
Re-import whenever the timetable changes — assigned sections are never deleted
automatically. Read the preview's warnings and errors before confirming, and
always re-import a term using the same format (CSV or XLSX) you used first.

Each month: `/admin/attestations` → توليد الناقص → review → PDF / combined
PDF; exported forms are locked, unlock to edit.

## After the first deploy (2026-09-30)

Done by `first-deploy.sh` and by hand: DB, `.env`, key, migrations, seed,
admin, vhost + certbot, Turnstile keys, mailbox password, code readable by
www-data. Mail: `deploy/mail-hostname.sh` gave mailcow a valid certificate
for `mail.q8ee.com` (and fixed its expired one). Also run on 2026-09-30:
`./deploy/cloudflare-trusted-proxies.sh` (9a) and `./deploy/install-backup.sh`
(12). Routine deploys: `./deploy/deploy.sh`.

Still to do:

1. Step 10's manual checks in the browser.
2. The admin changes the initial password via "forgot password", then
   `rm /root/parttime-admin-initial.txt`.
3. Import the term's jadawil export at `/admin/sections/import`.

## Milestone 3: PDF export (LibreOffice + fonts)

The (خ-3) PDF is made by LibreOffice (`soffice`, present: 7.3) from the Word
template. Once, on the server, before or right after deploying milestone 3:

1. **Absolute binary path.** PHP-FPM runs with `clear_env`, so a bare
   `soffice` looked up on PATH is unreliable. In `.env`:

   ```
   SOFFICE_PATH=/usr/bin/soffice
   ```

   then `php artisan config:cache` (or re-run `./deploy/deploy.sh`).

2. **Font substitution.** The official form uses "Simplified Arabic" and
   "PT Bold Heading", which are not on the server. Map both to Amiri
   (installed) with fontconfig, so LibreOffice shapes the Arabic correctly
   and keeps the form's line heights:

   ```bash
   cat > /etc/fonts/local.conf <<'XML'
   <?xml version="1.0"?>
   <!DOCTYPE fontconfig SYSTEM "fonts.dtd">
   <fontconfig>
     <match target="pattern">
       <test name="family"><string>Simplified Arabic</string></test>
       <edit name="family" mode="assign" binding="same"><string>Amiri</string></edit>
     </match>
     <match target="pattern">
       <test name="family"><string>PT Bold Heading</string></test>
       <edit name="family" mode="assign" binding="same"><string>Amiri</string></edit>
       <edit name="weight" mode="assign" binding="same"><const>bold</const></edit>
     </match>
   </fontconfig>
   XML
   fc-cache -f
   fc-match "Simplified Arabic"   # → Amiri
   fc-match "PT Bold Heading"     # → Amiri Bold
   ```

3. **Warm-up as www-data** (checks the web user can start LibreOffice and
   loads it into the page cache, so the first download does not time out):

   ```bash
   sudo -u www-data HOME=/tmp /usr/bin/soffice --headless --terminate_after_init
   ```

4. **Backups.** `deploy/parttime-q8ee-backup.sh` now excludes
   `storage/app/private/generated/tmp` (short-lived decrypted .docx/.pdf
   files); re-run `./deploy/install-backup.sh` once so the installed copy in
   `/usr/local/bin` picks that up.

5. **Visual check (required once).** At `/admin/attestations`, generate and
   download:
   - one PDF for a 5-week month (e.g. October 2026), and
   - one combined PDF for a month with two instructors.

   In both: exactly one page per instructor (no blank page, no page spilling
   onto a second), the Arabic is joined and right-to-left (not isolated
   letters), the week table and totals fit, and the footer signature lines
   are present. If a page spills, check `fc-match` above before changing the
   template.

## Milestone 4: deploy note

A routine `./deploy/deploy.sh` (one new migration, `checklist_renewals`).
Before deploying, check on the server that no term uses the removed
`archived` status (milestone 4 drops it from the code and its label, so
such a term would show a missing label):

```sql
select count(*) from terms where status='archived';   -- must be 0
```
