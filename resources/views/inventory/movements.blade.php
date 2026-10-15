@extends('layouts.app')
@section('title', 'Stock ledger')
@section('content')
@php $costs = auth()->user()->can('inventory.costs'); @endphp
<div class="card"><div class="card-header"><form class="row g-2" method="get">
    <div class="col-md-3"><select class="form-select form-select-sm" name="product" aria-label="Product"><option value="">All products</option>@foreach ($products as $p)<option value="{{ $p->id }}" @selected((int) request('product') === $p->id)>{{ $p->sku }} — {{ $p->name }}</option>@endforeach</select></div>
    <div class="col-md-3"><select class="form-select form-select-sm" name="type" aria-label="Type"><option value="">All types</option>@foreach (\App\Models\StockMovement::LABELS as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="from" value="{{ request('from') }}" aria-label="From"></div><div class="col-md-2"><input class="form-control form-control-sm" type="date" name="to" value="{{ request('to') }}" aria-label="To"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary">Filter</button></div></form></div>
    <x-table :headers="array_filter(['When', 'Product', 'Type', 'Change', 'Balance', $costs ? 'Unit cost' : null, 'Reference', 'Reason', 'By'])">
        @forelse ($movements as $m)<tr><td class="text-nowrap">{{ salon_datetime($m->created_at) }}</td><td><a href="{{ route('products.show', $m->product_id) }}">{{ $m->product->sku }}</a> <span class="small text-muted">{{ $m->product->name }}</span></td><td>{{ \App\Models\StockMovement::LABELS[$m->type] ?? $m->type }}</td>
            <td class="{{ \App\Support\Quantity::compare($m->quantity, '0') < 0 ? 'text-danger' : 'text-success' }}">{{ \App\Support\Quantity::compare($m->quantity, '0') > 0 ? '+' : '' }}{{ qty($m->quantity) }} {{ $m->product->unit }}</td><td>{{ qty($m->balance_after) }}</td>
            @if ($costs)<td>{{ money($m->unit_cost) }}</td>@endif<td class="small text-muted">{{ $m->reference_type }} {{ $m->reference_id }}</td><td class="small">{{ $m->reason }}</td><td>{{ $m->user?->name ?? 'system' }}</td></tr>
        @empty<tr><td colspan="9"><x-empty-state icon="bi-journal-text" title="No movements match" /></td></tr>@endforelse</x-table>
    <x-pagination :paginator="$movements" /></div>
<div class="text-muted small mt-2">The ledger is append-only: corrections are new, opposite entries.</div>
@endsection
