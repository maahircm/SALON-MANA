@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<div class="row g-3">
    @forelse ($reports as $group => $items)
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">{{ $group }}</div><div class="list-group list-group-flush">
        @foreach ($items as $key => $report)
            <a class="list-group-item list-group-item-action" href="{{ route('reports.show', $key) }}"><div class="fw-semibold">{{ $report['title'] }}</div><div class="small text-muted">{{ $report['description'] }}</div></a>
        @endforeach
    </div></div></div>
    @empty
    <div class="col-12"><x-empty-state icon="bi-bar-chart" title="No reports available for your role" /></div>
    @endforelse
    @can('finance.view')
    <div class="col-lg-6"><div class="card h-100"><div class="card-header">Finance</div><div class="list-group list-group-flush"><a class="list-group-item list-group-item-action" href="{{ route('finance.summary') }}"><div class="fw-semibold">Financial summary</div><div class="small text-muted">Revenue, cash collected, receivables, cost of goods, expenses and estimated net result.</div></a></div></div></div>
    @endcan
</div>
@endsection
