@extends('layouts.app')
@section('title', 'Point of Sale')
@php
    $oldItems = old('items');
    $initialItems = is_array($oldItems) ? array_values($oldItems) : $cart['items'];
    $initialPayments = is_array(old('payments')) ? array_values(old('payments')) : [];
    $chosenCustomer = old('customer_id') ? \App\Models\Customer::find(old('customer_id')) : $cart['customer'];
    $idempotencyKey = old('idempotency_key', (string) \Illuminate\Support\Str::uuid());
    $jsServices = $services->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'category' => $s->category->name, 'price' => $s->currentPrice(), 'tax' => (string) $s->tax_rate])->values()->all();
    $jsEmployees = $employees->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values()->all();
    $jsMethods = $methods->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'type' => $m->type])->values()->all();
    $jsConfig = ['symbol' => config('salon.currency_symbol'), 'maxDiscount' => (string) $maxDiscount, 'canDiscount' => $canDiscount];
@endphp
@section('content')
@if (! $session)<x-alert type="warning"><i class="bi bi-cash-stack"></i> You have no open cash drawer session, so payments will be refused. <a href="{{ route('cash.index') }}">Open one</a> (credit sales and drafts do not need it).</x-alert>@endif
<form method="post" action="{{ route('pos.store') }}" id="posForm" class="row g-4">@csrf
<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
<input type="hidden" name="draft_id" value="{{ old('draft_id', $cart['draft_id']) }}">
<input type="hidden" name="customer_id" id="customer_id" value="{{ $chosenCustomer?->id }}">
<div class="col-xl-8">
    <div class="card mb-3"><div class="card-header">Customer</div><div class="card-body">
        <div id="customerChosen" class="{{ $chosenCustomer ? '' : 'd-none' }}"><strong id="customerName">{{ $chosenCustomer?->name }}</strong> <small class="text-muted" id="customerMeta">{{ $chosenCustomer?->customer_number }}</small> <button type="button" class="btn btn-sm btn-link" id="customerClear">change</button></div>
        <div id="customerSearchBox" class="{{ $chosenCustomer ? 'd-none' : '' }}">
            <input type="search" class="form-control" id="customerSearch" placeholder="Search customer by name, phone or number (optional for fully paid walk-ins)" autocomplete="off" aria-label="Search customer">
            <div class="list-group mt-1" id="customerResults"></div></div>
    </div></div>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">Items
        <div class="d-flex gap-2"><select id="serviceSelect" class="form-select form-select-sm" style="min-width:260px" aria-label="Add service"><option value="">Add a service…</option>
            @foreach ($services->groupBy('category.name') as $cat => $list)<optgroup label="{{ $cat }}">@foreach ($list as $s)<option value="{{ $s->id }}">{{ $s->name }} — {{ money($s->currentPrice()) }}</option>@endforeach</optgroup>@endforeach</select></div></div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Service</th><th style="width:80px">Qty</th><th>Provider</th>@if ($canDiscount)<th style="width:190px">Discount</th>@endif<th class="text-end">Total</th><th></th></tr></thead><tbody id="cartBody"></tbody></table></div>
        <div id="cartEmpty" class="p-4 text-center text-muted">Pick a service above, or open an appointment's checkout.</div>
    </div>
    <x-textarea name="notes" label="Invoice notes (optional)" :value="$cart['notes']" rows="2" />
