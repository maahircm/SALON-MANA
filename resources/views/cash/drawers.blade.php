@extends('layouts.app')
@section('title', 'Cash drawers')
@section('content')
<div class="card" style="max-width:640px"><x-table :headers="['Drawer', 'Sessions', 'Status', '']">
    @foreach ($drawers as $d)<tr><td>{{ $d->name }}</td><td>{{ $d->sessions_count }}</td><td><span class="badge text-bg-{{ $d->is_active ? 'success' : 'secondary' }}">{{ $d->is_active ? 'Active' : 'Inactive' }}</span></td>
        <td class="text-end"><x-confirm-form :action="route('cash.drawers.toggle', $d)" :message="($d->is_active ? 'Deactivate ' : 'Activate ').$d->name.'?'" :label="$d->is_active ? 'Deactivate' : 'Activate'" class="btn btn-sm btn-outline-secondary" /></td></tr>@endforeach</x-table>
    <form class="d-flex gap-2 p-3 border-top" method="post" action="{{ route('cash.drawers.store') }}">@csrf<input class="form-control form-control-sm" name="name" placeholder="e.g. Front desk" aria-label="Drawer name" required><button class="btn btn-sm btn-primary">Add</button></form></div>
<a class="btn btn-light mt-3" href="{{ route('cash.index') }}">Back</a>
@endsection
