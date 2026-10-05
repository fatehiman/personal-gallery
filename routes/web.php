<?php

use App\Http\Controllers\Admin\FolderController;
use App\Http\Controllers\Admin\ScanController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrowseController;
use App\Http\Controllers\MediaInfoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::get('/lang/{locale}', [AuthController::class, 'guestLocale'])->whereIn('locale', ['en', 'fa'])->name('guest.locale');

// auth.session: changing a password ends the user's other sessions (other browsers / devices).
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', fn () => redirect()->route('browse'));

    // Pages (one shell, the JS app does the rest)
    Route::get('/browse/{path?}', [BrowseController::class, 'page'])->where('path', '.*')->name('browse');
    Route::get('/favorites', [BrowseController::class, 'page'])->defaults('mode', 'favorites')->name('favorites');
    Route::get('/on-this-day', [BrowseController::class, 'page'])->defaults('mode', 'onthisday')->name('onthisday');
    Route::get('/search', [BrowseController::class, 'page'])->defaults('mode', 'search')->name('search');
    Route::get('/map', [BrowseController::class, 'map'])->name('map');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::post('/profile/avatar', [ProfileController::class, 'avatar'])->name('profile.avatar');
    Route::post('/profile/avatar/remove', [ProfileController::class, 'removeAvatar'])->name('profile.avatar.remove');
    Route::get('/avatar/{user}/{v}.webp', [ProfileController::class, 'avatarFile'])->whereNumber(['user', 'v'])->name('avatar');

    Route::prefix('api')->middleware('throttle:api')->group(function () {
        Route::get('/list', [BrowseController::class, 'list']);
        // Storage status for the "Retry" button. fresh=1 checks again (still at most every 2 s).
        Route::get('/health', fn (\Illuminate\Http\Request $r) => response()->json(['storage' => \App\Gallery\Health::ok($r->boolean('fresh')) ? 'ok' : 'down']));
        Route::get('/search', [SearchController::class, 'search']);
        Route::get('/favorites', [SearchController::class, 'favorites']);
        Route::get('/on-this-day', [SearchController::class, 'onThisDay']);
        Route::get('/map', [SearchController::class, 'map']);
        Route::get('/suggest/{kind}', [SearchController::class, 'suggest'])->whereIn('kind', ['tags', 'persons', 'cameras', 'places']);
        Route::post('/prefs', [ProfileController::class, 'prefs']);

        Route::get('/media/{media}', [MediaInfoController::class, 'show'])->whereNumber('media');
        Route::post('/media/{media}/description', [MediaInfoController::class, 'description'])->whereNumber('media');
        Route::post('/media/{media}/rotate', [MediaInfoController::class, 'rotate'])->whereNumber('media');
        Route::post('/media/{media}/favorite', [MediaInfoController::class, 'favorite'])->whereNumber('media');
        Route::post('/media/{media}/tags', [MediaInfoController::class, 'addTag'])->whereNumber('media');
        Route::delete('/media/{media}/tags/{tag}', [MediaInfoController::class, 'removeTag'])->whereNumber(['media', 'tag']);
        Route::post('/media/{media}/persons', [MediaInfoController::class, 'addPerson'])->whereNumber('media');
        Route::delete('/media/{media}/persons/{mp}', [MediaInfoController::class, 'removePerson'])->whereNumber(['media', 'mp']);

        Route::middleware('admin')->prefix('admin')->group(function () {
            Route::get('/dirs', [FolderController::class, 'dirs']);
            Route::get('/scan', [ScanController::class, 'status']);
            Route::post('/scan', [ScanController::class, 'start']);
            Route::post('/scan/stop', [ScanController::class, 'stop']);
        });
    });

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show']);
        Route::post('/users/{user}/folders', [FolderController::class, 'store'])->name('folders.store');
        Route::put('/users/{user}/folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
        Route::delete('/users/{user}/folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');
        Route::get('/scans', [ScanController::class, 'index'])->name('scans');
    });
});
