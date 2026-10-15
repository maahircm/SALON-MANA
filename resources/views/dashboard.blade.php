@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@if ($operations)
<div class="row g-3 mb-3">
    @isset($operations['appointments_today'])<div class="col-sm-6 col-xl-3"><x-kpi-card label="Appointments today" :value="$operations['appointments_today']" icon="bi-calendar-check" :hint="$operations['appointments_open'].' still open'" /></div>@endisset
    @isset($operations['new_customers'])<div class="col-sm-6 col-xl-3"><x-kpi-card label="New customers (30 days)" :value="$operations['new_customers']" icon="bi-person-plus" /></div>@endisset
    @isset($operations['low_stock'])<div class="col-sm-6 col-xl-3"><a class="text-decoration-none text-reset" href="{{ route('reports.show', 'low_stock') }}"><x-kpi-card label="Low-stock products" :value="$operations['low_stock']" icon="bi-box-seam" hint="At or below reorder level" /></a></div>@endisset
    @isset($operations['pending_expenses'])<div class="col-sm-6 col-xl-3"><a class="text-decoration-none text-reset" href="{{ route('expenses.index', ['status' => 'pending']) }}"><x-kpi-card label="Expenses awaiting approval" :value="$operations['pending_expenses']" icon="bi-hourglass-split" /></a></div>@endisset
    @isset($operations['pending_leave'])<div class="col-sm-6 col-xl-3"><a class="text-decoration-none text-reset" href="{{ route('leave.index') }}"><x-kpi-card label="Leave requests pending" :value="$operations['pending_leave']" icon="bi-calendar-x" /></a></div>@endisset
</div>
@endif
@if ($upcoming->isNotEmpty())
<div class="card mb-3"><div class="card-header">Next appointments today</div><ul class="list-group list-group-flush">
    @foreach ($upcoming as $a)<li class="list-group-item d-flex justify-content-between"><span><strong>{{ $a->start_at->format(config('salon.time_format')) }}</strong> · {{ $a->customer?->name ?? 'Walk-in' }} <span class="text-muted">— {{ $a->lines->pluck('service_name')->join(', ') }}</span></span>
        <a href="{{ route('appointments.show', $a) }}">{{ $a->appointment_number }}</a></li>@endforeach
</ul></div>
@endif

@if ($finance)
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"><h2 class="h5 mb-0">Business performance</h2><x-date-range-filter :range="$range" /></div>
<p class="text-muted small">{{ $range->label() }}. Revenue is invoiced sales excluding tax; cash collected is money actually received less refunds paid; they differ when customers owe a balance or pay later.</p>
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl"><x-kpi-card label="Revenue" :value="money($finance['net_revenue'])" icon="bi-graph-up" hint="Net of refunds, excl. tax" /></div>
    <div class="col-sm-6 col-xl"><x-kpi-card label="Cash collected" :value="money($finance['net_cash_collected'])" icon="bi-cash-coin" hint="Received less refunded" /></div>
    <div class="col-sm-6 col-xl"><x-kpi-card label="Refunds" :value="money($finance['refunds_paid'])" icon="bi-arrow-counterclockwise" hint="Money returned to customers" /></div>
    <div class="col-sm-6 col-xl"><x-kpi-card label="Expenses" :value="money($finance['operating_costs'])" icon="bi-wallet2" hint="Approved expenses + commissions" /></div>
    <div class="col-sm-6 col-xl"><x-kpi-card label="Estimated profit" :value="money($finance['net_result'])" icon="bi-bar-chart-line" hint="Revenue − cost of goods − expenses" /></div>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-8"><div class="card h-100"><div class="card-header">Revenue vs cash collected per day</div><div class="card-body"><canvas id="chartDaily" height="110" aria-label="Revenue and cash collected per day" role="img"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card h-100"><div class="card-header">Net cash by payment method</div><div class="card-body"><canvas id="chartMethods" height="200" aria-label="Net cash by payment method" role="img"></canvas></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">Top services by revenue</div><div class="card-body"><canvas id="chartServices" height="140" aria-label="Top services" role="img"></canvas></div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">Appointments by status</div><div class="card-body"><canvas id="chartAppointments" height="140" aria-label="Appointments by status" role="img"></canvas></div></div></div>
</div>
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') { document.querySelectorAll('canvas').forEach(function (c) { c.insertAdjacentHTML('afterend', '<p class="text-muted small">Charts could not be loaded (the Chart.js library is served from a CDN). The figures above are unaffected.</p>'); }); return; }
    var data = {{ Illuminate\Support\Js::from($charts) }};
    var palette = ['#2b6cb0', '#2f855a', '#c05621', '#6b46c1', '#b7791f', '#c53030', '#718096'];
    var money = function (v) { return new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v); };
    var empty = function (id, rows) { if (rows.length) { return false; } var c = document.getElementById(id); c.insertAdjacentHTML('afterend', '<p class="text-muted small mb-0">No data for this period.</p>'); c.remove(); return true; };
    new Chart(document.getElementById('chartDaily'), { type: 'line', data: { labels: data.daily.labels, datasets: [
        { label: 'Revenue', data: data.daily.revenue, borderColor: '#2b6cb0', backgroundColor: '#2b6cb0', tension: .25 },
        { label: 'Cash collected', data: data.daily.collected, borderColor: '#2f855a', backgroundColor: '#2f855a', tension: .25 }] },
        options: { responsive: true, interaction: { mode: 'index', intersect: false }, plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + money(c.parsed.y); } } } }, scales: { y: { beginAtZero: true } } } });
    if (!empty('chartMethods', data.methods)) { new Chart(document.getElementById('chartMethods'), { type: 'doughnut', data: { labels: data.methods.map(function (r) { return r.label; }), datasets: [{ data: data.methods.map(function (r) { return r.value; }), backgroundColor: palette }] } }); }
    if (!empty('chartServices', data.services)) { new Chart(document.getElementById('chartServices'), { type: 'bar', data: { labels: data.services.map(function (r) { return r.label; }), datasets: [{ label: 'Revenue', data: data.services.map(function (r) { return r.value; }), backgroundColor: '#2b6cb0' }] }, options: { indexAxis: 'y', plugins: { legend: { display: false } } } }); }
    if (!empty('chartAppointments', data.appointments)) { new Chart(document.getElementById('chartAppointments'), { type: 'doughnut', data: { labels: data.appointments.map(function (r) { return r.label; }), datasets: [{ data: data.appointments.map(function (r) { return r.value; }), backgroundColor: palette }] } }); }
})();
</script>
@endpush
@endif

@if (! $operations && ! $finance)
<div class="row g-3">
    <div class="col-md-4"><x-kpi-card label="Signed in as" :value="$user->name" icon="bi-person-badge" :hint="$user->email" /></div>
    <div class="col-md-4"><x-kpi-card label="Roles" :value="$user->roles->count()" icon="bi-shield-check" :hint="$user->roles->pluck('name')->join(', ') ?: 'No roles assigned'" /></div>
    <div class="col-md-4"><x-kpi-card label="Last sign-in" :value="optional($user->last_login_at)->format(config('salon.date_format').' '.config('salon.time_format')) ?? '—'" icon="bi-clock-history" :hint="$user->last_login_ip" /></div>
</div>
@endif
@endsection
