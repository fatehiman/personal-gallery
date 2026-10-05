<?php

use App\Gallery\InvalidPathException;
use App\Gallery\StorageUnavailableException;
use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Media files: no session and no cookies, so the CDN and the browser may cache them.
            Route::middleware([SecurityHeaders::class])->group(base_path('routes/media.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [SetLocale::class, SecurityHeaders::class]);
        $middleware->alias(['admin' => AdminOnly::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('browse'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (InvalidPathException $e) => response()->json(['message' => 'Not found'], 404));
        $exceptions->render(function (StorageUnavailableException $e, Request $request) {
            return response()->json(['message' => __('ui.storage_unavailable')], 503);
        });
    })->create();
