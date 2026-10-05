<?php

namespace App\Console\Commands;

use App\Gallery\Geocoder;
use Illuminate\Console\Command;

class GalleryGeocode extends Command
{
    protected $signature = 'gallery:geocode {--budget=50 : seconds to work before exit}';

    protected $description = 'Find city and country names for photos with GPS data';

    public function handle(Geocoder $geocoder): int
    {
        $n = $geocoder->run((int) $this->option('budget'));
        if ($n) {
            $this->info("Updated $n media.");
        }

        return self::SUCCESS;
    }
}
