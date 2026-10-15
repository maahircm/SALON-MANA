@extends('layouts.app')
@section('title', $promotion->name)
@section('content')
<div class="row g-4"><div class="col-lg-5"><div class="card mb-3"><div class="card-body"><h2 class="h5">{{ $promotion->name }} @if ($promotion->isRunning())<span class="badge text-bg-success">Running</span>@endif</h2><div class="text-muted">{{ $promotion->description }}</div>
    <dl class="row small mt-2 mb-0"><dt class="col-5">Discount</dt><dd class="col-7">{{ $promotion->discount_type === 'percent' ? rtrim(rtrim((string) $promotion->discount_value, '0'), '.').'%' : money($promotion->discount_value) }}</dd>
        <dt class="col-5">Minimum purchase</dt><dd class="col-7">{{ money($promotion->min_purchase) }}</dd><dt class="col-5">Runs</dt><dd class="col-7">{{ $promotion->starts_at->format('d M Y H:i') }} – {{ $promotion->ends_at->format('d M Y H:i') }}</dd>
        <dt class="col-5">Total limit</dt><dd class="col-7">{{ $promotion->max_redemptions ?? 'Unlimited' }}</dd><dt class="col-5">Per customer</dt><dd class="col-7">{{ $promotion->per_customer_limit ?? 'Unlimited' }}</dd></dl></div>
    @can('loyalty.manage')<div class="card-footer bg-transparent"><a class="btn btn-sm btn-outline-secondary" href="{{ route('promotions.edit', $promotion) }}">Edit</a></div>@endcan</div>
    <div class="card"><div class="card-header">Coupon codes</div><x-table :headers="['Code', 'Used', 'Limit', 'Status', '']">
        @forelse ($promotion->coupons as $c)<tr><td><code>{{ $c->code }}</code></td><td>{{ $c->used_count }}</td><td>{{ $c->max_uses ?? '∞' }}</td><td><span class="badge text-bg-{{ $c->is_active ? 'success' : 'secondary' }}">{{ $c->is_active ? 'Active' : 'Off' }}</span></td>
            <td class="text-end">@can('loyalty.manage')<x-confirm-form :action="route('coupons.toggle', $c)" :message="($c->is_active ? 'Deactivate ' : 'Activate ').$c->code.'?'" :label="$c->is_active ? 'Deactivate' : 'Activate'" class="btn btn-sm btn-outline-secondary" />@endcan</td></tr>
        @empty<tr><td colspan="5" class="text-muted">No codes yet.</td></tr>@endforelse</x-table>
        @can('loyalty.manage')<form class="row g-2 p-3 border-top" method="post" action="{{ route('promotions.coupons.store', $promotion) }}">@csrf<div class="col-md-6"><input class="form-control form-control-sm text-uppercase" name="code" placeholder="e.g. WELCOME10" aria-label="Code" required></div><div class="col-md-4"><input class="form-control form-control-sm" name="max_uses" type="number" min="1" placeholder="Max uses" aria-label="Max uses"></div><div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Add</button></div></form>@endcan</div></div>
<div class="col-lg-7"><div class="card"><div class="card-header">Usage history</div><x-table :headers="['When', 'Code', 'Invoice', 'Customer', 'Discount', 'Status']">
    @forelse ($redemptions as $r)<tr><td class="text-nowrap">{{ salon_datetime($r->created_at) }}</td><td><code>{{ $r->coupon->code }}</code></td><td><a href="{{ route('invoices.show', $r->invoice_id) }}">{{ $r->invoice->invoice_number }}</a></td><td>{{ $r->customer?->name ?? 'Walk-in' }}</td><td>{{ money($r->discount_amount) }}</td><td>{{ ucfirst($r->status) }}</td></tr>
    @empty<tr><td colspan="6"><x-empty-state title="Not used yet" /></td></tr>@endforelse</x-table></div></div></div>
@endsection
