<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Directory extends Model
{
    protected $fillable = ['path_hash', 'path', 'name', 'parent_id', 'mtime', 'dir_count', 'file_count', 'listed_at'];

    protected function casts(): array
    {
        return ['mtime' => 'datetime', 'listed_at' => 'datetime'];
    }

    public static function hashPath(string $path): string
    {
        return sha1($path);
    }

    public static function findByPath(string $path): ?self
    {
        return static::where('path_hash', static::hashPath($path))->first();
    }

    /**
     * Drop folders that are known to be empty: listed, no sub folder and no visible image or video.
     * A folder that was never listed is kept (we do not know yet).
     *
     * @param  \Illuminate\Support\Collection<int, self>  $dirs
     */
    public static function withoutEmpty($dirs)
    {
        $check = $dirs->filter(fn ($d) => $d->listed_at && (int) $d->dir_count === 0);
        if ($check->isEmpty()) {
            return $dirs;
        }
        $has = Media::whereIn('directory_id', $check->pluck('id'))->whereIn('type', ['image', 'video'])
            ->distinct()->pluck('directory_id')->flip();

        $checked = $check->pluck('id')->flip();

        return $dirs->reject(fn ($d) => $checked->has($d->id) && ! $has->has($d->id))->values();
    }

    /**
     * Pictures chosen by users as the folder image. Only usable ones (visible, with a thumbnail).
     *
     * @param  iterable<int>  $dirIds
     * @return \Illuminate\Support\Collection<int, Media> keyed by directory id
     */
    public static function customCovers($dirIds)
    {
        $ids = collect($dirIds)->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return Media::query()->join('folder_covers', 'folder_covers.media_id', '=', 'media.id')
            ->whereIn('folder_covers.directory_id', $ids)->where('media.has_thumb', true)
            ->get(['media.id', 'media.thumb_v', 'folder_covers.directory_id as cover_dir'])->keyBy('cover_dir');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }
}
