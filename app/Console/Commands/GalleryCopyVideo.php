<?php

namespace App\Console\Commands;

use App\Gallery\VideoCache;
use App\Models\Media;
use Illuminate\Console\Command;

class GalleryCopyVideo extends Command
{
    protected $signature = 'gallery:copy-video {id : media id}';

    protected $description = 'Copy one video from the storage box to the local temporary folder, and convert it if needed (started by the web app)';

    public function handle(): int
    {
        set_time_limit(0);
        $m = Media::withoutGlobalScopes()->where('type', 'video')->find($this->argument('id'));

        return $m && VideoCache::run($m) ? self::SUCCESS : self::FAILURE;
    }
}
