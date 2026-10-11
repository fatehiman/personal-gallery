<?php

namespace App\Console\Commands;

use App\Gallery\VideoCache;
use App\Models\Media;
use Illuminate\Console\Command;

class GalleryVideoDrop extends Command
{
    protected $signature = 'gallery:video-drop {id : media id} {--delay= : seconds to wait first}';

    protected $description = 'Delete the local copy of one video a few seconds after the last viewer left it (started by the web app)';

    public function handle(): int
    {
        $delay = $this->option('delay') !== null ? (int) $this->option('delay') : (int) config('gallery.video_drop_seconds');
        sleep(max(0, min(300, $delay)));
        $m = Media::withoutGlobalScopes()->where('type', 'video')->find($this->argument('id'));

        return $m && VideoCache::drop($m) ? self::SUCCESS : self::FAILURE;
    }
}
