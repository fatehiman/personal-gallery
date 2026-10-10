<?php

namespace App\Gallery;

use App\Models\Directory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "On this day" folder flags of one user (real paths).
 * A row is an explicit choice. The nearest row above a folder (or on it) decides; no row = not flagged.
 * Setting a folder always overwrites everything below it, so the rows below it are deleted.
 */
class OtdFolders
{
    /** @var array<string,bool> real path => flagged */
    private array $rows = [];

    public function __construct(public readonly User $user)
    {
        $this->load();
    }

    public static function for(User $user): self
    {
        return new self($user);
    }

    private function load(): void
    {
        $this->rows = DB::table('otd_folders')->where('user_id', $this->user->id)
            ->pluck('flagged', 'path')->map(fn ($v) => (bool) $v)->all();
    }

    public function hasFlags(): bool
    {
        return in_array(true, $this->rows, true);
    }

    /** Is this folder (real path) flagged, directly or through a parent? */
    public function effective(string $real): bool
    {
        for ($p = $real; $p !== null; $p = Paths::parent($p)) {
            if (array_key_exists($p, $this->rows)) {
                return $this->rows[$p];
            }
        }

        return false;
    }

    public function set(string $real, bool $on): void
    {
        DB::transaction(function () use ($real, $on) {
            $q = DB::table('otd_folders')->where('user_id', $this->user->id);
            // Overwrite the folder and everything below it.
            $real === '' ? (clone $q)->delete() : (clone $q)->where(fn ($w) => $w->where('path', $real)->orWhere('path', 'like', Paths::like($real).'/%'))->delete();
            $this->load();
            // A row is only needed when it differs from what the parents say.
            $inherited = $real !== '' && $this->effective(Paths::parent($real) ?? '');
            if ($on !== $inherited) {
                DB::table('otd_folders')->insert(['user_id' => $this->user->id, 'path_hash' => Directory::hashPath($real), 'path' => $real, 'flagged' => $on]);
            }
        });
        $this->load();
    }

    /** Limit a query on a media "path" column to files inside flagged folders. */
    public function scope(Builder $q, string $column = 'media.path'): Builder
    {
        if (! $this->hasFlags()) {
            return $q->whereRaw('1 = 0');
        }
        $paths = array_keys($this->rows);
        // The nearest row above each row (the longest other row that contains it).
        $children = [];
        foreach ($paths as $r) {
            $parent = "\0top";
            foreach ($paths as $o) {
                if ($o !== $r && Paths::isWithin($r, $o) && ($parent === "\0top" || strlen($o) > strlen($parent))) {
                    $parent = $o;
                }
            }
            $children[$parent][] = $r;
        }

        $under = function (Builder $w, string $p) use ($column) {
            $p === '' ? $w->whereRaw('1 = 1') : $w->where($column, 'like', Paths::like($p).'/%');
        };
        // Files whose nearest row is $row: inside it, but not inside one of its child rows.
        $own = function (Builder $w, string $row) use ($under, $children) {
            $under($w, $row);
            foreach ($children[$row] ?? [] as $c) {
                $w->whereNot(fn ($n) => $under($n, $c));
            }
        };

        return $q->where(function (Builder $w) use ($paths, $own) {
            foreach ($paths as $row) {
                if ($this->rows[$row]) {
                    $w->orWhere(fn ($x) => $own($x, $row));
                }
            }
        });
    }
}
