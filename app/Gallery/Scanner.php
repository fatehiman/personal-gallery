<?php

namespace App\Gallery;

use App\Models\GeoCache;
use App\Models\Media;
use App\Models\Tag;
use GdImage;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads one file: metadata (EXIF, IPTC, size, GPS, video info) and makes the WebP thumbnail.
 * This is the only class that opens file content on the storage box.
 */
class Scanner
{
    private const JUNK_DESCRIPTIONS = '/^(OLYMPUS DIGITAL CAMERA|SONY DSC|DCIM|default|Picture|Image|SAMSUNG|LG Electronics|\s*)$/i';

    public function scan(Media $m): bool
    {
        @set_time_limit(180);
        $abs = Paths::abs($m->path);
        $data = ['scanned_at' => now(), 'scan_error' => null];
        try {
            Paths::assertAvailable();
            if (! is_file($abs)) {
                throw new \RuntimeException('File not found');
            }
            if ($m->type === 'image') {
                $data += $this->scanImage($m, $abs);
            } elseif ($m->type === 'video') {
                $data += $this->scanVideo($m, $abs);
            }
        } catch (StorageUnavailableException $e) {
            return false; // try again later, do not mark as scanned
        } catch (Throwable $e) {
            // A read error during an outage is not the file's fault: do not mark it, try again later.
            Health::forget();
            if (! Health::ok(true)) {
                return false;
            }
            $data['scan_error'] = mb_substr($e->getMessage(), 0, 250);
            report($e);
        }

        $import = $data['_import'] ?? null;
        unset($data['_import']);
        if (isset($data['gps_lat'], $data['gps_lng'])) {
            $data = array_merge($data, $this->geoFromCache($data['gps_lat'], $data['gps_lng']));
        }
        $m->forceFill($data)->save();
        if ($import) {
            $this->importKeywords($m, $import);
        }

        return $data['scan_error'] === null;
    }

    // ---------------------------------------------------------------- images

    private function scanImage(Media $m, string $abs): array
    {
        $out = [];
        $info = [];
        $size = @getimagesize($abs, $info);
        if ($size) {
            [$out['width'], $out['height']] = [$size[0], $size[1]];
            $out['mime'] = $size['mime'] ?? null;
        }

        $exif = null;
        if (in_array($m->ext, ['jpg', 'jpeg', 'jpe', 'tif', 'tiff', 'heic', 'heif'], true) && function_exists('exif_read_data')) {
            $exif = @exif_read_data($abs, null, true, false) ?: null;
        }
        $orientation = 1;
        $import = ['tags' => [], 'description' => null];
        if ($exif) {
            $ifd = $exif['IFD0'] ?? [];
            $ex = $exif['EXIF'] ?? [];
            $orientation = (int) ($ifd['Orientation'] ?? 1);
            $out['orientation'] = $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
            $out['taken_at'] = $this->exifDate($ex['DateTimeOriginal'] ?? null)
                ?? $this->exifDate($ex['DateTimeDigitized'] ?? null)
                ?? $this->exifDate($ifd['DateTime'] ?? null);
            $out['camera_make'] = $this->str($ifd['Make'] ?? null, 100);
            $out['camera_model'] = $this->str($ifd['Model'] ?? null, 150);
            $out['lens'] = $this->str($ex['LensModel'] ?? $ex['UndefinedTag:0xA434'] ?? null, 150);
            $out['software'] = $this->str($ifd['Software'] ?? null, 150);
            if (isset($ex['ExposureTime'])) {
                $out['exposure'] = $this->str($this->exposure($ex['ExposureTime']), 30);
            }
            if (isset($ex['FNumber'])) {
                $out['aperture'] = $this->limit($this->frac($ex['FNumber']), 999);
            }
            $iso = $ex['ISOSpeedRatings'] ?? $ex['PhotographicSensitivity'] ?? null;
            if (is_array($iso)) {
                $iso = reset($iso);
            }
            $out['iso'] = is_numeric($iso) ? min((int) $iso, 4_000_000) : null;
            if (isset($ex['FocalLength'])) {
                $out['focal_length'] = $this->limit($this->frac($ex['FocalLength']), 99999);
            }
            if (isset($ex['Flash'])) {
                $out['flash'] = ((int) $ex['Flash'] & 1) === 1;
            }
            $out += $this->exifGps($exif['GPS'] ?? []);

            foreach (['XPKeywords'] as $k) {
                if (! empty($ifd[$k])) {
                    $import['tags'] = array_merge($import['tags'], preg_split('/[;,]/u', $this->ucs2($ifd[$k])));
                }
            }
            $desc = $this->ucs2($ifd['XPComment'] ?? '') ?: $this->ucs2($ifd['XPTitle'] ?? '') ?: $this->str($ifd['ImageDescription'] ?? null, 2000);
            if ($desc && ! preg_match(self::JUNK_DESCRIPTIONS, $desc)) {
                $import['description'] = $desc;
            }
            $out['exif'] = $this->exifSummary($exif);
        }
        if (! empty($info['APP13']) && ($iptc = @iptcparse($info['APP13']))) {
            foreach ($iptc['2#025'] ?? [] as $kw) {
                $import['tags'][] = $this->toUtf8($kw);
            }
            if (! $import['description'] && ! empty($iptc['2#120'][0])) {
                $import['description'] = $this->toUtf8($iptc['2#120'][0]);
            }
        }
        if ($orientation >= 5 && isset($out['width'])) {
            [$out['width'], $out['height']] = [$out['height'], $out['width']];
        }
        $out['_import'] = $import;

        // Thumbnail
        if (in_array($m->ext, config('gallery.thumbable_ext'), true) && $size && $size[0] * $size[1] <= config('gallery.max_pixels')) {
            $img = $this->loadImage($abs, $size[2]);
            if ($img) {
                $img = $this->fit($img);
                $img = $this->orient($img, $orientation);
                $out += $this->saveThumb($m, $img);
            }
        }

        return $out;
    }

