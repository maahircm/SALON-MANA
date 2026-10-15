@extends('layouts.app')
@section('title', 'Change password')
@section('content')
<div class="card" style="max-width:520px"><div class="card-body">
    <form method="post" action="{{ route('profile.password.update') }}">@csrf @method('PUT')
        <x-input name="current_password" label="Current password" type="password" required autocomplete="current-password" />
        <x-input name="password" label="New password" type="password" required autocomplete="new-password" help="At least {{ config('salon.password_min_length') }} characters with upper/lower case and a number." />
        <x-input name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />
        <button class="btn btn-primary" type="submit">Update password</button>
    </form>
</div></div>
@endsection
