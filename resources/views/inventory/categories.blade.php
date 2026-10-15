@extends('layouts.app')
@section('title', 'Product categories')
@section('content')
<div class="card" style="max-width:720px"><x-table :headers="['Category', 'Products', 'Active', '']">
    @forelse ($categories as $c)@php $dis = auth()->user()->cannot('inventory.manage'); @endphp
    <tr><td><input class="form-control form-control-sm" name="name" form="pc{{ $c->id }}" value="{{ $c->name }}" aria-label="Name" @disabled($dis)></td><td>{{ $c->products_count }}</td>
        <td><input type="hidden" name="is_active" form="pc{{ $c->id }}" value="0"><input class="form-check-input" type="checkbox" name="is_active" form="pc{{ $c->id }}" value="1" @checked($c->is_active) aria-label="Active" @disabled($dis)></td>
        <td class="text-end text-nowrap">@can('inventory.manage')<button class="btn btn-sm btn-outline-secondary" form="pc{{ $c->id }}">Save</button> <x-confirm-form method="delete" :action="route('product-categories.destroy', $c)" message="Delete {{ $c->name }}?" label="Delete" />@endcan</td></tr>
    @empty<tr><td colspan="4"><x-empty-state title="No categories yet" /></td></tr>@endforelse</x-table>
@foreach ($categories as $c)<form id="pc{{ $c->id }}" method="post" action="{{ route('product-categories.update', $c) }}">@csrf @method('PUT')</form>@endforeach
@can('inventory.manage')<form class="d-flex gap-2 p-3 border-top" method="post" action="{{ route('product-categories.store') }}">@csrf<input class="form-control form-control-sm" name="name" placeholder="New category" aria-label="New category" required><button class="btn btn-sm btn-primary">Add</button></form>@endcan</div>
<a class="btn btn-light mt-3" href="{{ route('products.index') }}">Back to products</a>
@endsection
