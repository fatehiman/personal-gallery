# Deployment (ger1 — gallery.peppasoft.com)

| Fact | Value |
|---|---|
| Server | ger1 (Hetzner, Ubuntu 24.04, Virtualmin), SSH alias `Ger1-root` (whitelist your IP first) |
| Site / Linux user | `gallery.peppasoft.com` / `gallery` (uid 1088), Cloudflare in front |
| App (whole repo) | `/mnt/ger_hd1/www/gallery/app` (mode 710) |
| Docroot | `/mnt/ger_hd1/www/gallery/app/public` (`virtualmin modify-web --document-dir app/public`) |
| PHP | FPM pool on PHP 8.4, user `gallery` (upload limit raised to 10 MB in the pool file) |
| Database | MySQL `gallery` (utf8mb4_persian_ci), user `gallery@localhost`. Password only in `app/.env` |
| Photos | `/mnt/gallery-storage` — read-only SSHFS mount of the storage box sub-account `u313450-sub10` (systemd `gallery-storage`) |
| Thumbnails | `app/storage/app/thumbs` (WebP, ~30 KB each) |
| Cron (user `gallery`) | `* * * * * cd /mnt/ger_hd1/www/gallery/app && php8.4 artisan schedule:run >> /dev/null 2>&1` |
| Health | `https://gallery.peppasoft.com/up` → 200 |

## Update (normal deploy)

From the repo root in Git Bash, after committing:

```bash
bash deploy/deploy.sh
```

It whitelists your IP, uploads `git archive HEAD`, backs up the old app and DB (`/root/gallery-backup-*.tgz`,
`/root/gallery-db-*.sql.gz`, keeps 5), keeps `.env`, `storage/` (thumbnails!) and `vendor/`, runs
`composer install --no-dev`, `migrate`, `optimize`, flushes this pool's opcache and checks `/up`.

## First-time setup (already done on 2026-10-05)

1. Packages: `apt-get install --no-install-recommends sshfs ffmpeg` (`sshpass` was already there).
2. Storage box mount (read-only sub-account):
   ```bash
   printf '%s' '<sub-account password>' > /etc/gallery-storagebox.pass   # no newline
   chmod 600 /etc/gallery-storagebox.pass
   # accept the host key once as root: sftp -P 23 u313450-sub10@u313450-sub10.your-storagebox.de
   cp deploy/gallery-storage.service /etc/systemd/system/
   systemctl daemon-reload && systemctl enable --now gallery-storage
   ls /mnt/gallery-storage            # must list the photo folders
   ```
   The unit mounts the account's **home** (`host:` — not `host:/`, which gives "Permission denied").
   It uses `sshpass -f` as `ssh_command`, so the password is read again on every reconnect.
   SSHFS's own directory cache is 60 s (`dcache_timeout`), shorter than the app's 20 min listing cache,
   so "Refresh" really sees new files.
   **When you rotate the storage box password**: update `/etc/gallery-storagebox.pass`, then
   `systemctl restart gallery-storage`.
3. Virtualmin: `modify-web --mode fpm`, then (separate call) `--php-version 8.4`, then `--document-dir app/public`;
   `enable-feature --mysql`; `ALTER DATABASE gallery CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci`.
4. `app/.env` (600, owner `gallery`) — copy `.env.example` and fill `APP_KEY`, `DB_PASSWORD`, `GALLERY_URL_KEY`.
   Test the DB login with `mysql --no-defaults ...` (root's `~/.my.cnf` otherwise overrides the password).
5. `bash deploy/deploy.sh`, then create the admin:
   `su -s /bin/bash gallery -c "cd /mnt/ger_hd1/www/gallery/app && php8.4 artisan gallery:user mainadmin --admin --name='Main Admin' --password-stdin" <<< '<password>'`
6. Cron line above in `crontab -u gallery -e`.

## Storage watchdog

`gallery-storage-watchdog.timer` runs `/usr/local/sbin/gallery-storage-watchdog` (source: `deploy/gallery-storage-watchdog.sh`)
every 2 minutes. If the mount is missing or does not answer in 20 s, it aborts the FUSE connection (frees every
stuck process), remounts, and sends a Telegram message ("reconnected", or "DOWN" as critical, at most hourly).
It never waits on the mount itself, because a hung FUSE mount blocks even `timeout ls`.

```bash
cp deploy/gallery-storage-watchdog.sh /usr/local/sbin/gallery-storage-watchdog && chmod 755 /usr/local/sbin/gallery-storage-watchdog
cp deploy/gallery-storage-watchdog.{service,timer} /etc/systemd/system/ && systemctl daemon-reload
systemctl enable --now gallery-storage-watchdog.timer
journalctl -t gallery-storage-watchdog -n 20     # what it did
```

## Security / Cloudflare

- `bootstrap/trusted_proxies.php` holds Cloudflare's IP ranges. If Cloudflare adds ranges
  (<https://www.cloudflare.com/ips/>), update the list (or set `GALLERY_TRUSTED_PROXIES` in `.env`,
  then `php8.4 artisan config:clear`). If the list is outdated, visitors from a new range are seen with a
  Cloudflare IP: they still work, but share a login limit.
- Failed logins are in `storage/logs/laravel-*.log` (`login failed`, `login blocked`).

## Rotate secrets

- **DB password**: `ALTER USER 'gallery'@'localhost' IDENTIFIED BY '…'; ALTER USER 'gallery'@'127.0.0.1' IDENTIFIED BY '…';`
  then update `DB_PASSWORD` in `app/.env` and run `php8.4 artisan config:cache` as `gallery`.
- **Media URL key** (`GALLERY_URL_KEY`): all old media links stop working; browsers and Cloudflare fetch new ones.
- **A user's password**: profile page, admin user page, or `php8.4 artisan gallery:user <name> --password-stdin`.

## Map tiles

The map uses OpenStreetMap tiles. Their servers block requests without a `Referer`, and the site sends
`Referrer-Policy: same-origin`, so the tile layer sets `referrerPolicy: 'strict-origin'` (only the site origin is sent).

## Do not

- Do not run `du`/`find`/`grep -r` on `/mnt/gallery-storage` (or on `/mnt/ger_hd1/www/kimiasoft/public_html`).
  Both are slow network storage boxes.
- Do not set `GALLERY_REQUIRE_MOUNT=false` on the server: if the mount is down, an empty folder would look like
  "all files deleted".
