<?php

namespace App\Gallery;

use App\Models\Media;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Videos are not played from the (slow) storage box. When a user opens a video, it is first copied to a temporary
 * folder on this server (storage/app/vcache), and the browser plays the local copy.
 *
 * - The copy runs in a background process (`gallery:copy-video`), in 4 MB pieces; every piece takes a read slot, so
 *   thumbnails and the background scan are not starved. The process holds a lock file while it works.
 * - Progress = size of the ".part" file. The user can cancel (a ".cancel" file stops the process).
 * - Before copying, the free disk space is checked (old copies are removed first when that helps). Not enough: no copy.
 * - Copies that nobody used for a few minutes are deleted (`gallery:video-clean`, every 5 minutes).
 *
 * Files of one video: <id>-<v>.<ext> (ready), .part (copying), .lock, .json (meta), .cancel.
 */
class VideoCache
{
    private const CHUNK = 4 * 1024 * 1024;

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

    private static function finalFile(Media $m): string
    {
        return self::base($m).'.'.Signer::ext($m);
    }

    /** Path of the finished local copy (null when there is none). */
    public static function readyFile(Media $m): ?string
    {
        $f = self::finalFile($m);

        return is_file($f) && filesize($f) === (int) $m->size ? $f : null;
    }

    public static function url(Media $m): string
    {
        return '/m/v/'.$m->id.'/'.$m->thumb_v.'/'.Signer::sig(Signer::kind('v', $m->hidden_at !== null), $m->id, $m->thumb_v).'.'.Signer::ext($m);
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

    /** @return array{state: string, copied: int, total: int, url?: string, error?: string} */
    public static function status(Media $m): array
    {
        $total = (int) $m->size;
        if ($ready = self::readyFile($m)) {
            @touch($ready); // "in use": keeps it from being cleaned

            return ['state' => 'ready', 'copied' => $total, 'total' => $total, 'url' => self::url($m)];
        }
        $base = self::base($m);
        if (self::running($base)) {
            return ['state' => 'copying', 'copied' => min($total, (int) @filesize($base.'.part')), 'total' => $total];
        }
        $meta = self::meta($base);
        if (! empty($meta['error'])) {
            return ['state' => 'error', 'copied' => 0, 'total' => $total, 'error' => $meta['error']];
        }

        return ['state' => 'none', 'copied' => 0, 'total' => $total];
    }

    /** Bytes that running copies still need. */
    private static function pendingBytes(): int
    {
        $sum = 0;
        foreach (glob(self::dir().'/*.json') ?: [] as $json) {
            $base = substr($json, 0, -5);
            if (self::running($base)) {
                $meta = self::meta($base);
                $sum += max(0, (int) ($meta['total'] ?? 0) - (int) @filesize($base.'.part'));
            }
        }

        return $sum;
    }

    /** Enough free space for $need bytes (plus the reserve)? Idle old copies are deleted first when that helps. */
    public static function ensureSpace(int $need): bool
    {
        $reserve = (int) config('gallery.video_reserve_mb') * 1048576;
        $want = $need + $reserve + self::pendingBytes();
        $free = fn () => (float) @disk_free_space(self::dir());
        if ($free() >= $want) {
            return true;
        }
        // Oldest-used ready copies first; never one that was used in the last 2 minutes.
        $files = [];
        foreach (glob(self::dir().'/*-*.*') ?: [] as $f) {
            if (! preg_match('/\.(part|lock|json|cancel)$/', $f) && is_file($f) && filemtime($f) < time() - 120) {
                $files[$f] = filemtime($f);
            }
        }
        asort($files);
        foreach (array_keys($files) as $f) {
            self::remove(preg_replace('/\.[^.]+$/', '', $f));
            if ($free() >= $want) {
                return true;
            }
        }

        return false;
    }

    /** Start the copy in the background (if needed). Returns the status; state "error" has an "error" key. */
    public static function start(Media $m): array
    {
        $st = self::status($m);
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
        if (! self::ensureSpace($total)) {
            return $err('video_no_space');
        }
        self::writeMeta($base, ['total' => $total, 'started' => time()]);
        @unlink($base.'.cancel');
        if (config('gallery.video_spawn')) {
            self::spawn($m->id);
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

    private static function spawn(int $id): void
    {
        $php = config('gallery.php_bin');
        $cmd = escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' gallery:copy-video '.$id.' > /dev/null 2>&1 &';
        Process::fromShellCommandline('nohup setsid '.$cmd, base_path())->setTimeout(10)->run();
    }

    public static function cancel(Media $m): array
    {
        $base = self::base($m);
        if (self::running($base)) {
            @touch($base.'.cancel');
            for ($i = 0; $i < 20 && self::running($base); $i++) {
                usleep(100_000);
            }
        }

        return self::status($m);
    }

    /** The background process: copy one file. Returns true when the copy is complete. */
    public static function run(Media $m): bool
    {
        $base = self::base($m);
        $lock = fopen($base.'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return false; // another process is copying it
        }
        $part = $base.'.part';
        $src = $dst = null;
        $fail = function (string $key) use ($base, $part, &$src, &$dst) {
            $src && fclose($src);
            $dst && fclose($dst);
            @unlink($part);
            self::writeMeta($base, ['error' => $key]);

            return false;
        };
        try {
            @unlink($base.'.cancel');
            $total = (int) $m->size;
            $abs = Paths::abs($m->path);
            Paths::assertAvailable();
            if (! is_file($abs) || is_link($abs)) {
                return $fail('video_missing');
            }
            if ((float) @disk_free_space(self::dir()) < $total + (int) config('gallery.video_reserve_mb') * 1048576 / 2) {
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
                    @unlink($base.'.cancel');
                    $src && fclose($src);
                    $dst && fclose($dst);
                    @unlink($part);
                    @unlink($base.'.json');

                    return false;
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
            fclose($src);
            fclose($dst);
            $src = $dst = null;
            rename($part, self::finalFile($m));
            @unlink($base.'.json');
            @touch(self::finalFile($m));

            return true;
        } catch (Throwable $e) {
            report($e);

            return $fail($e instanceof StorageUnavailableException ? 'video_storage' : 'video_failed');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Remove every file of one video (by base path). */
    private static function remove(string $base): void
    {
        foreach (glob($base.'.*') ?: [] as $f) {
            if (! str_ends_with($f, '.lock') || ! self::running($base)) {
                @unlink($f);
            }
        }
    }

    /** Delete copies that were not used for a while, and leftovers of failed copies. Returns the number removed. */
    public static function clean(?int $idleMinutes = null): int
    {
        $idle = ($idleMinutes ?? (int) config('gallery.video_cache_minutes')) * 60;
        $n = 0;
        foreach (glob(self::dir().'/*-*.*') ?: [] as $f) {
            if (! is_file($f)) {
                continue;
            }
            $base = preg_replace('/\.[^.]+$/', '', $f);
            if (self::running($base)) {
                continue;
            }
            $age = time() - filemtime($f);
            $isPart = (bool) preg_match('/\.(part|lock|json|cancel)$/', $f);
            // Ready copies: idle time. Leftovers (nobody is copying): after 10 minutes.
            if ($age > ($isPart ? 600 : $idle)) {
                @unlink($f);
                $isPart || $n++;
            }
        }

        return $n;
    }
}
