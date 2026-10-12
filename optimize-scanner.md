# Background scan (crawler): review, fixes and what to check next

Review date: 2026-10-12. Source: table `crawl_logs` on ger1 (643 runs, 2026-10-10 14:42 to 2026-10-11 22:51 UTC),
`storage/logs/laravel-*.log`, and the code (`app/Gallery/Crawler.php`, `Indexer.php`, `Scanner.php`).

## 1. Findings (before the fixes)

### What worked well

| Check | Result |
|---|---|
| Rhythm | Every 3 minutes. Gap between runs: always 174–187 s. No run overlapped. |
| Work time | 19.4–21.0 s per run (budget 20 s). |
| Errors | 0 in every run. No `media.scan_error`. |
| Users have priority | Works: 27 runs `paused: a user is browsing`, 1 run `stopped: a user started browsing`. |
| Folder order | Depth first, by name, correct (`Gordan` → `Mohammadali/...` → `NiceShot` → `Sara` → `Younes&Amanj`). |
| Resume | 610 runs ended with `time over, same folder next time` and continued in the same folder. |

Run results:

| Note | Runs | Avg. seconds |
|---|---|---|
| time over, same folder next time | 610 | 19.8 |
| paused: a user is browsing | 27 | 0.1 |
| time over | 5 | 19.7 |
| stopped: a user started browsing | 1 | 14.0 |

### Progress at review time

- About **42,600 files read in ~32 hours** (about 1,300 per hour).
- Images: 46,696 of 48,065 read (97 %), 1,369 left (about 1 more hour of crawling).
- Folders: 597 of 779 listed, 182 not listed yet.
- Videos: 183 of 998 read, 815 left. Most are in `vdo/` (`.mts` ~440 MB, `.m2ts` ~360 MB on average).
- The first full round was not finished yet (`settings.crawl_cycles` not set).

### Efficiency

- DB cost is small (a few small queries per file; indexes are fine for this size).
- One file at a time, through the shared read slots (max 2 reads at once).
- Speed while working went down from ~5 files/s (first day) to ~2–3 files/s. Not a problem; cause not known
  (bigger photos or a busy server are possible).
- It works only 20 s of every 180 s (11 %). Server load was low (0.3–0.6 on 2 cores). After the first round,
  20 s is enough because unchanged folders cost only one `stat`.

### Problems found

1. **Bug: new files in a sub folder could be missed.** `Indexer::syncSubdirs` saves the date (`mtime`) of every child
   folder when the parent is listed. The crawler skipped a folder when the saved `mtime` was equal to the real one.
   Example: new photos in `A/B` (only B's date changes) → a user opens `A` (or the crawler lists `A`) → B's row gets the
   new date → the crawler thinks B did not change → the new photos were found only when somebody opened `B`.
2. **Small gap: a file overwritten in place** (same name, new size) does not change the folder date, so the crawler
   never saw the new size.
3. **Videos** (`vdo/` not reached yet): ffprobe + ffmpeg read big videos directly from the storage box. The 20 s budget
   was checked only between files, and the process timeouts were 60 s + 90 s + 90 s. A run could take up to ~4 min,
   longer than the 3-minute rhythm. A long video read also could not be stopped when a user started browsing.
4. **Telegram bug (not the crawler):** the 19:00 message on 2026-10-11 failed with 13 errors
   `fclose(): supplied resource is not a valid stream resource` (`TelegramDigest.php:138`). The HTTP client closes the
   file handle itself, so the second `fclose()` threw. Each upload was then counted as failed, and the fallback
   "send as document" used a closed handle.

Note: every page you open (also *Scan jobs*) counts as browsing, so while you look at the log the crawler shows
`paused`. This is expected.

## 2. Corrections (done 2026-10-12)

| # | Fix | Where |
|---|---|---|
| 1 | New column `directories.listed_mtime`: the folder date taken **just before** the folder's own listing. Only `Indexer::sync()` writes it. The crawler compares with it, not with `mtime` (the date shown to users, also updated by the parent's listing). Taking the date before the listing also means a file added during the listing is found next time. | migration `2026_10_12_000003_directories_listed_mtime.php`, `Indexer::sync`, `Crawler::open` |
| 2 | A folder is listed again when its last listing is older than **7 days** (`Crawler::RECHECK_DAYS`), even when its date did not change. | `Crawler::open` |
| 3 | Videos: a new file still starts only inside the budget (20 s), but a video may go on after it. **Hard limit 150 s per run** (`Crawler::MAX_SECONDS`), so a run always ends before the next one (180 s). ffprobe/ffmpeg get the deadline (`Scanner::scan($m, $deadline, $abort)`). If less than 1 s is left, they do not start. A video that times out is marked with `scan_error` (else the crawler would try the same file forever) and counted in *errors*. | `Crawler::walk`, `Scanner::runProcess` |
| 3b | While ffprobe/ffmpeg runs, the crawler checks every 2 s if a user started browsing. If yes, the process is stopped, the video is **not** marked (read again later), and the log says `stopped: a user started browsing`. | `Scanner::runProcess`, `ScanAbortedException` |
| 3c | The temporary first-frame file is deleted also when ffmpeg fails or times out. | `Scanner::scanVideo` |
| 4 | Telegram: every upload opens its own file handle and closes it only if it is still open. The "send as document" fallback opens the file again. | `TelegramDigest::callWithFile` |

