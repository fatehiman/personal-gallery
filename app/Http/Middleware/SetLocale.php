<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login');
        }
        $locale = $user?->locale ?? $request->session()->get('guest_locale', 'en');
        app()->setLocale(in_array($locale, ['en', 'fa'], true) ? $locale : 'en');

        return $next($request);
    }
}
