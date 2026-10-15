@extends('layouts.app')
@section('title', 'Purchase orders')
@section('content')
<div class="card"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <form class="d-flex gap-2" method="get"><input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="PO number" aria-label="Search"><select class="form-select form-select-sm" name="status" aria-label="Status"><option value="">Any status</option>@foreach (\App\Models\PurchaseOrder::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
        <select class="form-select form-select-sm" name="supplier" aria-label="Supplier"><option value="">All suppliers</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) request('supplier') === $s->id)>{{ $s->name }}</option>@endforeach</select><button class="btn btn-sm btn-outline-secondary">Filter</button></form>
    <a class="btn btn-sm btn-primary" href="{{ route('purchase-orders.create') }}"><i class="bi bi-plus-lg"></i> New purchase order</a></div>
    <x-table :headers="['Order', 'Supplier', 'Status', 'Expected', 'Total', '']">
        @forelse ($orders as $o)<tr><td><a href="{{ route('purchase-orders.show', $o) }}">{{ $o->po_number ?? 'Draft #'.$o->id }}</a></td><td>{{ $o->supplier->name }}</td><td><span class="badge text-bg-{{ ['draft' => 'secondary', 'ordered' => 'primary', 'partially_received' => 'warning', 'received' => 'success', 'cancelled' => 'dark'][$o->status] }}">{{ \App\Models\PurchaseOrder::STATUSES[$o->status] }}</span></td>
            <td>{{ $o->expected_on?->format(config('salon.date_format')) ?? '—' }}</td><td>{{ money($o->total) }}</td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase-orders.show', $o) }}">Open</a></td></tr>
        @empty<tr><td colspan="6"><x-empty-state icon="bi-clipboard-check" title="No purchase orders" /></td></tr>@endforelse</x-table>
    <x-pagination :paginator="$orders" /></div>
@endsection
