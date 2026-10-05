@extends('layouts.app')
@section('title', __('ui.login'))
@section('body_class', 'login-page')

@section('content')
<div class="login-wrap">
    <div class="login-art" aria-hidden="true">
        <span class="bubble b1"></span><span class="bubble b2"></span><span class="bubble b3"></span><span class="bubble b4"></span>
        <div class="polaroids">
            <span class="polaroid p1">@include('partials.icon', ['i' => 'camera'])</span>
            <span class="polaroid p2">@include('partials.icon', ['i' => 'heart'])</span>
            <span class="polaroid p3">@include('partials.icon', ['i' => 'map-pin'])</span>
        </div>
    </div>
    <form class="card login-card" method="post" action="{{ url('/login') }}">
        @csrf
        <input type="hidden" name="lang" value="{{ app()->getLocale() }}">
        <div class="login-head">
            <span class="brand-logo big">@include('partials.icon', ['i' => 'images'])</span>
            <h1>{{ __('ui.app_name') }}</h1>
            <p class="muted">{{ __('ui.tagline') }}</p>
        </div>

        <div class="lang-switch" role="group" aria-label="{{ __('ui.language') }}">
            <a href="{{ route('guest.locale', 'en') }}" class="{{ app()->getLocale() === 'en' ? 'on' : '' }}" lang="en">English</a>
            <a href="{{ route('guest.locale', 'fa') }}" class="{{ app()->getLocale() === 'fa' ? 'on' : '' }}" lang="fa">فارسی</a>
        </div>

        @if ($errors->any())
            <div class="flash err">{{ $errors->first() }}</div>
        @endif

        <label class="field">
            <span>{{ __('ui.username') }}</span>
            <span class="input-ic">@include('partials.icon', ['i' => 'user'])
                <input name="username" value="{{ old('username') }}" autocomplete="username" required autofocus dir="ltr" maxlength="64">
            </span>
        </label>
        <label class="field">
            <span>{{ __('ui.password') }}</span>
            <span class="input-ic">@include('partials.icon', ['i' => 'lock'])
                <input type="password" name="password" autocomplete="current-password" required dir="ltr" maxlength="200">
            </span>
        </label>
        <label class="check">
            <input type="checkbox" name="remember" value="1"> <span>{{ __('ui.remember') }}</span>
        </label>
        <button class="btn primary wide" type="submit">@include('partials.icon', ['i' => 'key-round']) {{ __('ui.login') }}</button>
    </form>
</div>
@endsection
