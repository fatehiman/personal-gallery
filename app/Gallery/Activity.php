<?php

namespace App\Gallery;

use Illuminate\Support\Facades\DB;

/**
 * "A user is using the gallery right now": the time of the last user request that may use the storage box
 * (pages, API calls, thumbnails and originals that are read from the storage). It is saved in settings.last_user_browsing
 * (unix time). The background crawler does not run while this is newer than QUIET_MINUTES, so users have the
 * full speed of the storage. The crawler continues by itself after the users were away for that time.
 * The username is saved too (settings.last_user_browsing_by), for the crawler log. Media requests (thumbnails,
 * originals) have no session: they update only the time and keep the last name.
 */
class Activity
{
    public const KEY = 'last_user_browsing';

    public const KEY_USER = 'last_user_browsing_by';

    public const QUIET_MINUTES = 5;

    /** Write at most every 10 seconds (one small query per request is the only cost). */
    private const THROTTLE = 10;

    public static function touch(?string $username = null): void
    {
        $last = self::last();
        $changed = $username !== null && $username !== self::who();
        if ($last === null || time() - $last >= self::THROTTLE || $changed) {
            Settings::set(self::KEY, time());
            if ($changed) {
                Settings::set(self::KEY_USER, $username);
            }
        }
    }

    /** Username of the last active user (read from the DB every time, like last()). */
    public static function who(): ?string
    {
        $v = DB::table('settings')->where('key', self::KEY_USER)->value('value');

        return $v === null || $v === '' ? null : (string) $v;
    }

    /** " (username)" for the crawler log, or "" when unknown. */
    public static function whoSuffix(): string
    {
        $who = self::who();

        return $who === null ? '' : ' ('.mb_substr($who, 0, 60).')';
    }

    /** Unix time of the last user activity (read from the DB every time: the crawler is a long process). */
    public static function last(): ?int
    {
        $v = DB::table('settings')->where('key', self::KEY)->value('value');

        return $v === null || $v === '' ? null : (int) $v;
    }

    /** True when a user was active in the last QUIET_MINUTES minutes. */
    public static function recent(): bool
    {
        $last = self::last();

        return $last !== null && time() - $last < self::QUIET_MINUTES * 60;
    }
}
