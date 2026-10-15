@extends('layouts.app')
@section('title', $service->name)
@section('content')
<div class="row g-4">
<div class="col-lg-5"><div class="card"><div class="card-body">
    <h2 class="h5">{{ $service->name }} <span class="badge text-bg-{{ $service->is_active ? 'success' : 'secondary' }}">{{ $service->is_active ? 'Active' : 'Inactive' }}</span></h2>
    <p class="text-muted">{{ $service->description }}</p>
    <dl class="row mb-0">
        <dt class="col-5">Category</dt><dd class="col-7">{{ $service->category->name }}</dd>
        <dt class="col-5">Duration</dt><dd class="col-7">{{ $service->duration_minutes }} min</dd>
        <dt class="col-5">Base price</dt><dd class="col-7">{{ money($service->base_price) }}</dd>
        <dt class="col-5">Promo price</dt><dd class="col-7">{{ $service->promo_price !== null ? money($service->promo_price).' ('.($service->promo_starts_on?->format('d M') ?? 'any').' – '.($service->promo_ends_on?->format('d M Y') ?? 'open').')' : '—' }}</dd>
        <dt class="col-5">Tax</dt><dd class="col-7">{{ $service->tax_rate }}%</dd>
        <dt class="col-5">Required skill</dt><dd class="col-7">{{ $service->requiredSkill?->name ?? '—' }}</dd>
    </dl>
</div>
@can('services.manage')<div class="card-footer bg-transparent d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('services.edit', $service) }}">Edit</a>
    <x-confirm-form method="delete" :action="route('services.destroy', $service)" message="Remove {{ $service->name }} from the catalogue? Existing bookings keep their details." label="Remove" /></div>@endcan</div></div>
<div class="col-lg-7">
    <div class="card mb-3"><div class="card-header">Add-ons</div><x-table :headers="['Add-on', 'Extra time', 'Price', '']">
        @forelse ($service->addons as $a)<tr><td>{{ $a->name }}</td><td>{{ $a->duration_minutes }} min</td><td>{{ money($a->price) }}</td>
            <td class="text-end">@can('services.manage')<x-confirm-form method="delete" :action="route('services.addons.destroy', [$service, $a])" message="Remove add-on?" label="Remove" />@endcan</td></tr>
        @empty<tr><td colspan="4" class="text-muted">No add-ons.</td></tr>@endforelse</x-table>
        @can('services.manage')<form class="row g-2 p-3 border-top" method="post" action="{{ route('services.addons.store', $service) }}">@csrf
            <div class="col-md-5"><input class="form-control form-control-sm" name="name" placeholder="Name" aria-label="Add-on name" required></div>
            <div class="col-md-3"><input class="form-control form-control-sm" type="number" name="duration_minutes" value="0" min="0" aria-label="Minutes"></div>
            <div class="col-md-2"><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="price" placeholder="Price" aria-label="Price" required></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div></form>@endcan</div>
    <div class="card"><div class="card-header">Price history</div><x-table :headers="['When', 'Base price', 'Promo price']">
        @foreach ($service->priceHistories as $h)<tr><td>{{ salon_datetime($h->created_at) }}</td><td>{{ $h->old_base_price !== null ? money($h->old_base_price).' → ' : '' }}{{ money($h->new_base_price) }}</td>
            <td>{{ $h->old_promo_price !== null ? money($h->old_promo_price).' → ' : '' }}{{ $h->new_promo_price !== null ? money($h->new_promo_price) : '—' }}</td></tr>@endforeach</x-table></div>
</div></div>
@endsection
