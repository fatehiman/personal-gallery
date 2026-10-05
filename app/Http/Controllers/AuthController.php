<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function guestLocale(Request $request, string $locale)
    {
        $request->session()->put('guest_locale', $locale);

        return $request->user() ? redirect()->route('browse') : redirect()->route('login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:200'],
            'lang' => ['nullable', 'in:en,fa'],
        ]);
        // Failed logins are limited per IP + username (5 per 5 min) and per IP (20 per 15 min).
        // The IP is the real visitor IP (X-Forwarded-For is trusted only from Cloudflare).
        $ip = (string) $request->ip();
        $limits = [
            'login:u:'.sha1($ip.'|'.Str::lower($data['username'])) => [5, 300],
            'login:ip:'.sha1($ip) => [20, 900],
        ];
        foreach ($limits as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                Log::warning('login blocked (too many failures)', ['ip' => $ip, 'username' => mb_substr($data['username'], 0, 64)]);
                throw ValidationException::withMessages(['username' => __('ui.login_throttled', ['m' => $minutes])])->status(429);
            }
        }

        $user = User::where('username', $data['username'])->first();
        // Always run a hash check, so a wrong username takes the same time as a wrong password.
        $ok = Hash::check($data['password'], $user?->password ?? '$2y$12$efBkze3.pqsOrn8LS4nGrePvXsuTVYGpq1Pr2vgMFT58BPNiMJnzO');
        if (! $user || ! $ok || ! $user->is_active) {
            foreach ($limits as $key => [, $decay]) {
                RateLimiter::hit($key, $decay);
            }
            Log::warning('login failed', ['ip' => $ip, 'username' => mb_substr($data['username'], 0, 64)]);
            throw ValidationException::withMessages(['username' => __('ui.login_failed')]);
        }
        RateLimiter::clear(array_key_first($limits));
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // Choosing Persian on the login page saves Persian. Choosing English does not change the saved language.
        $update = ['last_login_at' => now()];
        if (($data['lang'] ?? null) === 'fa') {
            $update['locale'] = 'fa';
        }
        $user->forceFill($update)->save();
        $request->session()->forget('guest_locale');

        return redirect()->intended(route('browse'));
    }

    public function logout(Request $request)
    {
        $locale = $request->user()?->locale;
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('guest_locale', $locale ?? 'en');

        return redirect()->route('login');
    }
}
