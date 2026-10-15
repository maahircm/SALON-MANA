@extends('layouts.app')
@section('title', $order->exists ? 'Edit purchase order' : 'New purchase order')
@section('content')
@php $lines = old('lines', $lines); $supplierId = old('supplier_id', $order->supplier_id ?? request('supplier')); @endphp
<form method="post" action="{{ $order->exists ? route('purchase-orders.update', $order) : route('purchase-orders.store') }}" class="row g-4">@csrf @if ($order->exists) @method('PUT') @endif
<div class="col-lg-4"><div class="card"><div class="card-body">
    <div class="mb-3"><label class="form-label" for="f_supplier_id">Supplier <span class="text-danger">*</span></label><select id="f_supplier_id" name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror" required><option value="">Select…</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((string) $supplierId === (string) $s->id)>{{ $s->name }}</option>@endforeach</select>@error('supplier_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <x-input name="expected_on" label="Expected delivery" type="date" :value="$order->expected_on?->toDateString()" />
    <x-textarea name="notes" label="Notes" :value="$order->notes" rows="3" />
    <button class="btn btn-primary w-100" type="submit">Save draft</button><div class="form-text">Place the order from its page once saved.</div>
</div></div></div>
<div class="col-lg-8"><div class="card"><div class="card-header">Products to order <small class="text-muted">(leave quantity empty to skip a product)</small></div>
    <div style="max-height:560px;overflow:auto"><x-table :headers="['Product', 'Quantity', 'Unit cost']">
        @foreach ($products as $p)<tr data-supplier="{{ $p->supplier_id }}"><td>{{ $p->sku }} — {{ $p->name }} <small class="text-muted">({{ $p->unit }})</small></td>
            <td style="width:140px"><input class="form-control form-control-sm" type="number" step="0.001" min="0" name="lines[{{ $p->id }}][quantity]" value="{{ $lines[$p->id]['quantity'] ?? '' }}" aria-label="Quantity of {{ $p->name }}"></td>
            <td style="width:140px"><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="lines[{{ $p->id }}][unit_cost]" value="{{ $lines[$p->id]['unit_cost'] ?? (float) $p->cost_price }}" aria-label="Unit cost of {{ $p->name }}"></td></tr>@endforeach</x-table></div></div></div>
</form>
@endsection
