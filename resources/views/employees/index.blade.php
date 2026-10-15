@extends('layouts.app')
@section('title', 'Employees')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <form class="d-flex flex-wrap gap-2" method="get">
            <input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="Name, number, title, phone" aria-label="Search employees">
            <select class="form-select form-select-sm" name="status" aria-label="Status" style="width:auto"><option value="">Any status</option>@foreach (\App\Models\Employee::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
            <select class="form-select form-select-sm" name="skill" aria-label="Skill" style="width:auto"><option value="">Any skill</option>@foreach ($skills as $s)<option value="{{ $s->id }}" @selected((int) request('skill') === $s->id)>{{ $s->name }}</option>@endforeach</select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
        <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('skills.index') }}">Skills</a>
            @can('employees.manage')<a class="btn btn-sm btn-primary" href="{{ route('employees.create') }}"><i class="bi bi-plus-lg"></i> New employee</a>@endcan</div>
    </div>
    <x-table :headers="['Number', 'Name', 'Job title', 'Phone', 'Skills', 'Status', '']">
        @forelse ($employees as $e)
        <tr><td>{{ $e->employee_number }}</td><td><span class="d-inline-block rounded-circle me-1" style="width:10px;height:10px;background:{{ $e->calendar_color }}"></span><a href="{{ route('employees.show', $e) }}">{{ $e->name }}</a></td>
            <td>{{ $e->job_title }}</td><td>{{ $e->phone }}</td><td>@foreach ($e->skills as $s)<span class="badge text-bg-light border">{{ $s->name }}</span> @endforeach</td>
            <td><span class="badge text-bg-{{ $e->status === 'active' ? 'success' : ($e->status === 'terminated' ? 'dark' : 'warning') }}">{{ \App\Models\Employee::STATUSES[$e->status] }}</span></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('employees.show', $e) }}">View</a></td></tr>
        @empty<tr><td colspan="7"><x-empty-state icon="bi-person-badge" title="No employees found">@can('employees.manage')<a href="{{ route('employees.create') }}">Register an employee</a>@endcan</x-empty-state></td></tr>@endforelse
    </x-table>
    <x-pagination :paginator="$employees" />
</div>
@endsection
