@extends('layouts.app')
@section('title', 'Leave requests')
@section('content')
<div class="row g-4">
<div class="col-lg-4"><div class="card"><div class="card-header">New leave request</div><div class="card-body">
    @if (! $mine && ! $approver)<x-alert type="info" class="mb-0">Your user account is not linked to an employee record.</x-alert>@else
    <form method="post" action="{{ route('leave.store') }}">@csrf
        @if ($approver)<div class="mb-3"><label class="form-label" for="f_employee_id">Employee</label><select class="form-select" id="f_employee_id" name="employee_id"><option value="">Myself{{ $mine ? ' ('.$mine->name.')' : '' }}</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected((int) old('employee_id') === $e->id)>{{ $e->name }}</option>@endforeach</select></div>@endif
        <x-select name="leave_type" label="Type" :options="\App\Models\LeaveRequest::TYPES" />
        <div class="row"><div class="col"><x-input name="starts_on" label="From" type="date" :value="now()->toDateString()" required /></div><div class="col"><x-input name="ends_on" label="To" type="date" :value="now()->toDateString()" required /></div></div>
        <x-textarea name="reason" label="Reason" rows="2" />
        <button class="btn btn-primary w-100">Submit request</button></form>@endif
</div></div></div>
<div class="col-lg-8"><div class="card"><div class="card-header"><form class="d-flex gap-2" method="get"><select class="form-select form-select-sm" style="width:auto" name="status" aria-label="Status"><option value="">All</option>@foreach (\App\Models\LeaveRequest::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select><button class="btn btn-sm btn-outline-secondary">Filter</button></form></div>
    <x-table :headers="['Employee', 'Type', 'Dates', 'Days', 'Status', 'Decision', '']">
        @forelse ($requests as $r)<tr><td>{{ $r->employee->name }}</td><td>{{ \App\Models\LeaveRequest::TYPES[$r->leave_type] }}</td><td class="text-nowrap">{{ $r->starts_on->format('d M') }} – {{ $r->ends_on->format('d M Y') }}</td><td>{{ $r->days }}</td>
            <td><span class="badge text-bg-{{ ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'][$r->status] }}">{{ \App\Models\LeaveRequest::STATUSES[$r->status] }}</span></td>
            <td class="small">{{ $r->decider?->name }} {{ $r->decision_note }}</td>
            <td class="text-end">
                @if ($approver && $r->isPending())
                    <form class="d-inline" method="post" action="{{ route('leave.approve', $r) }}">@csrf<button class="btn btn-sm btn-success">Approve</button></form>
                    <form class="d-inline" method="post" action="{{ route('leave.reject', $r) }}" data-confirm="Reject this request?">@csrf<input name="decision_note" class="form-control form-control-sm d-inline-block" style="width:130px" placeholder="Reason" aria-label="Rejection reason" required><button class="btn btn-sm btn-outline-danger">Reject</button></form>
                @endif
                @if (in_array($r->status, ['pending', 'approved']) && $r->ends_on->gte(today()))<x-confirm-form :action="route('leave.cancel', $r)" message="Cancel this leave request?" label="Cancel" />@endif
            </td></tr>
        @empty<tr><td colspan="7"><x-empty-state icon="bi-calendar-x" title="No leave requests" /></td></tr>@endforelse</x-table>
    <x-pagination :paginator="$requests" /></div></div></div>
@endsection
