@extends('layouts.app')
@section('title', 'Payment history')
@section('content')
<form class="row g-2 mb-3" method="get">
    <div class="col-md-3"><select class="form-select form-select-sm" name="method" aria-label="Method"><option value="">All methods</option>@foreach ($methods as $m)<option value="{{ $m->id }}" @selected((int) request('method') === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
    <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="from" value="{{ request('from') }}" aria-label="From"></div>
    <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="to" value="{{ request('to') }}" aria-label="To"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary">Filter</button></div></form>
<div class="card mb-4"><div class="card-header">Payments received</div>
    <x-table :headers="['When', 'Invoice', 'Customer', 'Method', 'Amount', 'Reference', 'Received by']">
        @forelse ($payments as $p)<tr><td class="text-nowrap">{{ salon_datetime($p->received_at) }}</td><td><a href="{{ route('invoices.show', $p->invoice_id) }}">{{ $p->invoice->invoice_number }}</a></td><td>{{ $p->invoice->customer?->name ?? 'Walk-in' }}</td>
            <td>{{ $p->method->name }}</td><td>{{ money($p->amount) }}</td><td>{{ $p->reference ?? '—' }}</td><td>{{ $p->receiver->name }}</td></tr>
        @empty<tr><td colspan="7"><x-empty-state icon="bi-credit-card" title="No payments in this range" /></td></tr>@endforelse</x-table>
    <x-pagination :paginator="$payments" /></div>
<div class="card"><div class="card-header">Refunds issued</div>
    <x-table :headers="['Refund', 'When', 'Invoice', 'Method', 'Amount', 'Reason', 'By']">
        @forelse ($refunds as $r)<tr><td>{{ $r->refund_number }}</td><td class="text-nowrap">{{ salon_datetime($r->refunded_at) }}</td><td><a href="{{ route('invoices.show', $r->invoice_id) }}">{{ $r->invoice->invoice_number }}</a></td>
            <td>{{ $r->method->name }}</td><td>{{ money($r->amount) }}</td><td>{{ $r->reason }}</td><td>{{ $r->refunder->name }}</td></tr>
        @empty<tr><td colspan="7"><x-empty-state icon="bi-arrow-counterclockwise" title="No refunds in this range" /></td></tr>@endforelse</x-table>
    <x-pagination :paginator="$refunds" /></div>
@endsection
