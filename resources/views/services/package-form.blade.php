@extends('layouts.app')
@section('title', $package->exists ? 'Edit package' : 'New package')
@section('content')
<form method="post" action="{{ $package->exists ? route('packages.update', $package) : route('packages.store') }}" class="row g-4">@csrf @if ($package->exists) @method('PUT') @endif
<div class="col-lg-6"><div class="card"><div class="card-body">
    <x-input name="name" label="Package name" :value="$package->name" required />
    <x-textarea name="description" label="Description" :value="$package->description" rows="2" />
    <div class="row"><div class="col"><x-input name="price" label="Package price" type="number" step="0.01" min="0" :value="$package->price" required /></div>
        <div class="col"><x-input name="validity_days" label="Validity (days)" type="number" min="1" :value="$package->validity_days" help="Leave empty for no expiry." /></div></div>
    <div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $package->is_active))><label class="form-check-label" for="is_active">Active</label></div>
</div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header">Included services (quantity)</div><div class="card-body" style="max-height:420px;overflow:auto">
    @foreach ($services as $s)<div class="d-flex align-items-center gap-2 mb-2"><input class="form-control form-control-sm" style="width:80px" type="number" min="0" max="99" name="items[{{ $s->id }}]" value="{{ old('items.'.$s->id, $quantities[$s->id] ?? 0) }}" aria-label="Quantity of {{ $s->name }}"><span>{{ $s->name }} <small class="text-muted">{{ money($s->base_price) }}</small></span></div>@endforeach
</div></div>
<div class="mt-3"><button class="btn btn-primary" type="submit">Save package</button> <a class="btn btn-light" href="{{ route('packages.index') }}">Cancel</a></div></div>
</form>
@endsection
