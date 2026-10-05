<?php

namespace App\Http\Controllers;

use App\Gallery\Access;
use App\Gallery\Paths;
use App\Gallery\Presenter;
use App\Gallery\Signer;
use App\Models\Directory;
use App\Models\Media;
use App\Models\Person;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Search works only on the database (files that were listed or scanned). It never reads the storage box. */
class SearchController extends Controller
{
    private const PAGE = 200;

    private function files(Builder $q, Access $access, Request $request): array
    {
        $page = max(0, (int) $request->query('page', 0));
        $rows = $q->with(['tags', 'persons'])->offset($page * self::PAGE)->limit(self::PAGE + 1)->get();
        $more = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);
        $favs = Presenter::favMap($access, $rows->pluck('id'));

        return [
            'files' => $rows->map(fn ($m) => Presenter::media($m, $access, $favs, true))->values()->all(),
            'more' => $more,
            'page' => $page,
        ];
    }

    private function sort(Builder $q, ?string $sort): Builder
    {
        $date = 'COALESCE(media.taken_at, media.file_mtime)';

        return match ($sort) {
            'name' => $q->orderBy('media.filename'),
            'name_desc' => $q->orderByDesc('media.filename'),
            'date' => $q->orderByRaw("$date asc"),
            'size' => $q->orderBy('media.size'),
            'size_desc' => $q->orderByDesc('media.size'),
            default => $q->orderByRaw("$date desc"),
        };
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'type' => ['nullable', 'in:image,video,other'],
            'in' => ['nullable', 'string', 'max:2000'],
            'tag' => ['nullable', 'string', 'max:100'],
            'person' => ['nullable', 'string', 'max:150'],
            'camera' => ['nullable', 'string', 'max:150'],
            'place' => ['nullable', 'string', 'max:150'],
            'fav' => ['nullable', 'boolean'],
            'gps' => ['nullable', 'boolean'],
            'described' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'max:20'],
        ]);
        $access = Access::for($request->user());
        $q = Media::query()->select(array_map(fn ($c) => 'media.'.$c, Media::LIST_COLUMNS));
        $access->scope($q, 'media.path');
        $dirs = Directory::query();
        $access->scope($dirs, 'path');

        if (! empty($data['in'])) {
            $r = $access->resolve($data['in']);
            if (! $r['virtualRoot'] && $r['real'] !== '') {
                $like = Paths::like($r['real']).'/%';
                $q->where('media.path', 'like', $like);
                $dirs->where('path', 'like', $like);
            }
        }
        $terms = preg_split('/\s+/u', trim((string) ($data['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        foreach (array_slice($terms, 0, 6) as $t) {
            $like = '%'.Paths::like($t).'%';
            $q->where(function (Builder $w) use ($like) {
                $w->where('media.filename', 'like', $like)
                    ->orWhere('media.description', 'like', $like)
                    ->orWhere('media.city_en', 'like', $like)->orWhere('media.city_fa', 'like', $like)
                    ->orWhere('media.country_en', 'like', $like)->orWhere('media.country_fa', 'like', $like)
                    ->orWhereHas('tags', fn ($t) => $t->where('name', 'like', $like))
                    ->orWhereHas('persons.person', fn ($p) => $p->where('name', 'like', $like))
                    ->orWhereHas('directory', fn ($d) => $d->where('name', 'like', $like));
            });
            $dirs->where('name', 'like', $like);
        }
        $filtered = false;
        if (! empty($data['from'])) {
            $q->whereRaw('COALESCE(media.taken_at, media.file_mtime) >= ?', [$data['from'].' 00:00:00']);
            $filtered = true;
        }
        if (! empty($data['to'])) {
            $q->whereRaw('COALESCE(media.taken_at, media.file_mtime) <= ?', [$data['to'].' 23:59:59']);
            $filtered = true;
        }
        if (! empty($data['type'])) {
            $q->where('media.type', $data['type']);
            $filtered = true;
        }
        if (! empty($data['tag'])) {
            $q->whereHas('tags', fn ($t) => $t->where('name', $data['tag']));
            $filtered = true;
        }
        if (! empty($data['person'])) {
            $q->whereHas('persons.person', fn ($p) => $p->where('name', $data['person']));
            $filtered = true;
        }
        if (! empty($data['camera'])) {
            $like = '%'.Paths::like($data['camera']).'%';
            $q->where(fn ($w) => $w->where('media.camera_model', 'like', $like)->orWhere('media.camera_make', 'like', $like));
            $filtered = true;
        }
        if (! empty($data['place'])) {
            $like = '%'.Paths::like($data['place']).'%';
            $q->where(fn ($w) => $w->where('media.city_en', 'like', $like)->orWhere('media.city_fa', 'like', $like)
                ->orWhere('media.country_en', 'like', $like)->orWhere('media.country_fa', 'like', $like));
            $filtered = true;
        }
        if ($request->boolean('fav')) {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('favorites')->whereColumn('favorites.media_id', 'media.id')
                ->where('favorites.user_id', $request->user()->id));
            $filtered = true;
        }
        if ($request->boolean('gps')) {
            $q->whereNotNull('media.gps_lat');
            $filtered = true;
        }
        if ($request->boolean('described')) {
            $q->whereNotNull('media.description');
            $filtered = true;
        }
        if (! $terms && ! $filtered) {
            return response()->json(['files' => [], 'folders' => [], 'more' => false, 'page' => 0]);
        }
        $out = $this->files($this->sort($q, $data['sort'] ?? null), $access, $request);
        $out['folders'] = [];
        if ($terms && ! $filtered && (int) $request->query('page', 0) === 0) {
            $out['folders'] = $dirs->orderBy('name')->limit(100)->get()
                ->map(function ($d) use ($access) {
                    $v = $access->toVirtual($d->path);
                    $crumbs = $v === null ? [] : $access->crumbs($v);

                    return $v === null ? null : [
                        'name' => $d->name, 'path' => $v, 'mtime' => Presenter::iso($d->mtime),
                        'count' => $d->listed_at ? $d->dir_count + $d->file_count : null,
                        'parentName' => count($crumbs) > 1 ? $crumbs[count($crumbs) - 2]['name'] : '/',
                    ];
                })->filter()->values()->all();
        }

        return response()->json($out);
    }

    public function favorites(Request $request)
    {
        $access = Access::for($request->user());
        $q = Media::query()->select(array_map(fn ($c) => 'media.'.$c, Media::LIST_COLUMNS))
            ->join('favorites', 'favorites.media_id', '=', 'media.id')
            ->where('favorites.user_id', $request->user()->id)
            ->orderByDesc('favorites.created_at');
        $access->scope($q, 'media.path');

        return response()->json($this->files($q, $access, $request) + ['folders' => []]);
    }

    public function onThisDay(Request $request)
    {
        $access = Access::for($request->user());
        $today = Carbon::now(config('app.display_timezone'));
        $q = Media::query()->select(array_map(fn ($c) => 'media.'.$c, Media::LIST_COLUMNS))
            ->whereNotNull('taken_at')
            ->whereMonth('taken_at', $today->month)->whereDay('taken_at', $today->day)
            ->whereYear('taken_at', '<', $today->year)
            ->orderByDesc('taken_at');
        $access->scope($q, 'media.path');

        return response()->json($this->files($q, $access, $request) + ['folders' => []]);
    }

    public function map(Request $request)
    {
        $access = Access::for($request->user());
        $q = Media::query()->whereNotNull('gps_lat')->orderBy('id')->limit(20000);
        $access->scope($q, 'path');
        $points = $q->get(['id', 'gps_lat', 'gps_lng', 'filename', 'thumb_v', 'has_thumb', 'type', 'ext', 'scanned_at'])
            ->map(fn ($m) => [$m->id, round($m->gps_lat, 5), round($m->gps_lng, 5), $m->has_thumb ? Signer::thumb($m) : null, $m->filename])
            ->all();

        return response()->json(['points' => $points]);
    }

    public function suggest(Request $request, string $kind)
    {
        $term = trim((string) $request->query('q', ''));
        if ($term === '' || mb_strlen($term) > 100) {
            return response()->json([]);
        }
        $like = '%'.Paths::like($term).'%';
        $starts = Paths::like($term).'%';
        $list = match ($kind) {
            'tags' => Tag::where('name', 'like', $like)->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$starts])->orderBy('name')->limit(20)->pluck('name'),
            'persons' => Person::where('name', 'like', $like)->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$starts])->orderBy('name')->limit(20)->pluck('name'),
            'cameras' => Media::where('camera_model', 'like', $like)->distinct()->orderBy('camera_model')->limit(20)->pluck('camera_model'),
            'places' => collect(['city_en', 'city_fa', 'country_en', 'country_fa'])
                ->flatMap(fn ($c) => Media::where($c, 'like', $like)->distinct()->limit(10)->pluck($c))->unique()->sort()->values()->take(20),
        };

        return response()->json($list->values());
    }
}
