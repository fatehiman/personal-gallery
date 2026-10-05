<?php

namespace App\Http\Controllers\Admin;

use App\Gallery\Avatars;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        $users = User::withCount('folders')->orderByDesc('is_admin')->orderBy('name')->get();

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        return view('admin.users.form', ['user' => new User(['is_active' => true, 'locale' => 'en', 'calendar' => 'gregorian'])]);
    }

    private function rules(?User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:190'],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::min(8)],
            'locale' => ['required', 'in:en,fa'],
            'calendar' => ['required', 'in:gregorian,jalali'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ];
    }

    private function save(Request $request, User $user, Avatars $avatars): User
    {
        $data = $request->validate($this->rules($user->exists ? $user : null));
        $self = $user->exists && $user->id === $request->user()->id;
        $user->fill([
            'name' => trim($data['name']),
            'username' => $data['username'],
            'email' => $data['email'] ? strtolower(trim($data['email'])) : null,
            'locale' => $data['locale'],
            'calendar' => $data['calendar'],
            // You cannot remove your own admin right or disable yourself.
            'is_admin' => $self ? true : $request->boolean('is_admin'),
            'is_active' => $self ? true : $request->boolean('is_active'),
        ]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
            $user->setRememberToken(\Illuminate\Support\Str::random(60)); // log the user out everywhere
        }
        $user->save();
        if ($request->hasFile('avatar')) {
            $avatars->storeUpload($user, $request->file('avatar'));
        } elseif ($request->boolean('remove_avatar')) {
            $avatars->remove($user);
        } else {
            $avatars->fetchIfMissing($user);
        }

        return $user;
    }

    public function store(Request $request, Avatars $avatars)
    {
        $user = $this->save($request, new User, $avatars);

        return redirect()->route('admin.users.edit', $user)->with('ok', __('ui.user_created'));
    }

    public function edit(User $user)
    {
        return view('admin.users.form', ['user' => $user->load('folders')]);
    }

    public function update(Request $request, User $user, Avatars $avatars)
    {
        $this->save($request, $user, $avatars);

        return redirect()->route('admin.users.edit', $user)->with('ok', __('ui.saved'));
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 422, __('ui.cannot_delete_self'));
        @unlink($user->avatarFile());
        $user->delete();

        return redirect()->route('admin.users.index')->with('ok', __('ui.user_deleted'));
    }
}
