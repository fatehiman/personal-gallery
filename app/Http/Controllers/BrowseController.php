<?php

namespace App\Http\Controllers;

use App\Gallery\Access;
use App\Gallery\Indexer;
use App\Gallery\OtdFolders;
use App\Gallery\Paths;
use App\Gallery\Presenter;
use App\Gallery\Signer;
use App\Http\Controllers\Admin\ScanController;
use App\Models\Directory;
use App\Models\Media;
use App\Models\ScanJob;
use Illuminate\Http\Request;

class BrowseController extends Controller
{
    public function page(Request $request, ?string $path = null)
    {
        return view('gallery', ['mode' => $request->route()->defaults['mode'] ?? 'browse']);
    }

    public function map()
    {
        return view('map');
    }

    public function list(Request $request, Indexer $indexer)
    {
        $access = Access::for($request->user());
        $vpath = Paths::normalize((string) $request->query('path', ''));
        $r = $access->resolve($vpath);

        $out = [
            'path' => $vpath,
            'crumbs' => $access->crumbs($vpath),
            'parent' => $vpath === '' ? null : (Paths::parent($vpath) ?? ''),
            'folders' => [],
            'files' => [],
        ];
        if ($access->isAdmin()) {
            $out['scan'] = ScanController::summary(ScanJob::active());
        }

        $otd = OtdFolders::for($request->user());
        if ($r['virtualRoot']) {
            foreach ($access->folders() as $f) {
                $dir = Directory::findByPath($f->path);
                $cover = Media::where('has_thumb', true)
                    ->where(fn ($w) => $f->path === '' ? $w : $w->where('path', 'like', Paths::like($f->path).'/%'))
                    ->orderBy('directory_id')->first();
                $out['folders'][] = [
                    'name' => $f->title,
                    'path' => (string) $f->id,
                    'mtime' => Presenter::iso($dir?->mtime),
                    'count' => $dir?->listed_at ? $dir->dir_count + $dir->file_count : null,
                    'cover' => $cover ? Signer::thumb($cover) : null,
                    'otd' => $otd->effective($f->path),
                ];
            }

            return response()->json($out);
        }

        // "Refresh" only reads the directory entries again (no file content), so every user may use it.
        // A folder is read again at most once per 30 seconds, whoever asks.
        $known = $request->boolean('refresh') ? Directory::findByPath($r['real']) : null;
        $force = $request->boolean('refresh') && ! ($known?->listed_at && $known->listed_at->gt(now()->subSeconds(30)));
        $dir = $indexer->sync($r['real'], $force);
        $subdirs = Directory::withoutEmpty(Directory::where('parent_id', $dir->id)->get());
        $covers = $subdirs->isEmpty() ? collect() : Media::whereIn('id', Media::query()
            ->selectRaw('min(id)')->whereIn('directory_id', $subdirs->pluck('id'))->where('has_thumb', true)->groupBy('directory_id'))
            ->get(['id', 'directory_id', 'thumb_v'])->keyBy('directory_id');
        foreach ($subdirs as $sd) {
            $out['folders'][] = [
                'name' => $sd->name,
                'path' => $vpath === '' ? $sd->name : $vpath.'/'.$sd->name,
                'mtime' => Presenter::iso($sd->mtime),
                'count' => $sd->listed_at ? $sd->dir_count + $sd->file_count : null,
                'cover' => isset($covers[$sd->id]) ? Signer::thumb($covers[$sd->id]) : null,
                'otd' => $otd->effective($sd->path),
            ];
        }
        $media = Media::where('directory_id', $dir->id)->with(['tags', 'persons'])->get(Media::LIST_COLUMNS);
        $favs = Presenter::favMap($access, $media->pluck('id'));
        foreach ($media as $m) {
            $out['files'][] = Presenter::media($m, $access, $favs);
        }
        $out['listedAt'] = Presenter::iso($dir->listed_at);

        return response()->json($out);
    }
}
