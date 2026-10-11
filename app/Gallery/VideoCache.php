<?php

namespace App\Gallery;

use App\Models\Media;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Videos are not played from the (slow) storage box. When a user opens a video, it is first copied to a temporary
 * folder on this server (storage/app/vcache), and the browser plays the local copy.
 *
 * - The copy runs in a background process (`gallery:copy-video`), in 4 MB pieces; every piece takes a read slot, so
 *   thumbnails and the background scan are not starved. The process holds a lock file while it works.
 * - Formats that browsers cannot play (mkv, avi, mts, wmv, ogv, old codecs ...) are converted to MP4 (H.264 + AAC) by
 *   the same process, **after** the copy (ffmpeg on the storage box would be far too slow). H.264 video is only
 *   re-packed (no re-encoding, fast); anything else is encoded (slow on this server: one at a time, lowest priority).
 *   The copy is deleted as soon as the converted file is ready.
 * - Videos that look playable but fail in the browser (for example HEVC in Firefox) are converted on request
 *   (`start(..., force: true)`); this is remembered per file version.
 * - Progress = size of the ".part" file (copy), ffmpeg's progress file (convert). The user can cancel (".cancel").
 * - Free disk space is checked before the copy (copy + converted file) and again before the conversion. Old unused
 *   copies are removed first when that helps. Not enough: nothing is copied or converted, the user sees an error.
 * - Each open viewer touches a "watcher" file (`.w-<token>`) every 30 s. When the last viewer leaves, the files are
 *   deleted a few seconds later (`gallery:video-drop`). Copies without a live viewer are removed by `gallery:video-clean`.
 *
 * Files of one video ("<id>-<v>"): .<ext> (copied original), .part (copying), .web.tmp (converting), .web.mp4
 * (converted), .prog (ffmpeg progress), .lock, .json (meta), .cancel, .w-<token> (viewers).
 */
class VideoCache
{
    private const CHUNK = 4 * 1024 * 1024;

    /** A viewer counts as "watching" while it sent a sign of life in the last 75 s (the page sends one every 30 s). */
    private const LIVE = 75;

    private const TEMP = '/\.(part|lock|json|cancel|prog|web\.tmp)$/';

