<?php

namespace App\Http\Middleware;

use App\Gallery\Activity;
use Closure;
use Illuminate\Http\Request;

/** Remembers that a user is browsing (the background crawler waits, see App\Gallery\Activity). */
class TrackActivity
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && self::counts($request)) {
            Activity::touch($request->user()->username);
        }

        return $next($request);
    }

    /**
     * Only requests that may read the storage box count. Not counted: automatic polls (storage "Retry"), profile
     * pictures (local files, loaded with every page), the admin pages and admin API (scan jobs, statistics, users,
     * settings: database only). Still counted there: the folder picker (lists the storage) and "Send now" (reads files).
     */
    private static function counts(Request $request): bool
    {
        if ($request->is('api/admin/dirs', 'admin/settings/send-now')) {
            return true;
        }

        return ! $request->is('api/health', 'avatar/*', 'admin', 'admin/*', 'api/admin', 'api/admin/*');
    }
}
