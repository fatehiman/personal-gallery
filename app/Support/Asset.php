<?php

namespace App\Support;

class Asset
{
    /** URL of a file in public/ with a version (file time) so browsers can cache it for a long time. */
    public static function url(string $path): string
    {
        $file = public_path($path);
        $v = is_file($file) ? base_convert((string) filemtime($file), 10, 36) : '0';

        return '/'.ltrim($path, '/').'?v='.$v;
    }
}
