@extends('layouts.app')
@section('title', $order->po_number ?? 'Draft purchase order #'.$order->id)
@section('content')
<div class="row g-4"><div class="col-lg-8">
    <div class="card mb-3"><div class="card-body d-flex justify-content-between flex-wrap gap-2"><div><h2 class="h5 mb-1">{{ $order->po_number ?? 'Draft #'.$order->id }} <span class="badge text-bg-{{ ['draft' => 'secondary', 'ordered' => 'primary', 'partially_received' => 'warning', 'received' => 'success', 'cancelled' => 'dark'][$order->status] }}">{{ \App\Models\PurchaseOrder::STATUSES[$order->status] }}</span></h2>
        <div>Supplier: <a href="{{ route('suppliers.show', $order->supplier) }}">{{ $order->supplier->name }}</a></div><div class="text-muted small">Expected {{ $order->expected_on?->format(config('salon.date_format')) ?? '—' }} · created by {{ $order->creator?->name }}</div>
        @if ($order->notes)<div class="small mt-1">{{ $order->notes }}</div>@endif @if ($order->cancel_reason)<div class="small mt-1 text-danger">Cancelled: {{ $order->cancel_reason }}</div>@endif</div><div class="fs-4 fw-bold">{{ money($order->total) }}</div></div>
    <x-table :headers="['Product', 'Ordered', 'Received', 'Outstanding', 'Unit cost', 'Line total']">
        @foreach ($order->items as $i)<tr><td>{{ $i->product->sku }} — {{ $i->product->name }}</td><td>{{ qty($i->quantity_ordered) }}</td><td>{{ qty($i->quantity_received) }}</td><td>{{ qty($i->outstanding()) }}</td><td>{{ money($i->unit_cost) }}</td><td>{{ \App\Support\Quantity::value($i->quantity_ordered, $i->unit_cost) !== '' ? money(\App\Support\Quantity::value($i->quantity_ordered, $i->unit_cost)) : '' }}</td></tr>@endforeach</x-table></div>

    @if ($order->canReceive())
    <div class="card mb-3 border-success-subtle"><div class="card-header">Receive goods</div><div class="card-body">
        <form method="post" action="{{ route('purchase-orders.receive', $order) }}" data-confirm="Record this delivery? Stock will be updated.">@csrf
            <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Product</th><th>Outstanding</th><th style="width:140px">Received now</th><th style="width:170px">Expiry (optional)</th></tr></thead><tbody>
                @foreach ($order->items as $i) @if (\App\Support\Quantity::compare($i->outstanding(), '0') > 0)<tr><td>{{ $i->product->name }}</td><td>{{ qty($i->outstanding()) }} {{ $i->product->unit }}</td>
                    <td><input class="form-control form-control-sm" type="number" step="0.001" min="0" name="items[{{ $i->id }}][quantity]" value="{{ old('items.'.$i->id.'.quantity') }}" aria-label="Received quantity of {{ $i->product->name }}"></td>
                    <td><input class="form-control form-control-sm" type="date" name="items[{{ $i->id }}][expiry_date]" value="{{ old('items.'.$i->id.'.expiry_date') }}" aria-label="Expiry of {{ $i->product->name }}"></td></tr>@endif @endforeach</tbody></table></div>
            <div class="row"><div class="col-md-6"><x-input name="supplier_reference" label="Supplier delivery note / invoice no." /></div><div class="col-md-6"><x-input name="notes" label="Notes" /></div></div>
            <button class="btn btn-success">Record goods receipt</button></form></div></div>
    @endif

    @if ($order->receipts->isNotEmpty())<div class="card"><div class="card-header">Goods receipts</div><div class="card-body">
        @foreach ($order->receipts as $r)<div class="mb-3"><strong>{{ $r->receipt_number }}</strong> <span class="text-muted small">{{ salon_datetime($r->received_at) }} · {{ $r->receiver->name }}@if ($r->supplier_reference) · ref {{ $r->supplier_reference }}@endif</span>
            <ul class="small mb-0">@foreach ($r->items as $ri)<li>{{ qty($ri->quantity) }} × {{ $ri->product->name }} @ {{ money($ri->unit_cost) }}@if ($ri->expiry_date) (exp {{ $ri->expiry_date->format('d M Y') }})@endif</li>@endforeach</ul></div>@endforeach</div></div>@endif
</div>
<div class="col-lg-4"><div class="card"><div class="card-header">Actions</div><div class="card-body d-grid gap-2">
    @if ($order->isEditable())<a class="btn btn-outline-secondary" href="{{ route('purchase-orders.edit', $order) }}">Edit draft</a>
        <form method="post" action="{{ route('purchase-orders.place', $order) }}" data-confirm="Place this order with the supplier?">@csrf<button class="btn btn-primary w-100">Place order</button></form>@endif
    @if (in_array($order->status, ['draft', 'ordered']) && $order->receipts->isEmpty())
        <form method="post" action="{{ route('purchase-orders.cancel', $order) }}" data-confirm="Cancel this purchase order?">@csrf<x-input name="reason" label="Cancellation reason" required /><button class="btn btn-outline-danger w-100">Cancel order</button></form>@endif
    @if ($order->status === 'partially_received')
        <form method="post" action="{{ route('purchase-orders.close', $order) }}" data-confirm="Close short? The undelivered balance will be abandoned.">@csrf<x-input name="reason" label="Why close short?" required /><button class="btn btn-outline-warning w-100">Close short</button></form>@endif
    <a class="btn btn-light" href="{{ route('purchase-orders.index') }}">All orders</a></div></div></div></div>
@endsection
