@extends('layouts.app')
@section('title', 'Inventory settings')
@section('content')
<form method="post" action="{{ route('settings.inventory.update') }}" class="card" style="max-width:640px">@csrf @method('PUT')<div class="card-body">
    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="allow_negative_stock" value="1" id="neg" @checked(old('allow_negative_stock', $values['allow_negative_stock']))><label class="form-check-label" for="neg">Allow stock to go negative</label></div>
    <div class="form-text mb-3">Off by default: a sale that would take stock below zero is refused. If enabled, every negative balance is recorded in the audit log.</div>
    <x-input name="expiry_alert_days" label="Flag products expiring within (days)" type="number" min="1" max="365" :value="$values['expiry_alert_days']" required />
    <div class="row"><div class="col"><x-input name="purchase_order_prefix" label="Purchase order prefix" :value="$values['purchase_order_prefix']" required /></div><div class="col"><x-input name="goods_receipt_prefix" label="Goods receipt prefix" :value="$values['goods_receipt_prefix']" required /></div><div class="col"><x-input name="supplier_return_prefix" label="Return prefix" :value="$values['supplier_return_prefix']" required /></div></div>
</div><div class="card-footer bg-transparent"><button class="btn btn-primary">Save settings</button></div></form>
@endsection
