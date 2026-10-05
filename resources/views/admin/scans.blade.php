@extends('layouts.app')
@section('title', __('ui.scan_jobs'))

@section('content')
<div class="wide">
    <h1 class="page-title">@include('partials.icon', ['i' => 'scan-search']) {{ __('ui.scan_jobs') }}</h1>
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
</div>
@endsection
