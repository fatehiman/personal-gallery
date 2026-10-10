<?php

namespace App\Gallery;

use App\Models\FolderAccess;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Maps "virtual paths" (what the browser sees) to real relative paths, and checks access.
 *
 * Admin: virtual path == real path, full access.
 * User:  ""            = virtual root (list of folder titles)
 *        "12"          = root of FolderAccess #12
 *        "12/a/b"      = FolderAccess #12 path + "/a/b"
 */
class Access
{
    /** Admin-only virtual folder with all deleted (hidden) files, in their real folder structure. */
    public const DELETED = '~deleted';

    /** @var Collection<int, FolderAccess> */
    private Collection $folders;

    public function __construct(public readonly User $user)
    {
        $this->folders = $user->is_admin ? collect() : $user->folders()->get();
    }

    public static function for(User $user): self
    {
        static $cache = null;
        $cache ??= new \WeakMap; // one Access per User object (per request)

        return $cache[$user] ??= new self($user);
    }

    /** For the admin: the real path inside "Deleted items" (null when the path is not in it). */
    public function deletedPath(string $vpath): ?string
    {
        if (! $this->isAdmin()) {
            return null;
        }
        if ($vpath === self::DELETED) {
            return '';
        }

        return str_starts_with($vpath, self::DELETED.'/') ? substr($vpath, strlen(self::DELETED) + 1) : null;
    }

    public function isAdmin(): bool
    {
        return $this->user->is_admin;
    }

    /** @return Collection<int, FolderAccess> */
    public function folders(): Collection
    {
        return $this->folders;
    }

    /**
     * @return array{real: ?string, access: ?FolderAccess, virtualRoot: bool}
     */
    public function resolve(?string $vpath): array
    {
        $vpath = Paths::normalize($vpath);
        if ($this->isAdmin()) {
            return ['real' => $vpath, 'access' => null, 'virtualRoot' => false];
        }
        if ($vpath === '') {
            return ['real' => null, 'access' => null, 'virtualRoot' => true];
        }
        [$id, $rest] = array_pad(explode('/', $vpath, 2), 2, '');
        $access = ctype_digit($id) ? $this->folders->firstWhere('id', (int) $id) : null;
        if (! $access) {
            throw new NotFoundHttpException;
        }
        $real = $access->path === '' ? $rest : ($rest === '' ? $access->path : $access->path.'/'.$rest);

        return ['real' => $real, 'access' => $access, 'virtualRoot' => false];
    }

    /** Real relative path -> virtual path for this user (null when not accessible). */
    public function toVirtual(string $real): ?string
    {
        if ($this->isAdmin()) {
            return $real;
        }
        foreach ($this->folders as $f) {
            if (Paths::isWithin($real, $f->path)) {
                $rest = $f->path === '' ? $real : ltrim(substr($real, strlen($f->path)), '/');

                return $rest === '' ? (string) $f->id : $f->id.'/'.$rest;
            }
        }

        return null;
    }

    public function canAccessReal(string $real): bool
    {
        return $this->toVirtual($real) !== null;
    }

    public function canAccessMedia(Media $media): bool
    {
        return $this->canAccessReal($media->path);
    }

    /** Limit a query on a "path" column to what this user may see. */
    public function scope(Builder $q, string $column = 'path'): Builder
    {
        if ($this->isAdmin()) {
            return $q;
        }
        if ($this->folders->isEmpty()) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function (Builder $w) use ($column) {
            foreach ($this->folders as $f) {
                if ($f->path === '') {
                    $w->orWhereRaw('1 = 1');

                    continue;
                }
                $w->orWhere($column, $f->path)
                    ->orWhere($column, 'like', Paths::like($f->path).'/%');
            }
        });
    }

    /** Breadcrumbs for a virtual path: [['name' => ..., 'path' => ...], ...] (root not included). */
    public function crumbs(string $vpath): array
    {
        $vpath = Paths::normalize($vpath);
        if ($vpath === '') {
            return [];
        }
        $segs = explode('/', $vpath);
        $out = [];
        $acc = '';
        foreach ($segs as $i => $seg) {
            $acc = $acc === '' ? $seg : $acc.'/'.$seg;
            $name = $seg;
            if ($i === 0 && ! $this->isAdmin()) {
                $name = $this->folders->firstWhere('id', (int) $seg)?->title ?? $seg;
            }
            $out[] = ['name' => $name, 'path' => $acc];
        }

        return $out;
    }
}
