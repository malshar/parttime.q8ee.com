#!/bin/bash
# mail-hostname.sh — make the server's mailcow answer as mail.q8ee.com with a
# valid Let's Encrypt certificate, so the app can send as noreply@q8ee.com
# over verified STARTTLS to MAIL_HOST=mail.q8ee.com.
#
# Run once from the Mac (ssh root@alsharidah.shop), AFTER the Cloudflare DNS
# record  A mail.q8ee.com -> 74.207.252.122  exists with the proxy OFF.
# Idempotent. Background (2026-09-29): mailcow's own certificate expired on
# 2024-10-10 because its ACME renewal checks that each host name resolves to
# the server IP, and mail.alsharidah.me is proxied through Cloudflare. This
# sets SKIP_IP_CHECK=y (the HTTP challenge itself works through the proxy)
# and adds mail.q8ee.com as an extra SAN, then lets mailcow renew.

set -euo pipefail
SERVER="root@alsharidah.shop"

echo "🔎 DNS check..."
if [ -n "$(dig +short AAAA mail.alsharidah.me @1.1.1.1)" ]; then
  echo "⚠️  mail.alsharidah.me is still proxied by Cloudflare (has an AAAA record); this server has no IPv6,"
  echo "    so mailcow cannot validate that name. Set its A record to DNS only (grey cloud) in the alsharidah.me zone."
fi
IP=$(dig +short A mail.q8ee.com @1.1.1.1 | head -1)
if [ "$IP" != "74.207.252.122" ]; then
  echo "❌ mail.q8ee.com resolves to '$IP', expected 74.207.252.122 (Cloudflare proxy must be OFF). Fix DNS first."; exit 1
fi

ssh "$SERVER" bash -s <<'REMOTE'
set -euo pipefail
VH=/etc/apache2/sites-available/mail.q8ee.com.conf
# Always (re)write: an older hand-made vhost that redirected everything to
# https://mail.q8ee.com (no SSL vhost exists for it) broke the ACME check.
[ -f "$VH" ] && ! grep -q 'acme-challenge' "$VH" && cp "$VH" "$VH.bak-$(date +%F)"
cat > "$VH" <<'CONF'
# mail.q8ee.com — HTTP only: forwards the ACME challenge to mailcow's nginx
# (127.0.0.1:8083) so mailcow can include this name in its certificate;
# everything else goes to the mailcow UI at its primary name.
<VirtualHost 74.207.252.122:80>
    ServerName mail.q8ee.com
    ServerAdmin mishal@q8ee.com
    ProxyPreserveHost On
    ProxyPass        "/.well-known/acme-challenge/" "http://127.0.0.1:8083/.well-known/acme-challenge/"
    ProxyPassReverse "/.well-known/acme-challenge/" "http://127.0.0.1:8083/.well-known/acme-challenge/"
    RewriteEngine on
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://mail.alsharidah.me%{REQUEST_URI} [END,NE,R=permanent]
    ErrorLog  ${APACHE_LOG_DIR}/mail.q8ee.com-error.log
    CustomLog ${APACHE_LOG_DIR}/mail.q8ee.com-access.log combined
</VirtualHost>
CONF
echo "  vhost written"
a2enmod -q proxy proxy_http rewrite
a2ensite -q mail.q8ee.com
apache2ctl configtest && systemctl reload apache2

cd /opt/mailcow-dockerized
sed -i 's/^SKIP_IP_CHECK=.*/SKIP_IP_CHECK=y/' mailcow.conf
grep -q '^ADDITIONAL_SAN=.*mail\.q8ee\.com' mailcow.conf || sed -i 's/^ADDITIONAL_SAN=.*/ADDITIONAL_SAN=mail.q8ee.com/' mailcow.conf
grep -E '^(SKIP_IP_CHECK|ADDITIONAL_SAN)=' mailcow.conf
docker compose up -d --quiet-pull 2>&1 | tail -3
docker compose restart acme-mailcow >/dev/null 2>&1   # leave the "skip for 1 hour" sleep
echo "  waiting for mailcow ACME (up to 3 min)..."
for i in $(seq 1 18); do
  sleep 10
  if docker compose logs --since 4m acme-mailcow 2>/dev/null | grep -qE 'Certificate .*(issued|renewed)|Certificate is valid|ordering.*success|Deploying'; then break; fi
done
docker compose logs --since 4m acme-mailcow | tail -12 | cut -c1-160
echo "--- certificate now served on 587:"
openssl s_client -connect 127.0.0.1:587 -starttls smtp -servername mail.q8ee.com </dev/null 2>/dev/null | openssl x509 -noout -enddate -ext subjectAltName
REMOTE
