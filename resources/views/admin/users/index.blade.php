@extends('layouts.app')
@section('title', __('ui.users'))

@section('content')
<div class="wide">
    <div class="page-head">
        <h1 class="page-title">@include('partials.icon', ['i' => 'users']) {{ __('ui.users') }}</h1>
        <a class="btn primary" href="{{ route('admin.users.create') }}">@include('partials.icon', ['i' => 'user-plus']) {{ __('ui.add_user') }}</a>
    </div>

    <div class="user-cards">
        @foreach ($users as $u)
            <div class="card user-card c{{ $u->id % 6 }} {{ $u->is_active ? '' : 'is-disabled' }}">
                @include('partials.avatar', ['u' => $u, 'size' => 'lg'])
                <div class="uc-body">
                    <b>{{ $u->name }}</b>
                    <span class="muted" dir="ltr">{{ '@'.$u->username }}</span>
                    @if ($u->email)<span class="muted small" dir="ltr">{{ $u->email }}</span>@endif
                    <div class="chips">
                        <span class="chip {{ $u->is_admin ? 'chip-admin' : '' }}">@include('partials.icon', ['i' => $u->is_admin ? 'shield' : 'user']) {{ $u->is_admin ? __('ui.admin') : __('ui.normal_user') }}</span>
                        @unless ($u->is_active)<span class="chip chip-off">{{ __('ui.disabled') }}</span>@endunless
                        @unless ($u->is_admin)<span class="chip">@include('partials.icon', ['i' => 'folder']) {{ $u->folders_count }}</span>@endunless
                    </div>
                    <small class="muted">{{ __('ui.last_login') }}:
                        @if ($u->last_login_at)<time data-dt="{{ $u->last_login_at->utc()->toIso8601ZuluString() }}"></time>@else {{ __('ui.never') }} @endif
                    </small>
                </div>
                <div class="uc-actions">
                    <a class="icon-btn" href="{{ route('admin.users.edit', $u) }}" title="{{ __('ui.edit') }}">@include('partials.icon', ['i' => 'pencil'])</a>
                    @if ($u->id !== auth()->id())
                        <form method="post" action="{{ route('admin.users.destroy', $u) }}" data-confirm="{{ __('ui.confirm_delete_user', ['name' => $u->name]) }}">
                            @csrf @method('DELETE')
                            <button class="icon-btn danger" type="submit" title="{{ __('ui.delete') }}">@include('partials.icon', ['i' => 'trash-2'])</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
