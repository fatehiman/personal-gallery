@php($url = $u->avatarUrl())
@if ($url)
    <img class="avatar avatar-{{ $size ?? 'md' }}" src="{{ $url }}" alt="" loading="lazy">
@else
    <span class="avatar avatar-{{ $size ?? 'md' }} avatar-initials c{{ $u->id % 6 }}" aria-hidden="true">{{ $u->initials() }}</span>
@endif
