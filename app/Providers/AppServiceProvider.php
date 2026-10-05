<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enough for fast browsing (one request per folder, suggestions after 500 ms), stops scripted abuse.
        \Illuminate\Support\Facades\RateLimiter::for('api', fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        //
    }
}
