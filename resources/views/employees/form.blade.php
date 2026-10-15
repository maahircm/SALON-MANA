@extends('layouts.app')
@section('title', $employee->exists ? 'Edit employee' : 'New employee')
@section('content')
<form method="post" action="{{ $employee->exists ? route('employees.update', $employee) : route('employees.store') }}" class="row g-4">@csrf @if ($employee->exists) @method('PUT') @endif
<div class="col-lg-7"><div class="card mb-3"><div class="card-header">Profile</div><div class="card-body"><div class="row">
    <div class="col-md-6"><x-input name="name" label="Full name" :value="$employee->name" required /></div>
    <div class="col-md-6"><x-input name="job_title" label="Job title" :value="$employee->job_title" /></div>
    <div class="col-md-6"><x-input name="phone" label="Phone" :value="$employee->phone" /></div>
    <div class="col-md-6"><x-input name="email" label="Email" type="email" :value="$employee->email" /></div>
    <div class="col-md-6"><x-input name="hire_date" label="Employment date" type="date" :value="$employee->hire_date?->toDateString()" /></div>
    <div class="col-md-6"><x-select name="status" label="Employment status" :options="\App\Models\Employee::STATUSES" :value="$employee->status" /></div>
    <div class="col-md-6"><x-input name="termination_date" label="Termination date" type="date" :value="$employee->termination_date?->toDateString()" help="Required when terminated." /></div>
    <div class="col-md-6"><x-input name="status_reason" label="Reason for status change" help="Recorded in the audit log." /></div>
    <div class="col-md-6"><x-input name="emergency_contact_name" label="Emergency contact" :value="$employee->emergency_contact_name" /></div>
    <div class="col-md-6"><x-input name="emergency_contact_phone" label="Emergency phone" :value="$employee->emergency_contact_phone" /></div>
</div><x-textarea name="notes" label="Notes" :value="$employee->notes" rows="2" /></div></div>
@if ($canSensitive)<div class="card"><div class="card-header">Salary (restricted)</div><div class="card-body"><x-input name="salary_amount" label="Monthly salary ({{ config('salon.currency') }})" type="number" step="0.01" min="0" :value="$employee->salary_amount" help="Visible only to users with payroll access. This system does not calculate statutory payroll." /></div></div>@endif
</div>
<div class="col-lg-5">
    <div class="card mb-3"><div class="card-header">Booking &amp; calendar</div><div class="card-body">
        <div class="form-check mb-3"><input type="hidden" name="is_bookable" value="0"><input class="form-check-input" type="checkbox" name="is_bookable" value="1" id="is_bookable" @checked(old('is_bookable', $employee->is_bookable))><label class="form-check-label" for="is_bookable">Can be booked for appointments</label></div>
        <x-input name="calendar_color" label="Calendar colour" type="color" :value="$employee->calendar_color" />
        <x-select name="user_id" label="Linked user account" :options="$users->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.$u->email.')'])->all()" :value="$employee->user_id" placeholder="None" />
        <div class="form-text">Linking lets a stylist sign in and see only their own appointments.</div>
    </div></div>
    <div class="card mb-3"><div class="card-header">Skills &amp; specialties</div><div class="card-body">
        @forelse ($skills as $s)<div class="form-check"><input class="form-check-input" type="checkbox" name="skills[]" value="{{ $s->id }}" id="sk{{ $s->id }}" @checked(in_array($s->id, old('skills', $employee->exists ? $employee->skills->pluck('id')->all() : [])))><label class="form-check-label" for="sk{{ $s->id }}">{{ $s->name }}</label></div>
        @empty<span class="text-muted small">No skills defined yet — add them under Employees › Skills.</span>@endforelse
    </div></div>
    <div class="card"><div class="card-header">Commission</div><div class="card-body"><div class="row">
        <div class="col"><x-select name="commission_type" label="Type" :options="['none' => 'None', 'percentage' => 'Percentage of service value', 'fixed' => 'Fixed amount per service']" :value="$employee->commission_type" /></div>
        <div class="col"><x-input name="commission_rate" label="Rate" type="number" step="0.01" min="0" :value="$employee->commission_rate" /></div></div>
        <div class="form-text">Stored now; commission statements are produced in a later phase.</div></div></div>
    <div class="mt-3"><button class="btn btn-primary" type="submit">Save employee</button> <a class="btn btn-light" href="{{ $employee->exists ? route('employees.show', $employee) : route('employees.index') }}">Cancel</a></div>
</div></form>
@endsection
