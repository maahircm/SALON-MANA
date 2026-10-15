@extends('layouts.app')
@section('title', $employee->name)
@section('content')
<div class="row g-4">
<div class="col-lg-4"><div class="card mb-3"><div class="card-body">
    <div class="text-muted small">{{ $employee->employee_number }}</div><h2 class="h5">{{ $employee->name }}</h2>
    <span class="badge text-bg-{{ $employee->status === 'active' ? 'success' : 'secondary' }}">{{ \App\Models\Employee::STATUSES[$employee->status] }}</span>
    <dl class="row small mt-3 mb-0">
        <dt class="col-5">Job title</dt><dd class="col-7">{{ $employee->job_title ?? '—' }}</dd>
        <dt class="col-5">Phone</dt><dd class="col-7">{{ $employee->phone ?? '—' }}</dd>
        <dt class="col-5">Email</dt><dd class="col-7 text-break">{{ $employee->email ?? '—' }}</dd>
        <dt class="col-5">Hired</dt><dd class="col-7">{{ $employee->hire_date?->format('d M Y') ?? '—' }}</dd>
        @if ($employee->termination_date)<dt class="col-5">Terminated</dt><dd class="col-7">{{ $employee->termination_date->format('d M Y') }}</dd>@endif
        <dt class="col-5">Bookable</dt><dd class="col-7">{{ $employee->is_bookable ? 'Yes' : 'No' }}</dd>
        <dt class="col-5">User account</dt><dd class="col-7">{{ $employee->user?->email ?? 'Not linked' }}</dd>
        <dt class="col-5">Commission</dt><dd class="col-7">{{ $employee->commission_type === 'none' ? 'None' : ($employee->commission_type === 'percentage' ? $employee->commission_rate.'%' : money($employee->commission_rate).' fixed') }}</dd>
        @if ($canSensitive)<dt class="col-5">Salary</dt><dd class="col-7">{{ $employee->salary_amount !== null ? money($employee->salary_amount) : '—' }}</dd>
        <dt class="col-5">Emergency</dt><dd class="col-7">{{ $employee->emergency_contact_name }} {{ $employee->emergency_contact_phone }}</dd>@endif
    </dl></div>
    <div class="card-footer bg-transparent d-flex gap-2">@can('employees.manage')<a class="btn btn-sm btn-outline-secondary" href="{{ route('employees.edit', $employee) }}">Edit</a>@endcan
        @can('employees.schedule')<a class="btn btn-sm btn-outline-primary" href="{{ route('employees.schedule', $employee) }}">Working hours</a>@endcan</div></div>
    <div class="card"><div class="card-header">Skills</div><div class="card-body">@forelse ($employee->skills as $s)<span class="badge text-bg-light border">{{ $s->name }}</span> @empty<span class="text-muted small">None assigned</span>@endforelse</div></div></div>
<div class="col-lg-8">
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3"><x-kpi-card label="Completed" :value="$metrics['completed']" icon="bi-check2-circle" hint="service lines" /></div>
        <div class="col-6 col-md-3"><x-kpi-card label="Upcoming" :value="$metrics['upcoming']" icon="bi-calendar-event" /></div>
        <div class="col-6 col-md-3"><x-kpi-card label="No-shows" :value="$metrics['no_shows']" icon="bi-person-x" /></div>
        <div class="col-6 col-md-3"><x-kpi-card label="Service value" :value="money($metrics['revenue_booked'])" icon="bi-cash-stack" hint="completed bookings" /></div>
    </div>
    <div class="card"><div class="card-header">Weekly working hours</div><x-table :headers="['Day', 'Hours']">
        @foreach (\App\Models\Employee::WEEKDAYS as $d => $name)<tr><td>{{ $name }}</td><td>{{ $employee->schedules->where('weekday', $d)->map(fn ($s) => substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5))->join(', ') ?: 'Off' }}</td></tr>@endforeach</x-table></div>
    <div class="text-muted small mt-2">Metrics count booked appointment lines. Paid sales and commission statements are added with the POS and commission features.</div>
</div></div>
@endsection
