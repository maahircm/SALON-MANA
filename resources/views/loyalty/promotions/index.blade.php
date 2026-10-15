@extends('layouts.app')
@section('title', 'Promotions & coupons')
@section('content')
<div class="card"><div class="card-header d-flex justify-content-between align-items-center">Promotions @can('loyalty.manage')<a class="btn btn-sm btn-primary" href="{{ route('promotions.create') }}"><i class="bi bi-plus-lg"></i> New promotion</a>@endcan</div>
    <x-table :headers="['Promotion', 'Discount', 'Min. purchase', 'Runs', 'Coupons', 'Redeemed', 'Status', '']">
        @forelse ($promotions as $p)<tr><td><a href="{{ route('promotions.show', $p) }}">{{ $p->name }}</a></td><td>{{ $p->discount_type === 'percent' ? rtrim(rtrim((string) $p->discount_value, '0'), '.').'%' : money($p->discount_value) }}</td><td>{{ money($p->min_purchase) }}</td>
            <td class="small text-nowrap">{{ $p->starts_at->format('d M Y') }} – {{ $p->ends_at->format('d M Y') }}</td><td>{{ $p->coupons_count }}</td><td>{{ $p->redeemed_count }}</td>
            <td>@if ($p->isRunning())<span class="badge text-bg-success">Running</span>@elseif (! $p->is_active)<span class="badge text-bg-secondary">Inactive</span>@elseif (now()->lt($p->starts_at))<span class="badge text-bg-info">Scheduled</span>@else<span class="badge text-bg-dark">Ended</span>@endif</td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('promotions.show', $p) }}">Open</a></td></tr>
        @empty<tr><td colspan="8"><x-empty-state icon="bi-tags" title="No promotions yet" /></td></tr>@endforelse</x-table><x-pagination :paginator="$promotions" /></div>
@endsection
