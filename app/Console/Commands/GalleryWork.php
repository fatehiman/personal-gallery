<?php

namespace App\Console\Commands;

use App\Gallery\ScanWorker;
use Illuminate\Console\Command;

class GalleryWork extends Command
{
    protected $signature = 'gallery:work {--budget=55 : seconds to work before exit}';

    protected $description = 'Run the active folder scan job for a limited time (started every minute by the scheduler)';

    public function handle(ScanWorker $worker): int
    {
        $worker->run((int) $this->option('budget'));

        return self::SUCCESS;
    }
}
