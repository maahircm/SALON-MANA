@extends('layouts.app')
@section('title', 'Returns to suppliers')
@section('content')
<div class="row g-4"><div class="col-lg-4"><div class="card"><div class="card-header">Return goods to a supplier</div><div class="card-body">
    <form method="post" action="{{ route('supplier-returns.store') }}">@csrf
        <x-select name="supplier_id" label="Supplier" :options="$suppliers->pluck('name', 'id')->all()" placeholder="Select…" />
        <x-select name="product_id" label="Product" :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->sku.' — '.$p->name.' ('.qty($p->current_stock).' '.$p->unit.')'])->all()" placeholder="Select…" />
        <x-input name="quantity" label="Quantity" type="number" step="0.001" min="0" required /><x-input name="reason" label="Reason (required)" required />
        <button class="btn btn-primary w-100">Record return</button></form></div></div></div>
<div class="col-lg-8"><div class="card"><div class="card-header">Returns</div><x-table :headers="['Return', 'When', 'Supplier', 'Product', 'Quantity', 'Reason', 'By']">
    @forelse ($returns as $r)<tr><td>{{ $r->return_number }}</td><td class="text-nowrap">{{ salon_datetime($r->created_at) }}</td><td>{{ $r->supplier->name }}</td><td>{{ $r->product->name }}</td><td>{{ qty($r->quantity) }} {{ $r->product->unit }}</td><td class="small">{{ $r->reason }}</td><td>{{ $r->creator->name }}</td></tr>
    @empty<tr><td colspan="7"><x-empty-state title="No returns yet" /></td></tr>@endforelse</x-table><x-pagination :paginator="$returns" /></div></div></div>
@endsection
