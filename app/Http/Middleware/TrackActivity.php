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
        // Automatic polls (storage "Retry", admin scan status) are not user activity.
        if ($request->user() && ! $request->is('api/health', 'api/admin/scan')) {
            Activity::touch();
        }

        return $next($request);
    }
}
