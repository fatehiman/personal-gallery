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

    /** @param  array<int,bool>  $favs  media id => true */
    public static function media(Media $m, Access $access, array $favs = [], bool $withFolder = false): array
    {
        static $playable = null;
        $playable ??= array_flip(config('gallery.playable_ext'));
        $fa = app()->getLocale() === 'fa';
        $out = [
            'id' => $m->id,
            'name' => $m->filename,
            'type' => $m->type,
            'ext' => $m->ext,
            'size' => (int) $m->size,
            'mtime' => self::iso($m->file_mtime),
            'ctime' => self::iso($m->file_ctime),
            'taken' => self::iso($m->taken_at, false),
            'w' => $m->width,
            'h' => $m->height,
            'tw' => $m->thumb_w,
            'th' => $m->thumb_h,
            'scanned' => $m->scanned_at !== null,
            'err' => $m->scan_error !== null,
            'thumb' => $m->isThumbable() && ($m->scanned_at === null || $m->has_thumb) ? Signer::thumb($m) : null,
            'ready' => (bool) $m->has_thumb,
            'url' => Signer::original($m),
            'dl' => Signer::download($m),
            'rot' => (int) $m->rotation,
            'desc' => $m->description,
            'fav' => isset($favs[$m->id]),
            'duration' => $m->duration,
            'camera' => trim(($m->camera_make ?? '').' '.($m->camera_model ?? '')) ?: null,
            'city' => $fa ? ($m->city_fa ?: $m->city_en) : ($m->city_en ?: $m->city_fa),
            'country' => $fa ? ($m->country_fa ?: $m->country_en) : ($m->country_en ?: $m->country_fa),
            'gps' => $m->gps_lat !== null ? [$m->gps_lat, $m->gps_lng] : null,
            'playable' => $m->type === 'video' && isset($playable[$m->ext]),
        ];
        if ($m->relationLoaded('tags')) {
            $out['tags'] = $m->tags->pluck('name')->all();
        }
        if ($m->relationLoaded('persons')) {
            $out['persons'] = $m->persons->map(fn ($p) => $p->person?->name)->filter()->values()->all();
        }
        if ($withFolder) {
            $folder = Paths::parent($m->path) ?? '';
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
