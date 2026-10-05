<?php

namespace App\Gallery;

use App\Models\GeoCache;
use App\Models\Media;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reverse geocoding with OpenStreetMap Nominatim (free, max 1 request per second).
 * Results are cached per ~1 km cell, so photos taken at the same place need only one lookup.
 */
class Geocoder
{
    public static function key(float $lat, float $lng): string
    {
        return sprintf('%.2f,%.2f', $lat, $lng);
    }

    /** Process pending media until the time budget (seconds) ends. Returns how many media were updated. */
    public function run(int $budget = 50): int
    {
        $end = time() + $budget;
        $done = 0;
        while (time() < $end) {
            $batch = Media::where('geo_status', 'pending')->whereNotNull('gps_lat')->orderBy('id')->limit(100)->get(['id', 'gps_lat', 'gps_lng']);
            if ($batch->isEmpty()) {
                break;
            }
            foreach ($batch->groupBy(fn ($m) => self::key($m->gps_lat, $m->gps_lng)) as $key => $items) {
                if (time() >= $end) {
                    break 2;
                }
                $row = GeoCache::where('geo_key', $key)->first() ?? $this->lookup($key, $items->first()->gps_lat, $items->first()->gps_lng);
                if (! $row) {
                    break 2; // network problem: keep them pending, try again on the next run
                }
                Media::whereIn('id', $items->pluck('id'))
                    ->update(['geo_status' => 'done'] + $row->only(['city_en', 'city_fa', 'country_en', 'country_fa', 'country_code']));
                $done += $items->count();
            }
        }

        return $done;
    }

    private function lookup(string $key, float $lat, float $lng): ?GeoCache
    {
        $res = [];
        foreach (['en', 'fa'] as $lang) {
            try {
                $r = Http::timeout(15)
                    ->withHeaders(['User-Agent' => config('gallery.geocode_agent'), 'Accept-Language' => $lang])
                    ->get(config('gallery.geocode_url'), ['format' => 'jsonv2', 'lat' => $lat, 'lon' => $lng, 'zoom' => 10, 'addressdetails' => 1]);
                $res[$lang] = $r->successful() ? ($r->json('address') ?? []) : null;
            } catch (Throwable) {
                $res[$lang] = null;
            }
            usleep(1_100_000); // usage policy: max 1 request per second
        }
        if ($res['en'] === null && $res['fa'] === null) {
            return null; // network error: try again next time
        }
        $city = fn (?array $a) => $a ? mb_substr((string) ($a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['county'] ?? $a['state'] ?? ''), 0, 150) ?: null : null;

        return GeoCache::firstOrCreate(['geo_key' => $key], [
            'city_en' => $city($res['en']),
            'city_fa' => $city($res['fa']),
            'country_en' => mb_substr((string) ($res['en']['country'] ?? ''), 0, 100) ?: null,
            'country_fa' => mb_substr((string) ($res['fa']['country'] ?? ''), 0, 100) ?: null,
            'country_code' => strtolower(substr((string) ($res['en']['country_code'] ?? $res['fa']['country_code'] ?? ''), 0, 2)) ?: null,
        ]);
    }
}
