<?php

namespace App\Http\Controllers;

use App\Gallery\Avatars;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return view('profile', ['user' => $request->user()]);
    }

    public function update(Request $request, Avatars $avatars)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'locale' => ['required', 'in:en,fa'],
            'calendar' => ['required', 'in:gregorian,jalali'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);
        $user = $request->user();
        $user->forceFill(['name' => trim($data['name']), 'locale' => $data['locale'], 'calendar' => $data['calendar']])->save();
        if ($request->hasFile('avatar')) {
            $avatars->storeUpload($user, $request->file('avatar'));
        } else {
            $avatars->fetchIfMissing($user);
        }

        return redirect()->route('profile')->with('ok', __('ui.saved', [], $user->locale));
    }

    public function avatar(Request $request, Avatars $avatars)
    {
        $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192']]);
        $ok = $avatars->storeUpload($request->user(), $request->file('avatar'));

        return redirect()->route('profile')->with($ok ? 'ok' : 'err', $ok ? __('ui.saved') : __('ui.invalid_image'));
    }

    public function removeAvatar(Request $request, Avatars $avatars)
    {
        $avatars->remove($request->user());

        return redirect()->route('profile')->with('ok', __('ui.saved'));
    }

    public function password(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $user = $request->user();
        // New remember token: "Keep me signed in" cookies on other devices stop working.
        $user->forceFill(['password' => Hash::make($request->input('password')), 'remember_token' => \Illuminate\Support\Str::random(60)])->save();
        // Keep this session valid for auth.session (it compares the password hash).
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        $request->session()->regenerate();

        return redirect()->route('profile')->with('ok', __('ui.password_changed'));
    }

    public function prefs(Request $request)
    {
        $data = $request->validate([
            'view' => ['nullable', 'in:tiny,small,medium,large,list,details'],
            'sort' => ['nullable', 'in:name,name_desc,date,date_desc,size,size_desc,type'],
            'hide_slow_tip' => ['nullable', 'boolean'],
            'slide_interval' => ['nullable', 'integer', 'in:3,5,10'],
        ]);
        $user = $request->user();
        $user->forceFill(['prefs' => array_merge($user->prefs ?? [], array_filter($data, fn ($v) => $v !== null))])->save();

        return response()->json(['ok' => true]);
    }

    public function avatarFile(Request $request, User $user)
    {
        abort_unless(is_file($user->avatarFile()), 404);

        return response()->file($user->avatarFile(), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
