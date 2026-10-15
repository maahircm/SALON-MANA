@extends('layouts.app')
@section('title', $promotion->exists ? 'Edit promotion' : 'New promotion')
@section('content')
<form method="post" action="{{ $promotion->exists ? route('promotions.update', $promotion) : route('promotions.store') }}" class="card" style="max-width:760px">@csrf @if ($promotion->exists) @method('PUT') @endif
<div class="card-body"><x-input name="name" label="Promotion name" :value="$promotion->name" required /><x-textarea name="description" label="Description" :value="$promotion->description" rows="2" />
    <div class="row"><div class="col-md-4"><x-select name="discount_type" label="Discount type" :options="['percent' => 'Percentage', 'fixed' => 'Fixed amount']" :value="$promotion->discount_type" /></div>
        <div class="col-md-4"><x-input name="discount_value" label="Value" type="number" step="0.01" min="0.01" :value="$promotion->discount_value" required /></div>
        <div class="col-md-4"><x-input name="min_purchase" label="Minimum purchase" type="number" step="0.01" min="0" :value="$promotion->min_purchase" required /></div>
        <div class="col-md-6"><x-input name="starts_at" label="Starts" type="datetime-local" :value="$promotion->starts_at?->format('Y-m-d\TH:i')" required /></div><div class="col-md-6"><x-input name="ends_at" label="Ends" type="datetime-local" :value="$promotion->ends_at?->format('Y-m-d\TH:i')" required /></div>
        <div class="col-md-6"><x-input name="max_redemptions" label="Total redemption limit" type="number" min="1" :value="$promotion->max_redemptions" help="Across all its coupons. Empty = unlimited." /></div>
        <div class="col-md-6"><x-input name="per_customer_limit" label="Per-customer limit" type="number" min="1" :value="$promotion->per_customer_limit" help="Needs a customer on the sale." /></div></div>
    <div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $promotion->is_active))><label class="form-check-label" for="is_active">Active</label></div></div>
<div class="card-footer bg-transparent"><button class="btn btn-primary">Save promotion</button> <a class="btn btn-light" href="{{ $promotion->exists ? route('promotions.show', $promotion) : route('promotions.index') }}">Cancel</a></div></form>
@endsection
