<?php

namespace App\Gallery;

use Illuminate\Support\Facades\Cache;

/**
 * Is the storage box usable right now?
 *
 * A frozen FUSE mount blocks every process that touches it, and PHP cannot time out such a call.
 * So this check never touches the mount from the PHP process:
 *   1. "mounted?" comes from /proc/self/mountinfo,
 *   2. "answers?" is asked by a separate background process (ls); we wait for it at most 3 seconds.
 * The result is cached (20 s when OK, 5 s when down), so normal requests do not pay for it.
 */
class Health
{
    private const KEY = 'gallery.storage.health';

    /** @param  bool  $fresh  check again now (still at most once per 2 seconds) */
    public static function ok(bool $fresh = false): bool
    {
        $c = Cache::get(self::KEY);
        if (is_array($c) && (! $fresh || $c['at'] > time() - 2) && $c['until'] > time()) {
            return $c['ok'];
        }
        $ok = self::probe();
        Cache::put(self::KEY, ['ok' => $ok, 'at' => time(), 'until' => time() + ($ok ? 20 : 5)], 60);

        return $ok;
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    private static function probe(): bool
    {
        $root = Paths::root();
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('exec')) {
            return is_dir($root); // local development: no FUSE mount
        }
        if (config('gallery.require_mount') && ! self::mounted($root)) {
            return false;
        }
        $dir = storage_path('app/locks');
        if (! is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $tag = $dir.'/probe-'.getmypid().'-'.bin2hex(random_bytes(4));
        // Runs in the background: if the mount hangs, only that "ls" hangs, not this request.
        exec('( ls -A '.escapeshellarg($root).' > '.escapeshellarg("$tag.out").' 2>/dev/null; echo $? > '.escapeshellarg("$tag.rc")
            .' ) > /dev/null 2>&1 &');
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            clearstatcache(true, "$tag.rc");
            if (is_file("$tag.rc") && filesize("$tag.rc") > 0) {
                $rc = (int) trim((string) @file_get_contents("$tag.rc"));
                $out = trim((string) @file_get_contents("$tag.out"));
                @unlink("$tag.rc");
                @unlink("$tag.out");

                return $rc === 0 && $out !== '';
            }
            usleep(50_000);
        }
        // Still running: the mount hangs. The leftover files are cleaned when the ls ends or by the next deploy.
        return false;
    }

    private static function mounted(string $root): bool
    {
        $info = @file_get_contents('/proc/self/mountinfo');
        if ($info === false) {
            return true; // cannot tell; the ls probe decides
        }
        foreach (explode("\n", $info) as $line) {
            $f = explode(' ', $line);
            if (isset($f[4]) && str_replace('\\040', ' ', $f[4]) === $root) {
                return true;
            }
        }

        return false;
    }
}
