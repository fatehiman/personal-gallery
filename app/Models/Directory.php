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

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }
}
