@extends('layouts.app')
@section('title', 'Attendance')
@section('content')
<div class="row g-4">
@if ($canSelf)
<div class="col-lg-4"><div class="card"><div class="card-header">My attendance today</div><div class="card-body">
    @if (! $mine)<x-alert type="info" class="mb-0">Your user account is not linked to an employee record, so you cannot clock in. Ask HR to link it.</x-alert>
    @else
        <div class="mb-2">{{ $mine->name }} · {{ now()->format('D d M Y') }}</div>
        @if ($today?->clock_in)<div>In: <strong>{{ $today->clock_in->format(config('salon.time_format')) }}</strong> @if ($today->status === 'late')<span class="badge text-bg-warning">late</span>@endif</div>@endif
        @if ($today?->clock_out)<div>Out: <strong>{{ $today->clock_out->format(config('salon.time_format')) }}</strong> · {{ intdiv($today->minutesWorked(), 60) }}h {{ $today->minutesWorked() % 60 }}m</div>@endif
        <div class="d-flex gap-2 mt-3">
            @if (! $today?->clock_in)<form method="post" action="{{ route('attendance.clock-in') }}">@csrf<button class="btn btn-success"><i class="bi bi-box-arrow-in-right"></i> Clock in</button></form>
            @elseif (! $today->clock_out)<form method="post" action="{{ route('attendance.clock-out') }}" data-confirm="Clock out now?">@csrf<button class="btn btn-danger"><i class="bi bi-box-arrow-right"></i> Clock out</button></form>
            @else<span class="text-muted">Done for today.</span>@endif
        </div>
    @endif
</div></div></div>
@endif
@if ($manager)
<div class="col-lg-{{ $canSelf ? 8 : 12 }}">
    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center">Attendance records <a class="btn btn-sm btn-outline-secondary" href="{{ route('attendance.summary') }}">Monthly summary</a></div>
        <div class="card-body border-bottom"><form class="row g-2" method="get">
            <div class="col-md-4"><select class="form-select form-select-sm" name="employee" aria-label="Employee"><option value="">All employees</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected((int) request('employee') === $e->id)>{{ $e->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><input class="form-control form-control-sm" type="date" name="from" value="{{ request('from') }}" aria-label="From"></div><div class="col-md-3"><input class="form-control form-control-sm" type="date" name="to" value="{{ request('to') }}" aria-label="To"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div></form></div>
        <x-table :headers="['Date', 'Employee', 'Status', 'In', 'Out', 'Hours', 'Notes']">
            @forelse ($records as $r)<tr><td>{{ $r->work_date->format(config('salon.date_format')) }}</td><td>{{ $r->employee->name }}</td><td><span class="badge text-bg-{{ ['present' => 'success', 'late' => 'warning', 'half_day' => 'info', 'absent' => 'danger'][$r->status] }}">{{ \App\Models\EmployeeAttendance::STATUSES[$r->status] }}</span></td>
                <td>{{ $r->clock_in?->format(config('salon.time_format')) ?? '—' }}</td><td>{{ $r->clock_out?->format(config('salon.time_format')) ?? '—' }}</td><td>{{ $r->clock_out ? intdiv($r->minutesWorked(), 60).'h '.($r->minutesWorked() % 60).'m' : '—' }}</td><td class="small">{{ $r->notes }}</td></tr>
            @empty<tr><td colspan="7"><x-empty-state icon="bi-clock-history" title="No attendance records" /></td></tr>@endforelse</x-table>
        <x-pagination :paginator="$records" /></div>
    <div class="card"><div class="card-header">Record / correct a day</div><div class="card-body">
        <form method="post" action="{{ route('attendance.store') }}" class="row g-2">@csrf
            <div class="col-md-3"><select class="form-select form-select-sm" name="employee_id" aria-label="Employee" required><option value="">Employee…</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected((int) old('employee_id') === $e->id)>{{ $e->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="work_date" value="{{ old('work_date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" aria-label="Date" required></div>
            <div class="col-md-2"><select class="form-select form-select-sm" name="status" aria-label="Status">@foreach (\App\Models\EmployeeAttendance::STATUSES as $k => $v)<option value="{{ $k }}" @selected(old('status') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="col-md-2"><input class="form-control form-control-sm" type="time" name="clock_in" value="{{ old('clock_in') }}" aria-label="Clock in"></div><div class="col-md-2"><input class="form-control form-control-sm" type="time" name="clock_out" value="{{ old('clock_out') }}" aria-label="Clock out"></div>
            <div class="col-md-6"><input class="form-control form-control-sm" name="reason" value="{{ old('reason') }}" placeholder="Reason (required, audited)" aria-label="Reason" required></div><div class="col-md-4"><input class="form-control form-control-sm" name="notes" value="{{ old('notes') }}" placeholder="Notes" aria-label="Notes"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Save</button></div></form></div></div>
</div>
@endif
</div>
@endsection
