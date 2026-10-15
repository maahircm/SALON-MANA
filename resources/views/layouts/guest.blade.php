@extends('layouts.base')
@section('body')
<div class="auth-wrap"><div class="auth-card">
    <div class="text-center mb-4"><i class="bi bi-scissors fs-1" style="color:var(--sm-accent)"></i><h1 class="h4 mt-2">{{ config('salon.name') }}</h1></div>
    <div class="card"><div class="card-body p-4">@yield('content')</div></div>
</div></div>
@endsection