</div>
<div class="col-xl-4">
    <div class="card mb-3 position-sticky" style="top:70px"><div class="card-header">Summary</div><div class="card-body">
        <dl class="row mb-2"><dt class="col-6">Subtotal</dt><dd class="col-6 text-end" id="tSubtotal">—</dd>
            <dt class="col-6">Discounts</dt><dd class="col-6 text-end" id="tDiscount">—</dd>
            <dt class="col-6">Tax</dt><dd class="col-6 text-end" id="tTax">—</dd>
            <dt class="col-6 fs-5">Total</dt><dd class="col-6 text-end fs-5 fw-bold" id="tTotal">—</dd></dl>
        <hr>
        <div class="d-flex justify-content-between align-items-center mb-2"><strong>Payments</strong><div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" id="addPayment">+ Split</button><button type="button" class="btn btn-outline-primary" id="payRemaining">Pay full</button></div></div>
        <div id="paymentRows"></div>
        <dl class="row mt-2 mb-0"><dt class="col-6">Paying now</dt><dd class="col-6 text-end" id="tPaid">—</dd>
            <dt class="col-6" id="tBalanceLabel">Balance on account</dt><dd class="col-6 text-end fw-semibold" id="tBalance">—</dd>
            <dt class="col-6 text-muted small" id="tChangeLabel"></dt><dd class="col-6 text-end text-muted small" id="tChange"></dd></dl>
        <div class="d-grid gap-2 mt-3"><button class="btn btn-primary btn-lg" type="submit" name="action" value="checkout" id="btnCheckout"><i class="bi bi-receipt"></i> Issue invoice</button>
            <button class="btn btn-outline-secondary" type="submit" name="action" value="save_draft" formnovalidate>Save as draft</button></div>
        <div class="form-text mt-2">Prices and tax are recalculated on the server. Leaving a balance requires a customer.</div>
    </div></div>
    @if ($drafts->isNotEmpty())<div class="card"><div class="card-header">My drafts</div><ul class="list-group list-group-flush">
        @foreach ($drafts as $d)<li class="list-group-item d-flex justify-content-between align-items-center"><a href="{{ route('pos.index', ['draft' => $d->id]) }}">Draft #{{ $d->id }} · {{ $d->customer?->name ?? 'Walk-in' }} · {{ money($d->total) }}</a>
            {{-- The button targets a form declared after </form>: HTML forbids nesting forms inside the checkout form. --}}
            <button type="submit" form="discardDraft{{ $d->id }}" class="btn btn-sm btn-outline-danger py-0" aria-label="Discard draft #{{ $d->id }}">×</button></li>@endforeach</ul></div>@endif
