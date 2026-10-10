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
  Manual scans are for quick needs. The normal way is the **background scan** (below).
- **Admin settings** (menu *Settings*): Telegram bot token, group/channel ID, minimum years, max images and max videos
  for the daily "On this day" message; background scan on/off and seconds per run.
- **Six views**: Mosaic, Small tiles, Gallery (default), Large tiles, **Quick list** (names, size, date only — no file is
  read) and **Detailed list** (date taken, dimensions, camera, location, tags, people, description).
- **Sort** by name, date, size (both directions) or type. The default is **newest first**. The sort order and the view
  are saved in the user account (not in the browser). **Up** button, **Home** in the menu and in the breadcrumbs.
- **Folderless** (toggle button): shows all photos and videos of the current folder **and all folders below it**, in one
  list (paged, sorted by the server). It uses only what the gallery already knows (database), so it never reads the
  storage box; a small note says how many folders are not read yet.
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
  The storage box is too slow to stream from, so a video is **first copied to this server** (`storage/app/vcache`) and
  played from the local copy. While copying, the viewer shows a progress ring with the size (`140/290 MB`, `0.3/1.2 GB`),
  the video length (HH:MM) and a **Cancel** button. Leaving the video (next/previous/close) also cancels the copy.
  - The copy runs in a background process (`gallery:copy-video`), in 4 MB pieces, each piece through a read slot.
  - **Free disk space is checked first**: the file size + a reserve (`GALLERY_VIDEO_RESERVE_MB`, default 3072) must fit.
    Old unused copies are removed first if that helps; if there is still not enough space, nothing is copied and the
    user sees an error. At most `GALLERY_VIDEO_COPY_MAX` (2) copies run at the same time.
  - A copy that nobody used for `GALLERY_VIDEO_CACHE_MINUTES` (15) minutes is deleted (`gallery:video-clean`, every 5 min).
    Playing keeps the copy alive. If the copy is gone while the video is paused, it is copied again once.
- **Delete** (all users): a trash button in the viewer, and the **Select** mode (mark items, then *Delete selected*).
  There is no delete icon on the thumbnails. A simple confirmation is asked, then the item disappears at once (no reload). For the user it is a permanent delete.
  In fact the file is only **hidden for everybody** (`media.hidden_at`, `hidden_by`); nothing is changed on the storage
  box. Later the main admin will be able to delete the hidden files for real. The **Select** button lets users mark
  several photos, videos and folders (a folder means all its photos and videos, also in sub folders) and delete them
  together. Select is not shown in the two smallest views (Mosaic, Small tiles).
- **Folder image (album cover)**: in the viewer, with the info panel open, a photo has the button *Set as folder image*.
  It is a shared setting of the folder (table `folder_covers`): every user who can see the folder may change it, and
  overwrites what others chose. If the chosen photo is deleted, the folder shows a normal picture again.
- **Deleted items** (admin only): a virtual folder at the root, not a real folder. It lists every deleted (hidden) file
  in its **real folder structure** (for example `rKmob/202307/Other/A.jpg`), whoever deleted it and whatever alias name
  the user's folder has. The admin can open folders, view files, and **restore** them: in *Select* mode the button is
  *Restore selected* (files and whole folders), and in the viewer the button is *Restore*. Links of deleted files use
  another signature, so their old public links stop working; only the admin's view makes new ones.
- **Empty folders are not shown** (for everybody): a folder that was read and has no sub folder and no visible photo or
  video. A folder that was never read is shown until it is known. A folder that has only empty sub folders is still shown.
