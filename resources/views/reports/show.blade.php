@extends('layouts.app')
@section('title', $definition['title'])
@php
    $fmt = function ($value, string $type) {
        return match ($type) {
            'money' => money($value),
            'int' => number_format((int) $value),
            'qty' => rtrim(rtrim(number_format((float) $value, 3), '0'), '.') ?: '0',
            'percent' => number_format((float) $value, 1).'%',
            'date' => $value ? \Illuminate\Support\Carbon::parse($value)->format(config('salon.date_format')) : '—',
            'datetime' => $value ? \Illuminate\Support\Carbon::parse($value)->format(config('salon.date_format').' '.config('salon.time_format')) : '—',
            default => $value === null || $value === '' ? '—' : $value,
        };
    };
@endphp
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
        @if ($definition['dated'])
            <select class="form-select form-select-sm" style="width:auto" name="range" aria-label="Period" onchange="this.form.from.value=''; this.form.to.value=''; this.form.submit()"><option value="">Custom</option>@foreach (\App\Support\DateRange::PRESETS as $k => $v)<option value="{{ $k }}" @selected(request('range') === $k)>{{ $v }}</option>@endforeach</select>
            <input class="form-control form-control-sm" style="width:auto" type="date" name="from" value="{{ $range->from->toDateString() }}" aria-label="From date"><input class="form-control form-control-sm" style="width:auto" type="date" name="to" value="{{ $range->to->toDateString() }}" aria-label="To date">
        @endif
        @if ($definition['period'])<select class="form-select form-select-sm" style="width:auto" name="period" aria-label="Group by">@foreach (['day' => 'By day', 'week' => 'By week', 'month' => 'By month'] as $k => $v)<option value="{{ $k }}" @selected($period === $k)>{{ $v }}</option>@endforeach</select>@endif
        @if ($definition['dated'] || $definition['period'])<button class="btn btn-sm btn-outline-secondary">Apply</button>@endif
    </form>
    <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('reports.index') }}">All reports</a>
        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="{{ request()->fullUrlWithQuery(['print' => 1]) }}"><i class="bi bi-printer"></i> Print view</a>
        @can('reports.export')<a class="btn btn-sm btn-outline-secondary" href="{{ route('reports.export', ['report' => $key] + request()->except(['page', 'print'])) }}"><i class="bi bi-download"></i> CSV</a>@endcan</div>
</div>
<div class="card"><div class="card-header"><strong>{{ $definition['title'] }}</strong> <span class="text-muted">· {{ $definition['dated'] ? $range->label() : 'current position' }}</span></div>
    @if ($truncated)<div class="alert alert-warning m-3 mb-0">Only the first {{ number_format(\App\Services\ReportCatalog::MAX_ROWS) }} rows are shown. Narrow the period.</div>@endif
    <x-table :headers="array_map(fn ($c) => $c[0], $definition['columns'])">
        @forelse ($rows as $row)<tr>@foreach ($definition['columns'] as $col => [$label, $type])<td @class(['text-end' => in_array($type, ['money', 'int', 'qty', 'percent'])])>{{ $fmt($row[$col] ?? null, $type) }}</td>@endforeach</tr>
        @empty<tr><td colspan="{{ count($definition['columns']) }}"><x-empty-state icon="bi-bar-chart" title="No data for this selection" /></td></tr>@endforelse
        @if ($rows->isNotEmpty() && $totals)<tr class="fw-semibold table-light">@foreach ($definition['columns'] as $col => [$label, $type])<td @class(['text-end' => in_array($type, ['money', 'int', 'qty', 'percent'])])>@if ($loop->first)Total @endif{{ isset($totals[$col]) ? $fmt($totals[$col], $type) : '' }}</td>@endforeach</tr>@endif
    </x-table>
    @if ($paginator)<x-pagination :paginator="$paginator" />@endif</div>
@if ($print)<script>window.addEventListener('load', () => window.print());</script>@endif
@endsection
