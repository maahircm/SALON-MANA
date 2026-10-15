@extends('layouts.app')
@section('title', 'Attendance summary')
@section('content')
<form class="d-flex gap-2 mb-3" method="get"><input class="form-control form-control-sm" style="width:auto" type="month" name="month" value="{{ $month->format('Y-m') }}" aria-label="Month"><button class="btn btn-sm btn-outline-secondary">Show</button><a class="btn btn-sm btn-light" href="{{ route('attendance.index') }}">Back</a></form>
<div class="card"><x-table :headers="['Employee', 'Present', 'Late', 'Half days', 'Absent', 'Leave days', 'Hours worked']">
    @forelse ($rows as $r)<tr><td><a href="{{ route('employees.show', $r['employee']) }}">{{ $r['employee']->name }}</a></td><td>{{ $r['summary']['present'] }}</td><td>{{ $r['summary']['late'] }}</td><td>{{ $r['summary']['half_day'] }}</td><td>{{ $r['summary']['absent'] }}</td><td>{{ $r['summary']['leave_days'] }}</td><td>{{ intdiv($r['summary']['minutes'], 60) }}h {{ $r['summary']['minutes'] % 60 }}m</td></tr>
    @empty<tr><td colspan="7"><x-empty-state title="No employees" /></td></tr>@endforelse</x-table></div>
<div class="text-muted small mt-2">Days are counted from recorded attendance; approved leave days are counted separately. This is not payroll.</div>
@endsection
