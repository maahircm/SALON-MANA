@extends('layouts.app')
@section('title', 'Point of Sale')
@php
    $oldItems = old('items');
    $initialItems = is_array($oldItems) ? array_values($oldItems) : $cart['items'];
    $initialPayments = is_array(old('payments')) ? array_values(old('payments')) : [];
    $chosenCustomer = old('customer_id') ? \App\Models\Customer::with('loyaltyAccount:customer_id,balance')->find(old('customer_id')) : $cart['customer']?->loadMissing('loyaltyAccount:customer_id,balance');
    $idempotencyKey = old('idempotency_key', (string) \Illuminate\Support\Str::uuid());
    $couponCode = old('coupon_code', $cart['coupon_code']);
    $jsServices = $services->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'price' => $s->currentPrice(), 'tax' => (string) $s->tax_rate])->values()->all();
    $jsProducts = $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode, 'price' => (string) $p->selling_price, 'tax' => (string) $p->tax_rate, 'stock' => (string) $p->current_stock, 'unit' => $p->unit])->values()->all();
    $jsPlans = $plans->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price' => (string) $m->price])->values()->all();
    $jsEmployees = $employees->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values()->all();
    $jsMethods = $methods->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'type' => $m->type])->values()->all();
    $jsConfig = ['symbol' => config('salon.currency_symbol'), 'canDiscount' => $canDiscount, 'previewUrl' => route('pos.preview'), 'perUnit' => $loyalty['perUnit'], 'loyalty' => $loyalty['enabled'],
        'points' => (int) ($chosenCustomer?->loyaltyAccount?->balance ?? 0)];
@endphp
@section('content')
@if (! $session)<x-alert type="warning"><i class="bi bi-cash-stack"></i> You have no open cash drawer session, so payments will be refused. <a href="{{ route('cash.index') }}">Open one</a> (credit sales, points payments and drafts do not need it).</x-alert>@endif
<form method="post" action="{{ route('pos.store') }}" id="posForm" class="row g-4">@csrf
<input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
<input type="hidden" name="draft_id" id="draft_id" value="{{ old('draft_id', $cart['draft_id']) }}">
<input type="hidden" name="customer_id" id="customer_id" value="{{ $chosenCustomer?->id }}">
<div class="col-xl-8">
    <div class="card mb-3"><div class="card-header">Customer</div><div class="card-body">
        <div id="customerChosen" class="{{ $chosenCustomer ? '' : 'd-none' }}"><strong id="customerName">{{ $chosenCustomer?->name }}</strong> <small class="text-muted" id="customerMeta">{{ $chosenCustomer?->customer_number }}</small>
            <span class="badge text-bg-warning d-none" id="memberBadge"></span> <span class="badge text-bg-light border d-none" id="pointsBadge"></span> <button type="button" class="btn btn-sm btn-link" id="customerClear">change</button></div>
        <div id="customerSearchBox" class="{{ $chosenCustomer ? 'd-none' : '' }}">
            <input type="search" class="form-control" id="customerSearch" placeholder="Search customer by name, phone or number (optional for fully paid walk-ins)" autocomplete="off" aria-label="Search customer">
            <div class="list-group mt-1" id="customerResults"></div></div>
    </div></div>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">Items
        <div class="d-flex flex-wrap gap-2"><select id="serviceSelect" class="form-select form-select-sm" style="min-width:210px" aria-label="Add service"><option value="">Add a service…</option>
            @foreach ($services->groupBy('category.name') as $cat => $list)<optgroup label="{{ $cat }}">@foreach ($list as $s)<option value="{{ $s->id }}">{{ $s->name }} — {{ money($s->currentPrice()) }}</option>@endforeach</optgroup>@endforeach</select>
            @if ($products->isNotEmpty())<select id="productSelect" class="form-select form-select-sm" style="min-width:210px" aria-label="Add product"><option value="">Add a product…</option>
                @foreach ($products as $p)<option value="{{ $p->id }}" @disabled(\App\Support\Quantity::compare($p->current_stock, '0') <= 0)>{{ $p->name }} — {{ money($p->selling_price) }} ({{ qty($p->current_stock) }} {{ $p->unit }})</option>@endforeach</select>
            <input id="barcodeInput" class="form-control form-control-sm" style="width:150px" placeholder="Scan barcode ↵" aria-label="Barcode" autocomplete="off">@endif
            @if ($plans->isNotEmpty())<select id="planSelect" class="form-select form-select-sm" style="min-width:190px" aria-label="Sell membership"><option value="">Sell a membership…</option>@foreach ($plans as $m)<option value="{{ $m->id }}">{{ $m->name }} — {{ money($m->price) }}</option>@endforeach</select>@endif</div></div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Item</th><th style="width:80px">Qty</th><th>Provider</th>@if ($canDiscount)<th style="width:190px">Discount</th>@endif<th class="text-end">Total</th><th></th></tr></thead><tbody id="cartBody"></tbody></table></div>
        <div id="cartEmpty" class="p-4 text-center text-muted">Pick a service above, or open an appointment's checkout.</div>
        <div id="previewErrors" class="px-3 pb-2 text-danger small"></div>
    </div>
    <div class="row"><div class="col-md-6"><x-textarea name="notes" label="Invoice notes (optional)" :value="$cart['notes']" rows="2" /></div>
        <div class="col-md-6"><label class="form-label" for="coupon_code">Coupon code</label><input class="form-control text-uppercase" id="coupon_code" name="coupon_code" value="{{ $couponCode }}" maxlength="40" autocomplete="off" placeholder="Optional"><div class="form-text">Validated by the server when totals refresh.</div></div></div>
