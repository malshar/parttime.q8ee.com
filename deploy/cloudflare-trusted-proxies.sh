#!/bin/bash
# cloudflare-trusted-proxies.sh — write Cloudflare's published IP ranges into
# TRUSTED_PROXIES in the server .env, so the app only believes X-Forwarded-For
# from the Cloudflare edge. A client hitting the origin IP directly is then
# seen with its own address (rate limits and audit_log IPs cannot be forged).
# Re-run whenever https://www.cloudflare.com/ips/ announces a change.
set -euo pipefail
SERVER="root@alsharidah.shop"
APP_DIR="/srv/www/parttime.q8ee.com/app"
V4=$(curl -fsS https://www.cloudflare.com/ips-v4); V6=$(curl -fsS https://www.cloudflare.com/ips-v6)
LIST=$(printf '%s\n%s\n' "$V4" "$V6" | grep -E '^[0-9a-f.:]+/[0-9]+$' | paste -sd, -)
[ -n "$LIST" ] || { echo "❌ could not fetch Cloudflare ranges"; exit 1; }
echo "Cloudflare ranges: $(echo "$LIST" | tr ',' '\n' | wc -l | tr -d ' ')"
ssh "$SERVER" "cd $APP_DIR && (grep -q '^TRUSTED_PROXIES=' .env || echo 'TRUSTED_PROXIES=' >> .env) && sed -i 's|^TRUSTED_PROXIES=.*|TRUSTED_PROXIES=$LIST|' .env && php artisan config:cache && grep -c '^TRUSTED_PROXIES=.*/' .env"
