@extends('layouts.app')
@section('title', 'Chairs & rooms')
@section('content')
<div class="card" style="max-width:760px"><x-table :headers="['Name', 'Type', 'Active', '']">
    @forelse ($rooms as $r)<tr>
        <td><input class="form-control form-control-sm" name="name" form="room{{ $r->id }}" value="{{ $r->name }}" aria-label="Name"></td>
        <td><select class="form-select form-select-sm" name="type" form="room{{ $r->id }}" aria-label="Type">@foreach (\App\Models\Room::TYPES as $k => $v)<option value="{{ $k }}" @selected($r->type === $k)>{{ $v }}</option>@endforeach</select></td>
        <td><input type="hidden" name="is_active" form="room{{ $r->id }}" value="0"><input class="form-check-input" type="checkbox" name="is_active" form="room{{ $r->id }}" value="1" @checked($r->is_active) aria-label="Active"></td>
        <td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-secondary" form="room{{ $r->id }}">Save</button> <x-confirm-form method="delete" :action="route('rooms.destroy', $r)" message="Delete {{ $r->name }}?" label="Delete" /></td></tr>
    @empty<tr><td colspan="4"><x-empty-state icon="bi-door-open" title="No chairs or rooms yet">Allocation is optional: add chairs/rooms to prevent two customers using the same one.</x-empty-state></td></tr>@endforelse</x-table>
@foreach ($rooms as $r)<form id="room{{ $r->id }}" method="post" action="{{ route('rooms.update', $r) }}">@csrf @method('PUT')</form>@endforeach
<form class="row g-2 p-3 border-top" method="post" action="{{ route('rooms.store') }}">@csrf
    <div class="col-md-6"><input class="form-control form-control-sm" name="name" placeholder="e.g. Chair 3" aria-label="Name" required></div>
    <div class="col-md-4"><select class="form-select form-select-sm" name="type" aria-label="Type">@foreach (\App\Models\Room::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Add</button></div></form></div>
@endsection
