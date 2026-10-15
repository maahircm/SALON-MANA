@extends('layouts.app')
@section('title', 'Daily summary')
@section('content')
<form class="d-flex gap-2 mb-3 no-print" method="get"><input class="form-control form-control-sm" style="width:auto" type="date" name="date" value="{{ $date->toDateString() }}" aria-label="Date"><button class="btn btn-sm btn-outline-secondary">Show</button><button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></form>
<h2 class="h5">{{ $date->format('l, d F Y') }}</h2>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><x-kpi-card label="Sales (invoiced)" :value="money($sales['total'])" icon="bi-receipt" :hint="$sales['count'].' invoices · '.$voided.' voided'" /></div>
    <div class="col-6 col-md-3"><x-kpi-card label="Payments collected" :value="money($collected)" icon="bi-cash-coin" hint="all methods" /></div>
    <div class="col-6 col-md-3"><x-kpi-card label="Refunds paid" :value="money($refunded)" icon="bi-arrow-counterclockwise" /></div>
    <div class="col-6 col-md-3"><x-kpi-card label="Net collected" :value="money($netCollected)" icon="bi-wallet2" hint="collected − refunds" /></div>
</div>
<div class="row g-4">
<div class="col-lg-6"><div class="card mb-3"><div class="card-header">Invoiced today</div><x-table :headers="['Measure', 'Amount']">
    <tr><td>Subtotal</td><td>{{ money($sales['subtotal']) }}</td></tr><tr><td>Discounts</td><td>−{{ money($sales['discounts']) }}</td></tr><tr><td>Tax</td><td>{{ money($sales['tax']) }}</td></tr><tr class="fw-bold"><td>Total sales</td><td>{{ money($sales['total']) }}</td></tr></x-table></div>
    <div class="card"><div class="card-header">Outstanding customer balances (all time)</div><div class="card-body fs-5">{{ money($outstanding) }}</div></div></div>
<div class="col-lg-6"><div class="card mb-3"><div class="card-header">Collected by method</div><x-table :headers="['Method', 'Collected', 'Refunded']">
    @php $names = array_unique(array_merge(array_keys($paymentsByMethod), array_keys($refundsByMethod))); @endphp
    @forelse ($names as $n)<tr><td>{{ $n }}</td><td>{{ money($paymentsByMethod[$n] ?? 0) }}</td><td>{{ money($refundsByMethod[$n] ?? 0) }}</td></tr>@empty<tr><td colspan="3" class="text-muted">No movements</td></tr>@endforelse</x-table></div>
    <div class="card"><div class="card-header">Drawer sessions</div><x-table :headers="['Cashier', 'Drawer', 'Status', 'Expected', 'Counted', 'Variance']">
        @forelse ($sessions as $s)<tr><td><a href="{{ route('cash.show', $s) }}">{{ $s->opener->name }}</a></td><td>{{ $s->drawer->name }}</td><td>{{ $s->isOpen() ? 'Open' : 'Closed' }}</td><td>{{ $s->expected_cash !== null ? money($s->expected_cash) : '—' }}</td><td>{{ $s->counted_cash !== null ? money($s->counted_cash) : '—' }}</td>
            <td class="{{ $s->variance !== null && \App\Support\Money::compare($s->variance, '0') !== 0 ? 'text-danger fw-semibold' : '' }}">{{ $s->variance !== null ? money($s->variance) : '—' }}</td></tr>@empty<tr><td colspan="6" class="text-muted">No sessions</td></tr>@endforelse</x-table></div></div></div>
<div class="text-muted small mt-3">Revenue (invoiced), cash collected, refunds and receivables are shown separately; this page is not a profit statement.</div>
@endsection
