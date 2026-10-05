# Personal Gallery

A self-hosted, colorful photo and video gallery for family albums. It reads photos from a **read-only**
storage (for example a Hetzner Storage Box mounted with SSHFS). It never changes or writes the original files.

Built with Laravel 13 + MySQL + Blade + plain JavaScript (no front-end build step, no npm at runtime).

## Features

- **Two languages, two themes**: English (LTR, "Morning Pastel", Nunito font) and Persian (RTL, "Persian Garden",
  Vazirmatn font, arch-shaped folders, light girih pattern). The theme follows the language. All colors are light pastels.
- **Calendar**: Gregorian or Jalali, chosen separately from the language. Every date shows the other calendar as a tooltip.
- **Login only** (no sign-up). Two access levels: **admin** and **user**.
- **Admin**: manages users (create, edit, delete), gives each user one or more folders with a title. Users see only these
  titles in their root; a title opens the real folder. Nested folders (a folder inside or around an assigned folder)
  are refused with a clear message.
- **Admin sees all folders** and can **scan a folder** (one scan job at a time, can be stopped from any scan button).
- **Six views**: Mosaic, Small tiles, Gallery (default), Large tiles, **Quick list** (names, size, date only — no file is
  read) and **Detailed list** (date taken, dimensions, camera, location, tags, people, description).
- **Sort** by name, date, size (both directions) or type. **Up / Home** buttons and breadcrumbs.
- **Search** in folder names, file names, tags, people, descriptions, city and country. Filters: date range (with a
  Gregorian/Jalali date picker), type, tag, person, camera, place, favorites, has location, has description, only this folder.
- **Fullscreen viewer**: fade transitions, swipe, keyboard, zoom (wheel, pinch, double-tap), slideshow (3/5/10 s),
  rotate (stored in the DB only), favorites, download, info panel with all EXIF data. A blurred thumbnail shows
  while the original loads (not at all when the original is already cached). The description is shown on the photo
  (also in the slideshow) and replaces the file name on tiles. The info panel stays open while moving next/previous,
  and is closed again when the viewer is opened next time. Text with Persian/Arabic letters is shown right-to-left.
- **Location names** need GPS data in the file. They are looked up in the background (cron, every minute,
  1 request per second) after a photo is first read — by viewing it or by a scan.
- **Tags** and **people**. People can have a face box (draw a rectangle on the photo, like Facebook). The box is saved
  as 0..1 coordinates, ready for automatic face detection later. Name lists suggest existing names after 500 ms of no
  typing, and match any part of a name (`ja` finds `Mrs. Janet Jackson`).
- **Videos**: first frame as thumbnail (ffmpeg), playback with Video.js, duration, codec, GPS from phone videos.
- **Favorites**, **On this day** (photos taken on today's date in past years), **Map** of photos with GPS
  (Leaflet + marker clusters; city and country names in English and Persian from OpenStreetMap Nominatim).
- **Profile**: change name, password, language, calendar and profile picture. Without a picture, one is looked up by
  e-mail on save (Gravatar, Libravatar, unavatar.io; at most ~10 seconds).
- **Fully responsive** (phones, tablets, desktops).

## How it keeps the slow storage fast

- **Nothing is scanned up front.** Opening a folder reads only the directory entries (names, sizes, dates).
  The listing is cached in the DB for 20 minutes, then read again on the next visit. Every user has a
  **Refresh** button (same work: names/sizes/dates only, no file content; at most once per 30 s per folder).
- A photo is read **only when its thumbnail is first needed**. That request reads EXIF/IPTC, size, GPS, video info,
  and makes a 480 px WebP thumbnail in `storage/app/thumbs` (about 25–35 KB each, so ~1 GB for 30,000 photos).
  The admin "Scan folder" job does exactly the same thing, file by file.
- **Lazy loading**: thumbnails that already exist load at once. New ones load only after scrolling stops for
  500 ms, only if still on screen, 3 at a time. If loading is slow, a tip suggests the Quick list (with
  "do not show again").
- **Read slots**: at most 2 files are read from the storage at the same time (web requests + scan job together).
- **Caching**: media URLs are signed (HMAC) and versioned, so they are cached for one year by the browser and by
  Cloudflare. Media responses have no cookies, so the CDN can cache them.
  Note: anyone who has an exact media URL can open that file (like a "shared link"). Change `GALLERY_URL_KEY`
  to make all old media URLs invalid.
- If the storage is not mounted, nothing is deleted from the DB (`GALLERY_REQUIRE_MOUNT=true`).

## Libraries (all stored locally in `public/vendor`, no CDN)

| Library | Version | Use |
|---|---|---|
| Video.js | 8.24.1 | video player |
| Leaflet + Leaflet.markercluster | 1.9.4 / 1.5.3 | map |
| Lucide icons | 1.52.0 | SVG icon sprite (`public/assets/icons.svg`) |
| Vazirmatn | 33.0.3 | Persian font |
| Nunito (Fontsource) | 5.3.0 | English font |

Backend: only Laravel itself (no extra Composer packages). Images use PHP GD + EXIF. Videos use `ffmpeg`/`ffprobe`.

## Requirements

PHP 8.3+ with `gd` (WebP), `exif`, `intl`, `mbstring`, `pdo_mysql`, `fileinfo`, `curl`; MySQL 8;
`ffmpeg` (optional, for video thumbnails); a cron entry for the scheduler.

## Local setup

```bash
composer install
cp .env.example .env      # set GALLERY_ROOT to a local photo folder, DB settings, GALLERY_REQUIRE_MOUNT=false
php artisan key:generate
php artisan migrate
php artisan gallery:user admin --admin --name="Admin"     # asks for the password
php artisan serve
```

Background work (scan job and place names) runs from the scheduler:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Useful commands:

| Command | What it does |
|---|---|
| `gallery:user <username> [--admin] [--name=] [--password-stdin]` | create a user or reset a password |
| `gallery:work` | run the active scan job for ~55 s (the scheduler starts it every minute) |
| `gallery:geocode` | find city/country names for photos with GPS (1 request per second) |

Tests: `php artisan test`.

## Deployment

See [DEPLOY.md](DEPLOY.md).

## Security notes

- No registration. Failed logins are limited per IP + username (5 per 5 min) and per IP (20 per 15 min),
  plus a flood limit of 10 login posts per minute per IP. Each failure is logged (`login failed`, with IP).
- The visitor IP comes from `X-Forwarded-For` **only when the request comes from Cloudflare's IP ranges**
  (`bootstrap/trusted_proxies.php`). A bot that connects to the server IP directly cannot fake its IP.
- Only the `APP_URL` host is accepted (other `Host` headers get 400). HSTS is sent on HTTPS.
- Sessions are regenerated on login. Changing a password (self or by admin) ends the user's other sessions
  and "keep me signed in" cookies. A disabled user is logged out on the next request.
- API calls are limited to 300 per minute per user.
- Every path from the browser is normalized; `..` is refused; symlinks are ignored; users can reach only their
  assigned folders (checked on every listing and every media action).
- Uploaded profile pictures are re-encoded (no original bytes are kept); images over 40 megapixels are refused
  before decoding (protects against "decompression bomb" files).
- Strict Content-Security-Policy (no inline scripts), `X-Frame-Options: DENY`, `noindex`.
- `.env` holds all secrets and is never committed.