Web requests (thumbnails) and the admin scan job call `Scanner::scan($m)` without a deadline: same behavior as before
(ffprobe 60 s, ffmpeg 90 s).

New tests (`tests/Feature/FeaturesTest.php`):
- `test_crawler_finds_new_files_in_a_sub_folder_after_the_parent_was_listed` (fails with the old check)
- `test_crawler_lists_a_folder_again_after_some_days_to_find_overwritten_files`
- `test_long_video_reads_stop_at_the_deadline_or_when_a_user_starts_browsing`
- `test_telegram_upload_works_and_falls_back_to_a_document`

### Expected effects after deploy

- `listed_mtime` is empty for all folders after the migration, so the crawler **lists every folder once again**
  (names/sizes/dates only, no file content). Runs will show more `listed` for a while. This also finds files that the
  old bug missed.
- After that, a quiet folder is listed again about once a week.
- Runs in `vdo/` can take longer than 20 s (up to 150 s).

## 3. What to check in the next log review

Run on ger1 (`ssh Ger1-root`, then `mysql gallery -e "..."`).

```sql
-- 1. Results per note: avg/max seconds, folders, listings, new files, reads, errors
SELECT note, COUNT(*) n, ROUND(AVG(seconds),1) avg_s, MAX(seconds) max_s, SUM(folders) f, SUM(listed) l,
       SUM(new_files) nf, SUM(scanned) sc, SUM(errors) e
FROM crawl_logs GROUP BY note ORDER BY n DESC;

-- 2. Rhythm: gaps between runs (should be ~180 s; a run should never reach 150 s)
SELECT gap, COUNT(*) FROM (SELECT TIMESTAMPDIFF(SECOND, LAG(started_at) OVER (ORDER BY id), started_at) gap
FROM crawl_logs) x GROUP BY gap ORDER BY COUNT(*) DESC LIMIT 15;

-- 3. Long runs (videos)
SELECT id, started_at, seconds, scanned, errors, from_path, note FROM crawl_logs WHERE seconds > 25 ORDER BY id DESC LIMIT 30;

-- 4. Progress
SELECT COUNT(*) dirs, SUM(listed_at IS NOT NULL) listed, SUM(listed_mtime IS NULL) no_listed_mtime FROM directories;
SELECT type, COUNT(*) n, SUM(scanned_at IS NULL) pending, SUM(scan_error IS NOT NULL) err FROM media GROUP BY type;
SELECT * FROM settings WHERE `key` IN ('crawl_pos','crawl_cycles','crawl_cycle_at','last_user_browsing');

-- 5. Errors (timeouts of videos show here)
SELECT ext, LEFT(scan_error,80) e, COUNT(*) FROM media WHERE scan_error IS NOT NULL GROUP BY ext, e ORDER BY 3 DESC;

-- 6. Speed per hour
SELECT DATE_FORMAT(started_at,'%Y-%m-%d %H:00') h, COUNT(*) runs, SUM(scanned) sc, SUM(listed) l,
       ROUND(SUM(scanned)/NULLIF(SUM(seconds),0),2) per_s FROM crawl_logs GROUP BY h ORDER BY h;
```

