@extends('layouts.app')
@section('title', 'Working hours — '.$employee->name)
@section('content')
<div class="row g-4">
<div class="col-lg-7"><form method="post" action="{{ route('employees.schedule.update', $employee) }}">@csrf @method('PUT')
<div class="card"><div class="card-header">Weekly schedule <small class="text-muted">(up to 2 blocks per day, e.g. split shifts; leave empty for a day off)</small></div>
<div class="card-body">
@foreach (\App\Models\Employee::WEEKDAYS as $d => $name)
    @php $blocks = $weekly->get($d, collect())->values(); @endphp
    <div class="row g-2 align-items-center mb-2"><div class="col-3 fw-semibold">{{ $name }}</div>
    @foreach ([0, 1] as $i)
        <div class="col-4 col-md-4"><div class="input-group input-group-sm">
            <input type="time" class="form-control" name="days[{{ $d }}][{{ $i }}][start]" value="{{ old("days.$d.$i.start", isset($blocks[$i]) ? substr($blocks[$i]->start_time, 0, 5) : '') }}" aria-label="{{ $name }} block {{ $i + 1 }} start">
            <input type="time" class="form-control" name="days[{{ $d }}][{{ $i }}][end]" value="{{ old("days.$d.$i.end", isset($blocks[$i]) ? substr($blocks[$i]->end_time, 0, 5) : '') }}" aria-label="{{ $name }} block {{ $i + 1 }} end"></div></div>
    @endforeach</div>
@endforeach
<div class="form-text">Bookings are also limited by salon opening hours (Booking Settings). Existing appointments are not moved automatically when hours change.</div>
</div><div class="card-footer bg-transparent"><button class="btn btn-primary">Save schedule</button> <a class="btn btn-light" href="{{ route('employees.show', $employee) }}">Back</a></div></div></form></div>
<div class="col-lg-5"><div class="card"><div class="card-header">Dated shifts &amp; days off</div>
    <x-table :headers="['Date', 'Type', 'Hours', '']">
        @forelse ($shifts as $s)<tr><td>{{ $s->shift_date->format('D d M Y') }}</td><td>{{ $s->type === 'off' ? 'Day off' : 'Work' }}</td><td>{{ $s->type === 'work' ? substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5) : '—' }}</td>
            <td class="text-end"><x-confirm-form method="delete" :action="route('employees.shifts.destroy', [$employee, $s])" message="Remove this shift?" label="Remove" /></td></tr>
        @empty<tr><td colspan="4" class="text-muted">No upcoming overrides.</td></tr>@endforelse</x-table>
    <form class="p-3 border-top" method="post" action="{{ route('employees.shifts.store', $employee) }}">@csrf
        <div class="row g-2">
            <div class="col-6"><input class="form-control form-control-sm" type="date" name="shift_date" min="{{ now()->toDateString() }}" aria-label="Date" required></div>
            <div class="col-6"><select class="form-select form-select-sm" name="type" aria-label="Type"><option value="work">Work (replaces weekly hours)</option><option value="off">Day off</option></select></div>
            <div class="col-6"><input class="form-control form-control-sm" type="time" name="start_time" aria-label="Start"></div>
            <div class="col-6"><input class="form-control form-control-sm" type="time" name="end_time" aria-label="End"></div>
            <div class="col-12"><input class="form-control form-control-sm" name="notes" placeholder="Note (optional)" aria-label="Note"></div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary">Add shift</button></div></div></form></div></div>
</div>
@endsection
