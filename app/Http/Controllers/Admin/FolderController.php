<?php

namespace App\Http\Controllers\Admin;

use App\Gallery\Indexer;
use App\Gallery\InvalidPathException;
use App\Gallery\Paths;
use App\Http\Controllers\Controller;
use App\Models\Directory;
use App\Models\FolderAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FolderController extends Controller
{
    /** Folder picker: sub-folders of a real path. */
    public function dirs(Request $request, Indexer $indexer)
    {
        $path = Paths::normalize((string) $request->query('path', ''));
        $dir = $indexer->sync($path);

        return response()->json([
            'path' => $path,
            'parent' => Paths::parent($path),
            'dirs' => Directory::where('parent_id', $dir->id)->orderBy('name')->get(['name', 'path'])
                ->map(fn ($d) => ['name' => $d->name, 'path' => $d->path]),
        ]);
    }

    public function store(Request $request, User $user)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'path' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $path = Paths::normalize($data['path'] ?? '');
        } catch (InvalidPathException) {
            throw ValidationException::withMessages(['path' => __('ui.folder_not_found')]);
        }
        if (! is_dir(Paths::abs($path))) {
            throw ValidationException::withMessages(['path' => __('ui.folder_not_found')]);
        }
        // No nested folders: a folder inside (or around) an assigned folder would show the same files twice.
        foreach ($user->folders as $f) {
            if ($f->path === $path) {
                throw ValidationException::withMessages(['path' => __('ui.folder_already_assigned', ['title' => $f->title])]);
            }
            if (Paths::isWithin($path, $f->path)) {
                throw ValidationException::withMessages(['path' => __('ui.folder_inside_assigned', ['title' => $f->title, 'path' => '/'.$f->path])]);
            }
            if (Paths::isWithin($f->path, $path)) {
                throw ValidationException::withMessages(['path' => __('ui.folder_contains_assigned', ['title' => $f->title, 'path' => '/'.$f->path])]);
            }
        }
        FolderAccess::create([
            'user_id' => $user->id, 'title' => trim($data['title']), 'path' => $path,
            'sort' => (int) $user->folders()->max('sort') + 1,
        ]);

        return redirect()->route('admin.users.edit', $user)->with('ok', __('ui.folder_added'));
    }

    public function update(Request $request, User $user, FolderAccess $folder)
    {
        abort_unless($folder->user_id === $user->id, 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:150'], 'sort' => ['nullable', 'integer', 'min:0', 'max:100000']]);
        $folder->update(['title' => trim($data['title']), 'sort' => $data['sort'] ?? $folder->sort]);

        return redirect()->route('admin.users.edit', $user)->with('ok', __('ui.saved'));
    }

    public function destroy(User $user, FolderAccess $folder)
    {
        abort_unless($folder->user_id === $user->id, 404);
        $folder->delete();

        return redirect()->route('admin.users.edit', $user)->with('ok', __('ui.folder_removed'));
    }
}
