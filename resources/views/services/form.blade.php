@extends('layouts.app')
@section('title', $service->exists ? 'Edit service' : 'New service')
@section('content')
@if ($categories->isEmpty())<x-alert type="warning">Create a <a href="{{ route('categories.index') }}">service category</a> first.</x-alert>@endif
@php $priceLocked = $service->exists && ! auth()->user()->can('services.price'); @endphp
<form method="post" action="{{ $service->exists ? route('services.update', $service) : route('services.store') }}" class="row g-4">@csrf @if ($service->exists) @method('PUT') @endif
<div class="col-lg-7"><div class="card"><div class="card-body">
    <x-input name="name" label="Service name" :value="$service->name" required />
    <div class="row"><div class="col-md-6"><x-select name="service_category_id" label="Category" :options="$categories->pluck('name', 'id')->all()" :value="$service->service_category_id" placeholder="Select…" required /></div>
        <div class="col-md-6"><x-select name="required_skill_id" label="Required staff skill" :options="$skills->pluck('name', 'id')->all()" :value="$service->required_skill_id" placeholder="None" /></div>
        <div class="col-md-6"><x-input name="duration_minutes" label="Duration (minutes)" type="number" min="5" step="5" :value="$service->duration_minutes" required /></div>
        <div class="col-md-6"><x-input name="tax_rate" label="Tax rate (%)" type="number" min="0" max="100" step="0.01" :value="$service->tax_rate" required /></div></div>
    <x-textarea name="description" label="Description" :value="$service->description" />
    <div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $service->is_active))><label class="form-check-label" for="is_active">Active (bookable)</label></div>
</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-header">Pricing ({{ config('salon.currency') }})</div><div class="card-body">
    @if ($priceLocked)<x-alert type="info">You do not have permission to change prices. Current price: <strong>{{ money($service->base_price) }}</strong>.</x-alert>
    @else
    <x-input name="base_price" label="Base price" type="number" min="0" step="0.01" :value="$service->base_price" required />
    <x-input name="promo_price" label="Promotional price (optional)" type="number" min="0" step="0.01" :value="$service->promo_price" />
    <div class="row"><div class="col"><x-input name="promo_starts_on" label="Promo starts" type="date" :value="$service->promo_starts_on?->toDateString()" /></div><div class="col"><x-input name="promo_ends_on" label="Promo ends" type="date" :value="$service->promo_ends_on?->toDateString()" /></div></div>
    <div class="form-text">Price changes are recorded; existing appointments and invoices keep the price they were booked at.</div>
    @endif
</div></div>
<div class="mt-3"><button class="btn btn-primary" type="submit">Save service</button> <a class="btn btn-light" href="{{ $service->exists ? route('services.show', $service) : route('services.index') }}">Cancel</a></div></div>
</form>
@endsection
