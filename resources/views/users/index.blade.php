@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <form class="d-flex gap-2" method="get">
            <input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="Search name or email" aria-label="Search users">
            <select class="form-select form-select-sm" name="status" aria-label="Status"><option value="">All</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
        @can('users.manage')<a class="btn btn-sm btn-primary" href="{{ route('users.create') }}"><i class="bi bi-plus-lg"></i> New user</a>@endcan
    </div>
    <x-table :headers="['Name', 'Email', 'Roles', '2FA', 'Status', 'Last sign-in', '']">
        @forelse ($users as $u)
        <tr>
            <td>{{ $u->name }}</td><td>{{ $u->email }}</td>
            <td>@foreach ($u->roles as $r)<span class="badge text-bg-light border">{{ $r->name }}</span> @endforeach</td>
            <td>{!! $u->hasTwoFactorEnabled() ? '<i class="bi bi-shield-check text-success"></i>' : '<span class="text-muted">—</span>' !!}</td>
            <td><span class="badge text-bg-{{ $u->is_active ? 'success' : 'secondary' }}">{{ $u->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td>{{ optional($u->last_login_at)->format(config('salon.date_format').' '.config('salon.time_format')) ?? '—' }}</td>
            <td class="text-end text-nowrap">
                @can('users.manage')
                    @unless ($u->isSuperAdmin() && ! auth()->user()->isSuperAdmin())
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('users.edit', $u) }}">Edit</a>
                        @if (! $u->is(auth()->user()))
                        <x-confirm-form :action="route('users.toggle-active', $u)" :message="($u->is_active ? 'Deactivate ' : 'Activate ').$u->name.'?'" :label="$u->is_active ? 'Deactivate' : 'Activate'" />
                        @endif
                    @endunless
                @endcan
            </td>
        </tr>
        @empty
        <tr><td colspan="7"><x-empty-state icon="bi-people" title="No users found">Try a different search.</x-empty-state></td></tr>
        @endforelse
    </x-table>
    <x-pagination :paginator="$users" />
</div>
@endsection
