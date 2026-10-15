@extends('layouts.guest')
@section('title', 'Account recovery')
@section('content')
<h2 class="h5">Account recovery</h2>
<ul class="small">
    <li><strong>Forgot your password?</strong> Use <a href="{{ route('password.request') }}">Forgot password</a>. A reset link is emailed if mail is configured.</li>
    <li><strong>Lost your authenticator device?</strong> Sign in with one of your saved recovery codes, then disable and re-enable two-factor authentication on your profile.</li>
    <li><strong>Account deactivated or no recovery codes?</strong> Ask a Salon Owner or Super Administrator to reset your password or two-factor settings.</li>
    <li><strong>Locked out as the only administrator?</strong> On the server run <code>php artisan salon:create-admin</code>.</li>
</ul>
<a href="{{ route('login') }}">Back to sign in</a>
@endsection
