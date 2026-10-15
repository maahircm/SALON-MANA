@extends('layouts.app')
@section('title', 'Customers')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <form class="d-flex flex-wrap gap-2" method="get">
            <input class="form-control form-control-sm" style="width:220px" name="q" value="{{ request('q') }}" placeholder="Name, phone, email, number" aria-label="Search customers">
            <select class="form-select form-select-sm" name="status" aria-label="Status" style="width:auto"><option value="">Any status</option>@foreach (\App\Models\Customer::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
            <select class="form-select form-select-sm" name="birthday_month" aria-label="Birthday month" style="width:auto"><option value="">Birthday: any</option>@foreach (range(1, 12) as $m)<option value="{{ $m }}" @selected((int) request('birthday_month') === $m)>{{ date('F', mktime(0, 0, 0, $m, 1)) }}</option>@endforeach</select>
            <div class="form-check align-self-center"><input class="form-check-input" type="checkbox" name="consent" value="1" id="consent" @checked(request('consent'))><label class="form-check-label" for="consent">Marketing consent</label></div>
            <div class="form-check align-self-center"><input class="form-check-input" type="checkbox" name="archived" value="1" id="archived" @checked(request('archived'))><label class="form-check-label" for="archived">Archived</label></div>
            <button class="btn btn-sm btn-outline-secondary">Filter</button>
        </form>
        <div class="d-flex gap-2">
            @can('customers.export')<a class="btn btn-sm btn-outline-secondary" href="{{ route('customers.export', request()->query()) }}"><i class="bi bi-download"></i> Export CSV</a>@endcan
            @can('customers.create')<a class="btn btn-sm btn-primary" href="{{ route('customers.create') }}"><i class="bi bi-plus-lg"></i> New customer</a>@endcan
        </div>
    </div>
    <x-table :headers="['Number', 'Name', 'Phone', 'Email', 'Preferred stylist', 'Status', 'Consent', '']">
        @forelse ($customers as $c)
        <tr>
            <td>{{ $c->customer_number }}</td><td><a href="{{ route('customers.show', $c) }}">{{ $c->name }}</a></td><td>{{ $c->phone }}</td><td>{{ $c->email }}</td>
            <td>{{ $c->preferredEmployee?->name ?? '—' }}</td>
            <td><span class="badge text-bg-{{ $c->status === 'active' ? 'success' : ($c->status === 'blocked' ? 'danger' : 'secondary') }}">{{ \App\Models\Customer::STATUSES[$c->status] ?? $c->status }}</span></td>
            <td>{!! $c->marketing_consent ? '<i class="bi bi-check-circle text-success" title="Consented"></i>' : '<span class="text-muted">—</span>' !!}</td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('customers.show', $c) }}">View</a></td>
        </tr>
        @empty
        <tr><td colspan="8"><x-empty-state icon="bi-person-heart" title="No customers found">@can('customers.create')<a href="{{ route('customers.create') }}">Register the first customer</a>@endcan</x-empty-state></td></tr>
        @endforelse
    </x-table>
    <x-pagination :paginator="$customers" />
</div>
@endsection
