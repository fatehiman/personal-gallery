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
        return ($this->type === 'image' && in_array($this->ext, config('gallery.thumbable_ext'), true))
            || $this->type === 'video';
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
