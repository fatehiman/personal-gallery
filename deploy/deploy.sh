#!/usr/bin/env bash
# Deploy the last git commit to ger1 (gallery.peppasoft.com).
# Run from the repo root in Git Bash:  bash deploy/deploy.sh
# First-time server setup is in DEPLOY.md.
set -euo pipefail

HOST=Ger1-root
HOME_DIR=/mnt/ger_hd1/www/gallery
APP=$HOME_DIR/app
SITE_USER=gallery
DOMAIN=gallery.peppasoft.com
STAMP=$(date +%Y%m%d-%H%M%S)

echo "== whitelist IP on the Hetzner cloud firewall"
curl -s "https://mon.peppasoft.com/addip?_type=s" | tail -1
sleep 5

echo "== build archive from HEAD ($(git rev-parse --short HEAD))"
TMP=$(mktemp -d)
git archive --format=tar.gz -o "$TMP/release.tgz" HEAD
scp -q "$TMP/release.tgz" "$HOST:/tmp/personal-gallery-release.tgz"
rm -rf "$TMP"

ssh "$HOST" bash -s -- "$APP" "$HOME_DIR" "$DOMAIN" "$STAMP" "$SITE_USER" <<'REMOTE'
set -euo pipefail
APP=$1; HOME_DIR=$2; DOMAIN=$3; STAMP=$4; SITE_USER=$5

echo "== backup old app (no vendor, no thumbnails) + database"
if [ -d "$APP" ]; then
  tar czf /root/gallery-backup-$STAMP.tgz -C "$HOME_DIR" --exclude=app/vendor --exclude=app/storage/framework \
    --exclude=app/storage/app/thumbs --exclude=app/storage/logs app || true
  mysqldump --single-transaction gallery 2>/dev/null | gzip > /root/gallery-db-$STAMP.sql.gz || true
  ls -1t /root/gallery-backup-*.tgz 2>/dev/null | tail -n +6 | xargs -r rm -f
  ls -1t /root/gallery-db-*.sql.gz 2>/dev/null | tail -n +6 | xargs -r rm -f
fi

echo "== extract"
STAGE=$(mktemp -d)
tar xzf /tmp/personal-gallery-release.tgz -C "$STAGE"
mkdir -p "$APP"
rsync -a --delete \
  --exclude=/.env --exclude=/vendor --exclude=/storage --exclude=/bootstrap/cache \
  "$STAGE"/ "$APP"/
mkdir -p "$APP"/storage/{app/thumbs,app/avatars,app/locks,framework/{cache,sessions,views},logs} "$APP"/bootstrap/cache
rsync -a "$STAGE"/storage/ "$APP"/storage/ --ignore-existing
rm -rf "$STAGE" /tmp/personal-gallery-release.tgz
rm -f "$APP"/bootstrap/cache/packages.php "$APP"/bootstrap/cache/services.php
chown -R $SITE_USER:$SITE_USER "$APP"
chmod 710 "$APP"; chmod 755 "$APP/public"; chmod 600 "$APP/.env" 2>/dev/null || true

echo "== composer + migrate + cache"
su -s /bin/bash $SITE_USER -c "cd $APP && php8.4 /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --quiet && php8.4 artisan migrate --force && php8.4 artisan optimize"

echo "== flush opcache of this FPM pool"
F=flush-$STAMP.php
trap 'rm -f "$APP/public/$F"' EXIT
echo '<?php opcache_reset(); echo "flushed";' > "$APP/public/$F"
chown $SITE_USER:$SITE_USER "$APP/public/$F"
curl -sk --max-time 15 --resolve "$DOMAIN:443:162.55.167.140" "https://$DOMAIN/$F" || echo "flush request failed"; echo
rm -f "$APP/public/$F"

echo "== storage mount"
systemctl is-active gallery-storage || true
ls /mnt/gallery-storage | head -5 || true
REMOTE

echo "== verify"
for i in 1 2 3 4 5; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "https://$DOMAIN/up") && [ "$code" = 200 ] && break
  sleep 1
done
echo "/up -> $code"
echo "/login -> $(curl -s -o /dev/null -w "%{http_code}" "https://$DOMAIN/login")"
