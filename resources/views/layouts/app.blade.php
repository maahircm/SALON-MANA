@extends('layouts.base')
@php
    // Only items whose route exists are rendered, so the menu never contains dead links.
    $nav = [
        'Overview' => [['dashboard', 'Dashboard', 'bi-speedometer2', 'dashboard.view']],
        'Salon' => [
            ['appointments.calendar', 'Appointment Calendar', 'bi-calendar-week', 'appointments.view'],
            ['appointments.list', 'Appointments', 'bi-list-check', 'appointments.view'],
            ['customers.index', 'Customers', 'bi-person-heart', 'customers.view'],
            ['services.index', 'Services', 'bi-scissors', 'services.view'],
            ['packages.index', 'Packages', 'bi-box2-heart', 'services.view'],
        ],
        'Sales' => [
            ['pos.index', 'Point of Sale', 'bi-cart-check', 'pos.use'],
            ['invoices.index', 'Invoices', 'bi-receipt', 'pos.invoices'],
            ['payments.index', 'Payments & Refunds', 'bi-credit-card', 'pos.invoices'],
            ['cash.index', 'Cash Drawer', 'bi-cash-stack', 'pos.drawer'],
            ['cash.daily', 'Daily Summary', 'bi-calendar-check', 'pos.close'],
        ],
        'Inventory' => [
            ['products.index', 'Products', 'bi-box-seam', 'inventory.view'],
            ['movements.index', 'Stock Ledger', 'bi-journal-text', 'inventory.view'],
            ['purchase-orders.index', 'Purchase Orders', 'bi-clipboard-check', 'inventory.purchasing'],
            ['suppliers.index', 'Suppliers', 'bi-truck', 'inventory.suppliers'],
            ['adjustments.index', 'Stock Adjustments', 'bi-sliders2', 'inventory.adjust'],
            ['supplier-returns.index', 'Supplier Returns', 'bi-arrow-return-left', 'inventory.purchasing'],
            ['inventory.valuation', 'Valuation', 'bi-cash-stack', 'inventory.costs'],
        ],
        'Finance & Reports' => [
            ['expenses.index', 'Expenses', 'bi-wallet2', 'expenses.view'],
            ['finance.summary', 'Financial Summary', 'bi-bar-chart-line', 'finance.view'],
            ['reports.index', 'Reports', 'bi-file-earmark-bar-graph', 'reports.view'],
        ],
        'Customers & Loyalty' => [
            ['loyalty.index', 'Loyalty', 'bi-stars', 'loyalty.view'],
            ['memberships.index', 'Memberships', 'bi-award', 'loyalty.view'],
            ['promotions.index', 'Promotions & Coupons', 'bi-tags', 'loyalty.view'],
        ],
        'Team' => [
            ['employees.index', 'Employees', 'bi-person-badge', 'employees.view'],
            ['schedules.index', 'Staff Schedule', 'bi-calendar3', 'employees.view'],
            ['attendance.index', 'Attendance', 'bi-clock-history', 'attendance.self'],
            ['attendance.summary', 'Attendance Summary', 'bi-table', 'employees.attendance'],
            ['leave.index', 'Leave', 'bi-calendar-x', 'leave.request'],
            ['commissions.index', 'Commissions', 'bi-cash-coin', 'employees.commissions'],
            ['commissions.mine', 'My Commissions', 'bi-cash-coin', 'commissions.own'],
            ['rooms.index', 'Chairs & Rooms', 'bi-door-open', 'employees.schedule'],
        ],
        'Administration' => [
            ['users.index', 'Users', 'bi-people', 'users.view'],
            ['roles.index', 'Roles & Permissions', 'bi-shield-lock', 'roles.manage'],
            ['settings.general', 'General Settings', 'bi-building-gear', 'settings.manage'],
            ['notification-settings.edit', 'Notification Settings', 'bi-bell', 'notifications.manage'],
            ['notification-logs.index', 'Message Delivery Log', 'bi-envelope-check', 'notifications.manage'],
            ['settings.booking', 'Booking Settings', 'bi-gear', 'settings.manage'],
            ['settings.pos', 'POS Settings', 'bi-sliders', 'settings.manage'],
            ['settings.inventory', 'Inventory Settings', 'bi-boxes', 'settings.manage'],
            ['settings.hr', 'HR & Commission Settings', 'bi-people', 'settings.manage'],
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
                <a class="nav-item {{ request()->routeIs($route) || (! in_array($route, ['appointments.calendar', 'appointments.list', 'categories.index', 'cash.daily', 'pos.index', 'movements.index', 'inventory.valuation', 'attendance.summary', 'commissions.mine', 'memberships.index', 'promotions.index']) && request()->routeIs(Str::beforeLast($route, '.').'.*')) ? 'active' : '' }}" href="{{ route($route) }}"><i class="bi {{ $icon }}"></i> {{ $label }}</a>
            @endforeach
        @endif
    @endforeach
</aside>
<div class="sm-main">
    <header class="sm-topbar">
        <button class="btn btn-light d-lg-none" data-toggle-sidebar aria-label="Toggle navigation"><i class="bi bi-list"></i></button>
        <h1 class="h5 mb-0 flex-grow-1">@yield('title', 'Dashboard')</h1>
        <div class="dropdown">
            <button class="btn btn-light position-relative" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications"><i class="bi bi-bell"></i>@if ($unreadCount > 0)<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif</button>
            <div class="dropdown-menu dropdown-menu-end p-0" style="width:340px">
                @forelse ($recentNotifications as $n)<form method="post" action="{{ route('notifications.read', $n) }}">@csrf<button class="dropdown-item text-wrap border-bottom py-2 {{ $n->isUnread() ? 'fw-semibold' : 'text-muted' }}"><div>{{ $n->title }}</div><div class="small text-muted text-truncate">{{ \Illuminate\Support\Str::limit($n->body, 70) }}</div></button></form>
                @empty<div class="p-3 text-muted small">No notifications.</div>@endforelse
                <a class="dropdown-item text-center small py-2" href="{{ route('notifications.index') }}">View all</a>
            </div>
        </div>
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
