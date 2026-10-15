@extends('layouts.app')
@section('title', 'Invoice '.$invoice->invoice_number)
@php
    $balance = $invoice->balanceDue();
    $refundable = $invoice->refundableAmount();
    $paymentKey = (string) \Illuminate\Support\Str::uuid();
    $refundKey = (string) \Illuminate\Support\Str::uuid();
@endphp
@section('content')
<div class="row g-4">
<div class="col-lg-8">
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2">
            <div><h2 class="h5 mb-1">{{ $invoice->invoice_number }} @if ($invoice->isVoided())<span class="badge text-bg-dark">VOIDED</span>@endif</h2>
                <div class="text-muted">{{ salon_datetime($invoice->issued_at) }} · issued by {{ $invoice->issuer?->name ?? '—' }}</div>
                <div>Customer: @if ($invoice->customer)<a href="{{ route('customers.show', $invoice->customer) }}">{{ $invoice->customer->name }}</a>@else Walk-in @endif</div>
                @if ($invoice->appointment_id)<div>Appointment: <a href="{{ route('appointments.show', $invoice->appointment_id) }}">#{{ $invoice->appointment_id }}</a></div>@endif</div>
            <div class="text-end"><div class="fs-4 fw-bold">{{ money($invoice->total) }}</div>
                <span class="badge text-bg-{{ ['paid' => 'success', 'partially_paid' => 'warning', 'unpaid' => 'danger'][$invoice->payment_status] }}">{{ ucwords(str_replace('_', ' ', $invoice->payment_status)) }}</span>
                @if ($invoice->refundStatus() !== 'none')<span class="badge text-bg-info">{{ $invoice->refundStatus() === 'full' ? 'Fully refunded' : 'Partly refunded' }}</span>@endif</div></div>
        @if ($invoice->void_reason)<div class="mt-2"><strong>Void reason:</strong> {{ $invoice->void_reason }} <span class="text-muted">({{ salon_datetime($invoice->voided_at) }})</span></div>@endif
        @if ($invoice->notes)<div class="mt-2"><strong>Notes:</strong> {{ $invoice->notes }}</div>@endif
    </div>
    <x-table :headers="['Item', 'Provider', 'Qty', 'Unit price', 'Discount', 'Tax', 'Total']">
        @foreach ($invoice->items as $it)<tr><td>{{ $it->description }}</td><td>{{ $it->employee?->name ?? '—' }}</td><td>{{ $it->quantity }}@if (($refundedQty[$it->id] ?? 0) > 0)<div class="small text-info">{{ $refundedQty[$it->id] }} refunded</div>@endif</td>
            <td>{{ money($it->unit_price) }}</td><td>{{ \App\Support\Money::compare($it->discount_amount, '0') > 0 ? '−'.money($it->discount_amount) : '—' }}</td><td>{{ money($it->tax_amount) }} <small class="text-muted">({{ rtrim(rtrim((string) $it->tax_rate, '0'), '.') }}%)</small></td><td>{{ money($it->line_total) }}</td></tr>@endforeach
        <tr class="table-light"><td colspan="6" class="text-end">Subtotal / discounts / tax</td><td>{{ money($invoice->subtotal) }} / −{{ money($invoice->discount_total) }} / {{ money($invoice->tax_total) }}</td></tr>
        <tr><td colspan="6" class="text-end fw-bold">Total</td><td class="fw-bold">{{ money($invoice->total) }}</td></tr>
        <tr><td colspan="6" class="text-end">Paid</td><td>{{ money($invoice->amount_paid) }}</td></tr>
        @if (\App\Support\Money::compare($invoice->refunded_total, '0') > 0)<tr><td colspan="6" class="text-end">Refunded</td><td>−{{ money($invoice->refunded_total) }}</td></tr>@endif
        <tr><td colspan="6" class="text-end fw-bold">Balance due</td><td class="fw-bold {{ \App\Support\Money::compare($balance, '0') > 0 ? 'text-danger' : '' }}">{{ money($balance) }}</td></tr>
    </x-table></div>

    <div class="card mb-3"><div class="card-header">Payments</div>
        <x-table :headers="['When', 'Method', 'Amount', 'Cash tendered / change', 'Reference', 'Received by']">
            @forelse ($invoice->payments as $p)<tr><td>{{ salon_datetime($p->received_at) }}</td><td>{{ $p->method->name }}</td><td>{{ money($p->amount) }}</td>
                <td>{{ $p->tendered_amount !== null ? money($p->tendered_amount).' / '.money($p->change_given) : '—' }}</td><td>{{ $p->reference ?? '—' }}</td><td>{{ $p->receiver->name }}</td></tr>
            @empty<tr><td colspan="6" class="text-muted">No payments recorded.</td></tr>@endforelse</x-table></div>

    @if ($invoice->refunds->isNotEmpty())<div class="card mb-3"><div class="card-header">Refunds</div>
        <x-table :headers="['Refund', 'When', 'Method', 'Amount', 'Reason', 'By']">
            @foreach ($invoice->refunds as $r)<tr><td>{{ $r->refund_number }}</td><td>{{ salon_datetime($r->refunded_at) }}</td><td>{{ $r->method->name }}</td><td>{{ money($r->amount) }}</td><td>{{ $r->reason }}</td><td>{{ $r->refunder->name }}</td></tr>@endforeach</x-table></div>@endif
