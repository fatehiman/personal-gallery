<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = ['name', 'username', 'email', 'password', 'is_admin', 'is_active', 'locale', 'calendar', 'prefs'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['is_admin' => false, 'is_active' => true, 'locale' => 'en', 'calendar' => 'gregorian', 'avatar_v' => 0];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'prefs' => 'array',
            'last_login_at' => 'datetime',
        ];
    }

    public function folders(): HasMany
    {
        return $this->hasMany(FolderAccess::class)->orderBy('sort')->orderBy('title');
    }

    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'favorites')->withPivot('created_at');
    }

    public function pref(string $key, mixed $default = null): mixed
    {
        return ($this->prefs ?? [])[$key] ?? $default;
    }

    public function avatarFile(): string
    {
        return storage_path('app/avatars/'.$this->id.'.webp');
    }

    public function avatarUrl(): ?string
    {
        if (! $this->avatar_source || ! is_file($this->avatarFile())) {
            return null;
        }

        return route('avatar', ['user' => $this->id, 'v' => $this->avatar_v]);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }
}
