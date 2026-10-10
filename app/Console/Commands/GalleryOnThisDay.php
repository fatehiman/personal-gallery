<?php

namespace App\Console\Commands;

use App\Gallery\Settings;
use App\Gallery\TelegramDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GalleryOnThisDay extends Command
{
    protected $signature = 'gallery:otd-telegram {--force : send again even if already sent today}';

    protected $description = 'Send today\'s "On this day" photos and videos (flagged folders of admins) to Telegram';

    public function handle(TelegramDigest $digest): int
    {
        if (! TelegramDigest::configured()) {
            $this->info('Telegram is not configured or not enabled.');

            return self::SUCCESS;
        }
        $today = Carbon::now(config('app.display_timezone'));
        if (! $this->option('force') && Settings::get('otd_last_sent') === $today->toDateString()) {
            return self::SUCCESS;
        }
        Settings::set('otd_last_sent', $today->toDateString()); // set first: a slow run must not be started twice
        $r = $digest->send($today);
        $this->info("sent {$r['sent']}, failed {$r['failed']}, skipped {$r['skipped']}");

        return self::SUCCESS;
    }
}
