@extends('layouts.app')
@section('title', 'Service categories')
@section('content')
<div class="card"><x-table :headers="['Category', 'Sort', 'Services', 'Active', '']">
    @forelse ($categories as $c)
    @php $dis = auth()->user()->cannot('services.manage'); @endphp
    <tr>
        <td><input class="form-control form-control-sm" name="name" form="cat{{ $c->id }}" value="{{ $c->name }}" aria-label="Name" @disabled($dis)></td>
        <td style="width:90px"><input class="form-control form-control-sm" type="number" name="sort_order" form="cat{{ $c->id }}" value="{{ $c->sort_order }}" aria-label="Sort order" @disabled($dis)></td>
        <td>{{ $c->services_count }}</td>
        <td><input type="hidden" name="is_active" form="cat{{ $c->id }}" value="0"><input class="form-check-input" type="checkbox" name="is_active" form="cat{{ $c->id }}" value="1" @checked($c->is_active) aria-label="Active" @disabled($dis)></td>
        <td class="text-end text-nowrap">@can('services.manage')<button class="btn btn-sm btn-outline-secondary" form="cat{{ $c->id }}">Save</button>
            <x-confirm-form method="delete" :action="route('categories.destroy', $c)" message="Delete category {{ $c->name }}?" label="Delete" />@endcan</td>
    </tr>
    @empty<tr><td colspan="5"><x-empty-state title="No categories yet" /></td></tr>@endforelse
</x-table>
@foreach ($categories as $c)<form id="cat{{ $c->id }}" method="post" action="{{ route('categories.update', $c) }}">@csrf @method('PUT')</form>@endforeach
@can('services.manage')<form class="row g-2 p-3 border-top" method="post" action="{{ route('categories.store') }}">@csrf
    <div class="col-md-6"><input class="form-control form-control-sm" name="name" placeholder="New category name" aria-label="New category name" required></div>
    <div class="col-md-2"><input class="form-control form-control-sm" type="number" name="sort_order" value="0" aria-label="Sort order"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary">Add category</button></div></form>@endcan</div>
<a class="btn btn-light mt-3" href="{{ route('services.index') }}">Back to services</a>
@endsection