</div>
<div class="col-lg-4">
    <div class="card mb-3"><div class="card-header">Actions</div><div class="card-body d-grid gap-2">
        <a class="btn btn-primary" href="{{ route('invoices.receipt', $invoice) }}" target="_blank"><i class="bi bi-printer"></i> {{ $invoice->print_count ? 'Reprint receipt' : 'Print receipt' }}</a>
        @if ($invoice->print_count)<div class="small text-muted">Printed {{ $invoice->print_count }}× (last {{ salon_datetime($invoice->last_printed_at) }}). Reprints are marked as duplicates.</div>@endif
    </div></div>

    @if ($invoice->isIssued() && \App\Support\Money::compare($balance, '0') > 0) @can('pos.use')
    <div class="card mb-3"><div class="card-header">Take payment <span class="float-end text-danger">{{ money($balance) }} due</span></div><div class="card-body">
        <form method="post" action="{{ route('invoices.payments.store', $invoice) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $paymentKey) }}">
            <x-select name="payments[0][payment_method_id]" label="Method" :options="$methods->pluck('name', 'id')->all()" />
            <x-input name="payments[0][amount]" label="Amount" type="number" step="0.01" min="0.01" :value="$balance" required />
            <x-input name="payments[0][tendered]" label="Cash handed over (cash only)" type="number" step="0.01" min="0" />
            <x-input name="payments[0][reference]" label="Reference (card / transfer)" />
            <button class="btn btn-success w-100">Record payment</button></form></div></div>
    @endcan @endif

    @if ($invoice->isIssued() && \App\Support\Money::compare($refundable, '0') > 0) @can('pos.refund')
    <div class="card mb-3 border-warning-subtle"><div class="card-header">Refund <span class="float-end text-muted">up to {{ money($refundable) }}</span></div><div class="card-body">
        <form method="post" action="{{ route('invoices.refunds.store', $invoice) }}" data-confirm="Process this refund? It cannot be undone.">@csrf<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $refundKey) }}">
            @foreach ($invoice->items as $it) @php $left = $it->quantity - ($refundedQty[$it->id] ?? 0); @endphp @if ($left > 0)
                <div class="d-flex align-items-center gap-2 mb-2"><input class="form-control form-control-sm" style="width:70px" type="number" min="0" max="{{ $left }}" name="quantities[{{ $it->id }}]" value="{{ old('quantities.'.$it->id, 0) }}" aria-label="Quantity of {{ $it->description }} to refund"><span class="small">{{ $it->description }} <span class="text-muted">(max {{ $left }})</span></span></div>@endif @endforeach
            <x-select name="payment_method_id" label="Refund via" :options="$methods->pluck('name', 'id')->all()" />
            <x-input name="reason" label="Reason (required)" required />
            <x-input name="reference" label="Reference" />
            <button class="btn btn-outline-warning w-100">Process refund</button></form></div></div>
    @endcan @endif

    @if ($invoice->isIssued()) @can('pos.void')
    <div class="card border-danger-subtle"><div class="card-header text-danger">Void invoice</div><div class="card-body">
        <p class="small text-muted">Only possible when no customer money is held (refund first). The invoice stays on record as voided.</p>
        <form method="post" action="{{ route('invoices.void', $invoice) }}" data-confirm="Void invoice {{ $invoice->invoice_number }}?">@csrf
            <x-input name="reason" label="Reason (required)" required /><button class="btn btn-outline-danger w-100">Void invoice</button></form></div></div>
    @endcan @endif
</div></div>
@endsection
