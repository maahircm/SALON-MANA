@extends('layouts.app')
@section('title', 'Financial summary')
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <x-date-range-filter :range="$range" />
    <div class="d-flex gap-2"><button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    @can('reports.export')<a class="btn btn-sm btn-outline-secondary" href="{{ route('finance.export', $range->query()) }}"><i class="bi bi-download"></i> CSV</a>@endcan</div>
</div>
<p class="text-muted">Period {{ $range->label() }}. A management summary built from invoices, payments, refunds, the stock ledger, approved expenses and accrued commissions — not a double-entry ledger.</p>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><x-kpi-card label="Net revenue" :value="money($figures['net_revenue'])" icon="bi-graph-up" hint="Invoiced excl. tax, less refunds' revenue value" /></div>
    <div class="col-sm-6 col-lg-3"><x-kpi-card label="Net cash collected" :value="money($figures['net_cash_collected'])" icon="bi-cash-coin" hint="Money received less money refunded" /></div>
    <div class="col-sm-6 col-lg-3"><x-kpi-card label="Operating costs" :value="money($figures['operating_costs'])" icon="bi-wallet2" hint="Approved expenses + commissions" /></div>
    <div class="col-sm-6 col-lg-3"><x-kpi-card label="Estimated net result" :value="money($figures['net_result'])" icon="bi-bar-chart-line" hint="Net revenue − COGS − operating costs" /></div>
</div>
<div class="row g-3">
<div class="col-lg-6"><div class="card"><div class="card-header">Profit and loss (accrual)</div><table class="table mb-0 align-middle">
    @foreach ([['Gross sales (incl. tax)', $figures['gross_sales'], false], ['− Tax collected', $figures['tax_collected'], false], ['Net sales (excl. tax)', $figures['net_sales'], true], ['− Sales returns (excl. tax)', $figures['sales_returns'], false],
        ['Net revenue', $figures['net_revenue'], true], ['− Cost of goods sold', $figures['cogs'], false], ['Gross profit', $figures['gross_profit'], true], ['− Operating expenses (approved)', $figures['expenses'], false],
        ['− Staff commissions', $figures['commissions'], false], ['Net operating result', $figures['net_result'], true]] as [$label, $value, $strong])
        <tr class="{{ $strong ? 'fw-semibold table-light' : '' }}"><td>{{ $label }}</td><td class="text-end">{{ money($value) }}</td></tr>
    @endforeach</table></div></div>
<div class="col-lg-6"><div class="card mb-3"><div class="card-header">Cash and receivables</div><table class="table mb-0">
    <tr><td>Payments received (money)</td><td class="text-end">{{ money($figures['payments_received']) }}</td></tr>
    <tr><td>− Refunds paid (money)</td><td class="text-end">{{ money($figures['refunds_paid']) }}</td></tr>
    <tr class="fw-semibold table-light"><td>Net cash collected</td><td class="text-end">{{ money($figures['net_cash_collected']) }}</td></tr>
    <tr><td>Settled with loyalty points (not money)</td><td class="text-end">{{ money($figures['points_settled']) }}</td></tr>
    <tr><td>Expenses paid out in period</td><td class="text-end">{{ money($figures['expenses_paid_out']) }}</td></tr>
    <tr><td>Outstanding receivables now ({{ $figures['receivable_count'] }} invoices)</td><td class="text-end">{{ money($figures['receivables']) }}</td></tr></table></div>
    <div class="card"><div class="card-header">Approved expenses by category</div><table class="table mb-0">
    @forelse ($figures['expenses_by_category'] as $name => $amount)<tr><td>{{ $name }}</td><td class="text-end">{{ money($amount) }}</td></tr>@empty<tr><td class="text-muted">No approved expenses in this period.</td></tr>@endforelse</table></div></div>
</div>
<p class="small text-muted mt-3">Commissions are shown separately; if payroll expenses already include commissions paid, exclude one of them to avoid counting twice.</p>
@endsection
