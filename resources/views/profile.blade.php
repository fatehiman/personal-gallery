@extends('layouts.app')
@section('title', __('ui.my_profile'))

@section('content')
<div class="narrow">
    <h1 class="page-title">@include('partials.icon', ['i' => 'user']) {{ __('ui.my_profile') }}</h1>

    <form class="card form" method="post" action="{{ route('profile.update') }}" enctype="multipart/form-data">
        @csrf
        @if ($errors->hasAny(['name', 'locale', 'calendar', 'avatar']))
            <div class="flash err">{{ $errors->first() }}</div>
        @endif
        <div class="avatar-edit">
            @include('partials.avatar', ['u' => $user, 'size' => 'xl'])
            <div>
                <label class="btn ghost small file-btn">@include('partials.icon', ['i' => 'upload']) {{ __('ui.upload_avatar') }}
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" data-preview-avatar>
                </label>
                <p class="muted small">{{ __('ui.avatar_hint') }}</p>
            </div>
        </div>

        <div class="grid2">
            <label class="field"><span>{{ __('ui.full_name') }}</span>
                <input name="name" value="{{ old('name', $user->name) }}" required maxlength="150"></label>
            <label class="field"><span>{{ __('ui.username') }}</span>
                <input value="{{ $user->username }}" disabled dir="ltr" title="{{ __('ui.readonly_field') }}"></label>
            <label class="field"><span>{{ __('ui.email') }}</span>
                <input value="{{ $user->email }}" disabled dir="ltr" title="{{ __('ui.readonly_field') }}"></label>
            <label class="field"><span>{{ __('ui.role') }}</span>
                <input value="{{ $user->is_admin ? __('ui.admin') : __('ui.normal_user') }}" disabled></label>
            <label class="field"><span>{{ __('ui.language') }}</span>
                <select name="locale">
                    <option value="en" @selected($user->locale === 'en')>English</option>
                    <option value="fa" @selected($user->locale === 'fa')>فارسی</option>
                </select></label>
            <label class="field"><span>{{ __('ui.calendar') }}</span>
                <select name="calendar">
                    <option value="gregorian" @selected($user->calendar === 'gregorian')>{{ __('ui.gregorian') }}</option>
                    <option value="jalali" @selected($user->calendar === 'jalali')>{{ __('ui.jalali') }}</option>
                </select></label>
        </div>
        <div class="form-foot">
            <button class="btn primary" type="submit">@include('partials.icon', ['i' => 'check']) {{ __('ui.save') }}</button>
        </div>
    </form>

    @if ($user->avatar_source)
        <form method="post" action="{{ route('profile.avatar.remove') }}" class="inline-form">
            @csrf
            <button class="btn ghost small" type="submit">@include('partials.icon', ['i' => 'trash-2']) {{ __('ui.remove_avatar') }}</button>
        </form>
    @endif

    <form class="card form" method="post" action="{{ route('profile.password') }}">
        @csrf
        <h2>@include('partials.icon', ['i' => 'key-round']) {{ __('ui.change_password') }}</h2>
        @if ($errors->hasAny(['current_password', 'password']))
            <div class="flash err">{{ $errors->first() }}</div>
        @endif
        <div class="grid2">
            <label class="field"><span>{{ __('ui.current_password') }}</span>
                <input type="password" name="current_password" required autocomplete="current-password" dir="ltr"></label>
            <span></span>
            <label class="field"><span>{{ __('ui.new_password') }}</span>
                <input type="password" name="password" required minlength="8" autocomplete="new-password" dir="ltr"></label>
            <label class="field"><span>{{ __('ui.confirm_password') }}</span>
                <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" dir="ltr"></label>
        </div>
        <div class="form-foot">
            <button class="btn primary" type="submit">@include('partials.icon', ['i' => 'check']) {{ __('ui.change_password') }}</button>
        </div>
    </form>
</div>
@endsection
