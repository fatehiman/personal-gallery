<?php

namespace App\Console\Commands;

use App\Gallery\VideoCache;
use Illuminate\Console\Command;

class GalleryVideoClean extends Command
{
    protected $signature = 'gallery:video-clean {--minutes= : delete copies unused for this many minutes}';

    protected $description = 'Delete local video copies that nobody used for a while';

    public function handle(): int
    {
        $n = VideoCache::clean($this->option('minutes') !== null ? (int) $this->option('minutes') : null);
        $this->info("removed $n");

        return self::SUCCESS;
    }
}
