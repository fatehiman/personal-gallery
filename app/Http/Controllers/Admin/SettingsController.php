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
            'otd_mode' => ['nullable', 'in:interval,weekdays'],
            'otd_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'otd_weekdays' => ['required_if:otd_mode,weekdays', 'nullable', 'array'],
            'otd_weekdays.*' => ['integer', 'between:1,7'],
            'otd_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
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
        // Schedule fields are optional so older forms keep the saved schedule.
        if (isset($data['otd_mode'])) {
            Settings::set('otd_mode', $data['otd_mode']);
            Settings::set('otd_interval_days', $data['otd_interval_days'] ?? 1);
            Settings::set('otd_weekdays', implode(',', array_unique(array_map('intval', $data['otd_weekdays'] ?? []))));
            Settings::set('otd_time', $data['otd_time'] ?? '20:00');
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
