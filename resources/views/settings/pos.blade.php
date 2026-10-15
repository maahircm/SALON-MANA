@extends('layouts.app')
@section('title', 'POS settings')
@section('content')
<form method="post" action="{{ route('settings.pos.update') }}" class="row g-4">@csrf @method('PUT')
<div class="col-lg-6"><div class="card"><div class="card-header">Numbering &amp; policy</div><div class="card-body">
    <div class="row"><div class="col"><x-input name="invoice_prefix" label="Invoice prefix" :value="$values['invoice_prefix']" required /></div><div class="col"><x-input name="refund_prefix" label="Refund prefix" :value="$values['refund_prefix']" required /></div>
        <div class="col"><x-input name="number_padding" label="Digits" type="number" min="3" max="10" :value="$values['number_padding']" required /></div></div>
    <div class="form-text mb-3">Numbers are sequential and gap-free. Changing the prefix only affects new documents.</div>
    <x-input name="max_discount_percent" label="Discount limit without approval (%)" type="number" step="0.01" min="0" max="100" :value="$values['max_discount_percent']" help="Larger discounts need the pos.discount_unlimited permission." required />
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="allow_credit_sales" value="1" id="credit" @checked(old('allow_credit_sales', $values['allow_credit_sales']))><label class="form-check-label" for="credit">Allow sales on account (customer balance)</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="require_open_drawer" value="1" id="drawer" @checked(old('require_open_drawer', $values['require_open_drawer']))><label class="form-check-label" for="drawer">Require an open drawer session to take payments / refunds</label></div>
</div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header">Receipt text</div><div class="card-body">
    <x-textarea name="receipt_header" label="Header (address, phone)" :value="$values['receipt_header']" rows="3" />
    <x-textarea name="receipt_footer" label="Footer" :value="$values['receipt_footer']" rows="2" />
</div></div><div class="mt-3"><button class="btn btn-primary">Save settings</button></div></div></form>
@endsection
