<?php

use Illuminate\Support\Facades\Schedule;

// Cron (as the site user):  * * * * * php8.4 /path/to/artisan schedule:run
Schedule::command('gallery:work')->everyMinute()->withoutOverlapping(15)->runInBackground();
Schedule::command('gallery:geocode')->everyMinute()->withoutOverlapping(15)->runInBackground();
// Slow background walk: every 3 minutes, about 20 seconds of work (see App\Gallery\Crawler).
Schedule::command('gallery:crawl')->cron('*/3 * * * *')->withoutOverlapping(10)->runInBackground();
// Delete local video copies that nobody used for a while (see App\Gallery\VideoCache).
Schedule::command('gallery:video-clean')->everyMinute()->withoutOverlapping(5);
// "On this day" digest for Telegram. Runs every minute; the command itself checks the admin settings
// (every N days or chosen weekdays, one fixed time, project time zone) and sends at most once a day.
Schedule::command('gallery:otd-telegram')->everyMinute()->withoutOverlapping(30)->runInBackground();
