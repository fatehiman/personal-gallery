@php
    use App\Support\Asset;
    $fa = app()->getLocale() === 'fa';
    $user = auth()->user();
    $cfg = [
        'locale' => app()->getLocale(),
        'dir' => $fa ? 'rtl' : 'ltr',
        'calendar' => $user?->calendar ?? ($fa ? 'jalali' : 'gregorian'),
        'admin' => (bool) $user?->is_admin,
        'csrf' => csrf_token(),
        'prefs' => $user?->prefs ?? (object) [],
        'icons' => Asset::url('assets/icons.svg'),
        't' => trans('ui'),
    ];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $fa ? 'rtl' : 'ltr' }}" class="theme-{{ $fa ? 'fa' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="{{ $fa ? '#e9f6f3' : '#eef5ff' }}">
    <title>@yield('title', __('ui.app_name')) · {{ __('ui.app_name') }}</title>
    <link rel="icon" href="{{ Asset::url('assets/favicon.svg') }}" type="image/svg+xml">
    <link rel="preload" href="/vendor/fonts/{{ $fa ? 'Vazirmatn-wght.woff2' : 'nunito-latin-wght-normal.woff2' }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ Asset::url('assets/base.css') }}">
    <link rel="stylesheet" href="{{ Asset::url($fa ? 'assets/theme-fa.css' : 'assets/theme-en.css') }}">
    @stack('head')
    <script type="application/json" id="pg-config">@json($cfg)</script>
    <script src="{{ Asset::url('assets/app.js') }}" defer></script>
    @stack('scripts')
</head>
<body class="@yield('body_class')">
@auth
    <header class="topbar">
        <button class="icon-btn only-mobile" type="button" data-toggle-menu aria-label="{{ __('ui.menu') }}">
            <svg class="ic"><use href="{{ $cfg['icons'] }}#i-menu"/></svg>
        </button>
        <a class="brand" href="{{ route('browse') }}">
            <span class="brand-logo"><svg class="ic"><use href="{{ $cfg['icons'] }}#i-images"/></svg></span>
            <span class="brand-name">{{ __('ui.app_name') }}</span>
        </a>
        <nav class="mainnav" id="mainnav">
            @php
                $links = [
                    ['browse', 'house', 'home', 'browse*'],
                    ['favorites', 'heart', 'favorites', 'favorites'],
                    ['onthisday', 'history', 'on_this_day', 'onthisday'],
                    ['map', 'map', 'map', 'map'],
                ];
                if ($user->is_admin) {
                    $links[] = ['admin.users.index', 'users', 'users', 'admin.users.*'];
                    $links[] = ['admin.scans', 'scan-search', 'scan_jobs', 'admin.scans'];
                    $links[] = ['admin.settings', 'settings', 'settings', 'admin.settings'];
                }
            @endphp
            @foreach ($links as [$route, $icon, $label, $pattern])
                <a href="{{ route($route) }}" class="navlink c{{ $loop->index % 6 }} {{ request()->routeIs($pattern) ? 'active' : '' }}">
                    <svg class="ic"><use href="{{ $cfg['icons'] }}#i-{{ $icon }}"/></svg><span>{{ __('ui.'.$label) }}</span>
                </a>
            @endforeach
        </nav>
        <div class="usermenu">
            <a class="avatar-link" href="{{ route('profile') }}" title="{{ __('ui.profile') }}">
                @include('partials.avatar', ['u' => $user, 'size' => 'sm'])
                <span class="only-wide">{{ $user->name }}</span>
            </a>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button class="icon-btn" type="submit" title="{{ __('ui.logout') }}" aria-label="{{ __('ui.logout') }}">
                    <svg class="ic"><use href="{{ $cfg['icons'] }}#i-log-out"/></svg>
                </button>
            </form>
        </div>
    </header>
    <div class="menu-backdrop" data-toggle-menu></div>
@endauth

<main class="page">
    @if (session('ok'))
        <div class="flash ok" data-autohide>{{ session('ok') }}</div>
    @endif
    @if (session('err'))
        <div class="flash err">{{ session('err') }}</div>
    @endif
    @yield('content')
</main>
<div id="toasts" class="toasts" aria-live="polite"></div>
</body>
</html>