    public static function dir(): string
    {
        $dir = config('gallery.video_cache_dir');
        if (! is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        return $dir;
    }

    private static function base(Media $m): string
    {
        return self::dir().'/'.$m->id.'-'.$m->thumb_v;
    }

    /** "<dir>/<id>-<v>" of any file of a video (null for other files). */
    private static function baseOf(string $file): ?string
    {
        return preg_match('/^(\d+-\d+)\./', basename($file), $mm) ? dirname($file).'/'.$mm[1] : null;
    }

    private static function rawFile(Media $m): string
    {
        return self::base($m).'.'.Signer::ext($m);
    }

    private static function webFile(Media $m): string
    {
        return self::base($m).'.web.mp4';
    }

    private static function hasRaw(Media $m): bool
    {
        $f = self::rawFile($m);

        return is_file($f) && filesize($f) === (int) $m->size;
    }

    // ---------------------------------------------------------------- what needs a conversion

    public static function canConvert(): bool
    {
        static $ok = null;

        return $ok ??= (bool) config('gallery.video_convert') && Scanner::ffmpegAvailable();
    }

    private static function forceKey(Media $m): string
    {
        return 'vconv:'.$m->id.':'.$m->thumb_v;
    }

    /** Must this video be converted before a browser can play it? (By extension and codec; or the browser failed.) */
    public static function needsConvert(Media $m): bool
    {
        if (! self::canConvert()) {
            return false;
        }
        if (! in_array(strtolower($m->ext), config('gallery.playable_ext'), true)) {
            return true;
        }
        if ($m->video_codec !== null && ! in_array(strtolower($m->video_codec), config('gallery.browser_codecs'), true)) {
            return true;
        }

        return (bool) Cache::get(self::forceKey($m));
    }

    /** Output size: the longer side at most `video_convert_max_side`, both sides even. */
    private static function outSize(int $w, int $h): array
    {
        $max = (int) config('gallery.video_convert_max_side');
        if ($w <= 0 || $h <= 0 || max($w, $h) <= $max) {
            return [$w, $h];
        }
        $f = $max / max($w, $h);

        return [(int) (round($w * $f / 2) * 2), (int) (round($h * $f / 2) * 2)];
    }

    /** Video bit rate limit by picture size (the shorter side). */
    private static function maxrate(int $w, int $h): int
    {
        $s = min($w, $h);

        return $s <= 0 ? 0 : ($s <= 480 ? 1_500_000 : ($s <= 720 ? 3_000_000 : 5_000_000));
    }

    /** About how many bytes the converted file needs (a little more, to be safe). */
    private static function estimate(int $size, float $seconds, int $w, int $h, bool $remux): int
    {
        if ($remux) {
            return (int) ($size * 1.05) + 1048576;
        }
        if ($seconds <= 0) {
            return (int) ($size * 1.5) + 1048576;
        }
        [$ow, $oh] = self::outSize($w, $h);
        $rate = self::maxrate($ow, $oh) + 192_000;

        return (int) ($seconds * $rate / 8 * 1.1) + 1048576;
    }

    /** Estimate from what the scan saved in the DB (before the copy). */
    private static function estimateFor(Media $m): int
    {
        $remux = strtolower((string) $m->video_codec) === 'h264' && ! Cache::get(self::forceKey($m));

        return self::estimate((int) $m->size, (float) $m->duration, (int) $m->width, (int) $m->height, $remux);
    }

    // ---------------------------------------------------------------- state

    public static function readyFile(Media $m): ?string
    {
        if (is_file(self::webFile($m))) {
            return self::webFile($m); // only a finished conversion has this name
        }

        return ! self::needsConvert($m) && self::hasRaw($m) ? self::rawFile($m) : null;
    }

    /** URL extension of the file that is played: "mp4" for converted files. */
    public static function urlExt(Media $m, string $file): string
    {
        return str_ends_with($file, '.web.mp4') ? 'mp4' : Signer::ext($m);
    }

    public static function mime(Media $m, string $file): string
    {
        return self::urlExt($m, $file) === 'webm' ? 'video/webm' : 'video/mp4';
    }

    public static function url(Media $m, string $file): string
    {
        return '/m/v/'.$m->id.'/'.$m->thumb_v.'/'.Signer::sig(Signer::kind('v', $m->hidden_at !== null), $m->id, $m->thumb_v).'.'.self::urlExt($m, $file);
    }

    /** Is a copy process working on this file now? (The process holds a lock; a crashed one releases it.) */
    private static function running(string $base): bool
    {
        if (! is_file($base.'.lock')) {
            return false;
        }
        $h = @fopen($base.'.lock', 'r');
        if (! $h) {
            return false;
        }
        $free = flock($h, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($h, LOCK_UN);
        }
        fclose($h);

        return ! $free;
    }

    private static function meta(string $base): array
    {
        $j = @file_get_contents($base.'.json');

        return $j ? (json_decode($j, true) ?: []) : [];
    }

    private static function writeMeta(string $base, array $meta): void
    {
        @file_put_contents($base.'.json', json_encode($meta));
    }

    /** Seconds of video that ffmpeg has written (from its progress file). */
    private static function progress(string $base): float
    {
        $h = @fopen($base.'.prog', 'r');
        if (! $h) {
            return 0;
        }
        fseek($h, max(0, (int) @filesize($base.'.prog') - 2048));
        $tail = (string) fread($h, 2048);
        fclose($h);

        return preg_match_all('/out_time_(?:us|ms)=(\d+)/', $tail, $mm) ? (int) end($mm[1]) / 1e6 : 0;
    }

    /**
     * @return array{state: string, copied: int, total: int, convert?: bool, done?: float, length?: float, url?: string,
     *               mime?: string, converted?: bool, error?: string}
     */
    public static function status(Media $m, ?string $watcher = null): array
    {
        if ($watcher !== null) {
            self::watch($m, $watcher);
        }
        $total = (int) $m->size;
        if ($ready = self::readyFile($m)) {
            @touch($ready); // "in use": keeps it from being cleaned

            return ['state' => 'ready', 'copied' => $total, 'total' => $total, 'url' => self::url($m, $ready),
                'mime' => self::mime($m, $ready), 'converted' => str_ends_with($ready, '.web.mp4')];
        }
        $base = self::base($m);
        $meta = self::meta($base);
        if (self::running($base)) {
            $convert = (bool) ($meta['convert'] ?? false);

            return match ($meta['phase'] ?? 'copy') {
                'wait' => ['state' => 'waiting', 'copied' => $total, 'total' => $total, 'convert' => true],
                'convert' => ['state' => 'converting', 'copied' => $total, 'total' => $total, 'convert' => true,
                    'length' => $len = (float) ($meta['length'] ?? 0), 'done' => round($len > 0 ? min($len, self::progress($base)) : self::progress($base), 1)],
                default => ['state' => 'copying', 'copied' => min($total, (int) @filesize($base.'.part')), 'total' => $total, 'convert' => $convert],
            };
        }
        if (! empty($meta['error'])) {
            return ['state' => 'error', 'copied' => 0, 'total' => $total, 'error' => $meta['error']];
        }

        return ['state' => 'none', 'copied' => 0, 'total' => $total, 'convert' => self::needsConvert($m)];
    }

    // ---------------------------------------------------------------- viewers

    private static function watch(Media $m, string $token): void
    {
        @touch(self::base($m).'.w-'.$token);
    }

    /** Does any viewer still show this video? */
    private static function watched(string $base): bool
    {
        foreach (glob($base.'.w-*') ?: [] as $f) {
            if (@filemtime($f) >= time() - self::LIVE) {
                return true;
            }
        }

        return false;
    }

    /**
     * A viewer left the video (next, previous, close, page closed, or Cancel). When nobody else watches it, a running
     * copy or conversion is stopped, and the files are deleted a few seconds later (unless somebody opens it again).
     */
    public static function leave(Media $m, ?string $watcher): array
    {
        $base = self::base($m);
        if ($watcher !== null) {
            @unlink($base.'.w-'.$watcher);
        }
        if (! self::watched($base)) {
            if (self::running($base)) {
                @touch($base.'.cancel');
                for ($i = 0; $i < 20 && self::running($base); $i++) {
                    usleep(100_000);
                }
            }
            if (config('gallery.video_spawn')) {
                self::spawn('gallery:video-drop '.$m->id);
            }
        }

        return self::status($m);
    }

    /** Delete the files of a video when nobody watches it and no process works on it. */
    public static function drop(Media $m): bool
    {
        $base = self::base($m);
        if (self::running($base) || self::watched($base)) {
            return false;
        }
        self::remove($base);

        return true;
    }

    // ---------------------------------------------------------------- disk space

    /** Bytes that running copies and conversions still need. */
    private static function pendingBytes(?string $except): int
    {
        $sum = 0;
        foreach (glob(self::dir().'/*.json') ?: [] as $json) {
            $base = substr($json, 0, -5);
            if ($base === $except || ! self::running($base)) {
                continue;
            }
            $meta = self::meta($base);
            $est = (int) ($meta['est'] ?? 0);
            $sum += max(0, match ($meta['phase'] ?? 'copy') {
                'wait' => $est,
                'convert' => $est - (int) @filesize($base.'.web.tmp'),
                default => (int) ($meta['total'] ?? 0) - (int) @filesize($base.'.part') + $est,
            });
        }

        return $sum;
    }

    /**
     * Enough free space for $need bytes (plus the reserve and what running jobs still need)? Copies that nobody
     * watches are deleted first (oldest first) when that helps. $except: the video we make space for.
     */
    public static function ensureSpace(int $need, ?string $except = null): bool
    {
        $want = $need + (int) config('gallery.video_reserve_mb') * 1048576 + self::pendingBytes($except);
        $free = fn () => (float) @disk_free_space(self::dir());
        if ($free() >= $want) {
            return true;
        }
        $bases = [];
        foreach (glob(self::dir().'/*-*.*') ?: [] as $f) {
            $base = self::baseOf($f);
            if ($base && $base !== $except && is_file($f) && ! str_contains(basename($f), '.w-')) {
                $bases[$base] = max($bases[$base] ?? 0, (int) filemtime($f));
            }
        }
        asort($bases);
        foreach (array_keys($bases) as $base) {
            if (self::running($base) || self::watched($base)) {
                continue;
            }
            self::remove($base);
            if ($free() >= $want) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- start / cancel

    /**
     * Start the copy (and conversion) in the background, if needed. $force: the browser could not play the file,
     * convert it. Returns the status; state "error" has an "error" key.
     */
    public static function start(Media $m, ?string $watcher = null, bool $force = false): array
    {
        if ($force) {
            if (! self::canConvert()) {
                return ['state' => 'error', 'copied' => 0, 'total' => (int) $m->size, 'error' => 'video_unsupported'];
            }
            Cache::forever(self::forceKey($m), 1);
        }
        $st = self::status($m, $watcher);
        if ($st['state'] !== 'none' && $st['state'] !== 'error') {
            return $st;
        }
        $base = self::base($m);
        @unlink($base.'.json');
        $total = (int) $m->size;
        $err = fn (string $key) => ['state' => 'error', 'copied' => 0, 'total' => $total, 'error' => $key];

        if ($total <= 0) {
            return $err('video_missing');
        }
        if (self::runningCount() >= (int) config('gallery.video_copy_max')) {
            return $err('video_busy');
        }
        $convert = self::needsConvert($m);
        $est = $convert ? self::estimateFor($m) : 0;
        $haveRaw = self::hasRaw($m);
        // Copy and converted file are on the disk at the same time (the copy is deleted after the conversion).
        if (! self::ensureSpace(($haveRaw ? 0 : $total) + $est, $base)) {
            $haveRaw && self::remove($base);

            return $err('video_no_space');
        }
        self::writeMeta($base, ['total' => $total, 'convert' => $convert, 'est' => $est, 'phase' => $haveRaw ? 'wait' : 'copy', 'started' => time()]);
        @unlink($base.'.cancel');
        if (config('gallery.video_spawn')) {
            self::spawn('gallery:copy-video '.$m->id);
            // Wait a moment until the process holds its lock (or has failed), so the first status is right.
            for ($i = 0; $i < 20 && ! self::running($base) && empty(self::meta($base)['error']) && ! self::readyFile($m); $i++) {
                usleep(100_000);
            }
        }

        return self::status($m);
    }

    private static function runningCount(): int
    {
        $n = 0;
        foreach (glob(self::dir().'/*.json') ?: [] as $json) {
            $n += self::running(substr($json, 0, -5)) ? 1 : 0;
        }

        return $n;
    }

    private static function spawn(string $command): void
    {
        $php = config('gallery.php_bin');
        $cmd = escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' '.$command.' > /dev/null 2>&1 &';
        Process::fromShellCommandline('nohup setsid '.$cmd, base_path())->setTimeout(10)->run();
    }

    // ---------------------------------------------------------------- the background process

    /** The background process: copy one file, then convert it if needed. Returns true when the video is playable. */
    public static function run(Media $m): bool
    {
        $base = self::base($m);
        $lock = fopen($base.'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return false; // another process works on it
        }
        $raw = self::rawFile($m);
        $part = $base.'.part';
        $src = $dst = null;
        $convert = false;
        $close = function () use (&$src, &$dst) {
            $src && fclose($src);
            $dst && fclose($dst);
            $src = $dst = null;
        };
        $fail = function (string $key) use ($base, $part, $raw, &$convert, $close) {
            $close();
            foreach ([$part, $base.'.web.tmp', $base.'.prog'] as $f) {
                @unlink($f);
            }
            $convert && @unlink($raw); // the browser cannot play it anyway; free the space
            self::writeMeta($base, ['error' => $key]);

            return false;
        };
        $cancelled = function () use ($base, $part, $raw, $close) {
            $close();
            foreach ([$part, $raw, $base.'.web.tmp', $base.'.prog', $base.'.json', $base.'.cancel'] as $f) {
                @unlink($f);
            }

            return false;
        };
        try {
            @unlink($base.'.cancel');
            $total = (int) $m->size;
            $convert = self::needsConvert($m);
            $meta = self::meta($base) + ['total' => $total, 'convert' => $convert, 'est' => $convert ? self::estimateFor($m) : 0];
            if (! self::hasRaw($m)) {
                self::writeMeta($base, ['phase' => 'copy'] + $meta);
                $abs = Paths::abs($m->path);
                Paths::assertAvailable();
                if (! is_file($abs) || is_link($abs)) {
                    return $fail('video_missing');
                }
                if ((float) @disk_free_space(self::dir()) < $total + $meta['est'] + (int) config('gallery.video_reserve_mb') * 1048576 / 2) {
                    return $fail('video_no_space');
                }
                $src = @fopen($abs, 'rb');
                $dst = @fopen($part, 'wb');
                if (! $src || ! $dst) {
                    return $fail('video_missing');
                }
                $slots = new ReadSlots;
                $done = 0;
                while ($done < $total) {
                    if (is_file($base.'.cancel')) {
                        return $cancelled();
                    }
                    if (! $slots->acquire(120)) {
                        return $fail('video_busy');
                    }
                    try {
                        $buf = fread($src, min(self::CHUNK, $total - $done));
                    } finally {
                        $slots->release();
                    }
                    if ($buf === false || $buf === '') {
                        return $fail('video_failed'); // read error or the file got shorter
                    }
                    if (@fwrite($dst, $buf) !== strlen($buf)) {
                        return $fail('video_no_space'); // disk full while writing
                    }
                    $done += strlen($buf);
                }
                $close();
                rename($part, $raw);
            }
            if (! $convert) {
                @unlink($base.'.json');
                @touch($raw);

                return true;
            }

            return self::convert($m, $base, $raw, $meta, $fail, $cancelled);
        } catch (Throwable $e) {
            report($e);

            return $fail($e instanceof StorageUnavailableException ? 'video_storage' : 'video_failed');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Convert the local copy to MP4. Only `video_convert_max` conversions run at the same time. */
    private static function convert(Media $m, string $base, string $raw, array $meta, \Closure $fail, \Closure $cancelled): bool
    {
        self::writeMeta($base, ['phase' => 'wait'] + $meta);
        $slot = null;
        for ($waited = 0; ! ($slot = self::convertSlot()); $waited++) {
            if (is_file($base.'.cancel')) {
                return $cancelled();
            }
            if ($waited > 3600) {
                return $fail('video_busy');
            }
            sleep(1);
        }
        try {
            $probe = self::probe($raw);
            $plan = self::plan($probe, $raw, $base.'.web.tmp', false);
            if (! $plan) {
                return $fail('video_convert_failed');
            }
            // The copy is already here: only the converted file needs space now.
            if (! self::ensureSpace($plan['est'], $base)) {
                return $fail('video_no_space');
            }
            self::writeMeta($base, ['phase' => 'convert', 'length' => $plan['length'], 'est' => $plan['est']] + $meta);
            $r = self::ffmpeg($base, $plan['args']);
            if ($r === 'fail' && $plan['remux']) {
                // Re-packing did not work (broken stream): encode it instead.
                $plan = self::plan($probe, $raw, $base.'.web.tmp', true);
                if (! self::ensureSpace($plan['est'], $base)) {
                    return $fail('video_no_space');
                }
                self::writeMeta($base, ['phase' => 'convert', 'length' => $plan['length'], 'est' => $plan['est']] + $meta);
                $r = self::ffmpeg($base, $plan['args']);
            }
            if ($r !== 'ok') {
                return $r === 'cancel' ? $cancelled() : $fail($r === 'space' ? 'video_no_space' : 'video_convert_failed');
            }
            rename($base.'.web.tmp', self::webFile($m));
            foreach ([$raw, $base.'.prog', $base.'.json'] as $f) {
                @unlink($f); // the copy is not needed any more
            }
            @touch(self::webFile($m));

            return true;
        } finally {
            flock($slot, LOCK_UN);
            fclose($slot);
        }
    }

    /** @return resource|null a held conversion slot */
    private static function convertSlot()
    {
        for ($i = 0; $i < max(1, (int) config('gallery.video_convert_max')); $i++) {
            $h = @fopen(self::dir().'/convert-'.$i.'.slot', 'c');
            if ($h && flock($h, LOCK_EX | LOCK_NB)) {
                return $h;
            }
            $h && fclose($h);
        }

        return null;
    }

    private static function probe(string $file): array
    {
        $p = new Process([config('gallery.ffprobe'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $file]);
        $p->setTimeout(120)->run();

        return json_decode($p->getOutput(), true) ?: [];
    }

    /**
     * ffmpeg arguments: H.264 video in 8-bit 4:2:0 is only re-packed (fast); everything else is encoded with libx264.
     * AAC/MP3 audio is kept, other audio becomes AAC stereo.
     *
     * @return array{args: list<string>, remux: bool, length: float, est: int}|null
     */
    private static function plan(array $probe, string $in, string $out, bool $encode): ?array
    {
        $streams = $probe['streams'] ?? [];
        $v = collect($streams)->first(fn ($s) => ($s['codec_type'] ?? '') === 'video' && empty($s['disposition']['attached_pic']));
        $a = collect($streams)->firstWhere('codec_type', 'audio');
        if (! $v && ! $a) {
            return null;
        }
        $length = (float) ($probe['format']['duration'] ?? $v['duration'] ?? $a['duration'] ?? 0);
        $w = (int) ($v['width'] ?? 0);
        $h = (int) ($v['height'] ?? 0);
        $remux = ! $encode && $v && ($v['codec_name'] ?? '') === 'h264' && in_array($v['pix_fmt'] ?? '', ['yuv420p', 'yuvj420p'], true);

        $args = ['-i', $in];
        foreach ([$v, $a] as $s) {
            if ($s) {
                array_push($args, '-map', '0:'.$s['index']);
            }
        }
        if ($v && $remux) {
            array_push($args, '-c:v', 'copy');
        } elseif ($v) {
            $max = (int) config('gallery.video_convert_max_side');
            $filters = [];
            if (in_array($v['field_order'] ?? '', ['tt', 'bb', 'tb', 'bt'], true)) {
                $filters[] = 'yadif'; // interlaced (camcorders): de-interlace
            }
            $filters[] = "scale=w='if(gte(iw,ih),trunc(min($max,iw)/2)*2,-2)':h='if(gte(iw,ih),-2,trunc(min($max,ih)/2)*2)'";
            [$ow, $oh] = self::outSize($w, $h);
            $rate = self::maxrate($ow, $oh) ?: 3_000_000;
            array_push($args, '-vf', implode(',', $filters), '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
                '-maxrate', (string) $rate, '-bufsize', (string) ($rate * 2), '-pix_fmt', 'yuv420p',
                '-threads', (string) max(1, (int) config('gallery.video_convert_threads')));
        }
        if ($a) {
            if (in_array($a['codec_name'] ?? '', ['aac', 'mp3'], true)) {
                array_push($args, '-c:a', 'copy');
            } else {
                array_push($args, '-c:a', 'aac', '-b:a', '160k', '-ac', '2');
            }
        }
        array_push($args, '-movflags', '+faststart', '-f', 'mp4', $out);

        return ['args' => $args, 'remux' => $remux, 'length' => round($length, 2),
            'est' => self::estimate((int) filesize($in), $length, $w, $h, $remux)];
    }

    /** Run ffmpeg (lowest CPU priority). Stops on cancel or when the disk gets too full. Returns ok|fail|cancel|space. */
    private static function ffmpeg(string $base, array $args): string
    {
        @unlink($base.'.prog');
        @unlink($base.'.web.tmp');
        $nice = PHP_OS_FAMILY === 'Windows' ? [] : ['nice', '-n', '19'];
        $p = new Process(array_merge($nice, [config('gallery.ffmpeg'), '-nostdin', '-v', 'error', '-y', '-nostats', '-progress', $base.'.prog'], $args));
        $p->setTimeout(null);
        $p->start();
        $minFree = (int) config('gallery.video_reserve_mb') * 1048576 / 2;
        for ($t = 0; $p->isRunning(); $t++) {
            usleep(250_000);
            if (is_file($base.'.cancel')) {
                $p->stop(3);

                return 'cancel';
            }
            if ($t % 8 === 0 && (float) @disk_free_space(self::dir()) < $minFree) {
                $p->stop(3);
                @unlink($base.'.web.tmp');

                return 'space';
            }
        }
        if ($p->isSuccessful() && (int) @filesize($base.'.web.tmp') > 0) {
            return 'ok';
        }
        logger()->warning('video convert failed', ['file' => basename($base), 'error' => mb_substr($p->getErrorOutput(), -500)]);
        @unlink($base.'.web.tmp');

        return 'fail';
    }

    // ---------------------------------------------------------------- cleanup

    /** Remove the files of one video (by base path). Viewer files stay (they expire by themselves). */
    private static function remove(string $base): void
    {
        $running = self::running($base);
        foreach (glob($base.'.*') ?: [] as $f) {
            if (! str_contains(basename($f), '.w-') && (! $running || ! str_ends_with($f, '.lock'))) {
                @unlink($f);
            }
        }
    }

    /**
     * Delete copies that nobody watches and that were not used for a while, leftovers of failed jobs, and old viewer
     * files. Returns the number of removed copies.
     */
    public static function clean(?int $idleMinutes = null): int
    {
        $idle = ($idleMinutes ?? (int) config('gallery.video_cache_minutes')) * 60;
        $groups = [];
        foreach (glob(self::dir().'/*-*.*') ?: [] as $f) {
            if (is_file($f) && ($base = self::baseOf($f))) {
                $groups[$base][] = $f;
            }
        }
        $n = 0;
        foreach ($groups as $base => $files) {
            if (self::running($base)) {
                continue;
            }
            $live = self::watched($base);
            foreach ($files as $f) {
                $age = time() - (int) @filemtime($f);
                if (str_contains(basename($f), '.w-')) {
                    $age > self::LIVE && @unlink($f);

                    continue;
                }
                $isTemp = (bool) preg_match(self::TEMP, $f);
                // Ready copies: idle time (and nobody watches). Leftovers (nobody works on them): after 10 minutes.
                if (! $live && $age > ($isTemp ? 600 : $idle)) {
                    @unlink($f);
                    $isTemp || $n++;
                }
            }
        }

        return $n;
    }
}
