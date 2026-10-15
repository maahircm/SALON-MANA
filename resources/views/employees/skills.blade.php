@extends('layouts.app')
@section('title', 'Skills & specialties')
@section('content')
<div class="card" style="max-width:640px"><x-table :headers="['Skill', 'Employees', '']">
    @forelse ($skills as $s)<tr><td>{{ $s->name }}</td><td>{{ $s->employees_count }}</td><td class="text-end">@can('employees.manage')<x-confirm-form method="delete" :action="route('skills.destroy', $s)" message="Delete skill {{ $s->name }}?" label="Delete" />@endcan</td></tr>
    @empty<tr><td colspan="3"><x-empty-state title="No skills yet" /></td></tr>@endforelse</x-table>
@can('employees.manage')<form class="d-flex gap-2 p-3 border-top" method="post" action="{{ route('skills.store') }}">@csrf<input class="form-control form-control-sm" name="name" placeholder="e.g. Hair colouring" aria-label="Skill name" required><button class="btn btn-sm btn-primary">Add</button></form>@endcan</div>
<a class="btn btn-light mt-3" href="{{ route('employees.index') }}">Back to employees</a>
@endsection
