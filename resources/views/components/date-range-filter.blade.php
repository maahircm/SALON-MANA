@props(['range', 'extra' => null])
<form method="get" class="d-flex flex-wrap gap-2 align-items-center date-range-filter">
    {{ $extra }}
    <select class="form-select form-select-sm" style="width:auto" name="range" aria-label="Period" onchange="if (this.value) { this.form.from.value = ''; this.form.to.value = ''; this.form.submit(); }">
        <option value="">Custom</option>@foreach (\App\Support\DateRange::PRESETS as $k => $v)<option value="{{ $k }}" @selected(request('range') === $k)>{{ $v }}</option>@endforeach
    </select>
    <input class="form-control form-control-sm" style="width:auto" type="date" name="from" value="{{ $range->from->toDateString() }}" aria-label="From date">
    <input class="form-control form-control-sm" style="width:auto" type="date" name="to" value="{{ $range->to->toDateString() }}" aria-label="To date">
    <button class="btn btn-sm btn-outline-secondary">Apply</button>
</form>