- **Favorites**, **On this day** (photos taken on today's date in past years, only from **flagged folders**), **Map** of photos with GPS
  (Leaflet + marker clusters; city and country names in English and Persian from OpenStreetMap Nominatim).
- **Profile**: change name, password, language, calendar and profile picture. Without a picture, one is looked up by
  e-mail on save (Gravatar, Libravatar, unavatar.io; at most ~10 seconds).
- **Fully responsive** (phones, tablets, desktops).

## On this day: flagged folders and Telegram

- By default nothing is flagged, so *On this day* is empty for everybody. Every folder tile has a small clock icon
  (visible on hover, always visible when on) to flag it. In the list views it is a button in the last column.
- Flags are **recursive**: flagging a folder flags everything below it. A sub folder can be un-flagged (and then its
  children are un-flagged too). Setting a folder always overwrites the flags of all folders below it.
  Table `otd_folders` (user, real path, flagged); the nearest row above a folder decides (`App\Gallery\OtdFolders`).
- Every day at **19:00 project time (IRST)** `gallery:otd-telegram` sends photos and videos taken on that date in past
  years to a Telegram group or channel. It uses the flagged folders of **admin** users only (normal users do not get
  Telegram messages). Settings: bot token (stored encrypted), group/channel ID, minimum years, max images, max videos.
  Videos over 50 MB are skipped; photos over 10 MB are sent as files. The bot must be a member of the group
  (an admin of the channel). The *Send today's memories now* button on the settings page sends the same message at once.

## Background scan (the slow crawler)

`gallery:crawl` runs from the scheduler every **3 minutes** and works about **20 seconds** (setting), then stops.
It goes through all folders in a fixed order (depth first, by name) and remembers the position (`settings.crawl_pos`).

- For each folder it makes **one `stat` call**. If the folder date is the same as in the DB, nothing was added or
  removed, and it goes on to the next folder at once.
- If the date changed (or the folder was never listed) it reads the listing, and reads (scan: EXIF + thumbnail) the
  files that were never read. A file with the same path and the same **size in bytes** is never read again, even when
  its date changed (only a different size triggers a new read). If the time is over, it continues in the same folder 3 minutes later.
- At the end of the tree it starts again from the first folder. After the first rounds it only finds new files and folders.
- Every run writes **one line** to the log (`crawl_logs`), shown on *Scan jobs* (last 300 runs; lines older than 10 days
  are deleted): time, seconds, from/to folder, folders checked, new files, files read, errors, result.
- It pauses while an admin scan job runs. It uses the same read slots (max 2 reads at once) as everything else.

## Time zones

The project time zone is `GALLERY_TZ` (default `Asia/Tehran`, IRST). Dates are stored in UTC. The column
`users.timezone` (empty = project time zone) is ready for a per-user setting; `User::tz()` already uses it for
*On this day*. There is no screen for it yet.

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
- **When the storage is down** (not mounted, disconnected or frozen), login and all pages still work.
  A health check (`App\Gallery\Health`) asks a separate background `ls` and waits at most 3 s, so a frozen
  mount never freezes a request. Folders listed in the last 20 minutes and existing thumbnails still show
  (they come from the DB and the local disk). Everything else shows a friendly message with **Retry** and an
  automatic retry every 30 s; when the storage is back, the user continues on the same folder, scroll position
  or photo. During an outage nothing is deleted from the DB and no file is marked as broken.

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

Background work (scan job, background scan, place names, Telegram) runs from the scheduler:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Useful commands:

| Command | What it does |
|---|---|
| `gallery:user <username> [--admin] [--name=] [--password-stdin]` | create a user or reset a password |
| `gallery:work` | run the active scan job for ~55 s (the scheduler starts it every minute) |
| `gallery:geocode` | find city/country names for photos with GPS (1 request per second) |
| `gallery:crawl [--budget=20]` | background scan: walk folders, read new files, for a few seconds (scheduler: every 3 min) |
| `gallery:copy-video <id>` | copy one video to the local temporary folder (started by the web app) |
| `gallery:video-clean [--minutes=]` | delete local video copies that were not used for a while (scheduler: every 5 min) |
| `gallery:otd-telegram [--force]` | send today's "On this day" memories to Telegram (scheduler: daily 19:00 IRST) |

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