</div>
<div class="col-xl-4">
    <div class="card mb-3 position-sticky" style="top:70px"><div class="card-header">Summary</div><div class="card-body">
        <dl class="row mb-2"><dt class="col-6">Subtotal</dt><dd class="col-6 text-end" id="tSubtotal">—</dd>
            <dt class="col-6">Discounts</dt><dd class="col-6 text-end" id="tDiscount">—</dd>
            <dt class="col-6" id="tCouponLabel">Coupon</dt><dd class="col-6 text-end" id="tCoupon">—</dd>
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
        <div class="form-text mt-2">Totals come from the server, including member discounts and coupons. Leaving a balance requires a customer.</div>
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
  const products = {{ Illuminate\Support\Js::from($jsProducts) }};
  const plans = {{ Illuminate\Support\Js::from($jsPlans) }};
  const employees = {{ Illuminate\Support\Js::from($jsEmployees) }};
  const methods = {{ Illuminate\Support\Js::from($jsMethods) }};
  let items = {{ Illuminate\Support\Js::from($initialItems) }};
  let payments = {{ Illuminate\Support\Js::from($initialPayments) }};
  let preview = null;   // the server's authoritative pricing of the current cart
  let customerPoints = cfg.points;

  // ---- display helpers (money maths below is only a fallback until the server answers) ----
  const cents = v => { const s = String(v ?? '0').trim(); if (!/^\d+(\.\d+)?$/.test(s)) return 0; const [w, f = ''] = s.split('.'); return parseInt(w, 10) * 100 + parseInt((f + '00').slice(0, 2), 10) + (f.length > 2 && +f[2] >= 5 ? 1 : 0); };
  const fmt = c => cfg.symbol + (c / 100).toFixed(2);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const find = (list, id) => list.find(x => String(x.id) === String(id));
  const ref = it => it.membership_plan_id ? find(plans, it.membership_plan_id) : (it.product_id ? find(products, it.product_id) : find(services, it.service_id));
  const kind = it => it.membership_plan_id ? 'membership' : (it.product_id ? 'product' : 'service');

  function localLine(it) {
    const s = ref(it); if (!s) return { total: 0 };
    const qty = (it.appointment_service_id || it.membership_plan_id) ? 1 : Math.max(1, parseInt(it.quantity || 1, 10));
    const gross = cents(s.price) * qty; let disc = 0;
    if (cfg.canDiscount && it.discount_type === 'percent') disc = Math.floor((gross * cents(it.discount_value) * 2 + 10000) / 20000);
    else if (cfg.canDiscount && it.discount_type === 'fixed') disc = cents(it.discount_value);
    disc = Math.min(disc, gross); const net = gross - disc;
    return { gross, disc, total: net + Math.floor((net * cents(s.tax || 0) * 2 + 10000) / 20000) };
  }
  const lineTotalCents = (it, i) => preview && preview.lines[i] ? cents(preview.lines[i].line_total) : localLine(it).total;
  function totals() {
    if (preview) return { gross: cents(preview.subtotal), disc: cents(preview.discount_total), coupon: cents(preview.coupon_discount_total), tax: cents(preview.tax_total), total: cents(preview.total) };
    return items.reduce((a, it) => { const m = localLine(it); a.gross += m.gross || 0; a.disc += m.disc || 0; a.total += m.total; return a; }, { gross: 0, disc: 0, coupon: 0, tax: 0, total: 0 });
  }

  // ---- server preview ----
  let timer = null;
  function schedulePreview() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const box = document.getElementById('previewErrors');
      if (!items.length) { preview = null; box.textContent = ''; render(false); return; }
      const body = { customer_id: document.getElementById('customer_id').value || null, coupon_code: document.getElementById('coupon_code').value.trim() || null, draft_id: document.getElementById('draft_id').value || null,
        items: items.map(it => ({ service_id: it.service_id || null, product_id: it.product_id || null, membership_plan_id: it.membership_plan_id || null, appointment_service_id: it.appointment_service_id || null,
          quantity: it.quantity || 1, employee_id: it.employee_id || null, discount_type: it.discount_type || 'none', discount_value: it.discount_value || 0 })) };
      try {
        const r = await fetch(cfg.previewUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(body) });
        const data = await r.json();
        if (r.ok) { preview = data; box.textContent = ''; }
        else { preview = null; box.textContent = Object.values(data.errors || { e: [data.message || 'Could not price the cart.'] }).flat().join(' '); }
      } catch (e) { preview = null; }
      render(false);
    }, 200);
  }

  function render(refresh = true) {
    const body = document.getElementById('cartBody'); body.innerHTML = '';
    document.getElementById('cartEmpty').classList.toggle('d-none', items.length > 0);
    items.forEach((it, i) => {
      const s = ref(it), k = kind(it), booked = !!it.appointment_service_id, fixedQty = booked || k === 'membership', pl = preview && preview.lines[i];
      const tr = document.createElement('tr');
      const sub = k === 'product' && s ? ' · ' + s.stock + ' ' + s.unit + ' in stock' : '';
      const tag = pl && pl.discount_source === 'membership' ? ' <span class="badge text-bg-warning">member</span>' : '';
      tr.innerHTML = `<td>${k === 'membership' ? 'Membership: ' : ''}${esc(s ? s.name : 'Unknown item')}${k !== 'service' ? ' <span class="badge text-bg-secondary">' + k + '</span>' : ''}${booked ? ' <span class="badge text-bg-info">booked</span>' : ''}${tag}
          <div class="small text-muted">${s ? fmt(cents(s.price)) + (s.tax && k !== 'membership' ? ' · tax ' + s.tax + '%' : '') + sub : ''}</div></td>
        <td><input type="number" min="1" max="99" class="form-control form-control-sm" value="${fixedQty ? 1 : (it.quantity || 1)}" ${fixedQty ? 'disabled' : ''} data-f="quantity" aria-label="Quantity"></td>
        <td>${k === 'membership' ? '' : `<select class="form-select form-select-sm" data-f="employee_id" aria-label="Provider"><option value="">—</option>${employees.map(e => `<option value="${e.id}" ${String(it.employee_id) === String(e.id) ? 'selected' : ''}>${esc(e.name)}</option>`).join('')}</select>`}</td>
        ${cfg.canDiscount ? `<td>${k === 'membership' ? '' : `<div class="input-group input-group-sm"><select class="form-select" data-f="discount_type" aria-label="Discount type"><option value="none">None</option><option value="percent" ${it.discount_type === 'percent' ? 'selected' : ''}>%</option><option value="fixed" ${it.discount_type === 'fixed' ? 'selected' : ''}>${cfg.symbol}</option></select><input type="number" min="0" step="0.01" class="form-control" value="${it.discount_value || ''}" data-f="discount_value" aria-label="Discount value"></div>`}</td>` : ''}
        <td class="text-end">${fmt(lineTotalCents(it, i))}</td><td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger" data-remove="${i}" aria-label="Remove line">✕</button></td>`;
      tr.querySelectorAll('[data-f]').forEach(el => el.addEventListener('change', () => { it[el.dataset.f] = el.value; render(); }));
      tr.querySelector('[data-remove]').addEventListener('click', () => { items.splice(i, 1); render(); });
      body.appendChild(tr);
    });
    renderPayments(); renderTotals();
    if (refresh) schedulePreview();
  }

  function visibleMethods() { return methods.filter(m => m.type !== 'loyalty' || (cfg.loyalty && customerPoints > 0 && document.getElementById('customer_id').value)); }
  function renderPayments() {
    const wrap = document.getElementById('paymentRows'); wrap.innerHTML = '';
    const avail = visibleMethods();
    payments.forEach((p, i) => {
      if (!avail.find(m => String(m.id) === String(p.payment_method_id))) p.payment_method_id = avail[0]?.id;
      const method = find(methods, p.payment_method_id);
      const row = document.createElement('div'); row.className = 'border rounded p-2 mb-2';
      const pts = method && method.type === 'loyalty' ? Math.ceil(cents(p.amount) * cents(cfg.perUnit) / 10000) : 0;
      row.innerHTML = `<div class="d-flex gap-2 mb-1"><select class="form-select form-select-sm" data-p="payment_method_id" aria-label="Payment method">${avail.map(m => `<option value="${m.id}" ${String(m.id) === String(p.payment_method_id) ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}</select>
          <input type="number" min="0" step="0.01" class="form-control form-control-sm" style="max-width:120px" value="${p.amount ?? ''}" data-p="amount" placeholder="Amount" aria-label="Amount"><button type="button" class="btn btn-sm btn-link text-danger" data-del="${i}" aria-label="Remove payment">✕</button></div>
        <div class="d-flex gap-2">${method && method.type === 'cash' ? `<input type="number" min="0" step="0.01" class="form-control form-control-sm" value="${p.tendered ?? ''}" data-p="tendered" placeholder="Cash handed over" aria-label="Cash handed over">` : ''}
          ${method && !['cash', 'loyalty'].includes(method.type) ? `<input class="form-control form-control-sm" value="${esc(p.reference ?? '')}" data-p="reference" placeholder="Reference / txn id" aria-label="Reference">` : ''}
          ${method && method.type === 'loyalty' ? `<span class="small text-muted">${pts} of ${customerPoints} points</span>` : ''}</div>`;
      row.querySelectorAll('[data-p]').forEach(el => el.addEventListener('change', () => { p[el.dataset.p] = el.value; if (el.dataset.p === 'payment_method_id') renderPayments(); renderTotals(); }));
      row.querySelector('[data-p=amount]').addEventListener('input', e => { p.amount = e.target.value; renderTotals(); });
      row.querySelector('[data-del]').addEventListener('click', () => { payments.splice(i, 1); renderPayments(); renderTotals(); });
      wrap.appendChild(row);
    });
  }

  function renderTotals() {
    const t = totals(), paid = payments.reduce((a, p) => a + cents(p.amount), 0);
    document.getElementById('tSubtotal').textContent = fmt(t.gross); document.getElementById('tDiscount').textContent = '−' + fmt(t.disc);
    document.getElementById('tCoupon').textContent = '−' + fmt(t.coupon || 0); document.getElementById('tCouponLabel').textContent = preview && preview.coupon ? 'Coupon ' + preview.coupon : 'Coupon';
    document.getElementById('tTax').textContent = fmt(t.tax || 0); document.getElementById('tTotal').textContent = fmt(t.total); document.getElementById('tPaid').textContent = fmt(paid);
    const bal = t.total - paid, balEl = document.getElementById('tBalance');
    balEl.textContent = bal >= 0 ? fmt(bal) : 'Overpaid ' + fmt(-bal); balEl.className = 'col-6 text-end fw-semibold ' + (bal < 0 ? 'text-danger' : (bal > 0 ? 'text-warning' : 'text-success'));
    document.getElementById('tBalanceLabel').textContent = bal > 0 ? 'Balance on account' : 'Balance';
    const change = payments.reduce((a, p) => { const m = find(methods, p.payment_method_id); return a + (m && m.type === 'cash' && p.tendered ? Math.max(0, cents(p.tendered) - cents(p.amount)) : 0); }, 0);
    document.getElementById('tChangeLabel').textContent = change ? 'Change to give' : ''; document.getElementById('tChange').textContent = change ? fmt(change) : '';
    document.getElementById('btnCheckout').disabled = items.length === 0;
  }

  const addItem = (extra) => { items.push(Object.assign({ quantity: 1, employee_id: '', discount_type: 'none', discount_value: '' }, extra)); render(); };
  document.getElementById('serviceSelect').addEventListener('change', e => { if (e.target.value) { addItem({ service_id: e.target.value }); e.target.value = ''; } });
  const planSelect = document.getElementById('planSelect');
  if (planSelect) planSelect.addEventListener('change', e => { if (e.target.value && !items.some(i => i.membership_plan_id)) addItem({ membership_plan_id: e.target.value }); e.target.value = ''; });
  const productSelect = document.getElementById('productSelect');
  if (productSelect) productSelect.addEventListener('change', e => { if (e.target.value) { addItem({ product_id: e.target.value }); e.target.value = ''; } });
  const barcode = document.getElementById('barcodeInput');
  if (barcode) barcode.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return; e.preventDefault();
    const code = barcode.value.trim(), p = products.find(x => x.barcode === code || x.sku === code); barcode.value = '';
    if (!p) { barcode.classList.add('is-invalid'); return; } barcode.classList.remove('is-invalid');
    const existing = items.find(i => String(i.product_id) === String(p.id) && (!i.discount_type || i.discount_type === 'none'));
    if (existing) { existing.quantity = parseInt(existing.quantity || 1, 10) + 1; render(); } else addItem({ product_id: p.id });
  });
  document.getElementById('coupon_code').addEventListener('change', schedulePreview);
  document.getElementById('addPayment').addEventListener('click', () => { payments.push({ payment_method_id: visibleMethods()[0]?.id, amount: '' }); renderPayments(); renderTotals(); });
  document.getElementById('payRemaining').addEventListener('click', () => {
    const t = totals(), others = payments.slice(1).reduce((a, p) => a + cents(p.amount), 0);
    if (!payments.length) payments.push({ payment_method_id: visibleMethods()[0]?.id });
    payments[0].amount = (Math.max(0, t.total - others) / 100).toFixed(2); renderPayments(); renderTotals();
  });

  // Build the real form fields just before submit so the server receives plain arrays.
  document.getElementById('posForm').addEventListener('submit', () => {
    const box = document.getElementById('hiddenInputs'); box.innerHTML = '';
    const add = (n, v) => { if (v === undefined || v === null) return; const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; box.appendChild(i); };
    items.forEach((it, i) => ['service_id', 'product_id', 'membership_plan_id', 'appointment_service_id', 'quantity', 'employee_id', 'discount_type', 'discount_value'].forEach(f => add(`items[${i}][${f}]`, it[f] ?? '')));
    payments.filter(p => p.amount !== '' && p.amount !== undefined).forEach((p, i) => ['payment_method_id', 'amount', 'reference', 'tendered'].forEach(f => add(`payments[${i}][${f}]`, p[f] ?? '')));
  }, true);

  // customer typeahead
  const search = document.getElementById('customerSearch'); let st;
  const showBadges = (points, member) => {
    const pb = document.getElementById('pointsBadge'), mb = document.getElementById('memberBadge');
    pb.classList.toggle('d-none', !(cfg.loyalty && points > 0)); pb.textContent = points + ' pts';
    mb.classList.toggle('d-none', !member); mb.textContent = member ? 'Member: ' + member : '';
  };
  showBadges(customerPoints, null);
  search.addEventListener('input', () => { clearTimeout(st); st = setTimeout(() => {
    const q = search.value.trim(), res = document.getElementById('customerResults'); if (q.length < 2) { res.innerHTML = ''; return; }
    fetch('{{ route('customers.search') }}?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } }).then(r => r.json()).then(list => {
      res.innerHTML = ''; list.forEach(c => { const b = document.createElement('button'); b.type = 'button'; b.className = 'list-group-item list-group-item-action'; b.textContent = `${c.name} · ${c.phone || ''} · ${c.customer_number}` + (c.member ? ' · ★ ' + c.member : '') + (c.points ? ' · ' + c.points + ' pts' : '');
        b.onclick = () => { document.getElementById('customer_id').value = c.id; document.getElementById('customerName').textContent = c.name; document.getElementById('customerMeta').textContent = c.customer_number; customerPoints = c.points || 0; showBadges(customerPoints, c.member);
          document.getElementById('customerChosen').classList.remove('d-none'); document.getElementById('customerSearchBox').classList.add('d-none'); res.innerHTML = ''; renderPayments(); schedulePreview(); }; res.appendChild(b); });
      if (!list.length) res.innerHTML = '<div class="list-group-item text-muted">No active customers found</div>'; }); }, 250); });
  document.getElementById('customerClear').addEventListener('click', () => { document.getElementById('customer_id').value = ''; customerPoints = 0; showBadges(0, null);
    document.getElementById('customerChosen').classList.add('d-none'); document.getElementById('customerSearchBox').classList.remove('d-none'); search.focus(); renderPayments(); schedulePreview(); });

  render();
})();
</script>
@endpush
