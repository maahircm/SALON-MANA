@extends('layouts.app')
@section('title', $product->exists ? 'Edit product' : 'New product')
@section('content')
<form method="post" action="{{ $product->exists ? route('products.update', $product) : route('products.store') }}" class="row g-4">@csrf @if ($product->exists) @method('PUT') @endif
<div class="col-lg-7"><div class="card"><div class="card-body"><div class="row">
    <div class="col-md-6"><x-input name="sku" label="SKU" :value="$product->sku" required /></div>
    <div class="col-md-6"><x-input name="barcode" label="Barcode" :value="$product->barcode" help="Optional; used by the POS scanner." /></div>
    <div class="col-12"><x-input name="name" label="Product name" :value="$product->name" required /></div>
    <div class="col-md-6"><x-select name="product_category_id" label="Category" :options="$categories->pluck('name', 'id')->all()" :value="$product->product_category_id" placeholder="None" /></div>
    <div class="col-md-6"><x-select name="supplier_id" label="Preferred supplier" :options="$suppliers->pluck('name', 'id')->all()" :value="$product->supplier_id" placeholder="None" /></div>
    <div class="col-md-4"><x-select name="unit" label="Unit of measure" :options="\App\Models\Product::UNITS" :value="$product->unit" /></div>
    <div class="col-md-4"><x-input name="reorder_level" label="Reorder level" type="number" step="0.001" min="0" :value="$product->reorder_level" required /></div>
    <div class="col-md-4"><x-input name="expiry_date" label="Expiry date" type="date" :value="$product->expiry_date?->toDateString()" /></div>
</div><x-textarea name="description" label="Description" :value="$product->description" rows="2" /></div></div></div>
<div class="col-lg-5">
    <div class="card mb-3"><div class="card-header">Selling</div><div class="card-body">
        <x-input name="selling_price" label="Selling price ({{ config('salon.currency') }})" type="number" step="0.01" min="0" :value="$product->selling_price" required />
        <x-input name="tax_rate" label="Tax rate (%)" type="number" step="0.01" min="0" max="100" :value="$product->tax_rate" required />
        <div class="form-check mb-2"><input type="hidden" name="is_sellable" value="0"><input class="form-check-input" type="checkbox" name="is_sellable" value="1" id="is_sellable" @checked(old('is_sellable', $product->is_sellable))><label class="form-check-label" for="is_sellable">Sold at the till (untick for consumables used in services)</label></div>
        <div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $product->is_active))><label class="form-check-label" for="is_active">Active</label></div>
    </div></div>
    @unless ($product->exists)
    @can('inventory.adjust')<div class="card"><div class="card-header">Opening stock</div><div class="card-body">
        <x-input name="opening_quantity" label="Quantity on hand" type="number" step="0.001" min="0" help="Recorded in the stock ledger as opening stock." />
        <x-input name="opening_cost" label="Cost per unit" type="number" step="0.01" min="0" />
    </div></div>@endcan
    @else<x-alert type="info">Stock quantity and cost cannot be edited here. Use <a href="{{ route('adjustments.index', ['product' => $product->id]) }}">stock adjustments</a> or goods receipts, so every change is in the ledger.</x-alert>@endunless
    <div class="mt-3"><button class="btn btn-primary" type="submit">Save product</button> <a class="btn btn-light" href="{{ $product->exists ? route('products.show', $product) : route('products.index') }}">Cancel</a></div>
</div></form>
@endsection
