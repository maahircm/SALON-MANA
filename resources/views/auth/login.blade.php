@extends('layouts.guest')
@section('title', 'Sign in')
@section('content')
<form method="post" action="{{ url('/login') }}">@csrf
    <x-validation-errors />
    @if (session('success'))<x-alert type="success">{{ session('success') }}</x-alert>@endif
    <x-input name="email" label="Email" type="email" required autofocus autocomplete="username" />
    <x-input name="password" label="Password" type="password" required autocomplete="current-password" />
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="remember" id="remember" value="1"><label class="form-check-label" for="remember">Keep me signed in</label></div>
    <button class="btn btn-primary w-100" type="submit">Sign in</button>
</form>
<div class="d-flex justify-content-between mt-3 small"><a href="{{ route('password.request') }}">Forgot password?</a><a href="{{ route('recovery.help') }}">Account recovery help</a></div>
@endsection
