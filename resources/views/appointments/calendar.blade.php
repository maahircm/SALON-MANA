@extends('layouts.app')
@section('title', 'Appointment calendar')
@push('head')<link rel="stylesheet" href="{{ asset('css/salon.css') }}">@endpush
@section('content')
<div class="card"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <div class="d-flex gap-2">
        @if ($canSeeAll)<select id="filterEmployee" class="form-select form-select-sm" aria-label="Stylist" style="width:auto"><option value="">All stylists</option>@foreach ($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select>@endif
        <select id="filterStatus" class="form-select form-select-sm" aria-label="Status" style="width:auto"><option value="">All statuses</option>@foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select>
    </div>
    <div class="d-flex gap-2">@foreach ($statuses as $s)<span class="badge" style="background:{{ $s->color() }}">{{ $s->label() }}</span>@endforeach</div>
    @if ($canCreate)<div class="d-flex gap-2"><a class="btn btn-sm btn-outline-primary" href="{{ route('appointments.create', ['walk_in' => 1]) }}">Walk-in</a><a class="btn btn-sm btn-primary" href="{{ route('appointments.create') }}"><i class="bi bi-calendar-plus"></i> New booking</a></div>@endif
</div><div class="card-body"><div id="calendar" style="min-height:650px"></div></div></div>
@endsection
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const empSel = document.getElementById('filterEmployee'), stSel = document.getElementById('filterStatus');
    const cal = new FullCalendar.Calendar(document.getElementById('calendar'), {
        initialView: window.innerWidth < 768 ? 'timeGridDay' : 'timeGridWeek',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
        timeZone: '{{ config('app.timezone') }}',
        height: 'auto', nowIndicator: true, slotMinTime: '07:00:00', slotMaxTime: '22:00:00', allDaySlot: false, firstDay: 1,
        events: function (info, ok, fail) {
            const p = new URLSearchParams({ start: info.startStr, end: info.endStr });
            if (empSel && empSel.value) p.set('employee_id', empSel.value);
            if (stSel.value) p.set('status', stSel.value);
            fetch('{{ route('appointments.events') }}?' + p, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.ok ? r.json() : Promise.reject(r)).then(ok).catch(fail);
        },
        eventDidMount: function (a) { a.el.title = a.event.extendedProps.status + ' — ' + a.event.extendedProps.staff; },
        @if ($canCreate)
        dateClick: function (i) {
            const d = i.dateStr.substring(0, 10), t = i.dateStr.length > 10 ? i.dateStr.substring(11, 16) : '';
            location.href = '{{ route('appointments.create') }}?date=' + d + '&time=' + t + (empSel && empSel.value ? '&employee_id=' + empSel.value : '');
        },
        @endif
    });
    cal.render();
    [empSel, stSel].forEach(el => el && el.addEventListener('change', () => cal.refetchEvents()));
});
</script>
@endpush
