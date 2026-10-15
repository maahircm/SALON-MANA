@extends('layouts.app')
@section('title', 'Loyalty')
@section('content')
<div class="row g-3 mb-3"><div class="col-md-4"><x-kpi-card label="Points outstanding" :value="number_format($outstanding)" icon="bi-stars" hint="liability across all customers" /></div>
    <div class="col-md-8 d-flex align-items-center gap-2 justify-content-end flex-wrap"><a class="btn btn-outline-secondary" href="{{ route('memberships.index') }}">Memberships</a><a class="btn btn-outline-secondary" href="{{ route('promotions.index') }}">Promotions & coupons</a>@can('loyalty.manage')<a class="btn btn-outline-primary" href="{{ route('loyalty.settings') }}">Program settings</a>@endcan</div></div>
<div class="card"><div class="card-header"><form class="d-flex gap-2" method="get"><input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="Customer name or number" aria-label="Search"><button class="btn btn-sm btn-outline-secondary">Search</button></form></div>
    <x-table :headers="['Customer', 'Number', 'Points', 'Lifetime earned', '']">
        @forelse ($accounts as $a)<tr><td>{{ $a->customer->name }}</td><td>{{ $a->customer->customer_number }}</td><td class="fw-semibold">{{ number_format($a->balance) }}</td><td>{{ number_format($a->lifetime_earned) }}</td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('loyalty.show', $a->customer_id) }}">Ledger</a></td></tr>
        @empty<tr><td colspan="5"><x-empty-state icon="bi-stars" title="No loyalty accounts yet">Points are earned when invoices with a customer are fully paid.</x-empty-state></td></tr>@endforelse</x-table>
    <x-pagination :paginator="$accounts" /></div>
@endsection
