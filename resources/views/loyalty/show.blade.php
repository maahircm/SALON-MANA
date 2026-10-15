@extends('layouts.app')
@section('title', 'Loyalty — '.$customer->name)
@section('content')
<div class="row g-4"><div class="col-lg-4"><div class="card mb-3"><div class="card-body"><div class="text-muted small">{{ $customer->customer_number }}</div><h2 class="h5">{{ $customer->name }}</h2>
    <div class="display-6">{{ number_format($account?->balance ?? 0) }} <small class="fs-6 text-muted">points</small></div><div class="small text-muted">Lifetime earned {{ number_format($account?->lifetime_earned ?? 0) }}</div>
    <a class="btn btn-sm btn-outline-secondary mt-2" href="{{ route('customers.show', $customer) }}">Customer profile</a></div></div>
    @can('loyalty.manage')<div class="card"><div class="card-header">Adjust points</div><div class="card-body"><form method="post" action="{{ route('loyalty.adjust', $customer) }}">@csrf
        <x-input name="points" label="Points (negative to remove)" type="number" step="1" required /><x-input name="reason" label="Reason (required)" required /><button class="btn btn-outline-primary w-100">Record adjustment</button></form></div></div>@endcan</div>
<div class="col-lg-8"><div class="card"><div class="card-header">Points ledger</div><x-table :headers="['When', 'Type', 'Points', 'Balance', 'Invoice', 'Reason', 'By']">
    @forelse ($transactions as $t)<tr><td class="text-nowrap">{{ salon_datetime($t->created_at) }}</td><td>{{ \App\Models\LoyaltyTransaction::LABELS[$t->type] }}</td><td class="{{ $t->points < 0 ? 'text-danger' : 'text-success' }}">{{ $t->points > 0 ? '+' : '' }}{{ number_format($t->points) }}</td><td>{{ number_format($t->balance_after) }}</td>
        <td>@if ($t->invoice)<a href="{{ route('invoices.show', $t->invoice_id) }}">{{ $t->invoice->invoice_number }}</a>@endif</td><td class="small">{{ $t->reason }}</td><td>{{ $t->creator?->name ?? 'system' }}</td></tr>
    @empty<tr><td colspan="7"><x-empty-state title="No points activity" /></td></tr>@endforelse</x-table><x-pagination :paginator="$transactions" /></div></div></div>
@endsection
