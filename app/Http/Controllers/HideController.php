<?php

namespace App\Http\Controllers;

use App\Gallery\Access;
use App\Gallery\Indexer;
use App\Gallery\Paths;
use App\Models\Directory;
use App\Models\Media;
use Illuminate\Http\Request;

/**
 * "Delete" for users. For the user it is a delete; in fact the file is only hidden (media.hidden_at) for everybody.
 * The file on the storage box is never touched. Later the main admin may delete hidden files for real.
 */
class HideController extends Controller
{
    private const MAX_DIRS = 300;

    public function hide(Request $request, Indexer $indexer)
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:1000'], 'ids.*' => ['integer'],
            'paths' => ['nullable', 'array', 'max:50'], 'paths.*' => ['string', 'max:2000'],
        ]);
        abort_if(empty($data['ids']) && empty($data['paths']), 422);
        $access = Access::for($request->user());
        $by = $request->user()->id;
        $stamp = ['hidden_at' => now(), 'hidden_by' => $by];
        $count = 0;

        // Check every folder first, so a bad one changes nothing.
        $roots = [];
        foreach ($data['paths'] ?? [] as $p) {
            $r = $access->resolve($p);
            abort_if($r['virtualRoot'] || $r['real'] === null || $r['real'] === '', 422);
            $roots[] = $r['real'];
        }
        foreach ($roots as $real) {
            $this->listTree($indexer, $real);
        }

        $ids = Media::whereIn('id', $data['ids'] ?? [])->get(['id', 'path'])
            ->filter(fn ($m) => $access->canAccessMedia($m))->pluck('id');
        if ($ids->isNotEmpty()) {
            $count += Media::whereIn('id', $ids)->update($stamp);
        }
        foreach ($roots as $real) {
            $count += Media::where('path', 'like', Paths::like($real).'/%')->update($stamp);
        }

        return response()->json(['hidden' => $count]);
    }

    /** Admin: get deleted files back (by id, or a whole folder of "Deleted items" by path). */
    public function restore(Request $request)
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:1000'], 'ids.*' => ['integer'],
            'paths' => ['nullable', 'array', 'max:50'], 'paths.*' => ['string', 'max:2000'],
        ]);
        abort_if(empty($data['ids']) && empty($data['paths']), 422);
        $access = Access::for($request->user());
        $back = ['hidden_at' => null, 'hidden_by' => null];
        $count = 0;
        $hidden = fn () => Media::withoutGlobalScopes()->whereNotNull('hidden_at');
        if (! empty($data['ids'])) {
            $count += $hidden()->whereIn('id', $data['ids'])->update($back);
        }
        foreach ($data['paths'] ?? [] as $p) {
            $real = $access->deletedPath(Paths::normalize($p));
            abort_if($real === null || $real === '', 422);
            $count += $hidden()->where('path', 'like', Paths::like($real).'/%')->update($back);
        }

        return response()->json(['restored' => $count]);
    }

    /** Make sure every folder below $real was listed once, so that no unknown file is left behind. */
    private function listTree(Indexer $indexer, string $real): void
    {
        $indexer->sync($real);
        for ($n = 0; $n < self::MAX_DIRS; $n++) {
            $dir = Directory::where(fn ($w) => $w->where('path', 'like', Paths::like($real).'/%'))->whereNull('listed_at')->first();
            if (! $dir) {
                return;
            }
            $indexer->sync($dir->path);
        }
        abort(422, __('ui.delete_too_big'));
    }
}
