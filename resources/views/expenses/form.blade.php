@extends('layouts.app')
@section('title', $expense->exists ? 'Edit expense '.$expense->expense_number : 'New expense')
@section('content')
<form method="post" enctype="multipart/form-data" action="{{ $expense->exists ? route('expenses.update', $expense) : route('expenses.store') }}" class="card" style="max-width:720px">@csrf @if ($expense->exists) @method('PUT') @endif
<div class="card-body"><div class="row">
    <div class="col-md-6"><x-select name="expense_category_id" label="Category" :options="$categories->pluck('name', 'id')->all()" :value="$expense->expense_category_id" placeholder="Choose…" required /></div>
    <div class="col-md-6"><x-select name="supplier_id" label="Supplier (optional)" :options="$suppliers->pluck('name', 'id')->all()" :value="$expense->supplier_id" placeholder="None" /></div>
    <div class="col-md-6"><x-input name="amount" label="Amount" type="number" step="0.01" min="0.01" :value="$expense->amount" required /></div>
    <div class="col-md-6"><x-input name="expense_date" label="Date" type="date" :value="$expense->expense_date?->format('Y-m-d')" max="{{ today()->format('Y-m-d') }}" required /></div></div>
    <x-input name="description" label="Description" :value="$expense->description" maxlength="255" required />
    <x-input name="receipt" label="Receipt (JPG, PNG or PDF, max 5 MB)" type="file" accept=".jpg,.jpeg,.png,.pdf" :help="$expense->hasReceipt() ? 'A receipt is attached; choosing a file replaces it.' : null" />
</div>
<div class="card-footer bg-transparent"><button class="btn btn-primary">{{ $expense->exists ? 'Save changes' : 'Submit for approval' }}</button> <a class="btn btn-light" href="{{ route('expenses.index') }}">Cancel</a></div></form>
@endsection