Also: `grep production.ERROR storage/logs/laravel-*.log` (crawler, scanner and Telegram errors) and the
Telegram result after 19:00 IRST.

### Questions for the next review

- Did the first round finish (`crawl_cycles` ≥ 1)? How long does one quiet round take now?
- How long do videos take in `vdo/`? Many timeouts (`scan_error` with "timed out" / "no time left")? If yes: maybe
  read the first frame from a short local copy, or allow a longer time only at night.
- Is the speed (files/s) still going down? If yes, find out why (big files, CPU, storage box).
- Is 20 s still the right budget after the first round, or can it be lower?
- Does the weekly re-listing cost too much (many `listed` per run every 7 days)? If yes, spread it (for example a
  random 6–8 days per folder).

---

# 4. Database and server review (2026-10-12)

Measured on ger1 (read only) and on a **scratch copy** of the `media` table (a temporary database, dropped afterwards;
production was not changed by the tests).

## 4.1 Facts

| Item | Value |
|---|---|
| Server ger1 | 2 CPU cores, 3.8 GB RAM (375 MB free, **1.6 GB swap in use**), load average ~2 (up to 4 while I tested). Shared with ~50 sites. |
| MySQL | 8.0.46, `innodb_buffer_pool_size` 768 MB, 19 of 151 connections used at most. |
| Gallery database | `media` 95 MB data + 28 MB indexes (59,850 rows, ~1.6 KB per row; `exif` JSON ~900 bytes of it). Everything else is tiny. |
| Buffer pool hit rate | 99.99 % (25,573 disk reads for 180 million requests). |
| Slow query log (> 3 s) | No gallery query. 4 entries from other sites (COMMIT / INSERT / UPDATE of 5–7 s at 05:42 UTC: a disk stall). |
| Disk `/mnt/ger_hd1` | 40 GB, 16 GB free. Thumbnails: 1.2 GB for 58.6 k photos (~21 KB each), already in 256 folders. Inodes 23 % used. |
| Storage box | 823 GB used of 1 TB, but **this is the whole storage box (the account may hold other things), not only gallery files**, so it says nothing sure about the gallery size. The database knows 112.6 GB (59.8 k files: 58.6 k photos 20.8 GB, 998 videos 86.4 GB, 216 other). The real total is unknown. |

## 4.2 The problem that was found (and fixed in migration `2026_10_12_000004`)

`EXPLAIN ANALYZE` showed that **every list, folderless and search query read all rows**: 260–420 ms each at 60 k rows.
Cause: the global scope `hidden_at IS NULL` made MySQL choose `media_hidden_at_index` (only ~10 rows have a value),
read every row through it (random reads) and sort them in memory. The time grows with the number of files.

| Query (copy of production, 59.8 k rows) | Before | After |
|---|---|---|
| Folderless, whole gallery, newest first | 259 ms | **1.1 ms** |
| User with 2 folders | 361 ms | **1.1 ms** |
| Folderless, a sub folder with 7 k files | 423 ms | 70 ms |
| Small sub folder (1.5 k files) | 328 ms | 12 ms |
| Folder cover on the home page | 313 ms | 17 ms |
| Deleted items count | 0.05 ms | 0.1 ms |

Changes (MySQL only, `INPLACE` + `LOCK=NONE`: no write lock, a few seconds; the code works with or without them):

- `sort_at` = `COALESCE(taken_at, file_mtime)` as a **VIRTUAL generated column** + index `media_visible_sort (hidden_at, sort_at)`.
  MySQL uses it by itself for the existing `ORDER BY COALESCE(taken_at, file_mtime)`: no query was changed.
- `media_path_prefix (path(191))` and `directories_path_prefix (path(191))` for `path LIKE 'folder/%'`.
- `media_hidden_at_index` is dropped (replaced by the first index; `hidden_at IS NOT NULL` still works through it).

