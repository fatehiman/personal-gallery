<?php

namespace App\Http\Controllers;

use App\Gallery\Access;
use App\Gallery\Presenter;
use App\Gallery\Scanner;
use App\Gallery\Signer;
use App\Gallery\VideoCache;
use App\Models\Media;
use App\Models\MediaPerson;
use App\Models\Person;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MediaInfoController extends Controller
{
    private function media(Request $request, int $id): Media
    {
        $m = Media::findOrFail($id);
        abort_unless(Access::for($request->user())->canAccessMedia($m), 404);

        return $m;
    }

    private function cleanName(?string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        abort_if($s === '' || mb_strlen($s) > $max, 422, __('ui.invalid_name'));

        return $s;
    }

    public function show(Request $request, int $media)
    {
        $m = $this->media($request, $media);
        $m->load(['tags', 'persons']);
        $access = Access::for($request->user());
        $out = Presenter::media($m, $access, Presenter::favMap($access, [$m->id]), true);
        $out['tagList'] = $m->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all();
        $out['personList'] = $m->persons->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->person?->name, 'x' => $p->x, 'y' => $p->y, 'w' => $p->w, 'h' => $p->h,
        ])->all();
        $out['info'] = array_filter([
            'make' => $m->camera_make, 'model' => $m->camera_model, 'lens' => $m->lens, 'exposure' => $m->exposure,
            'aperture' => $m->aperture ? 'f/'.rtrim(rtrim(number_format($m->aperture, 1), '0'), '.') : null,
            'iso' => $m->iso, 'focal' => $m->focal_length ? rtrim(rtrim(number_format($m->focal_length, 1), '0'), '.').' mm' : null,
            'flash' => $m->flash === null ? null : ($m->flash ? __('ui.yes') : __('ui.no')),
            'software' => $m->software, 'codec' => $m->video_codec, 'mime' => $m->mime,
            'altitude' => $m->gps_alt !== null ? round($m->gps_alt).' m' : null,
        ], fn ($v) => $v !== null && $v !== '');
        $out['exif'] = $m->exif ?? [];
        $out['path'] = $access->isAdmin() ? $m->path : null;

        return response()->json($out);
    }

    /** Video playback: state of the local copy (copying / ready / error). */
    public function videoStatus(Request $request, int $media)
    {
        return response()->json($this->videoReply(VideoCache::status($this->video($request, $media))));
    }

    /** Start copying the video to the server (background). Checks the free disk space first. */
    public function videoStart(Request $request, int $media)
    {
        return response()->json($this->videoReply(VideoCache::start($this->video($request, $media))));
    }

    public function videoCancel(Request $request, int $media)
    {
        return response()->json($this->videoReply(VideoCache::cancel($this->video($request, $media))));
    }

    private function video(Request $request, int $id): Media
    {
        $m = $this->media($request, $id);
        abort_unless($m->type === 'video', 404);

        return $m;
    }

    private function videoReply(array $st): array
    {
        if (isset($st['error'])) {
            $st['message'] = __('ui.'.$st['error']);
        }

        return $st;
    }

    public function description(Request $request, int $media)
    {
        $m = $this->media($request, $media);
        $data = $request->validate(['description' => ['nullable', 'string', 'max:2000']]);
        $m->forceFill(['description' => trim((string) ($data['description'] ?? '')) ?: null])->save();

        return response()->json(['desc' => $m->description]);
    }

    public function rotate(Request $request, int $media, Scanner $scanner)
    {
        $m = $this->media($request, $media);
        $dir = $request->validate(['dir' => ['required', 'in:cw,ccw']])['dir'];
        $delta = $dir === 'cw' ? 90 : 270;
        $scanner->rotateThumb($m, $delta);
        // New version => new URLs, so caches never show the old rotation.
        $m->forceFill(['rotation' => ($m->rotation + $delta) % 360, 'thumb_v' => $m->thumb_v + 1])->save();

        return response()->json([
            'rot' => $m->rotation, 'thumb' => Signer::thumb($m), 'url' => Signer::original($m), 'dl' => Signer::download($m),
            'tw' => $m->thumb_w, 'th' => $m->thumb_h,
        ]);
    }

    public function favorite(Request $request, int $media)
    {
        $m = $this->media($request, $media);
        $on = $request->boolean('on');
        $q = DB::table('favorites')->where('user_id', $request->user()->id)->where('media_id', $m->id);
        if ($on) {
            $q->exists() || DB::table('favorites')->insert(['user_id' => $request->user()->id, 'media_id' => $m->id, 'created_at' => now()]);
        } else {
            $q->delete();
        }

        return response()->json(['fav' => $on]);
    }

    public function addTag(Request $request, int $media)
    {
        $m = $this->media($request, $media);
        $name = $this->cleanName($request->input('name'), 100);
        $tag = Tag::firstOrCreate(['name' => $name]);
        $m->tags()->syncWithoutDetaching([$tag->id => ['user_id' => $request->user()->id, 'created_at' => now()]]);

        return response()->json(['id' => $tag->id, 'name' => $tag->name]);
    }

    public function removeTag(Request $request, int $media, int $tag)
    {
        $m = $this->media($request, $media);
        $m->tags()->detach($tag);
        if (! DB::table('media_tag')->where('tag_id', $tag)->exists()) {
            Tag::whereKey($tag)->delete(); // keep the suggestion list clean
        }

        return response()->json(['ok' => true]);
    }

    public function addPerson(Request $request, int $media)
    {
        $m = $this->media($request, $media);
        $data = $request->validate([
            'x' => ['nullable', 'numeric', 'between:0,1'], 'y' => ['nullable', 'numeric', 'between:0,1'],
            'w' => ['nullable', 'numeric', 'between:0,1'], 'h' => ['nullable', 'numeric', 'between:0,1'],
        ]);
        $name = $this->cleanName($request->input('name'), 150);
        $person = Person::firstOrCreate(['name' => $name]);
        $mp = MediaPerson::create([
            'media_id' => $m->id, 'person_id' => $person->id, 'user_id' => $request->user()->id,
            'x' => $data['x'] ?? null, 'y' => $data['y'] ?? null, 'w' => $data['w'] ?? null, 'h' => $data['h'] ?? null,
        ]);

        return response()->json(['id' => $mp->id, 'name' => $person->name, 'x' => $mp->x, 'y' => $mp->y, 'w' => $mp->w, 'h' => $mp->h]);
    }

    public function removePerson(Request $request, int $media, int $mp)
    {
        $m = $this->media($request, $media);
        $row = MediaPerson::where('media_id', $m->id)->findOrFail($mp);
        $personId = $row->person_id;
        $row->delete();
        if (! MediaPerson::where('person_id', $personId)->exists()) {
            Person::whereKey($personId)->delete();
        }

        return response()->json(['ok' => true]);
    }
}
