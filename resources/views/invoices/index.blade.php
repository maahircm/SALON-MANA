@extends('layouts.app')
@section('title', 'Invoices')
@section('content')
<div class="card">
    <div class="card-header"><form class="row g-2" method="get">
        <div class="col-md-3"><input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="Invoice no. or customer" aria-label="Search"></div>
        <div class="col-md-2"><select class="form-select form-select-sm" name="status" aria-label="Status"><option value="">Any status</option><option value="issued" @selected(request('status') === 'issued')>Issued</option><option value="voided" @selected(request('status') === 'voided')>Voided</option></select></div>
        <div class="col-md-2"><select class="form-select form-select-sm" name="payment_status" aria-label="Payment"><option value="">Any payment</option>@foreach (['unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'paid' => 'Paid'] as $k => $v)<option value="{{ $k }}" @selected(request('payment_status') === $k)>{{ $v }}</option>@endforeach</select></div>
        <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="from" value="{{ request('from') }}" aria-label="From"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="to" value="{{ request('to') }}" aria-label="To"></div>
        <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary">Filter</button></div></form></div>
    <x-table :headers="['Invoice', 'Date', 'Customer', 'Total', 'Paid', 'Balance', 'Status', '']">
        @forelse ($invoices as $i)
        <tr><td><a href="{{ route('invoices.show', $i) }}">{{ $i->invoice_number }}</a></td><td class="text-nowrap">{{ salon_datetime($i->issued_at) }}</td><td>{{ $i->customer?->name ?? 'Walk-in' }}</td>
            <td>{{ money($i->total) }}</td><td>{{ money($i->amount_paid) }}</td><td class="{{ \App\Support\Money::compare($i->balanceDue(), '0') > 0 ? 'text-danger fw-semibold' : '' }}">{{ money($i->balanceDue()) }}</td>
            <td>@if ($i->isVoided())<span class="badge text-bg-dark">Voided</span>@else<span class="badge text-bg-{{ ['paid' => 'success', 'partially_paid' => 'warning', 'unpaid' => 'danger'][$i->payment_status] }}">{{ ucwords(str_replace('_', ' ', $i->payment_status)) }}</span>@endif
                @if ($i->refundStatus() !== 'none')<span class="badge text-bg-info">{{ $i->refundStatus() === 'full' ? 'Refunded' : 'Part refunded' }}</span>@endif</td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('invoices.show', $i) }}">Open</a></td></tr>
        @empty<tr><td colspan="8"><x-empty-state icon="bi-receipt" title="No invoices yet">Invoices appear here once a sale is issued from the POS.</x-empty-state></td></tr>@endforelse
    </x-table>
    <x-pagination :paginator="$invoices" />
</div>
@endsection
