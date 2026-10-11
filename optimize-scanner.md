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
