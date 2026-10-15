@extends('layouts.app')
@section('title', 'General settings')
@section('content')
<form method="post" action="{{ route('settings.general.update') }}" enctype="multipart/form-data" class="row g-4">@csrf @method('PUT')
<div class="col-lg-6"><div class="card h-100"><div class="card-header">Salon profile</div><div class="card-body">
    <x-input name="salon_name" label="Salon name" :value="$values['general.salon_name']" maxlength="100" required />
    <div class="row"><div class="col-md-6"><x-input name="phone" label="Phone" :value="$values['general.phone']" /></div><div class="col-md-6"><x-input name="email" label="Email" type="email" :value="$values['general.email']" /></div></div>
    <x-textarea name="address" label="Address" rows="2" :value="$values['general.address']" />
    <div class="mb-3"><label class="form-label" for="logo">Logo (PNG, JPG or WebP, max 1 MB, up to 2000×2000)</label>
        @if ($logo)<div class="mb-2"><img src="{{ asset('storage/'.$logo) }}" alt="Current logo" style="max-height:64px" class="border rounded p-1"> <button class="btn btn-sm btn-outline-danger" form="removeLogo">Remove</button></div>@endif
        <input class="form-control @error('logo') is-invalid @enderror" type="file" name="logo" id="logo" accept=".png,.jpg,.jpeg,.webp">@error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
</div></div></div>
<div class="col-lg-6"><div class="card h-100"><div class="card-header">Locale</div><div class="card-body">
    <x-select name="timezone" label="Timezone" :options="array_combine($timezones, $timezones)" :value="$values['general.timezone']" :disabled="$timezoneLocked" />
    @if ($timezoneLocked)<input type="hidden" name="timezone" value="{{ $values['general.timezone'] }}"><div class="form-text mb-3">Locked: appointments or invoices already exist, and changing the timezone would shift every stored time.</div>@endif
    <div class="row"><div class="col-md-6"><x-input name="currency" label="Currency code (ISO)" :value="$values['general.currency']" maxlength="3" :readonly="$currencyLocked" style="text-transform:uppercase" required /></div><div class="col-md-6"><x-input name="currency_symbol" label="Symbol" :value="$values['general.currency_symbol']" maxlength="5" :readonly="$currencyLocked" required /></div></div>
    @if ($currencyLocked)<div class="form-text mb-3">Locked: invoices exist and amounts are never converted.</div>@endif
    <div class="row"><div class="col-md-6"><x-select name="date_format" label="Date format" :options="$dateFormats" :value="$values['general.date_format']" /></div><div class="col-md-6"><x-select name="time_format" label="Time format" :options="$timeFormats" :value="$values['general.time_format']" /></div></div>
</div></div></div>
<div class="col-lg-6"><div class="card h-100"><div class="card-header">Tax</div><div class="card-body">
    <div class="row"><div class="col-md-6"><x-input name="tax_label" label="Tax name on receipts (e.g. VAT, GST)" :value="$values['tax.label']" maxlength="20" required /></div><div class="col-md-6"><x-input name="tax_number" label="Tax registration number" :value="$values['tax.number']" maxlength="50" /></div></div>
    <x-input name="tax_default_rate" label="Default tax rate for new services and products (%)" type="number" step="0.01" min="0" max="100" :value="$values['tax.default_rate']" help="Only pre-fills new items; each service and product keeps its own rate, and issued invoices never change." required />
</div></div></div>
<div class="col-lg-6"><div class="card h-100"><div class="card-header">Receipt and finance</div><div class="card-body">
    <x-select name="receipt_paper" label="Receipt paper" :options="['80mm' => '80 mm thermal roll', 'a4' => 'A4 sheet']" :value="$values['receipt.paper']" />
    @foreach (['receipt_show_logo' => ['receipt.show_logo', 'Show the logo on receipts'], 'receipt_show_tax_number' => ['receipt.show_tax_number', 'Show the tax registration number'], 'receipt_show_cashier' => ['receipt.show_cashier', 'Show who served the customer']] as $field => [$key, $label])
        <div class="form-check"><input class="form-check-input" type="checkbox" name="{{ $field }}" value="1" id="{{ $field }}" @checked(old($field, $values[$key]))><label class="form-check-label" for="{{ $field }}">{{ $label }}</label></div>@endforeach
    <div class="form-text mb-3">Receipt header and footer text are under <a href="{{ route('settings.pos') }}">POS settings</a>.</div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="expense_self_approval" value="1" id="selfApproval" @checked(old('expense_self_approval', $values['finance.expense_self_approval']))><label class="form-check-label" for="selfApproval">Allow people to approve expenses they submitted themselves</label></div>
    <div class="form-text">Off by default (separation of duties). Super Administrators may always approve.</div>
</div></div></div>
<div class="col-12"><button class="btn btn-primary">Save settings</button> <a class="btn btn-light" href="{{ route('notification-settings.edit') }}">Notification settings</a></div>
</form>
<form id="removeLogo" method="post" action="{{ route('settings.general.logo.remove') }}">@csrf @method('DELETE')</form>
@endsection
