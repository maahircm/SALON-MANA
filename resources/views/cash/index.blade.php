@extends('layouts.app')
@section('title', 'Cash drawer')
@section('content')
<div class="row g-4">
<div class="col-lg-4">
    @if ($current)
    <div class="card border-success"><div class="card-header text-success">Your drawer is open</div><div class="card-body">
        <div>{{ $current->drawer->name }} · since {{ salon_datetime($current->opened_at) }}</div><div class="text-muted">Opening cash {{ money($current->opening_cash) }}</div>
        <a class="btn btn-primary mt-3" href="{{ route('cash.show', $current) }}">Manage / close drawer</a></div></div>
    @else @can('pos.drawer')
    <div class="card"><div class="card-header">Open a drawer session</div><div class="card-body">
        <form method="post" action="{{ route('cash.open') }}">@csrf
            <x-select name="cash_drawer_id" label="Drawer" :options="$drawers->pluck('name', 'id')->all()" />
            <x-input name="opening_cash" label="Opening cash counted ({{ config('salon.currency') }})" type="number" step="0.01" min="0" required />
            <button class="btn btn-primary w-100">Open drawer</button></form></div></div>
    @else<x-alert type="info">You cannot operate a cash drawer.</x-alert>@endcan @endif
    @can('pos.close')<div class="d-grid gap-2 mt-3"><a class="btn btn-outline-secondary" href="{{ route('cash.drawers') }}">Manage drawers</a><a class="btn btn-outline-secondary" href="{{ route('cash.daily') }}">Daily summary</a></div>@endcan
</div>
<div class="col-lg-8"><div class="card"><div class="card-header">Sessions</div>
    <x-table :headers="['#', 'Drawer', 'Cashier', 'Opened', 'Closed', 'Variance', '']">
        @forelse ($sessions as $s)<tr><td>{{ $s->id }}</td><td>{{ $s->drawer->name }}</td><td>{{ $s->opener->name }}</td><td class="text-nowrap">{{ salon_datetime($s->opened_at) }}</td>
            <td class="text-nowrap">{{ $s->isOpen() ? 'Open' : salon_datetime($s->closed_at) }}</td>
            <td class="{{ $s->variance !== null && \App\Support\Money::compare($s->variance, '0') !== 0 ? 'text-danger fw-semibold' : '' }}">{{ $s->variance !== null ? money($s->variance) : '—' }}</td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('cash.show', $s) }}">Open</a></td></tr>
        @empty<tr><td colspan="7"><x-empty-state icon="bi-cash-stack" title="No sessions yet" /></td></tr>@endforelse</x-table>
    <x-pagination :paginator="$sessions" /></div></div></div>
@endsection
