<?php

namespace App\Gallery;

use App\Models\Directory;
use App\Models\Media;
use App\Models\ScanJob;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The slow background walk: every few minutes it works for a few seconds (about 20), then stops.
 * It goes through all folders in a fixed order (depth first, by name) and remembers where it stopped.
 * In each folder it first checks the folder date (one stat call). Only when the date changed (or the folder
 * was never listed) it reads the listing again, and it reads (scans) the files that were never read.
 * A file with the same path and size is never read again, whatever its date says (see Indexer).
 * At the end of the tree it starts again from the beginning. So after the first rounds it only finds new files.
 * Every run writes one line to crawl_logs (kept 10 days).
 */
class Crawler
{
    public const POS = 'crawl_pos';

    public const LOG_DAYS = 10;

    /** @var array{folders:int, listed:int, scanned:int, errors:int} */
    private array $stats;

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
        if (! self::enabled()) {
            return;
        }
        $t0 = microtime(true);
        $from = (string) Settings::get(self::POS, '');
        $filesBefore = Media::withoutGlobalScopes()->count();
        $this->stats = ['folders' => 0, 'listed' => 0, 'scanned' => 0, 'errors' => 0];
        $note = null;
        try {
            $note = $this->walk($budget ?? self::seconds());
        } catch (Throwable $e) {
            report($e);
            $note = 'error: '.mb_substr($e->getMessage(), 0, 120);
            $this->stats['errors']++;
        }
        $to = (string) Settings::get(self::POS, '');
        DB::table('crawl_logs')->insert([
            'started_at' => now()->subSeconds((int) round(microtime(true) - $t0)),
            'seconds' => round(microtime(true) - $t0, 1),
            'from_path' => $from, 'to_path' => $to,
            'folders' => $this->stats['folders'], 'listed' => $this->stats['listed'],
            'new_files' => max(0, Media::withoutGlobalScopes()->count() - $filesBefore),
            'scanned' => $this->stats['scanned'], 'errors' => $this->stats['errors'],
            'note' => $note,
        ]);
        DB::table('crawl_logs')->where('started_at', '<', now()->subDays(self::LOG_DAYS))->delete();
    }

    /** @return string why it stopped */
    private function walk(int $budget): string
    {
        if (ScanJob::active()) {
            return 'paused: admin scan is running'; // it does the same work, do not read twice
        }
        if (! Health::ok(true)) {
            return 'storage not available';
        }
        $end = time() + $budget;
        $slots = new ReadSlots;
        $pos = (string) Settings::get(self::POS, '');

        try {
            while (time() < $end) {
                $dir = $this->open($pos);
                if (! $dir) {
                    if ($pos === '') {
                        return 'root folder not readable';
                    }
                    $pos = ''; // the folder was removed: start from the beginning
                    Settings::set(self::POS, $pos);

                    continue;
                }
                $this->stats['folders']++;
                $pending = Media::where('directory_id', $dir->id)->whereNull('scanned_at')->whereIn('type', ['image', 'video']);
                while (time() < $end && ($m = (clone $pending)->orderBy('filename')->first())) {
                    if (! $slots->acquire(20)) {
                        return 'read slots busy';
                    }
                    try {
                        $ok = $this->scanner->scan($m);
                    } finally {
                        $slots->release();
                    }
                    if ($m->scanned_at === null) {
                        return 'storage not available'; // same folder again next time
                    }
                    $this->stats['scanned']++;
                    $ok || $this->stats['errors']++;
                }
                if ((clone $pending)->exists()) {
                    return 'time over, same folder next time';
                }
                $next = $this->next($dir);
                if ($next === null) {
                    Settings::set('crawl_cycles', Settings::int('crawl_cycles', 0) + 1);
                    Settings::set('crawl_cycle_at', now()->toIso8601String());
                    Settings::set(self::POS, '');

                    return 'round finished, starts again from the first folder';
                }
                $pos = $next;
                Settings::set(self::POS, $pos);
            }

            return 'time over';
        } catch (StorageUnavailableException) {
            return 'storage not available';
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
            $this->stats['listed']++;

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
