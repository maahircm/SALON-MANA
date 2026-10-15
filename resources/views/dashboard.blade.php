@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="row g-3">
    <div class="col-md-4"><x-kpi-card label="Signed in as" :value="$user->name" icon="bi-person-badge" :hint="$user->email" /></div>
    <div class="col-md-4"><x-kpi-card label="Roles" :value="$user->roles->count()" icon="bi-shield-check" :hint="$user->roles->pluck('name')->join(', ') ?: 'No roles assigned'" /></div>
    <div class="col-md-4"><x-kpi-card label="Last sign-in" :value="optional($user->last_login_at)->format(config('salon.date_format').' '.config('salon.time_format')) ?? '—'" icon="bi-clock-history" :hint="$user->last_login_ip" /></div>
</div>
<x-alert type="info" class="mt-4">Business modules (customers, appointments, POS, inventory, finance, reports) are added in later phases; this dashboard will then show the operational and financial metrics your permissions allow.</x-alert>
@endsection
