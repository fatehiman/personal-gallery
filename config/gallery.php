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

    // Videos are copied to this (local, temporary) folder before playing; the storage box is too slow to stream from.
    'video_cache_dir' => storage_path('app/vcache'),
    // Keep at least this much free disk space (MB) after the copy and the conversion. Not enough space: nothing is
    // copied or converted.
    'video_reserve_mb' => (int) env('GALLERY_VIDEO_RESERVE_MB', 3072),
    // A copy that no open viewer shows and that nobody used for this many minutes is deleted (closed browser tabs).
    'video_cache_minutes' => (int) env('GALLERY_VIDEO_CACHE_MINUTES', 2),
    // When the last viewer leaves a video, its files are deleted after this many seconds.
    'video_drop_seconds' => (int) env('GALLERY_VIDEO_DROP_SECONDS', 10),
    // How many videos may be copied at the same time.
    'video_copy_max' => (int) env('GALLERY_VIDEO_COPY_MAX', 2),
    // Convert formats that browsers cannot play to MP4 (H.264/AAC) after the copy. Needs ffmpeg with libx264.
    'video_convert' => (bool) env('GALLERY_VIDEO_CONVERT', true),
    // How many conversions may run at the same time (they use a lot of CPU), and ffmpeg threads for each.
    'video_convert_max' => (int) env('GALLERY_VIDEO_CONVERT_MAX', 1),
    'video_convert_threads' => (int) env('GALLERY_VIDEO_CONVERT_THREADS', 2),
    // Encoded videos get at most this many pixels on the longer side (1280 = 720p; smaller is faster).
    'video_convert_max_side' => (int) env('GALLERY_VIDEO_CONVERT_MAX_SIDE', 1280),
    // Start the copy as a background process (false in tests).
    'video_spawn' => (bool) env('GALLERY_VIDEO_SPAWN', true),
    // PHP command line binary for the copy process (the web server runs php-fpm, so PHP_BINARY is not usable).
    'php_bin' => env('GALLERY_PHP_BIN', 'php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION),

    'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),

    // Reverse geocoding (OpenStreetMap Nominatim, max 1 request per second).
    'geocode_url' => env('GEOCODE_URL', 'https://nominatim.openstreetmap.org/reverse'),
    'geocode_agent' => env('GEOCODE_AGENT', 'PersonalGallery/1.0 (self-hosted photo album)'),

    'image_ext' => ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp', 'avif', 'heic', 'heif', 'tif', 'tiff'],
    // Images that GD can decode (others get no thumbnail).
    'thumbable_ext' => ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp', 'avif'],
    'video_ext' => ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', '3gp', '3g2', 'mpg', 'mpeg', 'wmv', 'flv', 'mts', 'm2ts', 'ogv', 'ogg',
        'vob', 'mod', 'tod', 'divx', 'asf', 'f4v', 'rm', 'rmvb', 'dv'],
    // Containers that browsers can play directly (other videos are converted to MP4 after the copy).
    'playable_ext' => ['mp4', 'm4v', 'webm', 'mov'],
    // Video codecs that browsers can play (other codecs are converted). HEVC plays in most browsers today;
    // when it does not, the viewer asks for a conversion.
    'browser_codecs' => ['h264', 'hevc', 'vp8', 'vp9', 'av1'],
];
