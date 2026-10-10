<?php

namespace App\Gallery;

use App\Models\Directory;
use App\Models\Media;
use App\Models\ScanJob;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The slow background walk: every few minutes it works for a few seconds (about 20), then stops.
 * It goes through all folders in a fixed order (depth first, by name) and remembers where it stopped.
 * In each folder it first checks the folder date (one stat call). Only when the date changed (or the folder
 * was never listed) it reads the listing again, and it reads (scans) the files that were never read.
 * At the end of the tree it starts again from the beginning. So after the first rounds it only finds new files.
 */
class Crawler
{
    public const POS = 'crawl_pos';

    public function __construct(private Indexer $indexer, private Scanner $scanner) {}

    public static function enabled(): bool
    {
        return Settings::bool('crawl_enabled', true);
    }

    public static function seconds(): int
    {
        return max(5, min(50, Settings::int('crawl_seconds', 20)));
    }

    public function run(?int $budget = null): void
    {
        if (! self::enabled() || ScanJob::active() || ! Health::ok(true)) {
            return; // an admin scan job is running: it does the same work, do not read twice
        }
        $end = time() + ($budget ?? self::seconds());
        $slots = new ReadSlots;
        $pos = (string) Settings::get(self::POS, '');

        try {
            while (time() < $end) {
                $dir = $this->open($pos);
                if (! $dir) {
                    if ($pos === '') {
                        return; // root is not readable
                    }
                    $pos = ''; // the folder was removed: start from the beginning
                    Settings::set(self::POS, $pos);

                    continue;
                }
                $pending = Media::where('directory_id', $dir->id)->whereNull('scanned_at')->whereIn('type', ['image', 'video']);
                while (time() < $end && ($m = (clone $pending)->orderBy('filename')->first())) {
                    if (! $slots->acquire(20)) {
                        return;
                    }
                    try {
                        $this->scanner->scan($m);
                    } finally {
                        $slots->release();
                    }
                    if ($m->scanned_at === null) {
                        return; // storage not available: same folder again next time
                    }
                }
                if ((clone $pending)->exists()) {
                    return; // time is over, continue in this folder next time
                }
                $next = $this->next($dir);
                if ($next === null) {
                    Settings::set('crawl_cycles', Settings::int('crawl_cycles', 0) + 1);
                    Settings::set('crawl_cycle_at', now()->toIso8601String());
                    Settings::set(self::POS, '');

                    return; // one round finished; the next run starts a new round
                }
                $pos = $next;
                Settings::set(self::POS, $pos);
            }
        } catch (StorageUnavailableException) {
            return;
        } catch (Throwable $e) {
            report($e);
        } finally {
            $slots->release();
        }
    }

    /** The folder row, with a fresh listing when its date changed. Null when the folder is gone. */
    private function open(string $path): ?Directory
    {
        try {
            Paths::assertAvailable();
            $dir = Directory::findByPath($path);
            $st = @stat(Paths::abs($path));
            if ($dir && $st && $dir->listed_at && $dir->mtime && $dir->mtime->timestamp === $st['mtime']) {
                return $dir; // nothing was added or removed here
            }

            return $this->indexer->sync($path, true);
        } catch (NotFoundHttpException) {
            return null;
        }
    }

    /** Next folder in depth-first order (children first, then the next sibling, then the parent's next sibling). */
    private function next(Directory $dir): ?string
    {
        $child = Directory::where('parent_id', $dir->id)->orderBy('name')->orderBy('id')->first();
        if ($child) {
            return $child->path;
        }
        for ($cur = $dir; $cur && $cur->parent_id; $cur = Directory::find($cur->parent_id)) {
            $sibling = Directory::where('parent_id', $cur->parent_id)
                ->where(fn ($w) => $w->where('name', '>', $cur->name)->orWhere(fn ($x) => $x->where('name', $cur->name)->where('id', '>', $cur->id)))
                ->orderBy('name')->orderBy('id')->first();
            if ($sibling) {
                return $sibling->path;
            }
        }

        return null;
    }
}
