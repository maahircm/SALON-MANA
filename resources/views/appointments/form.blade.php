@extends('layouts.app')
@section('title', $appointment->exists ? 'Reschedule '.$appointment->appointment_number : ($values['source'] === 'walk_in' ? 'Walk-in booking' : 'New booking'))
@section('content')
@php
    $lines = old('lines', $values['lines']);
    $needsReason = $appointment->exists && in_array($appointment->status, [\App\Enums\AppointmentStatus::CheckedIn, \App\Enums\AppointmentStatus::InService, \App\Enums\AppointmentStatus::Completed]);
@endphp
<form method="post" action="{{ $appointment->exists ? route('appointments.update', $appointment) : route('appointments.store') }}" class="row g-4" id="bookingForm">@csrf @if ($appointment->exists) @method('PUT') @endif
<input type="hidden" name="source" value="{{ old('source', $values['source']) }}">
<div class="col-lg-5">
    <div class="card mb-3"><div class="card-header">Customer</div><div class="card-body">
        @if ($appointment->exists)
            <div class="fw-semibold">{{ $customer->name }}</div><div class="text-muted small">{{ $customer->customer_number }} · {{ $customer->phone }}</div>
        @else
            <input type="hidden" name="customer_id" id="customer_id" value="{{ old('customer_id', $customer?->id) }}">
            <div id="customerChosen" class="mb-2 {{ old('customer_id', $customer?->id) ? '' : 'd-none' }}"><strong id="customerName">{{ $customer?->name }}</strong> <button type="button" class="btn btn-sm btn-link" id="customerChange">change</button></div>
            <div id="customerSearchBox" class="{{ old('customer_id', $customer?->id) ? 'd-none' : '' }}">
                <label class="form-label" for="customerSearch">Search by name, phone or number</label>
                <input type="search" class="form-control" id="customerSearch" autocomplete="off" placeholder="Type at least 2 characters">
                <div class="list-group mt-1" id="customerResults"></div>
                @can('customers.create')<div class="form-text"><a href="{{ route('customers.create') }}" target="_blank">Register a new customer</a> then search again.</div>@endcan
            </div>
            @error('customer_id')<div class="text-danger small">{{ $message }}</div>@enderror
        @endif
    </div></div>
    <div class="card"><div class="card-header">When</div><div class="card-body">
        <div class="row"><div class="col-6"><x-input name="date" label="Date" type="date" :value="$values['date']" required /></div>
            <div class="col-6"><x-input name="time" label="Start time" type="time" :value="$values['time']" required step="300" /></div></div>
        <button type="button" class="btn btn-sm btn-outline-secondary mb-2" id="checkAvail"><i class="bi bi-clock"></i> Show free times for first stylist</button>
        <div id="slots" class="d-flex flex-wrap gap-1"></div>
        <x-textarea name="notes" label="Appointment notes" :value="$appointment->notes" rows="2" />
        @if ($needsReason)<x-input name="reason" label="Reason for changing this appointment (required)" required />@endif
    </div></div>
</div>
<div class="col-lg-7"><div class="card"><div class="card-header d-flex justify-content-between align-items-center">Services <button type="button" class="btn btn-sm btn-outline-primary" id="addLine"><i class="bi bi-plus"></i> Add service</button></div>
    <div class="card-body" id="lines">@error('lines')<div class="text-danger small mb-2">{{ $message }}</div>@enderror</div>
    <div class="card-footer bg-transparent small text-muted">Services run one after another in the order shown. <span id="totals" class="fw-semibold"></span></div></div>
<div class="mt-3"><button class="btn btn-primary" type="submit">{{ $appointment->exists ? 'Save changes' : 'Book appointment' }}</button>
    <a class="btn btn-light" href="{{ $appointment->exists ? route('appointments.show', $appointment) : route('appointments.calendar') }}">Cancel</a></div></div>
</form>
<template id="lineTpl"><div class="border rounded p-2 mb-2 line-row"><div class="row g-2">
    <div class="col-md-5"><label class="form-label small">Service</label><select class="form-select form-select-sm svc" data-name="service_id" required></select></div>
    <div class="col-md-4"><label class="form-label small">Stylist</label><select class="form-select form-select-sm emp" data-name="employee_id" required></select></div>
    <div class="col-md-3"><label class="form-label small">Chair/room</label><select class="form-select form-select-sm room" data-name="room_id"><option value="">—</option></select></div>
    <div class="col-12 d-flex justify-content-between"><span class="small text-muted info"></span><button type="button" class="btn btn-sm btn-link text-danger remove">Remove</button></div>
    <div class="col-12 text-danger small errs"></div></div></div></template>
@endsection
@push('scripts')
@php
    $jsServices = $services->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'duration' => $s->duration_minutes, 'skill' => $s->required_skill_id, 'price' => (string) $s->currentPrice()])->values()->all();
    $jsEmployees = $employees->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'skills' => $e->skills->pluck('id')->values()->all()])->values()->all();
    $jsRooms = $rooms->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()->all();
    $jsLines = array_values($lines);
    $jsErrors = $errors->getMessages();
