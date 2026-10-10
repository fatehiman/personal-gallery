@extends('layouts.app')
@section('title', __('ui.settings'))

@section('content')
<div class="narrow">
    <h1 class="page-title">@include('partials.icon', ['i' => 'settings']) {{ __('ui.settings') }}</h1>

    @if ($errors->any())
        <div class="flash err">{{ $errors->first() }}</div>
    @endif

    <form class="card form" method="post" action="{{ route('admin.settings.update') }}" autocomplete="off">
        @csrf
        <h2 class="section-h">@include('partials.icon', ['i' => 'history']) {{ __('ui.settings_telegram') }}</h2>
        <p class="muted small">{{ __('ui.settings_telegram_hint') }}</p>
        <label class="check"><input type="checkbox" name="telegram_enabled" value="1" @checked(old('telegram_enabled', \App\Gallery\Settings::bool('telegram_enabled')))> <span>{{ __('ui.telegram_enabled') }}</span></label>
        <div class="grid2">
            <label class="field"><span>{{ __('ui.telegram_token') }}</span>
                <input name="telegram_token" dir="ltr" maxlength="200" autocomplete="off" placeholder="{{ $hasToken ? __('ui.token_saved') : '123456:ABC…' }}"></label>
            <label class="field"><span>{{ __('ui.telegram_chat') }}</span>
                <input name="telegram_chat" dir="ltr" maxlength="100" value="{{ old('telegram_chat', \App\Gallery\Settings::get('telegram_chat')) }}" placeholder="-1001234567890"></label>
            <label class="field"><span>{{ __('ui.otd_min_years') }}</span>
                <input type="number" name="otd_min_years" min="1" max="100" required value="{{ old('otd_min_years', \App\Gallery\Settings::int('otd_min_years', 1)) }}"></label>
            <label class="field"><span>{{ __('ui.otd_max_images') }}</span>
                <input type="number" name="otd_max_images" min="0" max="50" required value="{{ old('otd_max_images', \App\Gallery\Settings::int('otd_max_images', 5)) }}"></label>
            <label class="field"><span>{{ __('ui.otd_max_videos') }}</span>
                <input type="number" name="otd_max_videos" min="0" max="20" required value="{{ old('otd_max_videos', \App\Gallery\Settings::int('otd_max_videos', 2)) }}"></label>
        </div>
        @if ($hasToken)
            <label class="check"><input type="checkbox" name="clear_token" value="1"> <span>{{ __('ui.token_remove') }}</span></label>
        @endif
        <p class="muted small">{{ __('ui.telegram_note') }}
            @if ($lastSent) <br>{{ __('ui.telegram_last', ['d' => $lastSent]) }} @endif</p>

        <h2 class="section-h">@include('partials.icon', ['i' => 'scan-search']) {{ __('ui.settings_crawl') }}</h2>
        <p class="muted small">{{ __('ui.settings_crawl_hint') }}</p>
        <label class="check"><input type="checkbox" name="crawl_enabled" value="1" @checked(old('crawl_enabled', \App\Gallery\Crawler::enabled()))> <span>{{ __('ui.crawl_enabled') }}</span></label>
        <div class="grid2">
            <label class="field"><span>{{ __('ui.crawl_seconds') }}</span>
                <input type="number" name="crawl_seconds" min="5" max="50" required value="{{ old('crawl_seconds', \App\Gallery\Crawler::seconds()) }}"></label>
        </div>
        <p class="muted small">
            {{ __('ui.crawl_status', ['dirs' => $crawl['dirs'], 'files' => $crawl['unscanned'], 'cycles' => $crawl['cycles']]) }}
            <br>{{ __('ui.crawl_position') }}: <code dir="ltr">/{{ $crawl['pos'] }}</code>
            @if ($crawl['cycle_at'])<br>{{ __('ui.crawl_last_cycle') }}: <time data-dt="{{ \Illuminate\Support\Carbon::parse($crawl['cycle_at'])->utc()->toIso8601ZuluString() }}"></time>@endif
        </p>

        <div class="form-foot">
            <button class="btn primary" type="submit">@include('partials.icon', ['i' => 'check']) {{ __('ui.save') }}</button>
        </div>
    </form>

    <form class="inline-form" method="post" action="{{ route('admin.settings.send') }}">
        @csrf
        <button class="btn ghost" type="submit">@include('partials.icon', ['i' => 'upload']) {{ __('ui.telegram_send_now') }}</button>
    </form>
</div>
@endsection
