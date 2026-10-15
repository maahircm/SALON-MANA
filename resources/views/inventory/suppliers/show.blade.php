@extends('layouts.app')
@section('title', $supplier->name)
@section('content')
<div class="row g-4"><div class="col-lg-4"><div class="card"><div class="card-body"><h2 class="h5">{{ $supplier->name }}</h2><span class="badge text-bg-{{ $supplier->is_active ? 'success' : 'secondary' }}">{{ $supplier->is_active ? 'Active' : 'Inactive' }}</span>
    <dl class="row small mt-3 mb-0"><dt class="col-4">Contact</dt><dd class="col-8">{{ $supplier->contact_person ?? '—' }}</dd><dt class="col-4">Phone</dt><dd class="col-8">{{ $supplier->phone ?? '—' }}</dd><dt class="col-4">Email</dt><dd class="col-8 text-break">{{ $supplier->email ?? '—' }}</dd><dt class="col-4">Address</dt><dd class="col-8">{{ $supplier->address ?? '—' }}</dd></dl>
    @if ($supplier->notes)<hr><div class="small">{{ $supplier->notes }}</div>@endif</div>
    <div class="card-footer bg-transparent d-flex gap-2">@can('inventory.suppliers')<a class="btn btn-sm btn-outline-secondary" href="{{ route('suppliers.edit', $supplier) }}">Edit</a>@endcan @can('inventory.purchasing')<a class="btn btn-sm btn-primary" href="{{ route('purchase-orders.create', ['supplier' => $supplier->id]) }}">New order</a>@endcan</div></div></div>
<div class="col-lg-8"><div class="card mb-3"><div class="card-header">Recent purchase orders</div><x-table :headers="['Order', 'Date', 'Status', 'Total']">
    @forelse ($orders as $o)<tr><td>@can('inventory.purchasing')<a href="{{ route('purchase-orders.show', $o) }}">{{ $o->po_number ?? 'Draft #'.$o->id }}</a>@else{{ $o->po_number ?? 'Draft #'.$o->id }}@endcan</td><td>{{ $o->created_at->format(config('salon.date_format')) }}</td><td>{{ \App\Models\PurchaseOrder::STATUSES[$o->status] }}</td><td>{{ money($o->total) }}</td></tr>@empty<tr><td colspan="4" class="text-muted">No orders.</td></tr>@endforelse</x-table></div>
    <div class="card"><div class="card-header">Products supplied</div><ul class="list-group list-group-flush">@forelse ($products as $p)<li class="list-group-item small">{{ $p->sku }} — {{ $p->name }}</li>@empty<li class="list-group-item text-muted small">None assigned.</li>@endforelse</ul></div></div></div>
@endsection
