@extends('layouts.app')
@section('title', $customer->exists ? 'Edit customer' : 'New customer')
@section('content')
@if (session('duplicates'))
<x-alert type="warning">
    <strong>Possible duplicate:</strong> another customer already uses this phone number:
    <ul class="mb-2">@foreach (session('duplicates') as $d)<li><a href="{{ route('customers.show', $d['id']) }}" target="_blank">{{ $d['number'] }} — {{ $d['name'] }}</a></li>@endforeach</ul>
    Check the box below and save again if this is genuinely a different person (e.g. a family phone).
    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="confirm_duplicate" value="1" id="confirm_duplicate" form="customerForm"><label class="form-check-label" for="confirm_duplicate">Save anyway</label></div>
</x-alert>
@endif
<form id="customerForm" method="post" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}" class="row g-4">@csrf @if ($customer->exists) @method('PUT') @endif
<div class="col-lg-7"><div class="card"><div class="card-body"><div class="row">
    <div class="col-md-6"><x-input name="name" label="Full name" :value="$customer->name" required /></div>
    <div class="col-md-6"><x-input name="phone" label="Phone" :value="$customer->phone" required help="Used to detect duplicates." /></div>
    <div class="col-md-6"><x-input name="email" label="Email" type="email" :value="$customer->email" /></div>
    <div class="col-md-6"><x-input name="date_of_birth" label="Date of birth" type="date" :value="$customer->date_of_birth?->toDateString()" max="{{ now()->subDay()->toDateString() }}" /></div>
    <div class="col-md-6"><x-select name="gender" label="Gender" :options="['female' => 'Female', 'male' => 'Male', 'other' => 'Other']" :value="$customer->gender" placeholder="Not specified" /></div>
    <div class="col-md-6"><x-select name="status" label="Status" :options="\App\Models\Customer::STATUSES" :value="$customer->status" /></div>
</div>
<x-textarea name="address" label="Address" :value="$customer->address" rows="2" />
<x-textarea name="notes" label="Notes" :value="$customer->notes" />
</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-body">
    <x-select name="preferred_employee_id" label="Preferred stylist" :options="$employees->pluck('name', 'id')->all()" :value="$customer->preferred_employee_id" placeholder="No preference" />
    <div class="form-check mb-1"><input type="hidden" name="marketing_consent" value="0"><input class="form-check-input" type="checkbox" name="marketing_consent" value="1" id="marketing_consent" @checked(old('marketing_consent', $customer->marketing_consent))>
        <label class="form-check-label" for="marketing_consent">Customer agrees to receive marketing messages</label></div>
    <div class="form-text">Marketing messages are only ever sent to customers with recorded consent.</div>
</div></div>
<div class="mt-3"><button class="btn btn-primary" type="submit">Save customer</button> <a class="btn btn-light" href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}">Cancel</a></div></div>
</form>
@endsection
