<?php

use Illuminate\Support\Facades\Schedule;

// Cron (as the site user):  * * * * * php8.4 /path/to/artisan schedule:run
Schedule::command('gallery:work')->everyMinute()->withoutOverlapping(15)->runInBackground();
Schedule::command('gallery:geocode')->everyMinute()->withoutOverlapping(15)->runInBackground();
// Slow background walk: every 3 minutes, about 20 seconds of work (see App\Gallery\Crawler).
Schedule::command('gallery:crawl')->cron('*/3 * * * *')->withoutOverlapping(10)->runInBackground();
// Delete local video copies that nobody used for a while (see App\Gallery\VideoCache).
Schedule::command('gallery:video-clean')->everyFiveMinutes()->withoutOverlapping(5);
// "On this day" digest for Telegram, every day at 19:00 project time (IRST by default).
Schedule::command('gallery:otd-telegram')->dailyAt('19:00')->timezone(config('app.display_timezone'))->withoutOverlapping(30)->runInBackground();
