@extends('layouts.app')
@section('title', $own ? 'My commissions' : 'Commission statements')
@section('content')
@if (! empty($unlinked))<x-alert type="info">Your user account is not linked to an employee record, so there is no commission statement to show. Ask HR to link it.</x-alert>@endif
<div class="card mb-3"><div class="card-body">
    <form class="row g-2" method="get">
        @unless ($own)<div class="col-md-3"><select class="form-select form-select-sm" name="employee" aria-label="Employee"><option value="">All employees</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected($employeeId === $e->id)>{{ $e->name }}</option>@endforeach</select></div>@endunless
        <div class="col-md-3"><input class="form-control form-control-sm" type="date" name="from" value="{{ $from->toDateString() }}" aria-label="From"></div><div class="col-md-3"><input class="form-control form-control-sm" type="date" name="to" value="{{ $to->toDateString() }}" aria-label="To"></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-outline-secondary">Show</button>@unless ($own)<a class="btn btn-sm btn-outline-secondary" href="{{ route('commissions.export', request()->query()) }}"><i class="bi bi-download"></i> CSV</a>@endunless</div></form></div></div>
<div class="row g-3 mb-3"><div class="col-md-4"><x-kpi-card label="Commission earned" :value="money($totals['earned'])" icon="bi-scissors" /></div><div class="col-md-4"><x-kpi-card label="Adjustments" :value="money($totals['adjustments'])" icon="bi-arrow-left-right" hint="refunds, voids, manual" /></div><div class="col-md-4"><x-kpi-card label="Net commission" :value="money($totals['net'])" icon="bi-wallet2" /></div></div>
<div class="row g-4"><div class="col-lg-{{ $canAdjust ? 8 : 12 }}"><div class="card"><x-table :headers="['Date', 'Employee', 'Type', 'Reference', 'Basis', 'Rule', 'Amount']">
    @forelse ($lines as $l)<tr><td class="text-nowrap">{{ $l['date']->format(config('salon.date_format').' '.config('salon.time_format')) }}</td><td>{{ $l['employee'] }}</td><td>{{ $l['type'] }}</td><td class="small">{{ $l['reference'] }}</td><td>{{ isset($l['basis']) ? money($l['basis']) : '' }}</td><td>{{ $l['rule'] ?? '' }}</td>
        <td class="{{ \App\Support\Money::compare($l['amount'], '0') < 0 ? 'text-danger' : '' }}">{{ money($l['amount']) }}</td></tr>
    @empty<tr><td colspan="7"><x-empty-state icon="bi-cash-coin" title="No commission activity in this period" /></td></tr>@endforelse</x-table></div></div>
@if ($canAdjust)<div class="col-lg-4"><div class="card"><div class="card-header">Manual adjustment</div><div class="card-body">
    <form method="post" action="{{ route('commissions.adjust') }}">@csrf
        <x-select name="employee_id" label="Employee" :options="$employees->pluck('name', 'id')->all()" placeholder="Select…" />
        <x-input name="amount" label="Amount (negative to deduct)" type="number" step="0.01" required /><x-input name="reason" label="Reason (required)" required />
        <button class="btn btn-outline-primary w-100">Record adjustment</button></form></div></div></div>@endif</div>
<div class="text-muted small mt-2">Rules: percentage or fixed rate per employee, on line value after discounts and before tax. The rate in force when the sale was made is kept on each line. Refunds and voids reduce commission proportionally. This is a statement, not statutory payroll.</div>
@endsection
