<?php

return [
    // Absolute path of the (read-only) storage box mount. Nothing is ever written there.
    'root' => rtrim(env('GALLERY_ROOT', storage_path('app/sample')), '/\\'),

    // When true, the root must be a mount point (different device than its parent).
    // This stops an empty, unmounted directory from being read as "all files were deleted".
    'require_mount' => (bool) env('GALLERY_REQUIRE_MOUNT', false),

    // Secret for signed media URLs. Change it to make every old media URL invalid.
    'url_key' => env('GALLERY_URL_KEY', env('APP_KEY')),

    // How long a directory listing is trusted before we read the directory again (minutes).
    // Reading a listing reads only names/sizes/dates (no file content), so this is cheap.
    'listing_ttl' => (int) env('GALLERY_LISTING_TTL', 20),

    // Thumbnails: longest side in px and WebP quality.
    'thumb_size' => (int) env('GALLERY_THUMB_SIZE', 480),
    'thumb_quality' => (int) env('GALLERY_THUMB_QUALITY', 72),
    'thumb_dir' => storage_path('app/thumbs'),
    // How many files may be read from the storage box at the same time (thumbnails + scan job).
    'read_slots' => (int) env('GALLERY_READ_SLOTS', 2),
    // Images with more pixels than this get no thumbnail (GD memory limit).
    'max_pixels' => (int) env('GALLERY_MAX_PIXELS', 100_000_000),

    'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),

    // Reverse geocoding (OpenStreetMap Nominatim, max 1 request per second).
    'geocode_url' => env('GEOCODE_URL', 'https://nominatim.openstreetmap.org/reverse'),
    'geocode_agent' => env('GEOCODE_AGENT', 'PersonalGallery/1.0 (self-hosted photo album)'),

    'image_ext' => ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp', 'avif', 'heic', 'heif', 'tif', 'tiff'],
    // Images that GD can decode (others get no thumbnail).
    'thumbable_ext' => ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp', 'avif'],
    'video_ext' => ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', '3gp', 'mpg', 'mpeg', 'wmv', 'flv', 'mts', 'm2ts', 'ogv'],
    // Videos that browsers can usually play directly.
    'playable_ext' => ['mp4', 'm4v', 'webm', 'mov', 'ogv'],
];
