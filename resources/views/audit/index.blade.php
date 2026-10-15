@extends('layouts.app')
@section('title', 'Audit Log')
@section('content')
<div class="card">
    <div class="card-header"><form class="row g-2" method="get">
        <div class="col-md-3"><input class="form-control form-control-sm" name="action" value="{{ request('action') }}" placeholder="Action prefix, e.g. user." aria-label="Action"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="from" value="{{ request('from') }}" aria-label="From"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" type="date" name="to" value="{{ request('to') }}" aria-label="To"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
    </form></div>
    <x-table :headers="['When', 'Actor', 'Action', 'Entity', 'IP', '']">
        @forelse ($logs as $l)
        <tr><td class="text-nowrap">{{ $l->created_at->format(config('salon.date_format').' '.config('salon.time_format')) }}</td>
            <td>{{ $l->user?->name ?? $l->actor_label ?? 'system' }}</td><td><code>{{ $l->action }}</code></td>
            <td>{{ $l->entity_type ? class_basename($l->entity_type).' #'.$l->entity_id : '—' }}</td><td>{{ $l->ip_address }}</td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('audit.show', $l) }}">Details</a></td></tr>
        @empty
        <tr><td colspan="6"><x-empty-state icon="bi-journal-text" title="No audit entries match" /></td></tr>
        @endforelse
    </x-table>
    <x-pagination :paginator="$logs" />
</div>
@endsection
