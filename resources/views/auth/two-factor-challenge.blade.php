@extends('layouts.guest')
@section('title', 'Two-factor authentication')
@section('content')
<x-validation-errors />
<form method="post" action="{{ route('two-factor.challenge') }}">@csrf
    <p class="text-muted">Enter the 6-digit code from your authenticator app.</p>
    <x-input name="code" label="Authentication code" inputmode="numeric" autocomplete="one-time-code" autofocus />
    <button class="btn btn-primary w-100" type="submit">Verify</button>
</form>
<hr>
<form method="post" action="{{ route('two-factor.challenge') }}">@csrf
    <x-input name="recovery_code" label="Or use a recovery code" />
    <button class="btn btn-outline-secondary w-100" type="submit">Use recovery code</button>
</form>
@endsection
