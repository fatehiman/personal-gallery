@extends('layouts.app')
@php
    $titles = ['browse' => __('ui.home'), 'favorites' => __('ui.favorites'), 'onthisday' => __('ui.on_this_day'), 'search' => __('ui.search')];
    $views = ['tiny' => 'grid-3x3', 'small' => 'layout-grid', 'medium' => 'grid-2x2', 'large' => 'image', 'list' => 'list', 'details' => 'table'];
    $sorts = ['name', 'name_desc', 'date', 'date_desc', 'size', 'size_desc', 'type'];
@endphp
@section('title', $titles[$mode] ?? __('ui.home'))
@section('body_class', 'gallery-page')
@push('head')
    <link rel="stylesheet" href="/vendor/videojs/video-js.min.css">
@endpush
@push('scripts')
    <script src="/vendor/videojs/video.min.js" defer></script>
    <script src="{{ \App\Support\Asset::url('assets/viewer.js') }}" defer></script>
    <script src="{{ \App\Support\Asset::url('assets/gallery.js') }}" defer></script>
@endpush

@section('content')
<div id="gallery" data-mode="{{ $mode }}">
    <div class="toolbar card">
        <div class="tb-group">
            <a class="tool c0" href="{{ route('browse') }}" data-nav="" title="{{ __('ui.home') }}">@include('partials.icon', ['i' => 'house'])<span>{{ __('ui.home') }}</span></a>
            <button class="tool c1" type="button" data-act="up" title="{{ __('ui.up') }}">@include('partials.icon', ['i' => 'arrow-up'])<span>{{ __('ui.up') }}</span></button>
        </div>
        <nav class="crumbs" id="crumbs" aria-label="breadcrumb"></nav>
        <div class="tb-group tb-end">
            <button class="tool c2" type="button" data-act="search" title="{{ __('ui.search') }}" aria-expanded="false">@include('partials.icon', ['i' => 'search'])<span>{{ __('ui.search') }}</span></button>
            <div class="dropdown">
                <button class="tool c3" type="button" data-dd="sort" title="{{ __('ui.sort') }}">@include('partials.icon', ['i' => 'arrow-up-down'])<span>{{ __('ui.sort') }}</span></button>
                <div class="dd-menu" data-dd-menu="sort">
                    @foreach ($sorts as $s)
                        <button type="button" data-sort="{{ $s }}">{{ __('ui.sort_'.$s) }}</button>
                    @endforeach
                </div>
            </div>
            <div class="dropdown">
                <button class="tool c4" type="button" data-dd="view" title="{{ __('ui.view') }}">@include('partials.icon', ['i' => 'layout-grid', 'class' => 'view-ic'])<span>{{ __('ui.view') }}</span></button>
                <div class="dd-menu" data-dd-menu="view">
                    @foreach ($views as $v => $ic)
                        <button type="button" data-view="{{ $v }}">@include('partials.icon', ['i' => $ic])
                            <span><b>{{ __('ui.view_'.$v) }}</b><small>{{ __('ui.view_'.$v.'_hint') }}</small></span></button>
                    @endforeach
                </div>
            </div>
            @if (auth()->user()->is_admin)
                <button class="tool c5 admin-only" type="button" data-act="refresh" title="{{ __('ui.refresh') }}">@include('partials.icon', ['i' => 'refresh-cw'])</button>
                <button class="tool scan-btn admin-only" type="button" data-act="scan" title="{{ __('ui.scan') }}">@include('partials.icon', ['i' => 'scan-search', 'class' => 'scan-ic'])<span class="scan-label">{{ __('ui.scan') }}</span></button>
            @endif
        </div>
    </div>

    <form class="searchpanel card" id="searchpanel" hidden autocomplete="off">
        <div class="sp-main">
            <span class="input-ic grow">@include('partials.icon', ['i' => 'search'])
                <input type="search" name="q" placeholder="{{ __('ui.search_placeholder') }}" maxlength="200">
            </span>
            <button class="btn primary" type="submit">{{ __('ui.apply') }}</button>
        </div>
        <div class="sp-row">
            <label class="check" data-here-wrap><input type="checkbox" name="here" value="1"> <span>{{ __('ui.search_here') }}</span></label>
            <label class="field inline"><span>{{ __('ui.date_from') }}</span><input type="text" name="from" data-datepicker readonly placeholder="—"></label>
            <label class="field inline"><span>{{ __('ui.date_to') }}</span><input type="text" name="to" data-datepicker readonly placeholder="—"></label>
            <button type="button" class="btn ghost small" data-act="more-filters">@include('partials.icon', ['i' => 'funnel']) {{ __('ui.filters') }}</button>
        </div>
        <div class="sp-more" hidden>
            <label class="field"><span>{{ __('ui.filter_type') }}</span>
                <select name="type"><option value="">{{ __('ui.all_files') }}</option><option value="image">{{ __('ui.images') }}</option><option value="video">{{ __('ui.videos') }}</option><option value="other">{{ __('ui.others') }}</option></select>
            </label>
            <label class="field"><span>{{ __('ui.filter_tag') }}</span><input name="tag" data-suggest="tags" maxlength="100"></label>
            <label class="field"><span>{{ __('ui.filter_person') }}</span><input name="person" data-suggest="persons" maxlength="150"></label>
            <label class="field"><span>{{ __('ui.filter_camera') }}</span><input name="camera" data-suggest="cameras" maxlength="150"></label>
            <label class="field"><span>{{ __('ui.filter_place') }}</span><input name="place" data-suggest="places" maxlength="150"></label>
            <div class="checks">
                <label class="check"><input type="checkbox" name="fav" value="1"> <span>{{ __('ui.filter_fav') }}</span></label>
                <label class="check"><input type="checkbox" name="gps" value="1"> <span>{{ __('ui.filter_gps') }}</span></label>
                <label class="check"><input type="checkbox" name="described" value="1"> <span>{{ __('ui.filter_described') }}</span></label>
            </div>
        </div>
        <div class="sp-foot">
            <small class="muted">@include('partials.icon', ['i' => 'info']) {{ __('ui.search_note') }}</small>
            <button type="reset" class="btn ghost small">{{ __('ui.clear') }}</button>
        </div>
    </form>

    @if (auth()->user()->is_admin)
        <div class="scanbar card" id="scanbar" hidden>
            @include('partials.icon', ['i' => 'loader-circle', 'class' => 'spin'])
            <div class="scanbar-text"><b data-scan-title></b><small data-scan-file></small></div>
            <div class="progress"><span data-scan-bar></span></div>
            <button type="button" class="btn small danger" data-act="scan">@include('partials.icon', ['i' => 'circle-stop']) {{ __('ui.stop_scan') }}</button>
        </div>
    @endif

    <section id="content" class="content view-medium" aria-live="polite"></section>
</div>
@endsection
