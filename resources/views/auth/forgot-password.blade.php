@extends('layouts.guest')
@section('title', 'Forgot password')
@section('content')
<x-validation-errors />
@if (session('success'))<x-alert type="success">{{ session('success') }}</x-alert>@endif
<form method="post" action="{{ route('password.email') }}">@csrf
    <p class="text-muted">Enter your email and we'll send a reset link.</p>
    <x-input name="email" label="Email" type="email" required autofocus />
    <button class="btn btn-primary w-100" type="submit">Email reset link</button>
</form>
<div class="mt-3 small"><a href="{{ route('login') }}">Back to sign in</a></div>
@endsection
