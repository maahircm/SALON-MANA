@extends('layouts.app')
@section('title', 'Products')
@section('content')
@php $costs = auth()->user()->can('inventory.costs'); @endphp
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <form class="d-flex flex-wrap gap-2" method="get">
            <input class="form-control form-control-sm" style="width:210px" name="q" value="{{ request('q') }}" placeholder="Name, SKU or barcode" aria-label="Search products">
            <select class="form-select form-select-sm" name="category" aria-label="Category" style="width:auto"><option value="">All categories</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected((int) request('category') === $c->id)>{{ $c->name }}</option>@endforeach</select>
            <select class="form-select form-select-sm" name="supplier" aria-label="Supplier" style="width:auto"><option value="">All suppliers</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) request('supplier') === $s->id)>{{ $s->name }}</option>@endforeach</select>
            <select class="form-select form-select-sm" name="status" aria-label="Status" style="width:auto"><option value="">Any status</option><option value="low" @selected(request('status') === 'low')>Low stock</option><option value="expiring" @selected(request('status') === 'expiring')>Expiring soon</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
        <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('product-categories.index') }}">Categories</a>
            @can('inventory.manage')<a class="btn btn-sm btn-primary" href="{{ route('products.create') }}"><i class="bi bi-plus-lg"></i> New product</a>@endcan</div>
    </div>
    <x-table :headers="array_filter(['SKU', 'Product', 'Category', 'In stock', 'Reorder at', $costs ? 'Cost' : null, 'Price', 'Status', ''])">
        @forelse ($products as $p)
        <tr><td>{{ $p->sku }}</td><td><a href="{{ route('products.show', $p) }}">{{ $p->name }}</a>@if (! $p->is_sellable) <span class="badge text-bg-light border">consumable</span>@endif</td><td>{{ $p->category?->name ?? '—' }}</td>
            <td class="{{ $p->isLowStock() ? 'text-danger fw-semibold' : '' }}">{{ qty($p->current_stock) }} {{ $p->unit }} @if ($p->isLowStock())<i class="bi bi-exclamation-triangle" title="At or below reorder level"></i>@endif</td>
            <td>{{ qty($p->reorder_level) }}</td>@if ($costs)<td>{{ money($p->cost_price) }}</td>@endif<td>{{ $p->is_sellable ? money($p->selling_price) : '—' }}</td>
            <td><span class="badge text-bg-{{ $p->is_active ? 'success' : 'secondary' }}">{{ $p->is_active ? 'Active' : 'Inactive' }}</span>@if ($p->expiry_date && $p->expiry_date->isPast()) <span class="badge text-bg-danger">Expired</span>@endif</td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('products.show', $p) }}">View</a></td></tr>
        @empty<tr><td colspan="9"><x-empty-state icon="bi-box-seam" title="No products found">@can('inventory.manage')<a href="{{ route('products.create') }}">Add the first product</a>@endcan</x-empty-state></td></tr>@endforelse
    </x-table>
    <x-pagination :paginator="$products" />
</div>
@endsection
