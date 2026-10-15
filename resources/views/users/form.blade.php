@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'New user')
@section('content')
<form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="row g-4">@csrf @if ($user->exists) @method('PUT') @endif
<div class="col-lg-6"><div class="card"><div class="card-body">
    <x-input name="name" label="Full name" :value="$user->name" required />
    <x-input name="email" label="Email" type="email" :value="$user->email" required />
    <x-input name="phone" label="Phone" :value="$user->phone" />
    <x-input name="password" :label="$user->exists ? 'New password (leave blank to keep)' : 'Initial password'" type="password" :required="! $user->exists" autocomplete="new-password" help="The user must change it at first sign-in." />
    <x-input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" />
</div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header">Roles</div><div class="card-body">
    @foreach ($roles as $role)
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="roles[]" value="{{ $role->id }}" id="role{{ $role->id }}" @checked(in_array($role->id, old('roles', $selected)))>
        <label class="form-check-label" for="role{{ $role->id }}">{{ $role->name }} <small class="text-muted">{{ $role->description }}</small></label></div>
    @endforeach
    <small class="text-muted">You can only assign roles whose permissions you already hold.</small>
</div></div>
<div class="mt-3"><button class="btn btn-primary" type="submit">Save</button> <a class="btn btn-light" href="{{ route('users.index') }}">Cancel</a></div></div>
</form>
@endsection
