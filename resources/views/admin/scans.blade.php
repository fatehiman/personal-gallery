@extends('layouts.app')
@section('title', __('ui.scan_jobs'))

@push('scripts')
    <script src="{{ \App\Support\Asset::url('assets/admin.js') }}" defer></script>
@endpush

@section('content')
<div class="wide">
    <h1 class="page-title">@include('partials.icon', ['i' => 'scan-search']) {{ __('ui.scan_jobs') }}</h1>

    {{-- The report is heavy (it counts the whole media table): it is never loaded with the page, only by the button. --}}
    {{-- A saved report (if any) is read by Ajax when the page opens: one row, cheap. --}}
    <section class="card stats-card" id="scan-stats" data-has-report="{{ $hasReport ? 1 : 0 }}">
        <div class="stats-head">
            <div>
                <h2>@include('partials.icon', ['i' => 'chart-pie']) {{ __('ui.stats_title') }}</h2>
                <p class="muted small">{{ __('ui.stats_hint') }}</p>
            </div>
            <button type="button" class="btn primary" data-stats-load>
                @include('partials.icon', ['i' => 'refresh-cw']) <span data-stats-label>{{ $hasReport ? __('ui.stats_refresh') : __('ui.stats_generate') }}</span>
            </button>
        </div>
        <div class="stats-body" data-stats-body aria-live="polite">
            @if ($hasReport)
                <p class="stats-empty muted">@include('partials.icon', ['i' => 'loader-circle', 'class' => 'spin']) {{ __('ui.stats_loading_saved') }}</p>
            @else
                <p class="stats-empty muted">@include('partials.icon', ['i' => 'square-dashed']) {{ __('ui.stats_placeholder') }}</p>
            @endif
        </div>
    </section>

    <div class="card table-card">
        @if ($jobs->isEmpty())
            <p class="muted">{{ __('ui.no_jobs') }}</p>
        @else
            <div class="table-scroll">
                <table class="table">
                    <thead><tr>
                        <th>#</th><th>{{ __('ui.folder') }}</th><th>{{ __('ui.status') }}</th><th>{{ __('ui.progress') }}</th>
                        <th>{{ __('ui.errors') }}</th><th>{{ __('ui.started') }}</th><th>{{ __('ui.finished') }}</th><th>{{ __('ui.by') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($jobs as $j)
                        <tr>
                            <td>{{ $j->id }}</td>
                            <td dir="ltr"><code>/{{ $j->path }}</code>@if ($j->recursive) <small class="muted">+</small>@endif
                                @if ($j->message)<br><small class="muted">{{ $j->message }}</small>@endif</td>
                            <td><span class="chip status-{{ $j->status }}">{{ __('ui.status_'.$j->status) }}</span></td>
                            <td>{{ $j->processed }} / {{ $j->total }}</td>
                            <td>{{ $j->errors }}</td>
                            <td>@if ($j->started_at)<time data-dt="{{ $j->started_at->utc()->toIso8601ZuluString() }}"></time>@endif</td>
                            <td>@if ($j->finished_at)<time data-dt="{{ $j->finished_at->utc()->toIso8601ZuluString() }}"></time>@endif</td>
                            <td>{{ $j->user?->name }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <h2 class="section-h">@include('partials.icon', ['i' => 'clock']) {{ __('ui.crawl_log') }}</h2>
    <p class="muted small">{{ __('ui.crawl_log_hint') }}</p>
    <div class="card table-card">
        @if ($logs->isEmpty())
            <p class="muted">{{ __('ui.crawl_log_empty') }}</p>
        @else
            <div class="table-scroll">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('ui.started') }}</th><th>{{ __('ui.crawl_secs') }}</th><th>{{ __('ui.crawl_from_to') }}</th>
                        <th>{{ __('ui.crawl_folders') }}</th><th>{{ __('ui.crawl_new') }}</th><th>{{ __('ui.crawl_read') }}</th>
                        <th>{{ __('ui.errors') }}</th><th>{{ __('ui.crawl_note') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($logs as $l)
                        <tr>
                            <td><time data-dt="{{ \Illuminate\Support\Carbon::parse($l->started_at, 'UTC')->toIso8601ZuluString() }}"></time></td>
                            <td>{{ $l->seconds }}</td>
                            <td dir="ltr"><code>/{{ $l->from_path }}</code>@if ($l->to_path !== $l->from_path) → <code>/{{ $l->to_path }}</code>@endif</td>
                            <td>{{ $l->folders }} <small class="muted">({{ $l->listed }})</small></td>
                            <td>{{ $l->new_files }}</td>
                            <td>{{ $l->scanned }}</td>
                            <td>{{ $l->errors }}</td>
                            <td><small class="muted">{{ $l->note }}</small></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
