@extends('layouts.app')
@section('title', $customer->name)
@section('content')
@if ($customer->isArchived())<x-alert type="secondary"><i class="bi bi-archive"></i> This customer is archived{{ $customer->anonymized_at ? ' and their personal data has been erased.' : '.' }}
    @if (! $customer->anonymized_at) @can('archive', $customer)<x-confirm-form :action="route('customers.restore', $customer->id)" message="Restore this customer?" label="Restore" class="btn btn-sm btn-outline-primary" />@endcan @endif</x-alert>@endif
<div class="row g-4">
<div class="col-lg-4">
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between"><div><div class="text-muted small">{{ $customer->customer_number }}</div><h2 class="h5 mb-1">{{ $customer->name }}</h2></div>
            <span class="badge text-bg-{{ $customer->status === 'active' ? 'success' : 'secondary' }} align-self-start">{{ \App\Models\Customer::STATUSES[$customer->status] }}</span></div>
        <dl class="row mb-0 small mt-2">
            <dt class="col-5">Phone</dt><dd class="col-7">{{ $customer->phone ?? '—' }}</dd>
            <dt class="col-5">Email</dt><dd class="col-7 text-break">{{ $customer->email ?? '—' }}</dd>
            <dt class="col-5">Birthday</dt><dd class="col-7">{{ $customer->date_of_birth?->format('d M Y') ?? '—' }}</dd>
            <dt class="col-5">Gender</dt><dd class="col-7">{{ ucfirst($customer->gender ?? '—') }}</dd>
            <dt class="col-5">Preferred stylist</dt><dd class="col-7">{{ $customer->preferredEmployee?->name ?? '—' }}</dd>
            <dt class="col-5">Marketing</dt><dd class="col-7">{{ $customer->marketing_consent ? 'Consented '.$customer->marketing_consent_at?->format('d M Y') : 'No consent' }}</dd>
            <dt class="col-5">Registered</dt><dd class="col-7">{{ $customer->registered_on?->format('d M Y') }}</dd>
            <dt class="col-5">Address</dt><dd class="col-7">{{ $customer->address ?? '—' }}</dd>
        </dl>
        @if ($customer->notes)<hr><div class="small">{{ $customer->notes }}</div>@endif
    </div>
    <div class="card-footer bg-transparent d-flex flex-wrap gap-2">
        @can('update', $customer)<a class="btn btn-sm btn-outline-secondary" href="{{ route('customers.edit', $customer) }}">Edit</a>@endcan
        @if ($customer->isBookable()) @can('appointments.create')<a class="btn btn-sm btn-primary" href="{{ route('appointments.create', ['customer_id' => $customer->id]) }}"><i class="bi bi-calendar-plus"></i> Book</a>@endcan @endif
        @if (! $customer->isArchived()) @can('archive', $customer)<x-confirm-form method="delete" :action="route('customers.destroy', $customer)" message="Archive {{ $customer->name }}? They will no longer be bookable; history is kept." label="Archive" />@endcan @endif
    </div></div>
    <div class="card mb-3"><div class="card-header">Lifetime value &amp; loyalty</div><div class="card-body text-muted small">Purchases, payments, loyalty points and lifetime value appear here once the POS and loyalty modules are enabled.</div></div>
    @can('archive', $customer) @if (! $customer->anonymized_at)
    <div class="card border-danger-subtle"><div class="card-header text-danger">Privacy erasure</div><div class="card-body">
        <p class="small text-muted">Permanently removes this customer's personal data (name, contact details, notes, preferences) while keeping appointment and financial records.</p>
        <form method="post" action="{{ route('customers.anonymize', $customer->id) }}" data-confirm="Permanently erase personal data for {{ $customer->customer_number }}? This cannot be undone.">@csrf
            <x-input name="reason" label="Reason (required)" required /><button class="btn btn-sm btn-outline-danger" type="submit">Erase personal data</button></form>
    </div></div>@endif @endcan
</div>
<div class="col-lg-8">
    <div class="card mb-3"><div class="card-header">Preferences &amp; notes</div>
        <div class="card-body">
        @forelse ($preferences as $p)
            <div class="d-flex justify-content-between border-bottom py-2"><div><span class="badge text-bg-{{ $p->is_sensitive ? 'warning' : 'light border' }}">{{ \App\Models\CustomerPreference::TYPES[$p->type][0] }}</span> {{ $p->value }}</div>
                @can('update', $customer)<x-confirm-form method="delete" :action="route('customers.preferences.destroy', [$customer, $p])" message="Remove this entry?" label="Remove" />@endcan</div>
        @empty<div class="text-muted small">No preferences recorded.</div>@endforelse
        @can('update', $customer)
        <form method="post" action="{{ route('customers.preferences.store', $customer) }}" class="row g-2 mt-2">@csrf
            <div class="col-md-3"><select class="form-select form-select-sm" name="type" aria-label="Type">@foreach (\App\Models\CustomerPreference::TYPES as $k => [$label, $sens])@if (! $sens || $canSensitive)<option value="{{ $k }}">{{ $label }}</option>@endif @endforeach</select></div>
            <div class="col-md-7"><input class="form-control form-control-sm" name="value" placeholder="e.g. prefers fragrance-free products" aria-label="Value" required></div>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div>
        </form>
        @if (! $canSensitive)<div class="form-text">Allergy and medical notes are restricted and hidden from your role.</div>@endif
        @endcan
        </div></div>
    <div class="card"><div class="card-header">Appointment history</div>
        <x-table :headers="['Appointment', 'When', 'Services', 'Status']">
            @forelse ($appointments as $a)
            <tr><td>@can('view', $a)<a href="{{ route('appointments.show', $a) }}">{{ $a->appointment_number }}</a>@else{{ $a->appointment_number }}@endcan</td><td>{{ salon_datetime($a->start_at) }}</td>
                <td>{{ $a->lines->pluck('service_name')->join(', ') }}</td><td><span class="badge" style="background:{{ $a->status->color() }}">{{ $a->status->label() }}</span></td></tr>
            @empty<tr><td colspan="4"><x-empty-state icon="bi-calendar" title="No appointments yet" /></td></tr>@endforelse
        </x-table></div>
</div></div>
@endsection
