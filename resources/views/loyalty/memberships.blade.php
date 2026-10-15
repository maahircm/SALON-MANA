@extends('layouts.app')
@section('title', 'Memberships')
@section('content')
<div class="card mb-4"><div class="card-header">Membership plans <small class="text-muted">— sold at the POS; benefits apply automatically while active</small></div>
    <x-table :headers="['Plan', 'Price', 'Days', 'Discount %', 'Points ×', 'Active', 'Members', '']">
        @forelse ($plans as $p)@php $dis = auth()->user()->cannot('loyalty.manage'); @endphp
        <tr><td><input class="form-control form-control-sm" form="plan{{ $p->id }}" name="name" value="{{ $p->name }}" aria-label="Name" @disabled($dis)><input type="hidden" form="plan{{ $p->id }}" name="description" value="{{ $p->description }}"></td>
            <td style="width:110px"><input class="form-control form-control-sm" form="plan{{ $p->id }}" name="price" type="number" step="0.01" value="{{ $p->price }}" aria-label="Price" @disabled($dis)></td>
            <td style="width:90px"><input class="form-control form-control-sm" form="plan{{ $p->id }}" name="duration_days" type="number" value="{{ $p->duration_days }}" aria-label="Days" @disabled($dis)></td>
            <td style="width:100px"><input class="form-control form-control-sm" form="plan{{ $p->id }}" name="discount_percent" type="number" step="0.01" value="{{ $p->discount_percent }}" aria-label="Discount percent" @disabled($dis)></td>
            <td style="width:90px"><input class="form-control form-control-sm" form="plan{{ $p->id }}" name="points_multiplier" type="number" step="0.01" value="{{ $p->points_multiplier }}" aria-label="Points multiplier" @disabled($dis)></td>
            <td><input type="hidden" form="plan{{ $p->id }}" name="is_active" value="0"><input class="form-check-input" type="checkbox" form="plan{{ $p->id }}" name="is_active" value="1" @checked($p->is_active) aria-label="Active" @disabled($dis)></td>
            <td>{{ $p->active_count }}</td><td class="text-end">@can('loyalty.manage')<button class="btn btn-sm btn-outline-secondary" form="plan{{ $p->id }}">Save</button>@endcan</td></tr>
        @empty<tr><td colspan="8"><x-empty-state icon="bi-award" title="No plans yet" /></td></tr>@endforelse</x-table>
    @foreach ($plans as $p)<form id="plan{{ $p->id }}" method="post" action="{{ route('memberships.plans.update', $p) }}">@csrf @method('PUT')</form>@endforeach
    @can('loyalty.manage')<form class="row g-2 p-3 border-top" method="post" action="{{ route('memberships.plans.store') }}">@csrf
        <div class="col-md-3"><input class="form-control form-control-sm" name="name" placeholder="Plan name" aria-label="Plan name" required></div><div class="col-md-2"><input class="form-control form-control-sm" name="price" type="number" step="0.01" min="0" placeholder="Price" aria-label="Price" required></div>
        <div class="col-md-2"><input class="form-control form-control-sm" name="duration_days" type="number" min="1" placeholder="Days" aria-label="Days" required></div><div class="col-md-2"><input class="form-control form-control-sm" name="discount_percent" type="number" step="0.01" min="0" max="100" value="0" aria-label="Discount percent"></div>
        <div class="col-md-2"><input class="form-control form-control-sm" name="points_multiplier" type="number" step="0.01" min="0" value="1" aria-label="Points multiplier"></div><div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Add</button></div></form>@endcan</div>
<div class="card"><div class="card-header d-flex justify-content-between align-items-center">Customer memberships <form class="d-flex gap-2" method="get"><select class="form-select form-select-sm" name="status" aria-label="Status"><option value="">All</option>@foreach (['active', 'expired', 'cancelled'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select><button class="btn btn-sm btn-outline-secondary">Filter</button></form></div>
    <x-table :headers="['Customer', 'Plan', 'Valid', 'Paid', 'Terms', 'Status', '']">
        @forelse ($memberships as $m)<tr><td><a href="{{ route('customers.show', $m->customer_id) }}">{{ $m->customer->name }}</a></td><td>{{ $m->plan->name }}</td><td class="text-nowrap">{{ $m->starts_on->format('d M Y') }} – {{ $m->ends_on->format('d M Y') }}</td><td>{{ money($m->price_paid) }}</td>
            <td class="small">{{ rtrim(rtrim((string) $m->discount_percent, '0'), '.') }}% off · {{ $m->points_multiplier }}× points</td><td><span class="badge text-bg-{{ ['active' => 'success', 'expired' => 'secondary', 'cancelled' => 'danger'][$m->status] }}">{{ ucfirst($m->status) }}</span></td>
            <td class="text-end">@if ($m->status === 'active') @can('loyalty.manage')<form class="d-flex gap-1" method="post" action="{{ route('memberships.cancel', $m) }}" data-confirm="Cancel this membership?">@csrf<input class="form-control form-control-sm" name="reason" placeholder="Reason" aria-label="Reason" required><button class="btn btn-sm btn-outline-danger">Cancel</button></form>@endcan @endif</td></tr>
        @empty<tr><td colspan="7"><x-empty-state title="No memberships sold yet" /></td></tr>@endforelse</x-table><x-pagination :paginator="$memberships" /></div>
@endsection
