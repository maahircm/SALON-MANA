@extends('layouts.app')
@section('title', 'Roles & Permissions')
@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">Roles <a class="btn btn-sm btn-primary" href="{{ route('roles.create') }}"><i class="bi bi-plus-lg"></i> New role</a></div>
    <x-table :headers="['Role', 'Type', 'Permissions', 'Users', '']">
        @foreach ($roles as $r)
        <tr><td>{{ $r->name }}<div class="small text-muted">{{ $r->description }}</div></td>
            <td>{{ $r->is_system ? 'Predefined' : 'Custom' }}</td>
            <td>{{ $r->isSuperAdmin() ? 'All (implicit)' : $r->permissions_count }}</td><td>{{ $r->users_count }}</td>
            <td class="text-end text-nowrap">
                @unless ($r->isSuperAdmin())<a class="btn btn-sm btn-outline-secondary" href="{{ route('roles.edit', $r) }}">Edit</a>@endunless
                @unless ($r->is_system)<x-confirm-form method="delete" :action="route('roles.destroy', $r)" message="Delete role {{ $r->name }}?" label="Delete" />@endunless
            </td></tr>
        @endforeach
    </x-table>
</div>
@endsection
