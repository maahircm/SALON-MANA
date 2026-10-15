@extends('layouts.app')
@section('title', 'Staff schedule')
@section('content')
<div class="card"><div class="card-header d-flex justify-content-between align-items-center">
    <span>Week of {{ $start->format('d M Y') }}</span>
    <div class="btn-group btn-group-sm"><a class="btn btn-outline-secondary" href="{{ route('schedules.index', ['week' => $start->subWeek()->toDateString()]) }}">‹ Previous</a>
        <a class="btn btn-outline-secondary" href="{{ route('schedules.index') }}">This week</a><a class="btn btn-outline-secondary" href="{{ route('schedules.index', ['week' => $start->addWeek()->toDateString()]) }}">Next ›</a></div></div>
<x-table :headers="array_merge(['Employee'], $days->map(fn ($d) => $d->format('D d'))->all())">
    @forelse ($employees as $e)<tr><td class="text-nowrap"><a href="{{ route('employees.show', $e) }}">{{ $e->name }}</a></td>
        @foreach ($days as $d)@php $w = $grid[$e->id][$d->toDateString()]; @endphp<td class="small {{ $w ? '' : 'text-muted' }}">{{ $w ? implode(', ', $w) : 'Off' }}</td>@endforeach</tr>
    @empty<tr><td colspan="8"><x-empty-state title="No employees yet" /></td></tr>@endforelse
</x-table></div>
<div class="text-muted small mt-2">Shows effective hours: dated shifts override weekly hours and are limited to salon opening hours.</div>
@endsection
