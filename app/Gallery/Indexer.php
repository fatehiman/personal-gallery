<?php

namespace App\Gallery;

use App\Models\Directory;
use App\Models\Media;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps the "directories" and "media" tables in sync with the storage box.
 * It only reads directory entries (name, size, dates). It never opens a file.
 */
class Indexer
{
    private const SKIP = ['@eaDir', '#recycle', '$RECYCLE.BIN', 'System Volume Information', 'Thumbs.db', 'desktop.ini', '.DS_Store'];

    public static function typeForExt(string $ext): string
    {
        $ext = strtolower($ext);
        if (in_array($ext, config('gallery.image_ext'), true)) {
            return 'image';
        }
        if (in_array($ext, config('gallery.video_ext'), true)) {
            return 'video';
        }

        return 'other';
    }

    /** Make sure the directory listing in the DB is fresh, and return the Directory row. */
    public function sync(string $rel, bool $force = false): Directory
    {
        $rel = Paths::normalize($rel);
        $dir = Directory::findByPath($rel);
        $ttl = config('gallery.listing_ttl');
        if (! $force && $dir?->listed_at && $dir->listed_at->gt(now()->subMinutes($ttl))) {
            return $dir;
        }

        Paths::assertAvailable();
        $abs = Paths::abs($rel);
        if (! is_dir($abs) || is_link($abs)) {
            // Make sure the folder is really gone and the storage did not just fail in the middle.
            // Otherwise an outage would delete its rows (and the tags / people saved on its photos).
            Health::forget();
            Paths::assertAvailable(true);
            $parent = Paths::parent($rel);
            if ($dir && ! is_link($abs) && ($parent === null || is_dir(Paths::abs($parent)))) {
                $this->forget($rel);
            }
            throw new NotFoundHttpException;
        }
        $names = @scandir($abs);
        if ($names === false) {
            // Read error (network?): keep what we have, never delete on errors.
            if ($dir) {
                return $dir;
            }
            throw new StorageUnavailableException('Cannot read directory');
        }

        $dir = $this->ensureDirectory($rel);
        $subdirs = [];
        $files = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.' || in_array($name, self::SKIP, true)) {
                continue;
            }
            if (! mb_check_encoding($name, 'UTF-8')) {
                continue;
            }
            $st = @lstat($abs.'/'.$name);
            if (! $st) {
                continue;
            }
            $mode = $st['mode'] & 0170000;
            if ($mode === 0040000) {
                $subdirs[$name] = $st['mtime'];
            } elseif ($mode === 0100000) {
                $files[$name] = $st;
            }
            // Symlinks and special files are ignored on purpose.
        }

        DB::transaction(function () use ($dir, $rel, $subdirs, $files) {
            $this->syncSubdirs($dir, $rel, $subdirs);
            $this->syncFiles($dir, $rel, $files);
            $st = @stat(Paths::abs($rel));
            $dir->forceFill([
                'listed_at' => now(),
                'dir_count' => count($subdirs),
                'file_count' => count($files),
                'mtime' => $st ? Carbon::createFromTimestamp($st['mtime']) : null,
            ])->save();
        });

        return $dir->refresh();
    }

    private function join(string $rel, string $name): string
    {
        return $rel === '' ? $name : $rel.'/'.$name;
    }

    private function syncSubdirs(Directory $dir, string $rel, array $subdirs): void
    {
        $existing = Directory::where('parent_id', $dir->id)->get()->keyBy('name');
        foreach ($subdirs as $name => $mtime) {
            $path = $this->join($rel, (string) $name);
            $row = $existing->get($name);
            if (! $row || $row->path !== $path) {
                Directory::firstOrCreate(
                    ['path_hash' => Directory::hashPath($path)],
                    ['path' => $path, 'name' => (string) $name, 'parent_id' => $dir->id, 'mtime' => Carbon::createFromTimestamp($mtime)],
                );
            } elseif ($row->mtime?->timestamp !== $mtime) {
                $row->forceFill(['mtime' => Carbon::createFromTimestamp($mtime)])->save();
            }
        }
        foreach ($existing as $name => $row) {
            if (! array_key_exists($name, $subdirs)) {
                $this->forget($row->path);
            }
        }
    }

    private function syncFiles(Directory $dir, string $rel, array $files): void
    {
        // Hidden (deleted by users) rows stay in the table, so a hidden file does not come back.
        $existing = Media::withoutGlobalScopes()->where('directory_id', $dir->id)
            ->get(['id', 'filename', 'size', 'file_mtime', 'has_thumb', 'thumb_v'])
            ->keyBy('filename');
        $insert = [];
        $now = now();
        foreach ($files as $name => $st) {
            $name = (string) $name;
            $row = $existing->get($name);
            $mtime = Carbon::createFromTimestamp($st['mtime']);
            if (! $row) {
                $path = $this->join($rel, $name);
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $insert[] = [
                    'directory_id' => $dir->id,
                    'path_hash' => sha1($path),
                    'path' => $path,
                    'filename' => $name,
                    'ext' => mb_substr($ext, 0, 16),
                    'type' => self::typeForExt($ext),
                    'size' => $st['size'],
                    'file_mtime' => $mtime,
                    'file_ctime' => Carbon::createFromTimestamp($st['ctime']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } elseif ((int) $row->size === (int) $st['size']) {
                // Same path, same name, same size: it is the same file. A changed date alone never triggers a new read.
                if ($row->file_mtime?->timestamp !== $st['mtime']) {
                    Media::withoutGlobalScopes()->whereKey($row->id)->update(['file_mtime' => $mtime]);
                }
            } else {
                // Size changed: the file was replaced, read it again on next view.
                @unlink($row->thumbFile());
                Media::withoutGlobalScopes()->whereKey($row->id)->update([
                    'size' => $st['size'], 'file_mtime' => $mtime,
                    'scanned_at' => null, 'scan_error' => null, 'has_thumb' => false,
                    'thumb_v' => $row->thumb_v + 1, 'updated_at' => $now,
                ]);
            }
        }
        foreach (array_chunk($insert, 300) as $chunk) {
            Media::insertOrIgnore($chunk);
        }
        $gone = $existing->filter(fn ($row, $name) => ! array_key_exists($name, $files));
        foreach ($gone as $row) {
            @unlink($row->thumbFile());
        }
        if ($gone->isNotEmpty()) {
            Media::withoutGlobalScopes()->whereIn('id', $gone->pluck('id'))->delete();
        }
    }

    /** Create the directory row (and its parents) when missing. */
    public function ensureDirectory(string $rel): Directory
    {
        if ($dir = Directory::findByPath($rel)) {
            return $dir;
        }
        $parent = $rel === '' ? null : $this->ensureDirectory(Paths::parent($rel));

        return Directory::firstOrCreate(
            ['path_hash' => Directory::hashPath($rel)],
            ['path' => $rel, 'name' => $rel === '' ? '/' : Paths::basename($rel), 'parent_id' => $parent?->id],
        );
    }

    /** Remove a directory (and everything inside it) from the DB, with its thumbnails. */
    public function forget(string $rel): void
    {
        $like = Paths::like($rel).'/%';
        $dirIds = Directory::where('path', $rel)->orWhere('path', 'like', $like)->pluck('id');
        Media::withoutGlobalScopes()->whereIn('directory_id', $dirIds)->where('has_thumb', true)->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $m) {
                    @unlink($m->thumbFile());
                }
            });
        foreach ($dirIds->chunk(500) as $ids) {
            Media::withoutGlobalScopes()->whereIn('directory_id', $ids)->delete();
            Directory::whereIn('id', $ids)->delete();
        }
    }
}
