<?php

namespace App\Gallery;

use App\Models\Directory;
use App\Models\Media;
use App\Models\ScanJob;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Runs the (only) active scan job for a limited time, then stops. The scheduler starts it again every minute,
 * so a long job continues where it stopped. Phase 1 "walk" lists the directories, phase 2 "scan" reads files one by one.
 */
class ScanWorker
{
    public function __construct(private Indexer $indexer, private Scanner $scanner) {}

    public static function mediaInScope(ScanJob $job): Builder
    {
        $q = Media::query()->whereIn('type', ['image', 'video']);
        if ($job->recursive) {
            if ($job->path !== '') {
                $q->where(fn ($w) => $w->where('path', 'like', Paths::like($job->path).'/%'));
            }
        } else {
            $dir = Directory::findByPath($job->path);
            $q->where('directory_id', $dir?->id ?? 0);
        }

        return $q;
    }

    public function run(int $budget = 55): void
    {
        $end = time() + $budget;
        $job = ScanJob::active();
        if (! $job) {
            return;
        }
        if ($job->status === 'queued') {
            $job->update(['status' => 'running', 'started_at' => now(), 'state' => ['phase' => 'walk', 'dirs' => [$job->path]]]);
        }
        $slots = new ReadSlots;

        try {
            while (time() < $end) {
                $job->refresh();
                if ($job->stop_requested) {
                    $job->update(['status' => 'stopped', 'finished_at' => now(), 'current_file' => null]);

                    return;
                }
                $state = $job->state ?? ['phase' => 'walk', 'dirs' => [$job->path]];

                if ($state['phase'] === 'walk') {
                    $dir = array_shift($state['dirs']);
                    if ($dir === null) {
                        $state['phase'] = 'scan';
                        $total = self::mediaInScope($job)->count();
                        $done = self::mediaInScope($job)->whereNotNull('scanned_at')->count();
                        $job->update(['state' => $state, 'total' => $total, 'processed' => $done, 'current_file' => null]);

                        continue;
                    }
                    try {
                        $row = $this->indexer->sync($dir, true);
                    } catch (NotFoundHttpException) {
                        $job->update(['state' => $state]);

                        continue; // directory was removed meanwhile
                    }
                    if ($job->recursive) {
                        foreach (Directory::where('parent_id', $row->id)->orderBy('name')->pluck('path') as $child) {
                            $state['dirs'][] = $child;
                        }
                    }
                    $job->update(['state' => $state, 'current_file' => $dir === '' ? '/' : $dir]);

                    continue;
                }

                $m = self::mediaInScope($job)->whereNull('scanned_at')->orderBy('directory_id')->orderBy('filename')->first();
                if (! $m) {
                    $job->update(['status' => 'done', 'finished_at' => now(), 'current_file' => null,
                        'processed' => self::mediaInScope($job)->whereNotNull('scanned_at')->count()]);

                    return;
                }
                $job->update(['current_file' => $m->path]);
                if (! $slots->acquire(20)) {
                    continue;
                }
                $ok = $this->scanner->scan($m);
                $slots->release();
                if ($m->scanned_at === null) {
                    // Storage not available: stop for now, try again next minute.
                    $job->update(['message' => 'Storage not available, retrying']);

                    return;
                }
                $job->increment('processed');
                if (! $ok) {
                    $job->increment('errors');
                }
            }
        } catch (Throwable $e) {
            report($e);
            $job->update(['message' => mb_substr($e->getMessage(), 0, 450)]);
            if ($e instanceof StorageUnavailableException) {
                return; // keep the job, try again next minute
            }
            $job->update(['status' => 'failed', 'finished_at' => now()]);
        } finally {
            $slots->release();
        }
    }
}
