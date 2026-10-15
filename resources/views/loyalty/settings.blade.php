@extends('layouts.app')
@section('title', 'Loyalty program settings')
@section('content')
<form method="post" action="{{ route('loyalty.settings.update') }}" class="card" style="max-width:680px">@csrf @method('PUT')<div class="card-body">
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="enabled" @checked(old('enabled', $values['enabled']))><label class="form-check-label" for="enabled">Loyalty program enabled (earning and redeeming)</label></div>
    <div class="row"><div class="col-md-6"><x-input name="earn_points_per_unit" label="Points earned per {{ config('salon.currency_symbol') }}1 spent" type="number" step="0.01" min="0" :value="$values['earn_points_per_unit']" required help="On net spend (after discounts, before tax), when an invoice is fully paid. Not on memberships or the part paid with points." /></div>
        <div class="col-md-6"><x-input name="redeem_points_per_unit" label="Points needed per {{ config('salon.currency_symbol') }}1 of value" type="number" step="0.01" min="0.01" :value="$values['redeem_points_per_unit']" required /></div>
        <div class="col-md-6"><x-input name="min_redeem_points" label="Minimum points per redemption" type="number" min="0" :value="$values['min_redeem_points']" required /></div>
        <div class="col-md-6"><x-input name="max_redeem_percent" label="Max share of an invoice payable with points (%)" type="number" step="0.01" min="0" max="100" :value="$values['max_redeem_percent']" required /></div>
        <div class="col-md-6"><x-input name="vip_threshold" label="VIP segment: lifetime value from" type="number" step="0.01" min="0" :value="$values['vip_threshold']" required /></div></div>
</div><div class="card-footer bg-transparent"><button class="btn btn-primary">Save settings</button> <a class="btn btn-light" href="{{ route('loyalty.index') }}">Back</a></div></form>
@endsection