Not fixed by any index: the search box (`LIKE '%word%'` on 6 columns) still reads the whole table (~0.3 s per 60 k rows).

## 4.3 Is the server enough?

- **Today (60 k files): yes.** The database is 125 MB and fits the buffer pool 6 times. No slow gallery query.
- **The weak points are not MySQL's size**, but: (1) the shared 2-core host that already runs at load ~2 and swaps,
  (2) queries that scan everything (fixed above), (3) disk for thumbnails, (4) the time the crawler needs.
- **If the gallery grows to ~1 million media files** (this is only an upper-bound scenario: the 823 GB on the storage box is not all gallery files, so the real number is unknown. The statistics button will show the real count as the crawler goes on):
  - `media` ≈ 1.6 GB data + 0.5 GB indexes: **does not fit** the 768 MB buffer pool, and the host has no free RAM.
  - Thumbnails ≈ 21 GB: **more than the 16 GB free** on `/mnt/ger_hd1`.
  - The first crawler round: 42.6 k files took 32 h (1.3 k/h) → 1 M files ≈ **32 days**.
  - Whole-table queries (search, the statistics button): ≈ 0.3–0.8 s per 60 k rows → 5–13 s per 1 M rows.
- Up to ~300 k files the current server is fine with the new indexes. Above that, plan the items below.

## 4.4 Suggestions (not done yet, in the order I would do them)

1. **Watch the numbers**: the statistics button (admin *Scan jobs*) shows total files, folders and "took N ms".
   Press it now and then; when "took" passes ~2 s, or files pass ~300 k, do the next items.
2. **Slim the `media` table**: move `exif` (JSON, ~55 % of every row) to a 1:1 table `media_exif`, read only by the
   info panel. Rows 1.6 KB → ~0.7 KB: scans, the statistics and the buffer pool need about half. Medium work (Scanner,
   MediaInfoController, a data-moving migration).
3. **Search**: a narrow table `media_search (media_id, text)` with only the searched text (~150 bytes per row) scanned
   instead of `media`: ~10× faster. I also tested an ngram FULLTEXT index: it was **112 MB for 112 MB of data** and
   returned 0 rows for a word that LIKE finds 15 times, so it is not a drop-in replacement. Not recommended as is.
4. **Disk for thumbnails**: watch `df /mnt/ger_hd1`. Options if it gets tight: lower `GALLERY_THUMB_QUALITY` (72) or
   `GALLERY_THUMB_SIZE` (480), or move the app folder to a bigger disk. (The crawler makes a thumbnail for every
   photo, not only for the ones users look at, so the disk use grows with the number of photos.)
5. **Crawler speed for the first round**: raise "seconds of work" (max 50) at night only; or let a second worker use the
   second read slot when nobody is browsing (about 2× faster; check that the storage box accepts it).
6. **MySQL (server wide, affects the other sites too, so it is your decision)**:
   - `long_query_time` 3 s → 0.5 s to see problems earlier.
   - The 5–7 s COMMITs of other sites at 05:42 look like a disk stall (maybe the backup). Not verified.
   - Buffer pool: raise only if RAM is added. `innodb_flush_log_at_trx_commit=2` would remove most fsync waits, at the
     price of losing up to 1 s of commits after a power loss.
7. **Bigger step if the gallery grows a lot**: its own small VPS (4 GB RAM, 2 cores) for the gallery + MySQL. The shared host
   with 50 sites and swap is the real limit, not the schema.
8. Small things: the crawler counts all `media` rows twice per run (`Media::count()`); `MAX(id)` is enough. Sessions and
   cache are files (fine for one server).

## 4.5 New admin feature

*Scan jobs* page → box **Database statistics**: nothing is loaded with the page. The button *Generate report* calls
`GET /api/admin/scan/stats` (admin only, database only) and shows 6 numbers and 5 circle charts (files by type, size by
type, most common extensions, read status of photos/videos, folders listed or not). One query reads the table in order
(no WHERE: 40 % faster than going through the `hidden_at` index). Code: `ScanController::stats`, `public/assets/admin.js`,
styles in `base.css` (palette classes `c0..c5`, so both themes work).
