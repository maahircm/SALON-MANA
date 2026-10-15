@extends('layouts.app')
@section('title', 'My profile')
@section('content')
@if (session('recovery_codes'))
<x-alert type="warning"><strong>Save these recovery codes now</strong> — they are shown only once. Each can be used a single time.
    <pre class="mt-2 mb-0">{{ implode("\n", session('recovery_codes')) }}</pre></x-alert>
@endif
<div class="row g-4">
<div class="col-lg-6">
    <div class="card"><div class="card-header">Profile</div><div class="card-body">
        <form method="post" action="{{ route('profile.update') }}">@csrf @method('PUT')
            <x-input name="name" label="Name" :value="$user->name" required />
            <div class="mb-3"><label class="form-label">Email</label><input class="form-control" value="{{ $user->email }}" disabled></div>
            <x-input name="phone" label="Phone" :value="$user->phone" />
            <x-select name="locale" label="Language" :options="['en' => 'English', 'so' => 'Somali (interface translation pending)']" :value="$user->locale" />
            <button class="btn btn-primary" type="submit">Save</button>
            <a class="btn btn-light" href="{{ route('profile.password') }}">Change password</a>
        </form>
    </div></div>
</div>
<div class="col-lg-6">
    <div class="card mb-4"><div class="card-header">Two-factor authentication</div><div class="card-body">
    @if ($user->hasTwoFactorEnabled())
        <p class="text-success"><i class="bi bi-shield-check"></i> Enabled</p>
        <form method="post" action="{{ route('profile.two-factor.disable') }}" data-confirm="Disable two-factor authentication?">@csrf @method('DELETE')
            <x-input name="current_password" label="Confirm password to disable" type="password" required />
            <button class="btn btn-outline-danger" type="submit">Disable</button>
        </form>
    @elseif ($setupSecret)
        <p>Add this key to an authenticator app (Google Authenticator, Authy, 1Password…):</p>
        <p><code class="user-select-all fs-6">{{ $setupSecret }}</code></p>
        <p class="small text-muted">Or open on your phone: <span class="user-select-all text-break">{{ $otpauth }}</span></p>
        <form method="post" action="{{ route('profile.two-factor.confirm') }}">@csrf
            <x-input name="code" label="Enter the 6-digit code to confirm" inputmode="numeric" required />
            <button class="btn btn-primary" type="submit">Confirm and enable</button>
        </form>
    @else
        <p class="text-muted">Add a second step at sign-in. Optional but recommended for administrators.</p>
        <form method="post" action="{{ route('profile.two-factor.enable') }}">@csrf<button class="btn btn-outline-primary" type="submit">Set up two-factor</button></form>
    @endif
    </div></div>
    <div class="card"><div class="card-header">Recent sign-in activity</div>
        <x-table :headers="['When', 'Event', 'IP']">
            @forelse ($activities as $a)<tr><td>{{ $a->created_at->format(config('salon.date_format').' '.config('salon.time_format')) }}</td><td>{{ str_replace('_', ' ', $a->event) }}</td><td>{{ $a->ip_address }}</td></tr>
            @empty<tr><td colspan="3"><x-empty-state title="No activity yet" /></td></tr>@endforelse
        </x-table>
    </div>
</div>
</div>
@endsection
