<?php

namespace App\Http\Controllers\Admin;

use App\Gallery\Crawler;
use App\Gallery\Settings;
use App\Gallery\TelegramDigest;
use App\Http\Controllers\Controller;
use App\Models\Directory;
use App\Models\Media;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        return view('admin.settings', [
            'hasToken' => (bool) Settings::get('telegram_token'),
            'crawl' => [
                'dirs' => Directory::count(),
                'unscanned' => Media::whereNull('scanned_at')->whereIn('type', ['image', 'video'])->count(),
                'cycles' => Settings::int('crawl_cycles', 0),
                'cycle_at' => Settings::get('crawl_cycle_at'),
                'pos' => (string) Settings::get(Crawler::POS, ''),
            ],
            'lastSent' => Settings::get('otd_last_sent'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'telegram_token' => ['nullable', 'string', 'max:200', 'regex:/^[0-9]{5,}:[A-Za-z0-9_-]{20,}$/'],
            'telegram_chat' => ['nullable', 'string', 'max:100', 'regex:/^(-?[0-9]{5,20}|@[A-Za-z0-9_]{4,64})$/'],
            'otd_min_years' => ['required', 'integer', 'min:1', 'max:100'],
            'otd_max_images' => ['required', 'integer', 'min:0', 'max:50'],
            'otd_max_videos' => ['required', 'integer', 'min:0', 'max:20'],
            'crawl_seconds' => ['required', 'integer', 'min:5', 'max:50'],
        ]);
        // An empty token field keeps the saved token. "Remove token" clears it.
        if (! empty($data['telegram_token'])) {
            Settings::set('telegram_token', trim($data['telegram_token']));
        } elseif ($request->boolean('clear_token')) {
            Settings::set('telegram_token', null);
        }
        Settings::set('telegram_chat', trim((string) ($data['telegram_chat'] ?? '')));
        Settings::set('telegram_enabled', $request->boolean('telegram_enabled') ? 1 : 0);
        foreach (['otd_min_years', 'otd_max_images', 'otd_max_videos', 'crawl_seconds'] as $k) {
            Settings::set($k, $data[$k]);
        }
        Settings::set('crawl_enabled', $request->boolean('crawl_enabled') ? 1 : 0);

        return redirect()->route('admin.settings')->with('ok', __('ui.saved'));
    }

    /** Send today's digest now (does not change the "sent today" mark of the daily job). */
    public function sendNow(TelegramDigest $digest)
    {
        if (! TelegramDigest::configured()) {
            return redirect()->route('admin.settings')->with('err', __('ui.telegram_not_ready'));
        }
        set_time_limit(300);
        $r = $digest->send();

        return redirect()->route('admin.settings')->with('ok', __('ui.telegram_sent', $r));
    }
}
