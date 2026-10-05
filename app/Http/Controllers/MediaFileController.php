<?php

namespace App\Http\Controllers;

use App\Gallery\Paths;
use App\Gallery\ReadSlots;
use App\Gallery\Scanner;
use App\Gallery\Signer;
use App\Gallery\StorageUnavailableException;
use App\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves thumbnails and original files by signed URL. No session is used here.
 * A thumbnail request for a file that was never read also scans that file (metadata + thumbnail).
 */
class MediaFileController extends Controller
{
    private const YEAR = 'public, max-age=31536000, immutable';

    private const MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif', 'heic' => 'image/heic', 'heif' => 'image/heif',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime',
        'webm' => 'video/webm', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo', '3gp' => 'video/3gpp',
        'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg', 'wmv' => 'video/x-ms-wmv', 'flv' => 'video/x-flv', 'ogv' => 'video/ogg',
        'mts' => 'video/mp2t', 'm2ts' => 'video/mp2t',
    ];

    private function fail(int $status, int $retry = 0): Response
    {
        $r = response('', $status)->header('Cache-Control', 'no-store');
        if ($retry) {
            $r->header('Retry-After', (string) $retry);
        }

        return $r;
    }

    public function thumb(int $id, int $v, string $sig, Scanner $scanner)
    {
        if (! Signer::check('t', $id, $v, $sig)) {
            return $this->fail(404);
        }
        $m = Media::find($id);
        if (! $m || $m->thumb_v !== $v) {
            return $this->fail(404);
        }
        if (! $m->has_thumb || ! is_file($m->thumbFile())) {
            if ($m->scanned_at !== null && ! $m->has_thumb) {
                return $this->fail(404); // already read once, no thumbnail possible
            }
            if (! $m->isThumbable()) {
                return $this->fail(404);
            }
            $slots = new ReadSlots;
            if (! $slots->acquire(15)) {
                return $this->fail(503, 3);
            }
            try {
                $m->refresh();
                if (! $m->has_thumb || ! is_file($m->thumbFile())) {
                    if ($m->has_thumb) {
                        $m->forceFill(['scanned_at' => null])->save(); // thumb file lost: read again
                    }
                    $scanner->scan($m);
                }
            } finally {
                $slots->release();
            }
            if ($m->scanned_at === null) {
                return $this->fail(503, 10);
            }
            if (! $m->has_thumb) {
                return $this->fail(404);
            }
        }

        return response()->file($m->thumbFile(), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => self::YEAR,
        ]);
    }

    public function original(string $mode, int $id, int $v, string $sig, string $ext)
    {
        if (! Signer::check('o', $id, $v, $sig)) {
            return $this->fail(404);
        }
        $m = Media::find($id);
        if (! $m || $m->thumb_v !== $v || Signer::ext($m) !== $ext) {
            return $this->fail(404);
        }
        try {
            Paths::assertAvailable();
        } catch (StorageUnavailableException) {
            return $this->fail(503, 30);
        }
        $abs = Paths::abs($m->path);
        if (! is_file($abs) || is_link($abs)) {
            return $this->fail(404);
        }
        @set_time_limit(0);
        session_write_close();

        $r = new BinaryFileResponse($abs, 200, [
            'Content-Type' => self::MIME[$m->ext] ?? 'application/octet-stream',
            'Cache-Control' => self::YEAR,
        ], true, null, false, false);
        $r->setAutoEtag(false);
        $r->setLastModified($m->file_mtime);
        $fallback = preg_replace('/[^\x20-\x7e]|[%\/\\\\"]/', '_', $m->filename);
        $r->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $mode === 'd' ? 'attachment' : 'inline', $m->filename, $fallback
        ));

        return $r;
    }
}
