<?php

namespace App\Http\Controllers\Admin;

use App\Gallery\Paths;
use App\Http\Controllers\Controller;
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
        return view('admin.scans', ['jobs' => ScanJob::with('user')->latest('id')->limit(50)->get()]);
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
