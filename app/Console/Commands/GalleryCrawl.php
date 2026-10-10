<?php

namespace App\Console\Commands;

use App\Gallery\Crawler;
use Illuminate\Console\Command;

class GalleryCrawl extends Command
{
    protected $signature = 'gallery:crawl {--budget= : seconds to work (default: the setting, 20)}';

    protected $description = 'Slow background walk: list folders and read new files for a few seconds, then continue next time';

    public function handle(Crawler $crawler): int
    {
        $crawler->run($this->option('budget') !== null ? (int) $this->option('budget') : null);

        return self::SUCCESS;
    }
}
