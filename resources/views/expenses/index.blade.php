@extends('layouts.app')
@section('title', 'Expenses')
@section('content')
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><x-kpi-card label="Approved (filtered)" :value="money($approvedTotal)" icon="bi-cash-stack" hint="Approved expenses matching the filters" /></div>
    <div class="col-sm-6 col-lg-3"><x-kpi-card label="Awaiting approval" :value="$pendingCount" icon="bi-hourglass-split" /></div>
</div>
<div class="card"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <form class="d-flex flex-wrap gap-2" method="get">
        <input class="form-control form-control-sm" style="width:180px" name="q" value="{{ request('q') }}" placeholder="Number or description" aria-label="Search expenses">
        <select class="form-select form-select-sm" style="width:auto" name="status" aria-label="Status"><option value="">Any status</option>@foreach (\App\Models\Expense::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
        <select class="form-select form-select-sm" style="width:auto" name="category" aria-label="Category"><option value="">Any category</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected((int) request('category') === $c->id)>{{ $c->name }}</option>@endforeach</select>
        <select class="form-select form-select-sm" style="width:auto" name="payment" aria-label="Payment"><option value="">Paid or unpaid</option><option value="paid" @selected(request('payment') === 'paid')>Paid</option><option value="unpaid" @selected(request('payment') === 'unpaid')>Unpaid</option></select>
        <input class="form-control form-control-sm" style="width:auto" type="date" name="from" value="{{ request('from') }}" aria-label="From date"><input class="form-control form-control-sm" style="width:auto" type="date" name="to" value="{{ request('to') }}" aria-label="To date">
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
    </form>
    <div class="d-flex gap-2">@can('expenses.approve')<a class="btn btn-sm btn-outline-secondary" href="{{ route('expense-categories.index') }}">Categories</a>@endcan
    @can('expenses.create')<a class="btn btn-sm btn-primary" href="{{ route('expenses.create') }}"><i class="bi bi-plus-lg"></i> New expense</a>@endcan</div></div>
    <x-table :headers="['Number', 'Date', 'Category', 'Description', 'Amount', 'Status', 'Payment', '']">
        @forelse ($expenses as $e)<tr><td><a href="{{ route('expenses.show', $e) }}">{{ $e->expense_number }}</a></td><td>{{ $e->expense_date->format(config('salon.date_format')) }}</td><td>{{ $e->category->name }}</td><td>{{ $e->description }}@if ($e->hasReceipt()) <i class="bi bi-paperclip" title="Receipt attached"></i>@endif</td>
            <td class="text-end">{{ money($e->amount) }}</td>
            <td><span class="badge text-bg-{{ ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'][$e->status] }}">{{ \App\Models\Expense::STATUSES[$e->status] }}</span></td>
            <td>@if ($e->isApproved())<span class="badge text-bg-{{ $e->payment_status === 'paid' ? 'success' : 'light border' }}">{{ ucfirst($e->payment_status) }}</span>@else —@endif</td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('expenses.show', $e) }}">View</a></td></tr>
        @empty<tr><td colspan="8"><x-empty-state icon="bi-wallet2" title="No expenses found" /></td></tr>@endforelse</x-table>
    <x-pagination :paginator="$expenses" /></div>
@endsection
