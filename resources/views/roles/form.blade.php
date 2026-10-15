@extends('layouts.app')
@section('title', $role->exists ? 'Edit role' : 'New role')
@section('content')
<form method="post" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}">@csrf @if ($role->exists) @method('PUT') @endif
<div class="card mb-3"><div class="card-body">
    <x-input name="name" label="Role name" :value="$role->name" :readonly="$role->is_system" required />
    <x-input name="description" label="Description" :value="$role->description" />
</div></div>
<div class="row g-3">
@foreach ($groups as $group => $perms)
    <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-header d-flex justify-content-between">{{ $group }}
        <span class="form-check"><input class="form-check-input" type="checkbox" id="all_{{ Str::slug($group) }}" data-check-group="{{ Str::slug($group) }}" aria-label="Select all in {{ $group }}"></span></div>
        <div class="card-body">
        @foreach ($perms as $p)
            <div class="form-check"><input class="form-check-input" type="checkbox" name="permissions[]" value="{{ $p->id }}" id="p{{ $p->id }}" data-group="{{ Str::slug($group) }}" @checked(in_array($p->id, old('permissions', $selected)))>
            <label class="form-check-label" for="p{{ $p->id }}">{{ $p->label }} <small class="text-muted">{{ $p->key }}</small></label></div>
        @endforeach
        </div></div></div>
@endforeach
</div>
<div class="mt-3"><button class="btn btn-primary" type="submit">Save role</button> <a class="btn btn-light" href="{{ route('roles.index') }}">Cancel</a></div>
</form>
@endsection
