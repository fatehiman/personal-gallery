<?php

namespace App\Gallery;

use Illuminate\Support\Facades\DB;

/**
 * "A user is using the gallery right now": the time of the last user request that may use the storage box
 * (pages, API calls, thumbnails and originals that are read from the storage). It is saved in settings.last_user_browsing
 * (unix time). The background crawler does not run while this is newer than QUIET_MINUTES, so users have the
 * full speed of the storage. The crawler continues by itself after the users were away for that time.
 */
class Activity
{
    public const KEY = 'last_user_browsing';

    public const QUIET_MINUTES = 5;

    /** Write at most every 10 seconds (one small query per request is the only cost). */
    private const THROTTLE = 10;

    public static function touch(): void
    {
        $last = self::last();
        if ($last === null || time() - $last >= self::THROTTLE) {
            Settings::set(self::KEY, time());
        }
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
