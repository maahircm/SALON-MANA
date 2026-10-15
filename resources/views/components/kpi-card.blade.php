@props(['label', 'value', 'icon' => 'bi-graph-up', 'hint' => null])
<div class="card h-100"><div class="kpi d-flex justify-content-between align-items-start">
    <div><div class="label">{{ $label }}</div><div class="value">{{ $value }}</div>@if ($hint)<div class="hint">{{ $hint }}</div>@endif</div>
    <div class="icon"><i class="bi {{ $icon }}"></i></div>
</div></div>
