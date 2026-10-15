@extends('layouts.app')
@section('title', $product->name)
@section('content')
@php $costs = auth()->user()->can('inventory.costs'); @endphp
<div class="row g-4">
<div class="col-lg-4"><div class="card mb-3"><div class="card-body">
    <div class="text-muted small">{{ $product->sku }}@if ($product->barcode) · {{ $product->barcode }}@endif</div><h2 class="h5">{{ $product->name }}</h2>
    <span class="badge text-bg-{{ $product->is_active ? 'success' : 'secondary' }}">{{ $product->is_active ? 'Active' : 'Inactive' }}</span>
    @if (! $product->is_sellable)<span class="badge text-bg-light border">consumable</span>@endif @if ($product->isLowStock())<span class="badge text-bg-danger">Low stock</span>@endif
    <div class="display-6 mt-3 {{ $product->isLowStock() ? 'text-danger' : '' }}">{{ qty($product->current_stock) }} <small class="fs-6 text-muted">{{ $product->unit }}</small></div>
    <dl class="row small mt-2 mb-0">
        <dt class="col-6">Reorder level</dt><dd class="col-6">{{ qty($product->reorder_level) }}</dd>
        <dt class="col-6">Category</dt><dd class="col-6">{{ $product->category?->name ?? '—' }}</dd>
        <dt class="col-6">Supplier</dt><dd class="col-6">{{ $product->supplier?->name ?? '—' }}</dd>
        <dt class="col-6">Selling price</dt><dd class="col-6">{{ $product->is_sellable ? money($product->selling_price).' (+'.rtrim(rtrim((string) $product->tax_rate, '0'), '.').'% tax)' : '—' }}</dd>
        @if ($costs)<dt class="col-6">Average cost</dt><dd class="col-6">{{ money($product->cost_price) }}</dd><dt class="col-6">Stock value</dt><dd class="col-6">{{ money($product->stockValue()) }}</dd>@endif
        <dt class="col-6">Expiry</dt><dd class="col-6">{{ $product->expiry_date?->format('d M Y') ?? '—' }}</dd>
    </dl>@if ($product->description)<hr><div class="small">{{ $product->description }}</div>@endif</div>
    <div class="card-footer bg-transparent d-flex flex-wrap gap-2">@can('inventory.manage')<a class="btn btn-sm btn-outline-secondary" href="{{ route('products.edit', $product) }}">Edit</a>
        <form method="post" action="{{ route('products.destroy', $product) }}" data-confirm="Delete {{ $product->name }}? Only possible without history.">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form>@endcan
        @can('inventory.adjust')<a class="btn btn-sm btn-outline-primary" href="{{ route('adjustments.index', ['product' => $product->id]) }}">Adjust stock</a>@endcan</div></div>
    @if ($consumedBy->isNotEmpty())<div class="card"><div class="card-header">Used by services</div><ul class="list-group list-group-flush">@foreach ($consumedBy as $c)<li class="list-group-item small">{{ $c->service->name }} — {{ qty($c->quantity) }} {{ $product->unit }} each</li>@endforeach</ul></div>@endif
</div>
<div class="col-lg-8"><div class="card"><div class="card-header d-flex justify-content-between">Recent stock movements <a class="small" href="{{ route('movements.index', ['product' => $product->id]) }}">Full ledger</a></div>
    <x-table :headers="['When', 'Type', 'Change', 'Balance', 'Reason', 'By']">
        @forelse ($movements as $m)<tr><td class="text-nowrap">{{ salon_datetime($m->created_at) }}</td><td>{{ \App\Models\StockMovement::LABELS[$m->type] ?? $m->type }}</td>
            <td class="{{ \App\Support\Quantity::compare($m->quantity, '0') < 0 ? 'text-danger' : 'text-success' }}">{{ \App\Support\Quantity::compare($m->quantity, '0') > 0 ? '+' : '' }}{{ qty($m->quantity) }}</td><td>{{ qty($m->balance_after) }}</td><td class="small">{{ $m->reason }}</td><td>{{ $m->user?->name ?? 'system' }}</td></tr>
        @empty<tr><td colspan="6" class="text-muted">No movements yet.</td></tr>@endforelse</x-table></div></div></div>
@endsection
