@extends('layouts.app')
@section('title', 'Expense categories')
@section('content')
<div class="card" style="max-width:720px"><x-table :headers="['Category', 'Expenses', 'Active', '']">
    @foreach ($categories as $c)<tr>
        <td><input form="cat{{ $c->id }}" class="form-control form-control-sm" name="name" value="{{ $c->name }}" aria-label="Category name" required></td><td>{{ $c->expenses_count }}</td>
        <td><input form="cat{{ $c->id }}" type="hidden" name="is_active" value="0"><input form="cat{{ $c->id }}" class="form-check-input" type="checkbox" name="is_active" value="1" @checked($c->is_active) aria-label="Active"></td>
        <td class="text-end"><button form="cat{{ $c->id }}" class="btn btn-sm btn-outline-secondary">Save</button></td></tr>@endforeach</x-table>
    @foreach ($categories as $c)<form id="cat{{ $c->id }}" method="post" action="{{ route('expense-categories.update', $c) }}">@csrf @method('PUT')</form>@endforeach
    <form method="post" action="{{ route('expense-categories.store') }}" class="card-footer d-flex gap-2">@csrf<input class="form-control form-control-sm" name="name" placeholder="New category" aria-label="New category" required><button class="btn btn-sm btn-primary">Add</button></form></div>
@endsection