</div>
<div id="hiddenInputs"></div>
</form>
@foreach ($drafts as $d)
<form id="discardDraft{{ $d->id }}" method="post" action="{{ route('pos.drafts.destroy', $d) }}" data-confirm="Discard draft #{{ $d->id }}?">@csrf @method('DELETE')</form>
@endforeach
@endsection
@push('scripts')
<script>
(function () {
  const cfg = {{ Illuminate\Support\Js::from($jsConfig) }};
  const services = {{ Illuminate\Support\Js::from($jsServices) }};
  const employees = {{ Illuminate\Support\Js::from($jsEmployees) }};
  const methods = {{ Illuminate\Support\Js::from($jsMethods) }};
  let items = {{ Illuminate\Support\Js::from($initialItems) }};
  let payments = {{ Illuminate\Support\Js::from($initialPayments) }};

  // ---- exact integer-cent maths, mirroring the server's Money helper (display only; the server is authoritative) ----
  const cents = v => { const s = String(v ?? '0').trim(); if (!/^\d+(\.\d+)?$/.test(s)) return 0; const [w, f = ''] = s.split('.'); return parseInt(w, 10) * 100 + parseInt((f + '00').slice(0, 2), 10) + (f.length > 2 && +f[2] >= 5 ? 1 : 0); };
  const fmt = c => cfg.symbol + (c / 100).toFixed(2);
  const pct = (amountCents, rate) => { const bp = cents(rate); return Math.floor((amountCents * bp * 2 + 10000) / 20000); };
  const svc = id => services.find(s => String(s.id) === String(id));
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function lineMath(it) {
    const s = svc(it.service_id); if (!s) return { gross: 0, disc: 0, tax: 0, total: 0 };
    const booked = !!it.appointment_service_id;
    const qty = booked ? 1 : Math.max(1, parseInt(it.quantity || 1, 10));
    const gross = cents(s.price) * qty;
    let disc = 0;
    if (cfg.canDiscount && it.discount_type === 'percent') disc = pct(gross, it.discount_value);
    else if (cfg.canDiscount && it.discount_type === 'fixed') disc = cents(it.discount_value);
    disc = Math.min(disc, gross);
    const net = gross - disc, tax = pct(net, s.tax);
    return { gross, disc, tax, total: net + tax };
  }
  function totals() {
    return items.reduce((a, it) => { const m = lineMath(it); a.gross += m.gross; a.disc += m.disc; a.tax += m.tax; a.total += m.total; return a; }, { gross: 0, disc: 0, tax: 0, total: 0 });
  }

  function render() {
    const body = document.getElementById('cartBody'); body.innerHTML = '';
    document.getElementById('cartEmpty').classList.toggle('d-none', items.length > 0);
    items.forEach((it, i) => {
      const s = svc(it.service_id); const m = lineMath(it); const booked = !!it.appointment_service_id;
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${esc(s ? s.name : 'Unknown service')}${booked ? ' <span class="badge text-bg-info">booked</span>' : ''}<div class="small text-muted">${s ? fmt(cents(s.price)) + ' · tax ' + s.tax + '%' : ''}</div></td>
        <td><input type="number" min="1" max="99" class="form-control form-control-sm" value="${booked ? 1 : (it.quantity || 1)}" ${booked ? 'disabled' : ''} data-f="quantity" aria-label="Quantity"></td>
        <td><select class="form-select form-select-sm" data-f="employee_id" aria-label="Provider"><option value="">—</option>${employees.map(e => `<option value="${e.id}" ${String(it.employee_id) === String(e.id) ? 'selected' : ''}>${esc(e.name)}</option>`).join('')}</select></td>
        ${cfg.canDiscount ? `<td><div class="input-group input-group-sm"><select class="form-select" data-f="discount_type" aria-label="Discount type"><option value="none">None</option><option value="percent" ${it.discount_type === 'percent' ? 'selected' : ''}>%</option><option value="fixed" ${it.discount_type === 'fixed' ? 'selected' : ''}>${cfg.symbol}</option></select><input type="number" min="0" step="0.01" class="form-control" value="${it.discount_value || ''}" data-f="discount_value" aria-label="Discount value"></div></td>` : ''}
        <td class="text-end">${fmt(m.total)}</td><td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove="${i}" aria-label="Remove line">✕</button></td>`;
      tr.querySelectorAll('[data-f]').forEach(el => el.addEventListener('change', () => { it[el.dataset.f] = el.value; render(); }));
      tr.querySelector('[data-remove]').addEventListener('click', () => { items.splice(i, 1); render(); });
      body.appendChild(tr);
    });
    renderPayments(); renderTotals();
  }

  function renderPayments() {
    const wrap = document.getElementById('paymentRows'); wrap.innerHTML = '';
    payments.forEach((p, i) => {
      const method = methods.find(m => String(m.id) === String(p.payment_method_id)) || methods[0];
      if (!p.payment_method_id && method) p.payment_method_id = method.id;
      const row = document.createElement('div'); row.className = 'border rounded p-2 mb-2';
      row.innerHTML = `<div class="d-flex gap-2 mb-1"><select class="form-select form-select-sm" data-p="payment_method_id" aria-label="Payment method">${methods.map(m => `<option value="${m.id}" ${String(m.id) === String(p.payment_method_id) ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}</select>
          <input type="number" min="0" step="0.01" class="form-control form-control-sm" style="max-width:120px" value="${p.amount ?? ''}" data-p="amount" placeholder="Amount" aria-label="Amount"><button type="button" class="btn btn-sm btn-link text-danger" data-del="${i}" aria-label="Remove payment">✕</button></div>
        <div class="d-flex gap-2">${method && method.type === 'cash' ? `<input type="number" min="0" step="0.01" class="form-control form-control-sm" value="${p.tendered ?? ''}" data-p="tendered" placeholder="Cash handed over" aria-label="Cash handed over">` : ''}
          ${method && method.type !== 'cash' ? `<input class="form-control form-control-sm" value="${esc(p.reference ?? '')}" data-p="reference" placeholder="Reference / txn id" aria-label="Reference">` : ''}</div>`;
      row.querySelectorAll('[data-p]').forEach(el => el.addEventListener('change', () => { p[el.dataset.p] = el.value; if (el.dataset.p === 'payment_method_id') renderPayments(); renderTotals(); }));
      row.querySelector('[data-p=amount]').addEventListener('input', e => { p.amount = e.target.value; renderTotals(); });
      row.querySelector('[data-del]').addEventListener('click', () => { payments.splice(i, 1); renderPayments(); renderTotals(); });
      wrap.appendChild(row);
    });
  }

  function renderTotals() {
    const t = totals(); const paid = payments.reduce((a, p) => a + cents(p.amount), 0);
    document.getElementById('tSubtotal').textContent = fmt(t.gross); document.getElementById('tDiscount').textContent = '−' + fmt(t.disc);
    document.getElementById('tTax').textContent = fmt(t.tax); document.getElementById('tTotal').textContent = fmt(t.total);
    document.getElementById('tPaid').textContent = fmt(paid);
    const bal = t.total - paid; const balEl = document.getElementById('tBalance');
    balEl.textContent = bal >= 0 ? fmt(bal) : 'Overpaid ' + fmt(-bal); balEl.className = 'col-6 text-end fw-semibold ' + (bal < 0 ? 'text-danger' : (bal > 0 ? 'text-warning' : 'text-success'));
    document.getElementById('tBalanceLabel').textContent = bal > 0 ? 'Balance on account' : 'Balance';
    const change = payments.reduce((a, p) => { const m = methods.find(x => String(x.id) === String(p.payment_method_id)); return a + (m && m.type === 'cash' && p.tendered ? Math.max(0, cents(p.tendered) - cents(p.amount)) : 0); }, 0);
    document.getElementById('tChangeLabel').textContent = change ? 'Change to give' : ''; document.getElementById('tChange').textContent = change ? fmt(change) : '';
    document.getElementById('btnCheckout').disabled = items.length === 0;
  }

  document.getElementById('serviceSelect').addEventListener('change', e => {
    if (!e.target.value) return; items.push({ service_id: e.target.value, quantity: 1, employee_id: '', discount_type: 'none', discount_value: '' }); e.target.value = ''; render();
  });
  document.getElementById('addPayment').addEventListener('click', () => { payments.push({ payment_method_id: methods[0]?.id, amount: '' }); renderPayments(); renderTotals(); });
  document.getElementById('payRemaining').addEventListener('click', () => {
    const t = totals(); const paidOthers = payments.slice(1).reduce((a, p) => a + cents(p.amount), 0);
    if (!payments.length) payments.push({ payment_method_id: methods[0]?.id });
    payments[0].amount = (Math.max(0, t.total - paidOthers) / 100).toFixed(2); renderPayments(); renderTotals();
  });

  // Build the real form fields just before submit so the server receives plain arrays.
  document.getElementById('posForm').addEventListener('submit', () => {
    const box = document.getElementById('hiddenInputs'); box.innerHTML = '';
    const add = (n, v) => { if (v === undefined || v === null) return; const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; box.appendChild(i); };
    items.forEach((it, i) => ['service_id', 'appointment_service_id', 'quantity', 'employee_id', 'discount_type', 'discount_value'].forEach(f => add(`items[${i}][${f}]`, it[f] ?? '')));
    payments.filter(p => p.amount !== '' && p.amount !== undefined).forEach((p, i) => ['payment_method_id', 'amount', 'reference', 'tendered'].forEach(f => add(`payments[${i}][${f}]`, p[f] ?? '')));
  }, true);

  // customer typeahead
  const search = document.getElementById('customerSearch'); let timer;
  search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => {
    const q = search.value.trim(), res = document.getElementById('customerResults'); if (q.length < 2) { res.innerHTML = ''; return; }
    fetch('{{ route('customers.search') }}?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(list => {
      res.innerHTML = ''; list.forEach(c => { const b = document.createElement('button'); b.type = 'button'; b.className = 'list-group-item list-group-item-action'; b.textContent = `${c.name} · ${c.phone || ''} · ${c.customer_number}`;
        b.onclick = () => { document.getElementById('customer_id').value = c.id; document.getElementById('customerName').textContent = c.name; document.getElementById('customerMeta').textContent = c.customer_number; document.getElementById('customerChosen').classList.remove('d-none'); document.getElementById('customerSearchBox').classList.add('d-none'); res.innerHTML = ''; }; res.appendChild(b); });
      if (!list.length) res.innerHTML = '<div class="list-group-item text-muted">No active customers found</div>'; }); }, 250); });
  document.getElementById('customerClear').addEventListener('click', () => { document.getElementById('customer_id').value = ''; document.getElementById('customerChosen').classList.add('d-none'); document.getElementById('customerSearchBox').classList.remove('d-none'); search.focus(); });

  render();
})();
</script>
@endpush
