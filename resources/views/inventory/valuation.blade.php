@extends('layouts.app')
@section('title', 'Inventory valuation')
@section('content')
<div class="row g-3 mb-3"><div class="col-md-4"><x-kpi-card label="Total stock value" :value="money($total)" icon="bi-boxes" hint="at weighted-average cost" /></div><div class="col-md-4"><x-kpi-card label="Active products" :value="$products->count()" icon="bi-box-seam" /></div></div>
<div class="card"><x-table :headers="['SKU', 'Product', 'Category', 'In stock', 'Avg cost', 'Value']">
    @forelse ($products as $p)<tr><td>{{ $p->sku }}</td><td>{{ $p->name }}</td><td>{{ $p->category?->name ?? '—' }}</td><td>{{ qty($p->current_stock) }} {{ $p->unit }}</td><td>{{ money($p->cost_price) }}</td><td>{{ money($p->stockValue()) }}</td></tr>@empty<tr><td colspan="6"><x-empty-state title="No products" /></td></tr>@endforelse
    <tr class="table-light fw-bold"><td colspan="5" class="text-end">Total</td><td>{{ money($total) }}</td></tr></x-table></div>
<div class="text-muted small mt-2">Valuation = stock on hand × weighted-average cost (updated on each goods receipt). Consumables count as stock until used.</div>
@endsection
