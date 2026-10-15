@extends('layouts.app')
@section('title', 'Expense '.$expense->expense_number)
@section('content')
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between"><h2 class="h5">{{ $expense->description }}</h2><span class="badge text-bg-{{ ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'][$expense->status] }} align-self-start">{{ \App\Models\Expense::STATUSES[$expense->status] }}</span></div>
    <dl class="row mb-0">
        <dt class="col-sm-4">Amount</dt><dd class="col-sm-8">{{ money($expense->amount) }}</dd>
        <dt class="col-sm-4">Category</dt><dd class="col-sm-8">{{ $expense->category->name }}</dd>
        <dt class="col-sm-4">Date</dt><dd class="col-sm-8">{{ $expense->expense_date->format(config('salon.date_format')) }}</dd>
        <dt class="col-sm-4">Supplier</dt><dd class="col-sm-8">{{ $expense->supplier?->name ?? '—' }}</dd>
        <dt class="col-sm-4">Submitted by</dt><dd class="col-sm-8">{{ $expense->creator?->name ?? '—' }}</dd>
        @if ($expense->decided_at)<dt class="col-sm-4">Decided by</dt><dd class="col-sm-8">{{ $expense->decider?->name }} · {{ $expense->decided_at->format(config('salon.date_format').' '.config('salon.time_format')) }}@if ($expense->decision_note)<br><span class="text-muted">{{ $expense->decision_note }}</span>@endif</dd>@endif
        <dt class="col-sm-4">Payment</dt><dd class="col-sm-8">@if ($expense->payment_status === 'paid') Paid {{ $expense->paid_on->format(config('salon.date_format')) }} by {{ $expense->paymentMethod?->name }}@if ($expense->payment_reference) ({{ $expense->payment_reference }})@endif @else Unpaid @endif</dd>
        <dt class="col-sm-4">Receipt</dt><dd class="col-sm-8">@if ($expense->hasReceipt())<a href="{{ route('expenses.receipt', $expense) }}" target="_blank" rel="noopener">{{ $expense->receipt_name }}</a> <span class="text-muted">({{ number_format($expense->receipt_size / 1024, 0) }} KB)</span>@else —@endif</dd>
    </dl></div>
    <div class="card-footer bg-transparent d-flex flex-wrap gap-2">
        @if ($expense->isPending())
            @can('expenses.create')<a class="btn btn-sm btn-outline-primary" href="{{ route('expenses.edit', $expense) }}">Edit</a>@endcan
            @if ($canDecide)
                <form method="post" action="{{ route('expenses.approve', $expense) }}" class="d-flex gap-1">@csrf<input class="form-control form-control-sm" name="note" placeholder="Note (optional)" aria-label="Approval note"><button class="btn btn-sm btn-success">Approve</button></form>
                <form method="post" action="{{ route('expenses.reject', $expense) }}" class="d-flex gap-1">@csrf<input class="form-control form-control-sm" name="note" placeholder="Reason" aria-label="Rejection reason" required><button class="btn btn-sm btn-outline-danger">Reject</button></form>
            @endif
            @if (auth()->user()->can('expenses.approve') || $expense->created_by === auth()->id())
                <form method="post" action="{{ route('expenses.cancel', $expense) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Cancel expense</button></form>
            @endif
        @endif
    </div></div>
    @if ($expense->isApproved() && $expense->payment_status === 'unpaid')@can('expenses.approve')
    <form method="post" action="{{ route('expenses.pay', $expense) }}" class="card mt-3"><div class="card-body"><h3 class="h6">Mark as paid</h3>@csrf<div class="row g-2">
        <div class="col-md-4"><x-select name="payment_method_id" label="Method" :options="$paymentMethods->pluck('name', 'id')->all()" placeholder="Choose…" /></div>
        <div class="col-md-4"><x-input name="paid_on" label="Paid on" type="date" :value="today()->format('Y-m-d')" /></div>
        <div class="col-md-4"><x-input name="payment_reference" label="Reference" /></div></div><button class="btn btn-sm btn-primary">Record payment</button></div></form>@endcan @endif
</div>
<div class="col-lg-5"><div class="card"><div class="card-header">Approval history</div><ul class="list-group list-group-flush">
    @foreach ($expense->events as $ev)<li class="list-group-item"><strong>{{ ucfirst($ev->action) }}</strong> by {{ $ev->user?->name ?? 'system' }}<div class="small text-muted">{{ $ev->created_at->format(config('salon.date_format').' '.config('salon.time_format')) }}@if ($ev->note) · {{ $ev->note }}@endif</div></li>@endforeach
</ul></div></div></div>
@endsection
