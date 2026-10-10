<?php

namespace App\Gallery;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Key/value settings in the DB. Secrets (the Telegram token) are stored encrypted. */
class Settings
{
    private const SECRET = ['telegram_token'];

    private static ?array $cache = null;

    private static function all(): array
    {
        return self::$cache ??= DB::table('settings')->pluck('value', 'key')->all();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $v = self::all()[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        if (in_array($key, self::SECRET, true)) {
            try {
                return Crypt::decryptString($v);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $v;
    }

    public static function int(string $key, int $default): int
    {
        return (int) self::get($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        return (bool) (int) self::get($key, $default ? 1 : 0);
    }

    public static function set(string $key, mixed $value): void
    {
        $value = $value === null ? null : (string) $value;
        if ($value !== null && $value !== '' && in_array($key, self::SECRET, true)) {
            $value = Crypt::encryptString($value);
        }
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now()]);
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
