<?php

namespace App\Gallery;

/**
 * All file system paths go through this class.
 * A "relative path" is relative to GALLERY_ROOT, uses "/" and has no leading or trailing slash.
 * "" is the root itself.
 */
class Paths
{
    /** Clean a user-supplied relative path. Throws on "..", NUL bytes and similar tricks. */
    public static function normalize(?string $path): string
    {
        $path = str_replace('\\', '/', (string) $path);
        if (str_contains($path, "\0")) {
            throw new InvalidPathException('Invalid path');
        }
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new InvalidPathException('Invalid path');
            }
            $out[] = $seg;
        }

        return implode('/', $out);
    }

    public static function root(): string
    {
        return config('gallery.root');
    }

    /** Absolute path of a (normalized) relative path. */
    public static function abs(string $rel): string
    {
        $rel = self::normalize($rel);

        return $rel === '' ? self::root() : self::root().'/'.$rel;
    }

    /** True when $child is $parent itself or is inside it. */
    public static function isWithin(string $child, string $parent): bool
    {
        if ($parent === '') {
            return true;
        }

        return $child === $parent || str_starts_with($child, $parent.'/');
    }

    public static function parent(string $rel): ?string
    {
        if ($rel === '') {
            return null;
        }
        $pos = strrpos($rel, '/');

        return $pos === false ? '' : substr($rel, 0, $pos);
    }

    public static function basename(string $rel): string
    {
        $pos = strrpos($rel, '/');

        return $pos === false ? $rel : substr($rel, $pos + 1);
    }

    /** Escape a string for use inside a SQL LIKE pattern. */
    public static function like(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /** Stop work when the storage box is not mounted (an empty dir must not look like "everything deleted"). */
    public static function assertAvailable(): void
    {
        $root = self::root();
        if (! is_dir($root)) {
            throw new StorageUnavailableException('Storage is not available');
        }
        if (config('gallery.require_mount')) {
            $a = @stat($root);
            $b = @stat(dirname($root));
            if (! $a || ! $b || $a['dev'] === $b['dev']) {
                throw new StorageUnavailableException('Storage is not mounted');
            }
        }
    }
}
