<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Media extends Model
{
    protected $table = 'media';

    protected $guarded = ['id'];

    /** Columns needed for listings (everything except the large "exif" JSON). */
    public const LIST_COLUMNS = ['id', 'directory_id', 'path', 'filename', 'ext', 'type', 'size', 'file_mtime', 'file_ctime',
        'scanned_at', 'scan_error', 'width', 'height', 'taken_at', 'camera_make', 'camera_model', 'duration', 'gps_lat', 'gps_lng',
        'city_en', 'city_fa', 'country_en', 'country_fa', 'has_thumb', 'thumb_v', 'thumb_w', 'thumb_h', 'description', 'rotation'];

    protected function casts(): array
    {
        return [
            'file_mtime' => 'datetime',
            'file_ctime' => 'datetime',
            'scanned_at' => 'datetime',
            'taken_at' => 'datetime',
            'exif' => 'array',
            'has_thumb' => 'boolean',
            'flash' => 'boolean',
            'gps_lat' => 'float',
            'gps_lng' => 'float',
            'gps_alt' => 'float',
            'aperture' => 'float',
            'focal_length' => 'float',
            'duration' => 'float',
        ];
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    public function persons(): HasMany
    {
        return $this->hasMany(MediaPerson::class)->with('person');
    }

    public function isThumbable(): bool
    {
        static $ok = null;
        $ok ??= array_flip(config('gallery.thumbable_ext'));

        return ($this->type === 'image' && isset($ok[$this->ext])) || $this->type === 'video';
    }

    public function needsScan(): bool
    {
        return $this->scanned_at === null && in_array($this->type, ['image', 'video'], true);
    }

    public function thumbFile(): string
    {
        return config('gallery.thumb_dir').'/'.sprintf('%02x', $this->id % 256).'/'.$this->id.'.webp';
    }
}
