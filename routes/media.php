<?php

use App\Http\Controllers\MediaFileController;
use Illuminate\Support\Facades\Route;

// Signed media URLs (see App\Gallery\Signer). No session, no cookies => cacheable by Cloudflare.
Route::get('/m/t/{id}/{v}/{sig}.webp', [MediaFileController::class, 'thumb'])
    ->whereNumber(['id', 'v'])->where('sig', '[A-Za-z0-9_-]{22}')->name('media.thumb');
Route::get('/m/{mode}/{id}/{v}/{sig}.{ext}', [MediaFileController::class, 'original'])
    ->where('mode', 'o|d')->whereNumber(['id', 'v'])->where('sig', '[A-Za-z0-9_-]{22}')->where('ext', '[a-z0-9]{1,16}')
    ->name('media.original');
