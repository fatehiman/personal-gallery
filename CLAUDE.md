# Personal Gallery — notes for coding agents

Laravel 13 + MySQL + Blade + plain JS (no Vite, no npm build). Read README.md first, DEPLOY.md for the server.

## Layout

- `app/Gallery/` — the core: `Paths` (all path handling, `..` refused), `Access` (virtual paths per user, access
  checks, SQL scope), `Indexer` (directory listing → DB, never opens files), `Scanner` (the only class that reads
  file content: EXIF/IPTC/ffprobe + WebP thumbnail), `ReadSlots` (flock semaphore: max N storage reads at once),
  `ScanWorker` (the single scan job, time-boxed, resumable), `Geocoder` (Nominatim, 1 req/s, cached per ~1 km),
  `Signer` (HMAC media URLs), `Presenter` (JSON for the browser), `Avatars`, `Crawler` (slow background walk, every 3 min,
  ~20 s, resumable, uses `Indexer` + `Scanner`), `OtdFolders` ("On this day" flags, nearest-row-wins, recursive),
  `Settings` (key/value in DB, the Telegram token is encrypted), `TelegramDigest` (daily message, admins only).
- `routes/media.php` — signed media routes **without** the web middleware (no session, no cookies → CDN cacheable).
- `public/assets/` — `app.js` (helpers, dates, Jalali, suggest, date picker), `gallery.js` (browser, views,
  lazy loading, search, scan), `viewer.js` (lightbox, faces, tags), `map.js`, `admin.js`, `base.css` +
  `theme-en.css` / `theme-fa.css`. Icons: `icons.svg` sprite (Lucide).
- `public/vendor/` — Video.js, Leaflet, markercluster, fonts. Keep everything local (no CDN links).
- Translations: `lang/en/ui.php`, `lang/fa/ui.php` (the same keys are sent to JS). Add every new key to both.

## Rules

- `Media` has a global scope that hides deleted files (`hidden_at`). Use `withoutGlobalScopes()` only in `Indexer` (so hidden
  rows are kept and do not come back) and in future admin tools. "Delete" by users = hide (`HideController`).
- A file is read again only when its **size** changes; a changed date never triggers a re-scan.
- Never write to `GALLERY_ROOT`. Never read file content outside `Scanner`/`MediaFileController::original`
  (one more exception: `TelegramDigest` uploads the files it sends, through `ReadSlots`).
- Every new endpoint that takes a path or a media id must go through `Access` (`resolve`, `canAccessMedia`, `scope`).
- Virtual paths: admin = real path; user = `<folder_access_id>/<sub path>`.
- Dates: `file_mtime` etc. are sent as UTC ISO with `Z`; `taken_at` is camera wall-clock time without `Z`.
- CSP has no `unsafe-inline` for scripts: no inline `<script>` code or `on…=` attributes. Config goes in the
  `#pg-config` JSON block.
- Keep colors light/pastel; both themes must be updated when adding UI.
- User preferences (view, sort, folderless) live in `users.prefs` (server), never in `localStorage`. Default sort: newest first.
- Time zone: `User::tz()` (own zone, else `GALLERY_TZ`). Never hard-code `Asia/Tehran`.
- Run `php artisan test` before committing. Deploy with `bash deploy/deploy.sh`.
