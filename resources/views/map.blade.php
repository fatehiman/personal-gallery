@extends('layouts.app')
@section('title', __('ui.map'))
@section('body_class', 'map-page')
@push('head')
    <link rel="stylesheet" href="/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="/vendor/markercluster/MarkerCluster.css">
    <link rel="stylesheet" href="/vendor/markercluster/MarkerCluster.Default.css">
    <link rel="stylesheet" href="/vendor/videojs/video-js.min.css">
@endpush
@push('scripts')
    <script src="/vendor/leaflet/leaflet.js" defer></script>
    <script src="/vendor/markercluster/leaflet.markercluster.js" defer></script>
    <script src="/vendor/videojs/video.min.js" defer></script>
    <script src="{{ \App\Support\Asset::url('assets/viewer.js') }}" defer></script>
    <script src="{{ \App\Support\Asset::url('assets/map.js') }}" defer></script>
@endpush

@section('content')
    <div class="card map-card">
        <div class="map-head">
            <h1>@include('partials.icon', ['i' => 'map']) {{ __('ui.map') }}</h1>
            <span class="muted" id="map-count"></span>
        </div>
        <div id="map" class="map"></div>
        <p class="muted empty-note" id="map-empty" hidden>{{ __('ui.map_empty') }}</p>
    </div>
@endsection
