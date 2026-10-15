<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
@php
  $cfg = app(\App\Services\SettingsService::class);
  $a4 = $cfg->get('receipt.paper') === 'a4';
  $logo = $cfg->get('receipt.show_logo') ? $cfg->get('general.logo_path') : null;
@endphp
<title>Receipt {{ $invoice->invoice_number }}</title>
<style>
  body{font-family:"Courier New",monospace;font-size:12px;color:#000;margin:0;background:#f2f2f2}
  .receipt{width:{{ $a4 ? '700px' : '300px' }};margin:16px auto;background:#fff;padding:14px}
  h1{font-size:15px;text-align:center;margin:0 0 4px} .c{text-align:center} .muted{color:#444}
  table{width:100%;border-collapse:collapse} td{padding:2px 0;vertical-align:top} td.r{text-align:right;white-space:nowrap}
  hr{border:0;border-top:1px dashed #000;margin:8px 0} .tot td{font-weight:bold;font-size:13px}
  .dup{border:2px solid #000;text-align:center;font-weight:bold;padding:3px;margin:6px 0} .void{font-size:20px;text-align:center;font-weight:bold;border:3px solid #000;margin:8px 0;padding:4px}
  .actions{text-align:center;margin:10px} @media print{body{background:#fff}.receipt{margin:0;width:auto}.actions{display:none}}
</style></head><body>
<div class="actions"><button onclick="window.print()">Print</button> <a href="{{ route('invoices.show', $invoice) }}">Back to invoice</a></div>
<div class="receipt">
  @if ($logo)<div class="c"><img src="{{ asset('storage/'.$logo) }}" alt="" style="max-height:56px;max-width:100%"></div>@endif
  <h1>{{ config('salon.name') }}</h1>
  @if ($cfg->get('general.address') || $cfg->get('general.phone'))<div class="c muted">{!! nl2br(e($cfg->get('general.address'))) !!}@if ($cfg->get('general.phone'))<br>{{ $cfg->get('general.phone') }}@endif</div>@endif
  @if ($cfg->get('receipt.show_tax_number') && $cfg->get('tax.number'))<div class="c muted">{{ $cfg->get('tax.label') }} no. {{ $cfg->get('tax.number') }}</div>@endif
  @if (app(\App\Services\SettingsService::class)->get('pos.receipt_header'))<div class="c muted">{!! nl2br(e(app(\App\Services\SettingsService::class)->get('pos.receipt_header'))) !!}</div>@endif
  <hr>
  @if ($isDuplicate)<div class="dup">DUPLICATE — REPRINT #{{ $invoice->print_count }}</div>@endif
  @if ($invoice->isVoided())<div class="void">VOID</div>@endif
  <div>Invoice: {{ $invoice->invoice_number }}</div>
  <div>Date: {{ salon_datetime($invoice->issued_at) }}</div>
  <div>Customer: {{ $invoice->customer?->name ?? 'Walk-in' }}</div>
  @if ($cfg->get('receipt.show_cashier'))<div>Served by: {{ $invoice->issuer?->name }}</div>@endif
  <hr>
  <table>@foreach ($invoice->items as $it)
    <tr><td colspan="2">{{ $it->description }}</td></tr>
    <tr><td class="muted">{{ $it->quantity }} × {{ money($it->unit_price) }}@if (\App\Support\Money::compare($it->discount_amount, '0') > 0) (−{{ money($it->discount_amount) }})@endif</td><td class="r">{{ money($it->line_total) }}</td></tr>@endforeach</table>
  <hr>
  <table>
    <tr><td>Subtotal</td><td class="r">{{ money($invoice->subtotal) }}</td></tr>
    @if (\App\Support\Money::compare($invoice->discount_total, '0') > 0)<tr><td>Discounts</td><td class="r">−{{ money($invoice->discount_total) }}</td></tr>@endif
    @if (\App\Support\Money::compare($invoice->coupon_discount_total, '0') > 0)<tr><td>Coupon{{ $invoice->coupon ? ' '.$invoice->coupon->code : '' }}</td><td class="r">−{{ money($invoice->coupon_discount_total) }}</td></tr>@endif
    <tr><td>{{ $cfg->get('tax.label') }}</td><td class="r">{{ money($invoice->tax_total) }}</td></tr>
    <tr class="tot"><td>TOTAL</td><td class="r">{{ money($invoice->total) }}</td></tr>
  </table>
  <hr>
  <table>@foreach ($invoice->payments as $p)<tr><td>{{ $p->method->name }}</td><td class="r">{{ money($p->amount) }}</td></tr>
    @if ($p->tendered_amount !== null)<tr><td class="muted">  Tendered / change</td><td class="r muted">{{ money($p->tendered_amount) }} / {{ money($p->change_given) }}</td></tr>@endif @endforeach
    @if (\App\Support\Money::compare($invoice->refunded_total, '0') > 0)<tr><td>Refunded</td><td class="r">−{{ money($invoice->refunded_total) }}</td></tr>@endif
    <tr class="tot"><td>BALANCE DUE</td><td class="r">{{ money($invoice->balanceDue()) }}</td></tr></table>
  <hr>
  <div class="c">{{ app(\App\Services\SettingsService::class)->get('pos.receipt_footer') }}</div>
</div>
</body></html>
