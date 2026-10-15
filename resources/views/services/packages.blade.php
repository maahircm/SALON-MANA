@extends('layouts.app')
@section('title', 'Service packages')
@section('content')
<div class="card"><div class="card-header d-flex justify-content-between align-items-center">Packages @can('services.manage')<a class="btn btn-sm btn-primary" href="{{ route('packages.create') }}"><i class="bi bi-plus-lg"></i> New package</a>@endcan</div>
<x-table :headers="['Package', 'Includes', 'Price', 'Validity', 'Status', '']">
    @forelse ($packages as $p)<tr><td>{{ $p->name }}</td><td>@foreach ($p->items as $i){{ $i->quantity }} × {{ $i->service->name }}@if (! $loop->last), @endif @endforeach</td><td>{{ money($p->price) }}</td>
        <td>{{ $p->validity_days ? $p->validity_days.' days' : 'No expiry' }}</td><td><span class="badge text-bg-{{ $p->is_active ? 'success' : 'secondary' }}">{{ $p->is_active ? 'Active' : 'Inactive' }}</span></td>
        <td class="text-end text-nowrap">@can('services.manage')<a class="btn btn-sm btn-outline-secondary" href="{{ route('packages.edit', $p) }}">Edit</a>
            <x-confirm-form method="delete" :action="route('packages.destroy', $p)" message="Delete package {{ $p->name }}?" label="Delete" />@endcan</td></tr>
    @empty<tr><td colspan="6"><x-empty-state icon="bi-box2-heart" title="No packages yet" /></td></tr>@endforelse
</x-table></div>
@endsection
