@extends('layouts.app')
@section('title', $user->exists ? __('ui.edit_user') : __('ui.add_user'))
@push('scripts')
    <script src="{{ \App\Support\Asset::url('assets/admin.js') }}" defer></script>
@endpush

@section('content')
@php $self = $user->exists && $user->id === auth()->id(); @endphp
<div class="narrow">
    <div class="page-head">
        <h1 class="page-title">@include('partials.icon', ['i' => $user->exists ? 'user-cog' : 'user-plus']) {{ $user->exists ? __('ui.edit_user') : __('ui.add_user') }}</h1>
        <a class="btn ghost" href="{{ route('admin.users.index') }}">@include('partials.icon', ['i' => 'users']) {{ __('ui.users') }}</a>
    </div>

    <form class="card form" method="post" enctype="multipart/form-data"
          action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
        @csrf
        @if ($user->exists) @method('PUT') @endif
        @if ($errors->any() && ! $errors->has('path') && ! $errors->has('title'))
            <div class="flash err">{{ $errors->first() }}</div>
        @endif

        <div class="avatar-edit">
            @if ($user->exists)
                @include('partials.avatar', ['u' => $user, 'size' => 'xl'])
            @else
                <span class="avatar avatar-xl avatar-initials c1">@include('partials.icon', ['i' => 'user'])</span>
            @endif
            <div>
                <label class="btn ghost small file-btn">@include('partials.icon', ['i' => 'upload']) {{ __('ui.upload_avatar') }}
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" data-preview-avatar>
                </label>
                @if ($user->avatar_source)
                    <label class="check small"><input type="checkbox" name="remove_avatar" value="1"> <span>{{ __('ui.remove_avatar') }}</span></label>
                @endif
                <p class="muted small">{{ __('ui.avatar_hint') }}</p>
            </div>
        </div>

        <div class="grid2">
            <label class="field"><span>{{ __('ui.full_name') }} *</span>
                <input name="name" value="{{ old('name', $user->name) }}" required maxlength="150"></label>
            <label class="field"><span>{{ __('ui.email') }}</span>
                <input type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="190" dir="ltr"></label>
            <label class="field"><span>{{ __('ui.username') }} *</span>
                <input name="username" value="{{ old('username', $user->username) }}" required minlength="3" maxlength="64" pattern="[A-Za-z0-9._\-]+" dir="ltr" autocomplete="off"></label>
            <label class="field"><span>{{ __('ui.password') }} {{ $user->exists ? '' : '*' }}</span>
                <input type="password" name="password" {{ $user->exists ? '' : 'required' }} minlength="8" autocomplete="new-password" dir="ltr"
                       placeholder="{{ $user->exists ? __('ui.password_keep') : '' }}"></label>
            <label class="field"><span>{{ __('ui.language') }}</span>
                <select name="locale">
                    <option value="en" @selected(old('locale', $user->locale) === 'en')>English</option>
                    <option value="fa" @selected(old('locale', $user->locale) === 'fa')>فارسی</option>
                </select></label>
            <label class="field"><span>{{ __('ui.calendar') }}</span>
                <select name="calendar">
                    <option value="gregorian" @selected(old('calendar', $user->calendar) === 'gregorian')>{{ __('ui.gregorian') }}</option>
                    <option value="jalali" @selected(old('calendar', $user->calendar) === 'jalali')>{{ __('ui.jalali') }}</option>
                </select></label>
        </div>
        <div class="checks">
            <label class="check"><input type="checkbox" name="is_admin" value="1" @checked(old('is_admin', $user->is_admin)) @disabled($self)>
                <span>{{ __('ui.admin') }} <small class="muted">— {{ __('ui.is_admin_hint') }}</small></span></label>
            <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active)) @disabled($self)>
                <span>{{ __('ui.active') }}</span></label>
        </div>
        <div class="form-foot">
            <button class="btn primary" type="submit">@include('partials.icon', ['i' => 'check']) {{ __('ui.save') }}</button>
        </div>
    </form>

    @if ($user->exists && ! $user->is_admin)
        <div class="card form" id="folders">
            <h2>@include('partials.icon', ['i' => 'folder-open']) {{ __('ui.folder_access') }}</h2>
            <p class="muted small">{{ __('ui.folder_access_hint') }}</p>

            <div class="folder-list">
                @forelse ($user->folders as $f)
                    <div class="folder-row c{{ $loop->index % 6 }}">
                        <span class="fr-ic">@include('partials.icon', ['i' => 'folder'])</span>
                        <form method="post" action="{{ route('admin.folders.update', [$user, $f]) }}" class="fr-title">
                            @csrf @method('PUT')
                            <input name="title" value="{{ $f->title }}" maxlength="150" required aria-label="{{ __('ui.folder_title') }}">
                            <button class="icon-btn" type="submit" title="{{ __('ui.save') }}">@include('partials.icon', ['i' => 'check'])</button>
                        </form>
                        <code class="fr-path" dir="ltr">/{{ $f->path }}</code>
                        <form method="post" action="{{ route('admin.folders.destroy', [$user, $f]) }}" data-confirm="{{ __('ui.confirm_remove_folder', ['name' => $f->title]) }}">
                            @csrf @method('DELETE')
                            <button class="icon-btn danger" type="submit" title="{{ __('ui.remove') }}">@include('partials.icon', ['i' => 'trash-2'])</button>
                        </form>
                    </div>
                @empty
                    <p class="muted">{{ __('ui.no_folder_access') }}</p>
                @endforelse
            </div>

            <form method="post" action="{{ route('admin.folders.store', $user) }}" class="add-folder">
                @csrf
                @if ($errors->has('path') || $errors->has('title'))
                    <div class="flash err">{{ $errors->first('path') ?: $errors->first('title') }}</div>
                @endif
                <div class="grid2">
                    <label class="field"><span>{{ __('ui.folder_title') }} *</span>
                        <input name="title" value="{{ old('title') }}" required maxlength="150"></label>
                    <label class="field"><span>{{ __('ui.folder_path') }} *</span>
                        <span class="picker-input">
                            <input name="path" value="{{ old('path') }}" readonly required dir="ltr" data-folder-input placeholder="/">
                            <button class="btn ghost small" type="button" data-pick-folder>@include('partials.icon', ['i' => 'folder-open']) {{ __('ui.choose_folder') }}</button>
                        </span></label>
                </div>
                <div class="form-foot">
                    <button class="btn primary" type="submit">@include('partials.icon', ['i' => 'folder-plus']) {{ __('ui.add_folder') }}</button>
                </div>
            </form>
        </div>

        <dialog class="modal" id="folder-picker">
            <div class="modal-head">
                <b>@include('partials.icon', ['i' => 'folder-open']) {{ __('ui.choose_folder') }}</b>
                <button class="icon-btn" type="button" data-close>@include('partials.icon', ['i' => 'x'])</button>
            </div>
            <div class="picker-path" dir="ltr" data-picker-path>/</div>
            <div class="picker-list" data-picker-list></div>
            <div class="modal-foot">
                <button class="btn ghost" type="button" data-close>{{ __('ui.cancel') }}</button>
                <button class="btn primary" type="button" data-picker-choose>@include('partials.icon', ['i' => 'check']) {{ __('ui.choose_this_folder') }}</button>
            </div>
        </dialog>
    @endif
</div>
@endsection
