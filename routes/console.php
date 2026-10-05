<?php

use Illuminate\Support\Facades\Schedule;

// Cron (as the site user):  * * * * * php8.4 /path/to/artisan schedule:run
Schedule::command('gallery:work')->everyMinute()->withoutOverlapping(15)->runInBackground();
Schedule::command('gallery:geocode')->everyMinute()->withoutOverlapping(15)->runInBackground();
