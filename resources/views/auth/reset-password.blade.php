@extends('layouts.guest')
@section('title', 'Reset password')
@section('content')
<x-validation-errors />
<form method="post" action="{{ route('password.update') }}">@csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <x-input name="email" label="Email" type="email" :value="$email" required />
    <x-input name="password" label="New password" type="password" required autocomplete="new-password" help="At least {{ config('salon.password_min_length') }} characters with upper/lower case and a number." />
    <x-input name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />
    <button class="btn btn-primary w-100" type="submit">Reset password</button>
</form>
@endsection
