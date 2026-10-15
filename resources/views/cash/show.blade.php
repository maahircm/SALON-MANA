@extends('layouts.app')
@section('title', 'Drawer session #'.$session->id)
@section('content')
<div class="row g-4">
<div class="col-lg-5">
    <div class="card mb-3"><div class="card-header d-flex justify-content-between">{{ $session->drawer->name }} <span class="badge text-bg-{{ $session->isOpen() ? 'success' : 'secondary' }}">{{ $session->isOpen() ? 'Open' : 'Closed' }}</span></div><div class="card-body">
        <div>Cashier: {{ $session->opener->name }}</div><div>Opened: {{ salon_datetime($session->opened_at) }}</div>
        @unless ($session->isOpen())<div>Closed: {{ salon_datetime($session->closed_at) }} by {{ $session->closer?->name }}</div>@endunless
        @php $r = $recon ?? ($session->summary ?? []); @endphp
        <table class="table table-sm mt-3 mb-0">
            <tr><td>Opening cash</td><td class="text-end">{{ money($r['opening'] ?? $session->opening_cash) }}</td></tr>
            <tr><td>+ Cash sales</td><td class="text-end">{{ money($r['cash_sales'] ?? 0) }}</td></tr>
            <tr><td>− Cash refunds</td><td class="text-end">{{ money($r['cash_refunds'] ?? 0) }}</td></tr>
            <tr><td>+ Paid in</td><td class="text-end">{{ money($r['paid_in'] ?? 0) }}</td></tr>
            <tr><td>− Paid out</td><td class="text-end">{{ money($r['paid_out'] ?? 0) }}</td></tr>
            <tr class="fw-bold"><td>Expected in drawer</td><td class="text-end">{{ money($r['expected'] ?? $session->expected_cash) }}</td></tr>
            @unless ($session->isOpen())<tr><td>Counted</td><td class="text-end">{{ money($session->counted_cash) }}</td></tr>
            <tr class="fw-bold {{ \App\Support\Money::compare($session->variance, '0') !== 0 ? 'text-danger' : 'text-success' }}"><td>Variance</td><td class="text-end">{{ money($session->variance) }}</td></tr>@endunless
        </table>
        @if ($session->closing_notes)<div class="mt-2"><strong>Notes:</strong> {{ $session->closing_notes }}</div>@endif
    </div></div>
    @if ($session->isOpen())
    <div class="card mb-3"><div class="card-header">Cash in / out</div><div class="card-body">
        <form method="post" action="{{ route('cash.movement', $session) }}">@csrf
            <x-select name="type" label="Type" :options="['paid_in' => 'Paid in (cash added)', 'paid_out' => 'Paid out (cash removed)']" />
            <x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" required />
            <x-input name="reason" label="Reason" required />
            <button class="btn btn-outline-primary w-100">Record movement</button></form></div></div>
    <div class="card border-danger-subtle"><div class="card-header">Close &amp; reconcile</div><div class="card-body">
        <form method="post" action="{{ route('cash.close', $session) }}" data-confirm="Close this drawer session? It cannot be reopened.">@csrf
            <x-input name="counted_cash" label="Cash counted in drawer" type="number" step="0.01" min="0" required help="Count the drawer first; the expected amount is shown on the left." />
            <x-textarea name="closing_notes" label="Notes (required if the count differs)" rows="2" />
            <button class="btn btn-danger w-100">Close session</button></form></div></div>
    @endif
</div>
<div class="col-lg-7">
    <div class="card mb-3"><div class="card-header">Payments taken ({{ $payments->count() }})</div>
        <x-table :headers="['Time', 'Invoice', 'Method', 'Amount']">@forelse ($payments as $p)<tr><td>{{ $p->received_at->format(config('salon.time_format')) }}</td><td><a href="{{ route('invoices.show', $p->invoice_id) }}">{{ $p->invoice->invoice_number }}</a></td><td>{{ $p->method->name }}</td><td>{{ money($p->amount) }}</td></tr>
            @empty<tr><td colspan="4" class="text-muted">None</td></tr>@endforelse</x-table></div>
    <div class="card mb-3"><div class="card-header">Refunds paid ({{ $refunds->count() }})</div>
        <x-table :headers="['Time', 'Refund', 'Invoice', 'Method', 'Amount']">@forelse ($refunds as $f)<tr><td>{{ $f->refunded_at->format(config('salon.time_format')) }}</td><td>{{ $f->refund_number }}</td><td>{{ $f->invoice->invoice_number }}</td><td>{{ $f->method->name }}</td><td>{{ money($f->amount) }}</td></tr>
            @empty<tr><td colspan="5" class="text-muted">None</td></tr>@endforelse</x-table></div>
    <div class="card"><div class="card-header">Cash movements</div>
        <x-table :headers="['Time', 'Type', 'Amount', 'Reason', 'By']">@forelse ($movements as $m)<tr><td>{{ $m->created_at->format(config('salon.time_format')) }}</td><td>{{ $m->type === 'paid_in' ? 'Paid in' : 'Paid out' }}</td><td>{{ money($m->amount) }}</td><td>{{ $m->reason }}</td><td>{{ $m->creator->name }}</td></tr>
            @empty<tr><td colspan="5" class="text-muted">None</td></tr>@endforelse</x-table></div>
</div></div>
<a class="btn btn-light mt-3" href="{{ route('cash.index') }}">Back</a>
@endsection
