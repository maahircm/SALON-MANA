@extends('layouts.app')
@section('title', $supplier->exists ? 'Edit supplier' : 'New supplier')
@section('content')
<form method="post" action="{{ $supplier->exists ? route('suppliers.update', $supplier) : route('suppliers.store') }}" class="card" style="max-width:720px">@csrf @if ($supplier->exists) @method('PUT') @endif
<div class="card-body"><div class="row"><div class="col-md-6"><x-input name="name" label="Supplier name" :value="$supplier->name" required /></div><div class="col-md-6"><x-input name="contact_person" label="Contact person" :value="$supplier->contact_person" /></div>
    <div class="col-md-6"><x-input name="phone" label="Phone" :value="$supplier->phone" /></div><div class="col-md-6"><x-input name="email" label="Email" type="email" :value="$supplier->email" /></div></div>
    <x-textarea name="address" label="Address" :value="$supplier->address" rows="2" /><x-textarea name="notes" label="Notes" :value="$supplier->notes" rows="2" />
    <div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $supplier->is_active))><label class="form-check-label" for="is_active">Active</label></div></div>
<div class="card-footer bg-transparent"><button class="btn btn-primary">Save supplier</button> <a class="btn btn-light" href="{{ $supplier->exists ? route('suppliers.show', $supplier) : route('suppliers.index') }}">Cancel</a></div></form>
@endsection