@endphp
<script>
(function () {
  const services = {{ Illuminate\Support\Js::from($jsServices) }};
  const employees = {{ Illuminate\Support\Js::from($jsEmployees) }};
  const rooms = {{ Illuminate\Support\Js::from($jsRooms) }};
  const initial = {{ Illuminate\Support\Js::from($jsLines) }};
  const serverErrors = {{ Illuminate\Support\Js::from($jsErrors) }};
  const money = '{{ config('salon.currency_symbol') }}';
  const wrap = document.getElementById('lines'), tpl = document.getElementById('lineTpl');

  function fill(sel, items, val, blank) {
    sel.innerHTML = (blank !== undefined ? '<option value="">' + blank + '</option>' : '') + items.map(i => `<option value="${i.id}">${i.name.replace(/</g, '&lt;')}</option>`).join('');
    if (val) sel.value = String(val);
  }
  function refreshRow(row) {
    const svc = services.find(s => String(s.id) === row.querySelector('.svc').value);
    const emp = row.querySelector('.emp'), cur = emp.value;
    const eligible = employees.filter(e => !svc || !svc.skill || e.skills.includes(svc.skill));
    fill(emp, eligible, cur, 'Select stylist…');
    row.querySelector('.info').textContent = svc ? svc.duration + ' min · ' + money + Number(svc.price).toFixed(2) : '';
    totals();
  }
  function reindex() { wrap.querySelectorAll('.line-row').forEach((row, i) => row.querySelectorAll('[data-name]').forEach(el => el.name = `lines[${i}][${el.dataset.name}]`)); }
  function addLine(v) {
    const row = tpl.content.firstElementChild.cloneNode(true);
    const s = row.querySelector('.svc'); fill(s, services, v && v.service_id, 'Select service…');
    fill(row.querySelector('.room'), rooms, v && v.room_id, '—');
    wrap.appendChild(row); reindex(); refreshRow(row);
    if (v && v.employee_id) row.querySelector('.emp').value = String(v.employee_id);
    s.addEventListener('change', () => refreshRow(row));
    row.querySelector('.remove').addEventListener('click', () => { if (wrap.querySelectorAll('.line-row').length > 1) { row.remove(); reindex(); totals(); } });
    return row;
  }
  function totals() {
    let min = 0, amt = 0;
    wrap.querySelectorAll('.line-row').forEach(r => { const s = services.find(x => String(x.id) === r.querySelector('.svc').value); if (s) { min += s.duration; amt += Number(s.price); } });
    document.getElementById('totals').textContent = min ? `Total ${min} min · ${money}${amt.toFixed(2)}` : '';
  }
  (initial.length ? initial : [{}]).forEach(v => addLine(v));
  document.getElementById('addLine').addEventListener('click', () => addLine({}));
  // show server-side line errors under the matching row
  Object.entries(serverErrors).forEach(([k, msgs]) => { const m = k.match(/^lines\.(\d+)\./); if (m) { const r = wrap.querySelectorAll('.line-row')[m[1]]; if (r) r.querySelector('.errs').textContent += msgs.join(' ') + ' '; } });

  // availability helper
  document.getElementById('checkAvail').addEventListener('click', function () {
    const row = wrap.querySelector('.line-row'), emp = row.querySelector('.emp').value, date = document.getElementById('f_date').value;
    const box = document.getElementById('slots');
    if (!emp || !date) { box.textContent = 'Choose a stylist and a date first.'; return; }
    let dur = 0; wrap.querySelectorAll('.line-row').forEach(r => { if (r.querySelector('.emp').value === emp) { const s = services.find(x => String(x.id) === r.querySelector('.svc').value); if (s) dur += s.duration; } });
    const p = new URLSearchParams({ employee_id: emp, date, duration: dur || 30, exclude: '{{ $appointment->exists ? $appointment->id : '' }}' });
    if (!p.get('exclude')) p.delete('exclude');
    box.textContent = 'Loading…';
    fetch('{{ route('appointments.availability') }}?' + p, { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(d => {
      box.innerHTML = d.slots.length ? '' : '<span class="text-muted small">No free times that day.</span>';
      d.slots.forEach(t => { const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm btn-outline-primary'; b.textContent = t; b.onclick = () => { document.getElementById('f_time').value = t; }; box.appendChild(b); });
    }).catch(() => { box.textContent = 'Could not load availability.'; });
  });

  // customer typeahead
  const search = document.getElementById('customerSearch');
  if (search) {
    let timer;
    search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(() => {
        const q = search.value.trim(), res = document.getElementById('customerResults');
        if (q.length < 2) { res.innerHTML = ''; return; }
        fetch('{{ route('customers.search') }}?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(list => {
          res.innerHTML = '';
          list.forEach(c => { const a = document.createElement('button'); a.type = 'button'; a.className = 'list-group-item list-group-item-action'; a.textContent = c.name + ' · ' + (c.phone || '') + ' · ' + c.customer_number;
            a.onclick = () => { document.getElementById('customer_id').value = c.id; document.getElementById('customerName').textContent = c.name; document.getElementById('customerChosen').classList.remove('d-none'); document.getElementById('customerSearchBox').classList.add('d-none'); res.innerHTML = ''; };
            res.appendChild(a); });
          if (!list.length) res.innerHTML = '<div class="list-group-item text-muted">No active customers found</div>';
        });
      }, 250);
    });
    document.getElementById('customerChange').addEventListener('click', () => { document.getElementById('customer_id').value = ''; document.getElementById('customerChosen').classList.add('d-none'); document.getElementById('customerSearchBox').classList.remove('d-none'); search.focus(); });
  }
})();
</script>
@endpush
