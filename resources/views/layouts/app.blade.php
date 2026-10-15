@extends('layouts.base')
@php
    // Only items whose route exists are rendered, so the menu never contains dead links.
    $nav = [
        'Overview' => [['dashboard', 'Dashboard', 'bi-speedometer2', 'dashboard.view']],
        'Administration' => [
            ['users.index', 'Users', 'bi-people', 'users.view'],
            ['roles.index', 'Roles & Permissions', 'bi-shield-lock', 'roles.manage'],
            ['audit.index', 'Audit Log', 'bi-journal-text', 'audit.view'],
        ],
    ];
@endphp
@section('body')
<aside class="sm-sidebar" aria-label="Main navigation">
    <a class="brand" href="{{ route('dashboard') }}"><i class="bi bi-scissors"></i> {{ config('salon.name') }}</a>
    @foreach ($nav as $heading => $items)
        @php $visible = collect($items)->filter(fn ($i) => \Illuminate\Support\Facades\Route::has($i[0]) && auth()->user()->can($i[3])); @endphp
        @if ($visible->isNotEmpty())
            <div class="nav-heading">{{ $heading }}</div>
            @foreach ($visible as [$route, $label, $icon])
                <a class="nav-item {{ request()->routeIs(Str::beforeLast($route, '.').'.*') || request()->routeIs($route) ? 'active' : '' }}" href="{{ route($route) }}"><i class="bi {{ $icon }}"></i> {{ $label }}</a>
            @endforeach
        @endif
    @endforeach
</aside>
<div class="sm-main">
    <header class="sm-topbar">
        <button class="btn btn-light d-lg-none" data-toggle-sidebar aria-label="Toggle navigation"><i class="bi bi-list"></i></button>
        <h1 class="h5 mb-0 flex-grow-1">@yield('title', 'Dashboard')</h1>
        <div class="dropdown">
            <button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-circle"></i> {{ auth()->user()->name }}</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="bi bi-person"></i> My profile</a></li>
                <li><a class="dropdown-item" href="{{ route('profile.password') }}"><i class="bi bi-key"></i> Change password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><form method="post" action="{{ route('logout') }}">@csrf<button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button></form></li>
            </ul>
        </div>
    </header>
    <main class="sm-content">
        <x-validation-errors />
        @yield('content')
    </main>
</div>
@endsection
