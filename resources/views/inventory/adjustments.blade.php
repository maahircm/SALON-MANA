@extends('layouts.app')
@section('title', 'Stock adjustments')
@section('content')
<div class="row g-4"><div class="col-lg-4"><div class="card"><div class="card-header">Record an adjustment</div><div class="card-body">
    <form method="post" action="{{ route('adjustments.store') }}">@csrf
        <div class="mb-3"><label class="form-label" for="f_product_id">Product</label><select id="f_product_id" name="product_id" class="form-select @error('product_id') is-invalid @enderror" required><option value="">Select…</option>@foreach ($products as $p)<option value="{{ $p->id }}" @selected((string) old('product_id', $selected) === (string) $p->id)>{{ $p->sku }} — {{ $p->name }} (now {{ qty($p->current_stock) }} {{ $p->unit }})</option>@endforeach</select>@error('product_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <x-select name="kind" label="Type" :options="\App\Models\InventoryAdjustment::KINDS" />
        <x-input name="quantity" label="Quantity" type="number" step="0.001" min="0" required help="Counted quantity for a stock count correction; the amount lost or found for the other types." />
        <x-input name="reason" label="Reason (required)" required />
        <button class="btn btn-primary w-100">Record adjustment</button></form></div></div></div>
<div class="col-lg-8"><div class="card"><div class="card-header">History</div><x-table :headers="['When', 'Product', 'Type', 'Before', 'Change', 'After', 'Reason', 'By']">
    @forelse ($adjustments as $a)<tr><td class="text-nowrap">{{ salon_datetime($a->created_at) }}</td><td>{{ $a->product->name }}</td><td>{{ \App\Models\InventoryAdjustment::KINDS[$a->kind] }}</td><td>{{ qty($a->previous_quantity) }}</td><td>{{ qty($a->quantity_delta) }}</td><td>{{ qty($a->new_quantity) }}</td><td class="small">{{ $a->reason }}</td><td>{{ $a->user->name }}</td></tr>
    @empty<tr><td colspan="8"><x-empty-state title="No adjustments yet" /></td></tr>@endforelse</x-table><x-pagination :paginator="$adjustments" /></div></div></div>
@endsection