    private function loadImage(string $abs, int $type): ?GdImage
    {
        @ini_set('memory_limit', '768M');
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($abs),
            IMAGETYPE_PNG => @imagecreatefrompng($abs),
            IMAGETYPE_GIF => @imagecreatefromgif($abs),
            IMAGETYPE_WEBP => @imagecreatefromwebp($abs),
            IMAGETYPE_BMP => @imagecreatefrombmp($abs),
            IMAGETYPE_AVIF => function_exists('imagecreatefromavif') ? @imagecreatefromavif($abs) : false,
            default => false,
        };

        return $img ?: null;
    }

    /** Scale down to fit inside a square box of thumb_size. */
    private function fit(GdImage $img): GdImage
    {
        $box = config('gallery.thumb_size');
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $box / max($w, $h));
        if ($scale >= 1 && imageistruecolor($img)) {
            return $img;
        }
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        unset($img);

        return $dst;
    }

    private function orient(GdImage $img, int $o): GdImage
    {
        switch ($o) {
            case 2: imageflip($img, IMG_FLIP_HORIZONTAL);

                return $img;
            case 3: return imagerotate($img, 180, 0);
            case 4: imageflip($img, IMG_FLIP_VERTICAL);

                return $img;
            case 5: imageflip($img, IMG_FLIP_VERTICAL);

                return imagerotate($img, -90, 0);
            case 6: return imagerotate($img, -90, 0);
            case 7: imageflip($img, IMG_FLIP_VERTICAL);

                return imagerotate($img, 90, 0);
            case 8: return imagerotate($img, 90, 0);
        }

        return $img;
    }

    private function saveThumb(Media $m, GdImage $img): array
    {
        if ($m->rotation) {
            $img = imagerotate($img, -$m->rotation, 0);
        }
        $file = $m->thumbFile();
        if (! is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        $tmp = $file.'.'.getmypid().'.tmp';
        imagewebp($img, $tmp, config('gallery.thumb_quality'));
        rename($tmp, $file);

        return ['has_thumb' => true, 'thumb_w' => imagesx($img), 'thumb_h' => imagesy($img)];
    }

    /** Rotate only the stored thumbnail (no need to read the original again). */
    public function rotateThumb(Media $m, int $delta): void
    {
        $file = $m->thumbFile();
        if (! $m->has_thumb || ! is_file($file) || ! ($img = @imagecreatefromwebp($file))) {
            return;
        }
        $img = imagerotate($img, -$delta, 0);
        $tmp = $file.'.'.getmypid().'.tmp';
        imagewebp($img, $tmp, config('gallery.thumb_quality'));
        rename($tmp, $file);
        $m->forceFill(['thumb_w' => imagesx($img), 'thumb_h' => imagesy($img)]);
    }

    // ---------------------------------------------------------------- videos

    public static function ffmpegAvailable(): bool
    {
        static $ok = null;

        return $ok ??= (new ExecutableFinder)->find(config('gallery.ffmpeg')) !== null
            || is_executable(config('gallery.ffmpeg'));
    }

    private function scanVideo(Media $m, string $abs): array
    {
        $out = [];
        if (! self::ffmpegAvailable()) {
            return $out;
        }
        $p = new Process([config('gallery.ffprobe'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $abs]);
        $p->setTimeout(60)->run();
        $probe = json_decode($p->getOutput(), true) ?: [];
        $format = $probe['format'] ?? [];
        $tags = array_change_key_case($format['tags'] ?? []);
        $video = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'video') ?? [];

        $duration = (float) ($format['duration'] ?? $video['duration'] ?? 0);
        $out['duration'] = $duration > 0 ? round($duration, 2) : null;
        $out['video_codec'] = $this->str($video['codec_name'] ?? null, 40);
        $out['mime'] = $this->str($format['format_name'] ?? null, 100);
        $w = (int) ($video['width'] ?? 0);
        $h = (int) ($video['height'] ?? 0);
        $rotate = (int) ($video['tags']['rotate'] ?? 0);
        foreach ($video['side_data_list'] ?? [] as $sd) {
            if (isset($sd['rotation'])) {
                $rotate = (int) $sd['rotation'];
            }
        }
        if (abs($rotate) % 180 === 90) {
            [$w, $h] = [$h, $w];
        }
        $out['width'] = $w ?: null;
        $out['height'] = $h ?: null;
        $out['camera_make'] = $this->str($tags['com.apple.quicktime.make'] ?? $tags['make'] ?? null, 100);
        $out['camera_model'] = $this->str($tags['com.apple.quicktime.model'] ?? $tags['model'] ?? null, 150);
        $created = $tags['com.apple.quicktime.creationdate'] ?? $tags['creation_time'] ?? null;
        if ($created) {
            try {
                $out['taken_at'] = Carbon::parse($created)->setTimezone(config('app.display_timezone'))->format('Y-m-d H:i:s');
            } catch (Throwable) {
            }
        }
        $loc = $tags['com.apple.quicktime.location.iso6709'] ?? $tags['location'] ?? null;
        if ($loc && preg_match('/([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)?/', $loc, $mm)) {
            $out['gps_lat'] = (float) $mm[1];
            $out['gps_lng'] = (float) $mm[2];
            $out['gps_alt'] = isset($mm[3]) ? (float) $mm[3] : null;
            $out['geo_status'] = 'pending';
        }
        $out['exif'] = array_filter(['format' => $format['format_long_name'] ?? null, 'bit_rate' => $format['bit_rate'] ?? null,
            'fps' => $video['avg_frame_rate'] ?? null, 'audio' => collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio')['codec_name'] ?? null,
            'encoder' => $tags['encoder'] ?? null, 'rotate' => $rotate ?: null]);

        // First frame (a little after the start, the very first frame is often black).
        $tmp = tempnam(sys_get_temp_dir(), 'pgv').'.jpg';
        foreach ([$duration > 2 ? 1 : 0, 0] as $at) {
            $p = new Process([config('gallery.ffmpeg'), '-v', 'error', '-y', '-ss', (string) $at, '-i', $abs, '-frames:v', '1',
                '-vf', sprintf("scale=w='min(%1\$d,iw)':h='min(%1\$d,ih)':force_original_aspect_ratio=decrease", config('gallery.thumb_size')), '-q:v', '3', $tmp]);
            $p->setTimeout(90)->run();
            if (is_file($tmp) && filesize($tmp) > 0) {
                break;
            }
        }
        if (is_file($tmp) && filesize($tmp) > 0 && ($img = @imagecreatefromjpeg($tmp))) {
            $out += $this->saveThumb($m, $img);
        }
        @unlink($tmp);
        @unlink(substr($tmp, 0, -4));

        return $out;
    }

    // ---------------------------------------------------------------- helpers

    private function geoFromCache(float $lat, float $lng): array
    {
        $row = GeoCache::where('geo_key', Geocoder::key($lat, $lng))->first();
        if (! $row) {
            return ['geo_status' => 'pending'];
        }

        return ['geo_status' => 'done'] + $row->only(['city_en', 'city_fa', 'country_en', 'country_fa', 'country_code']);
    }

    private function importKeywords(Media $m, array $import): void
    {
        if ($import['description'] && ! $m->description) {
            $m->forceFill(['description' => mb_substr($import['description'], 0, 2000)])->save();
        }
        $names = collect($import['tags'])->map(fn ($t) => trim((string) $t))->filter(fn ($t) => $t !== '' && mb_strlen($t) <= 100)->unique()->take(30);
        if ($names->isEmpty() || $m->tags()->exists()) {
            return;
        }
        $ids = $names->map(fn ($n) => Tag::firstOrCreate(['name' => $n])->id);
        $m->tags()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [$id => ['created_at' => now()]])->all());
    }

    private function exifDate($v): ?string
    {
        if (! is_string($v) || ! preg_match('/^(\d{4})[:\-](\d{2})[:\-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', trim($v), $m)) {
            return null;
        }
        if ((int) $m[1] < 1900 || (int) $m[1] > 2100 || (int) $m[2] < 1 || (int) $m[2] > 12 || (int) $m[3] < 1 || (int) $m[3] > 31) {
            return null;
        }

        return "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
    }

    private function exifGps(array $gps): array
    {
        if (empty($gps['GPSLatitude']) || empty($gps['GPSLongitude'])) {
            return [];
        }
        $lat = $this->dms($gps['GPSLatitude']);
        $lng = $this->dms($gps['GPSLongitude']);
        if ($lat === null || $lng === null || ($lat == 0 && $lng == 0) || abs($lat) > 90 || abs($lng) > 180) {
            return [];
        }
        if (strtoupper((string) ($gps['GPSLatitudeRef'] ?? 'N')) === 'S') {
            $lat = -$lat;
        }
        if (strtoupper((string) ($gps['GPSLongitudeRef'] ?? 'E')) === 'W') {
            $lng = -$lng;
        }
        $alt = isset($gps['GPSAltitude']) ? $this->frac($gps['GPSAltitude']) : null;
        if ($alt !== null && (($gps['GPSAltitudeRef'] ?? "\0") === "\1" || ($gps['GPSAltitudeRef'] ?? 0) === 1)) {
            $alt = -$alt;
        }

        return ['gps_lat' => round($lat, 7), 'gps_lng' => round($lng, 7), 'gps_alt' => $alt !== null ? $this->limit($alt, 9_999_999) : null, 'geo_status' => 'pending'];
    }

    private function dms($parts): ?float
    {
        if (! is_array($parts) || count($parts) < 3) {
            return null;
        }
        $d = $this->frac($parts[0]);
        $m = $this->frac($parts[1]);
        $s = $this->frac($parts[2]);

        return $d === null || $m === null || $s === null ? null : $d + $m / 60 + $s / 3600;
    }

    private function frac($v): ?float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }
        if (is_string($v) && preg_match('#^(-?\d+(?:\.\d+)?)/(\d+(?:\.\d+)?)$#', trim($v), $m)) {
            return (float) $m[2] == 0 ? null : (float) $m[1] / (float) $m[2];
        }

        return null;
    }

    private function limit(?float $v, float $max): ?float
    {
        return $v === null || ! is_finite($v) || abs($v) > $max ? null : round($v, 2);
    }

    private function exposure($v): ?string
    {
        $f = $this->frac($v);
        if (! $f) {
            return null;
        }

        return $f >= 1 ? rtrim(rtrim(number_format($f, 1), '0'), '.').'s' : '1/'.round(1 / $f).'s';
    }

    private function str($v, int $max): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim(str_replace("\0", '', $this->toUtf8($v)));

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function toUtf8(string $v): string
    {
        return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
    }

    /** Windows "XP" tags are UTF-16LE (sometimes given as an array of byte values). */
    private function ucs2($v): string
    {
        if (is_array($v)) {
            $v = implode('', array_map('chr', $v));
        }
        if (! is_string($v) || $v === '') {
            return '';
        }

        return trim(str_replace("\0", '', @mb_convert_encoding($v, 'UTF-8', 'UTF-16LE') ?: ''));
    }

    /** A short, JSON-safe summary of the EXIF data for the info panel. */
    private function exifSummary(array $exif): array
    {
        $out = [];
        foreach (['IFD0', 'EXIF', 'GPS'] as $sec) {
            foreach ($exif[$sec] ?? [] as $k => $v) {
                if (str_starts_with($k, 'UndefinedTag') || in_array($k, ['MakerNote', 'UserComment', 'ComponentsConfiguration', 'FileSource', 'SceneType', 'CFAPattern'], true) || str_starts_with($k, 'XP')) {
                    continue;
                }
                if (is_array($v)) {
                    $v = implode(', ', array_filter($v, 'is_scalar'));
                }
                if (! is_scalar($v)) {
                    continue;
                }
                $v = (string) $v;
                if (! mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', $v) || strlen($v) > 200) {
                    continue;
                }
                $out[$k] = $v;
                if (count($out) >= 120) {
                    break 2;
                }
            }
        }

        return $out;
    }
}
