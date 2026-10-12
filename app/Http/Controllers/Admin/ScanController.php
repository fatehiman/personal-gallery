<?php

namespace App\Http\Controllers\Admin;

use App\Gallery\Paths;
use App\Http\Controllers\Controller;
use App\Models\Directory;
use App\Models\Media;
use App\Models\ScanJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScanController extends Controller
{
    public static function summary(?ScanJob $job): ?array
    {
        if (! $job) {
            return null;
        }

        return [
            'id' => $job->id,
            'path' => $job->path,
            'status' => $job->status,
            'recursive' => $job->recursive,
            'total' => $job->total,
            'processed' => $job->processed,
            'errors' => $job->errors,
            'current' => $job->current_file,
            'phase' => $job->state['phase'] ?? null,
            'message' => $job->message,
            'stopping' => $job->stop_requested,
        ];
    }

    public function index()
    {
        return view('admin.scans', [
            'jobs' => ScanJob::with('user')->latest('id')->limit(50)->get(),
            'logs' => DB::table('crawl_logs')->orderByDesc('id')->limit(300)->get(),
        ]);
    }

    /**
     * Statistics of everything the gallery knows (database only, the storage box is never touched).
     * The count reads the whole media table, so the page does not load it: the admin asks for it with a button.
     * One pass, no WHERE: the hidden flag is a group column, so MySQL reads the table in order (about 40% faster
     * than going through the hidden_at index).
     */
    public function stats()
    {
        $t0 = microtime(true);
        $rows = Media::withoutGlobalScopes()->selectRaw(
            'type, ext, (hidden_at IS NOT NULL) AS hid, COUNT(*) AS n, SUM(size) AS bytes, SUM(scanned_at IS NOT NULL) AS scanned, '
            .'SUM(scan_error IS NOT NULL) AS errors, SUM(has_thumb) AS thumbs, SUM(gps_lat IS NOT NULL) AS gps'
        )->groupByRaw('type, ext, hid')->get();

        $hidden = (int) $rows->where('hid', 1)->sum('n');
        $visible = $rows->where('hid', 0);
        $sum = fn ($c, string $f) => (int) $c->sum($f);

        $types = [];
        foreach (['image', 'video', 'other'] as $t) {
            $r = $visible->where('type', $t);
            $types[] = ['type' => $t, 'count' => $sum($r, 'n'), 'size' => $sum($r, 'bytes')];
        }
        $exts = $visible->groupBy('ext')->map(fn ($r, $ext) => ['ext' => (string) $ext, 'count' => $sum($r, 'n'), 'size' => $sum($r, 'bytes')])
            ->sortByDesc('count')->values();
        // Only photos and videos are ever read; "other" files (music, documents ...) just stay in the list.
        $readable = $visible->whereIn('type', ['image', 'video']);
        $read = $sum($readable, 'scanned');
        $dirs = Directory::query()->selectRaw('COUNT(*) AS total, SUM(listed_at IS NOT NULL) AS listed')->first();

        return response()->json([
            'generated_at' => now()->utc()->toIso8601ZuluString(),
            'ms' => (int) round((microtime(true) - $t0) * 1000),
            'folders' => ['total' => (int) $dirs->total, 'listed' => (int) $dirs->listed],
            'files' => ['total' => $sum($visible, 'n'), 'size' => $sum($visible, 'bytes'), 'hidden' => $hidden,
                'thumbs' => $sum($visible, 'thumbs'), 'gps' => $sum($visible, 'gps')],
            'types' => $types,
            'exts' => $exts->take(40)->all(),
            'read' => ['read' => $read - $sum($readable, 'errors'), 'pending' => $sum($readable, 'n') - $read, 'errors' => $sum($readable, 'errors')],
        ]);
    }

    public function status()
    {
        return response()->json([
            'active' => self::summary(ScanJob::active()),
            'last' => self::summary(ScanJob::whereNotIn('status', ['queued', 'running'])->latest('id')->first()),
        ]);
    }

    public function start(Request $request)
    {
        $data = $request->validate(['path' => ['nullable', 'string', 'max:2000'], 'recursive' => ['nullable', 'boolean']]);
        $path = Paths::normalize($data['path'] ?? '');
        Paths::assertAvailable();
        abort_unless(is_dir(Paths::abs($path)), 404, __('ui.folder_not_found'));

        // Only one job at a time; nothing is queued behind a running job.
        $job = DB::transaction(function () use ($request, $path) {
            if (ScanJob::whereIn('status', ['queued', 'running'])->lockForUpdate()->exists()) {
                return null;
            }

            return ScanJob::create([
                'user_id' => $request->user()->id, 'path' => $path, 'recursive' => $request->boolean('recursive', true),
                'status' => 'queued', 'state' => ['phase' => 'walk', 'dirs' => [$path]],
            ]);
        });
        if (! $job) {
            return response()->json(['message' => __('ui.scan_busy'), 'active' => self::summary(ScanJob::active())], 409);
        }

        return response()->json(['active' => self::summary($job)]);
    }

    public function stop()
    {
        $job = ScanJob::active();
        if ($job) {
            $job->status === 'queued'
                ? $job->update(['status' => 'stopped', 'finished_at' => now()])
                : $job->update(['stop_requested' => true]);
        }

        return response()->json(['active' => self::summary(ScanJob::active())]);
    }
}
