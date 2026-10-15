@extends('layouts.app')
@section('title', 'Appointments')
@section('content')
<div class="card">
    <div class="card-header"><form class="row g-2" method="get">
        <div class="col-md-3"><input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="Number or customer" aria-label="Search"></div>
        <div class="col-md-2"><select class="form-select form-select-sm" name="status" aria-label="Status"><option value="">Any status</option>@foreach ($statuses as $s)<option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
        @can('appointments.all')<div class="col-md-2"><select class="form-select form-select-sm" name="employee_id" aria-label="Stylist"><option value="">Any stylist</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected((int) request('employee_id') === $e->id)>{{ $e->name }}</option>@endforeach</select></div>@endcan
        <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="from" value="{{ request('from') }}" aria-label="From"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="to" value="{{ request('to') }}" aria-label="To"></div>
        <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary">Filter</button></div></form></div>
    <x-table :headers="['Number', 'When', 'Customer', 'Services', 'Stylist', 'Status', '']">
        @forelse ($appointments as $a)
        <tr><td><a href="{{ route('appointments.show', $a) }}">{{ $a->appointment_number }}</a></td><td class="text-nowrap">{{ salon_datetime($a->start_at) }}</td><td>{{ $a->customer->name }}</td>
            <td>{{ $a->lines->pluck('service_name')->join(', ') }}</td><td>{{ $a->lines->pluck('employee.name')->unique()->join(', ') }}</td>
            <td><span class="badge" style="background:{{ $a->status->color() }}">{{ $a->status->label() }}</span></td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('appointments.show', $a) }}">Open</a></td></tr>
        @empty<tr><td colspan="7"><x-empty-state icon="bi-calendar" title="No appointments match" /></td></tr>@endforelse
    </x-table>
    <x-pagination :paginator="$appointments" />
</div>
@endsection
