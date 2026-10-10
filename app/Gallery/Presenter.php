<?php

namespace App\Gallery;

use App\Models\Media;

/** Turns Media rows into the small JSON objects the browser uses. */
class Presenter
{
    public static function iso($dt, bool $utc = true): ?string
    {
        if (! $dt) {
            return null;
        }

        // The app time zone is UTC, so stored times are already UTC.
        return $dt->format($utc ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s');
    }

    /** Raw DB value ("Y-m-d H:i:s", UTC) -> ISO string. Much faster than going through Carbon. */
    private static function rawIso($v, bool $utc = true): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof \DateTimeInterface) {
            return self::iso($v, $utc);
        }

        return str_replace(' ', 'T', substr((string) $v, 0, 19)).($utc ? 'Z' : '');
    }

    /**
     * Uses the raw attributes (not Eloquent accessors): a folder can have thousands of files.
     *
     * @param  array<int,bool>  $favs  media id => true
     */
    public static function media(Media $m, Access $access, array $favs = [], bool $withFolder = false): array
    {
        static $playable = null, $thumbable = null;
        $playable ??= array_flip(config('gallery.playable_ext'));
        $thumbable ??= array_flip(config('gallery.thumbable_ext'));
        $fa = app()->getLocale() === 'fa';
        $a = $m->getAttributes();
        $id = (int) $a['id'];
        $v = (int) $a['thumb_v'];
        $ext = (string) $a['ext'];
        $urlExt = preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?: 'bin';
        $hasThumb = (bool) $a['has_thumb'];
        $scanned = $a['scanned_at'] !== null;
        $canThumb = $a['type'] === 'video' || ($a['type'] === 'image' && isset($thumbable[$ext]));
        $hidden = ($a['hidden_at'] ?? null) !== null;
        $osig = Signer::sig(Signer::kind('o', $hidden), $id, $v);
        $num = fn ($x) => $x === null ? null : (float) $x;
        $out = [
            'id' => $id,
            'name' => $a['filename'],
            'type' => $a['type'],
            'ext' => $ext,
            'size' => (int) $a['size'],
            'mtime' => self::rawIso($a['file_mtime'] ?? null),
            'ctime' => self::rawIso($a['file_ctime'] ?? null),
            'taken' => self::rawIso($a['taken_at'] ?? null, false),
            'w' => isset($a['width']) ? (int) $a['width'] : null,
            'h' => isset($a['height']) ? (int) $a['height'] : null,
            'tw' => isset($a['thumb_w']) ? (int) $a['thumb_w'] : null,
            'th' => isset($a['thumb_h']) ? (int) $a['thumb_h'] : null,
            'scanned' => $scanned,
            'err' => ($a['scan_error'] ?? null) !== null,
            'thumb' => $canThumb && (! $scanned || $hasThumb) ? "/m/t/$id/$v/".Signer::sig(Signer::kind('t', $hidden), $id, $v).'.webp' : null,
            'ready' => $hasThumb,
            'url' => "/m/o/$id/$v/$osig.$urlExt",
            'dl' => "/m/d/$id/$v/$osig.$urlExt",
            'rot' => (int) ($a['rotation'] ?? 0),
            'desc' => $a['description'] ?? null,
            'fav' => isset($favs[$id]),
            'hidden' => $hidden,
            'duration' => $num($a['duration'] ?? null),
            'camera' => trim(($a['camera_make'] ?? '').' '.($a['camera_model'] ?? '')) ?: null,
            'city' => $fa ? (($a['city_fa'] ?? null) ?: ($a['city_en'] ?? null)) : (($a['city_en'] ?? null) ?: ($a['city_fa'] ?? null)),
            'country' => $fa ? (($a['country_fa'] ?? null) ?: ($a['country_en'] ?? null)) : (($a['country_en'] ?? null) ?: ($a['country_fa'] ?? null)),
            'gps' => ($a['gps_lat'] ?? null) !== null ? [(float) $a['gps_lat'], (float) $a['gps_lng']] : null,
            'playable' => $a['type'] === 'video' && isset($playable[$ext]),
        ];
        if ($m->relationLoaded('tags')) {
            $out['tags'] = array_map(fn ($t) => $t->getAttributes()['name'], $m->getRelation('tags')->all());
        }
        if ($m->relationLoaded('persons')) {
            $out['persons'] = array_values(array_filter(array_map(fn ($p) => $p->getRelation('person')?->getAttributes()['name'] ?? null, $m->getRelation('persons')->all())));
        }
        if ($withFolder) {
            $folder = Paths::parent($a['path']) ?? '';
            $out['folder'] = $access->toVirtual($folder);
            $crumbs = $out['folder'] === null ? [] : $access->crumbs($out['folder']);
            $out['folderName'] = $crumbs ? end($crumbs)['name'] : '/';
        }

        return $out;
    }

    public static function favMap(Access $access, iterable $ids): array
    {
        $ids = collect($ids)->all();
        if (! $ids) {
            return [];
        }

        return \DB::table('favorites')->where('user_id', $access->user->id)->whereIn('media_id', $ids)
            ->pluck('media_id')->mapWithKeys(fn ($id) => [$id => true])->all();
    }
}
