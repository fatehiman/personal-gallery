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
        if (($del = $access->deletedPath($vpath)) !== null) {
            return $this->deleted($request, $access, $vpath, $del);
        }
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
                $custom = $dir ? Directory::customCovers([$dir->id])->first() : null;
                $cover = Media::where('has_thumb', true)
                    ->where(fn ($w) => $f->path === '' ? $w : $w->where('path', 'like', Paths::like($f->path).'/%'))
                    ->orderBy('directory_id')->first();
                $out['folders'][] = [
                    'name' => $f->title,
                    'path' => (string) $f->id,
                    'mtime' => Presenter::iso($dir?->mtime),
                    'count' => $dir?->listed_at ? $dir->dir_count + $dir->file_count : null,
                    'cover' => ($custom ?? $cover) ? Signer::thumb($custom ?? $cover) : null,
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
        Directory::customCovers($subdirs->pluck('id'))->each(fn ($m, $dirId) => $covers->put($dirId, $m));
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
        if ($access->isAdmin() && $vpath === '') {
            // The virtual folder "Deleted items" (not a real folder).
            $out['folders'][] = [
                'name' => __('ui.deleted_items'), 'path' => Access::DELETED, 'mtime' => null, 'special' => 'deleted',
                'count' => Media::withoutGlobalScopes()->whereNotNull('hidden_at')->count(), 'cover' => null, 'otd' => false,
            ];
        }

        return response()->json($out);
    }

    /** "Deleted items" (admin): hidden files, shown in their real folder structure. Database only. */
    private function deleted(Request $request, Access $access, string $vpath, string $real)
    {
        $crumbs = $access->crumbs($vpath);
        $crumbs[0]['name'] = __('ui.deleted_items');
        $out = [
            'path' => $vpath, 'crumbs' => $crumbs, 'parent' => Paths::parent($vpath) ?? '',
            'folders' => [], 'files' => [], 'deleted' => true, 'scan' => ScanController::summary(ScanJob::active()),
        ];
        $q = Media::withoutGlobalScopes()->whereNotNull('hidden_at');
        if ($real !== '') {
            $q->where('path', 'like', Paths::like($real).'/%');
        }
        $cut = $real === '' ? 0 : strlen($real) + 1;
        $dirs = [];
        foreach ((clone $q)->pluck('path') as $p) {
            $rest = substr($p, $cut);
            if (($pos = strpos($rest, '/')) !== false) {
                $seg = substr($rest, 0, $pos);
                $dirs[$seg] = ($dirs[$seg] ?? 0) + 1;
            }
        }
        ksort($dirs);
        foreach ($dirs as $name => $n) {
            $out['folders'][] = [
                'name' => (string) $name, 'path' => $vpath.'/'.$name, 'mtime' => null, 'count' => $n, 'cover' => null, 'otd' => false,
            ];
        }
        $dir = Directory::findByPath($real);
        if ($dir) {
            $media = (clone $q)->where('directory_id', $dir->id)->with(['tags', 'persons'])->get(Media::LIST_COLUMNS);
            $favs = Presenter::favMap($access, $media->pluck('id'));
            foreach ($media as $m) {
                $out['files'][] = Presenter::media($m, $access, $favs);
            }
        }

        return response()->json($out);
    }
}
