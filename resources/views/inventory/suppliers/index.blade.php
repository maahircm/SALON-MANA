@extends('layouts.app')
@section('title', 'Suppliers')
@section('content')
<div class="card"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <form class="d-flex gap-2" method="get"><input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="Name, contact or phone" aria-label="Search suppliers"><select class="form-select form-select-sm" name="status" aria-label="Status"><option value="">All</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select><button class="btn btn-sm btn-outline-secondary">Filter</button></form>
    @can('inventory.suppliers')<a class="btn btn-sm btn-primary" href="{{ route('suppliers.create') }}"><i class="bi bi-plus-lg"></i> New supplier</a>@endcan</div>
    <x-table :headers="['Supplier', 'Contact', 'Phone', 'Email', 'Products', 'Status', '']">
        @forelse ($suppliers as $s)<tr><td><a href="{{ route('suppliers.show', $s) }}">{{ $s->name }}</a></td><td>{{ $s->contact_person }}</td><td>{{ $s->phone }}</td><td>{{ $s->email }}</td><td>{{ $s->products_count }}</td>
            <td><span class="badge text-bg-{{ $s->is_active ? 'success' : 'secondary' }}">{{ $s->is_active ? 'Active' : 'Inactive' }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('suppliers.show', $s) }}">View</a></td></tr>
        @empty<tr><td colspan="7"><x-empty-state icon="bi-truck" title="No suppliers yet" /></td></tr>@endforelse</x-table>
    <x-pagination :paginator="$suppliers" /></div>
@endsection
