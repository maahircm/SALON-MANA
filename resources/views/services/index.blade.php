@extends('layouts.app')
@section('title', 'Service catalogue')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <form class="d-flex gap-2" method="get">
            <input class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="Search services" aria-label="Search services">
            <select class="form-select form-select-sm" name="category" aria-label="Category" style="width:auto"><option value="">All categories</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected((int) request('category') === $c->id)>{{ $c->name }}</option>@endforeach</select>
            <select class="form-select form-select-sm" name="status" aria-label="Status" style="width:auto"><option value="">Any</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('categories.index') }}">Categories</a>
            @can('services.manage')<a class="btn btn-sm btn-primary" href="{{ route('services.create') }}"><i class="bi bi-plus-lg"></i> New service</a>@endcan
        </div>
    </div>
    <x-table :headers="['Service', 'Category', 'Duration', 'Price', 'Tax', 'Skill', 'Status', '']">
        @forelse ($services as $s)
        <tr><td><a href="{{ route('services.show', $s) }}">{{ $s->name }}</a></td><td>{{ $s->category->name }}</td><td>{{ $s->duration_minutes }} min</td>
            <td>@if ($s->hasActivePromo())<s class="text-muted">{{ money($s->base_price) }}</s> <strong>{{ money($s->promo_price) }}</strong> <span class="badge text-bg-warning">Promo</span>@else{{ money($s->base_price) }}@endif</td>
            <td>{{ rtrim(rtrim($s->tax_rate, '0'), '.') }}%</td><td>{{ $s->requiredSkill?->name ?? '—' }}</td>
            <td><span class="badge text-bg-{{ $s->is_active ? 'success' : 'secondary' }}">{{ $s->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('services.show', $s) }}">View</a></td></tr>
        @empty<tr><td colspan="8"><x-empty-state icon="bi-scissors" title="No services yet">@can('services.manage')<a href="{{ route('services.create') }}">Add a service</a> (create a category first if none exist).@endcan</x-empty-state></td></tr>@endforelse
    </x-table>
    <x-pagination :paginator="$services" />
</div>
@endsection
