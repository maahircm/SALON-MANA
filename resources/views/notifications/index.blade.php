@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Your notifications</span>
    <form method="post" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-sm btn-outline-secondary">Mark all as read</button></form></div>
    <ul class="list-group list-group-flush">
    @forelse ($notifications as $n)
        <li class="list-group-item d-flex justify-content-between align-items-start gap-3 {{ $n->isUnread() ? 'bg-body-tertiary' : '' }}">
            <div><div class="fw-semibold">@if ($n->isUnread())<span class="badge text-bg-primary me-1">New</span>@endif{{ $n->title }}</div><div class="text-muted small" style="white-space:pre-line">{{ $n->body }}</div><div class="small text-muted">{{ $n->created_at->format(config('salon.date_format').' '.config('salon.time_format')) }}</div></div>
            <form method="post" action="{{ route('notifications.read', $n) }}">@csrf<button class="btn btn-sm btn-outline-secondary">{{ $n->url ? 'Open' : 'Mark read' }}</button></form></li>
    @empty<li class="list-group-item"><x-empty-state icon="bi-bell" title="You have no notifications" /></li>@endforelse
    </ul><x-pagination :paginator="$notifications" /></div>
@endsection
