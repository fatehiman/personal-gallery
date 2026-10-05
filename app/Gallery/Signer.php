<?php

namespace App\Gallery;

use App\Models\Media;

/**
 * Signed, unguessable media URLs. They need no session, so Cloudflare and the browser can cache them for a year.
 * The version ("v") changes when a file is replaced or rotated, so a new URL is used.
 */
class Signer
{
    public static function sig(string $kind, int $id, int $v): string
    {
        static $key = null;
        $key ??= (string) config('gallery.url_key');
        $raw = hash_hmac('sha256', "$kind|$id|$v", $key, true);

        return substr(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='), 0, 22);
    }

    public static function check(string $kind, int $id, int $v, string $sig): bool
    {
        return hash_equals(self::sig($kind, $id, $v), $sig);
    }

    public static function ext(Media $m): string
    {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($m->ext));

        return $ext === '' ? 'bin' : $ext;
    }

    public static function thumb(Media $m): string
    {
        return '/m/t/'.$m->id.'/'.$m->thumb_v.'/'.self::sig('t', $m->id, $m->thumb_v).'.webp';
    }

    public static function original(Media $m): string
    {
        return '/m/o/'.$m->id.'/'.$m->thumb_v.'/'.self::sig('o', $m->id, $m->thumb_v).'.'.self::ext($m);
    }

    public static function download(Media $m): string
    {
        return '/m/d/'.$m->id.'/'.$m->thumb_v.'/'.self::sig('o', $m->id, $m->thumb_v).'.'.self::ext($m);
    }
}
