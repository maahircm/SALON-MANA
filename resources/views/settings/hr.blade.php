@extends('layouts.app')
@section('title', 'Commission & attendance settings')
@section('content')
<form method="post" action="{{ route('settings.hr.update') }}" class="card" style="max-width:680px">@csrf @method('PUT')<div class="card-body">
    <x-select name="accrue_on" label="Commission is earned when" :options="['issue' => 'The invoice is issued (default)', 'full_payment' => 'The invoice is paid in full']" :value="$accrueOn" />
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="include_products" value="1" id="incp" @checked(old('include_products', $includeProducts))><label class="form-check-label" for="incp">Also pay commission on retail product lines handled by an employee</label></div>
    <x-input name="grace_minutes" label="Late-arrival grace period (minutes)" type="number" min="0" max="120" :value="$grace" required />
    <div class="small text-muted">Each employee's rate and type are set on their profile. A commission keeps the rate that applied when the sale was made.</div>
</div><div class="card-footer bg-transparent"><button class="btn btn-primary">Save settings</button></div></form>
@endsection
