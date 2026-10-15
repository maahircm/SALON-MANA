@extends('layouts.app')
@section('title', 'Appointment '.$appointment->appointment_number)
@section('content')
<div class="row g-4">
<div class="col-lg-8">
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2"><div>
            <h2 class="h5 mb-1">{{ $appointment->customer->name }} <small class="text-muted">{{ $appointment->customer->customer_number }}</small></h2>
            <div>{{ salon_datetime($appointment->start_at) }} → {{ $appointment->end_at->format(config('salon.time_format')) }} <span class="text-muted">· {{ $appointment->source === 'walk_in' ? 'Walk-in' : 'Booked' }}</span></div></div>
            <span class="badge fs-6 align-self-start" style="background:{{ $appointment->status->color() }}">{{ $appointment->status->label() }}</span></div>
        @if ($appointment->is_late_cancellation)<x-alert type="warning" class="mt-3 mb-0">Late cancellation (inside the notice period).</x-alert>@endif
        @if ($appointment->cancellation_reason)<div class="mt-3"><strong>Cancellation reason:</strong> {{ $appointment->cancellation_reason }}</div>@endif
        @if ($appointment->notes)<div class="mt-3"><strong>Notes:</strong> {{ $appointment->notes }}</div>@endif
    </div>
    <x-table :headers="['#', 'Service', 'Stylist', 'Chair/room', 'Time', 'Price']">
        @foreach ($appointment->lines as $l)<tr><td>{{ $loop->iteration }}</td><td>{{ $l->service_name }}</td><td>{{ $l->employee->name }}</td><td>{{ $l->room?->name ?? '—' }}</td>
            <td class="text-nowrap">{{ $l->start_at->format('H:i') }}–{{ $l->end_at->format('H:i') }}</td><td>{{ money($l->unit_price) }}</td></tr>@endforeach
        <tr><td colspan="5" class="text-end fw-semibold">Booked total (before tax, not a payment)</td><td class="fw-semibold">{{ money($appointment->totalAmount()) }}</td></tr>
    </x-table></div>
    <div class="card"><div class="card-header">Status history</div><x-table :headers="['When', 'Change', 'By', 'Reason']">
        @foreach ($appointment->histories as $h)<tr><td>{{ salon_datetime($h->created_at) }}</td><td>{{ $h->from_status ? ucwords(str_replace('_', ' ', $h->from_status)).' → ' : '' }}{{ ucwords(str_replace('_', ' ', $h->to_status)) }}</td><td>{{ $h->user?->name ?? 'system' }}</td><td>{{ $h->reason }}</td></tr>@endforeach</x-table></div>
</div>
<div class="col-lg-4"><div class="card"><div class="card-header">Actions</div><div class="card-body d-grid gap-2">
    @foreach ($next as $to)
        @if ($to === \App\Enums\AppointmentStatus::Cancelled)
            <form method="post" action="{{ route('appointments.transition', $appointment) }}" data-confirm="Cancel this appointment?">@csrf<input type="hidden" name="status" value="cancelled">
                <label class="form-label small" for="cancelReason">Cancellation reason (required)</label><input class="form-control form-control-sm mb-2" id="cancelReason" name="reason" required>
                <button class="btn btn-outline-danger w-100">Cancel appointment</button></form>
        @elseif ($to === \App\Enums\AppointmentStatus::NoShow)
            <form method="post" action="{{ route('appointments.transition', $appointment) }}" data-confirm="Mark this customer as a no-show?">@csrf<input type="hidden" name="status" value="no_show"><button class="btn btn-outline-secondary w-100">Mark no-show</button></form>
        @else
            @php $actionLabels = ['confirmed' => 'Confirm booking', 'checked_in' => 'Check in', 'in_service' => 'Start service', 'completed' => 'Complete service']; @endphp
            <form method="post" action="{{ route('appointments.transition', $appointment) }}">@csrf<input type="hidden" name="status" value="{{ $to->value }}"><button class="btn btn-primary w-100">{{ $actionLabels[$to->value] }}</button></form>
        @endif
    @endforeach
    @if ($canEdit)<a class="btn btn-outline-secondary" href="{{ route('appointments.edit', $appointment) }}">Reschedule / edit</a>@endif
    @if ($next->isEmpty() && ! $canEdit)<span class="text-muted small">No further actions available.</span>@endif
    @if (in_array($appointment->status, [\App\Enums\AppointmentStatus::CheckedIn, \App\Enums\AppointmentStatus::InService, \App\Enums\AppointmentStatus::Completed], true)) @can('pos.use')<a class="btn btn-success" href="{{ route('pos.index', ['appointment' => $appointment->id]) }}"><i class="bi bi-cart-check"></i> Checkout</a>@endcan @endif
    <a class="btn btn-light" href="{{ route('customers.show', $appointment->customer) }}">Customer profile</a>
    <div class="small text-muted">Completing a service does not mark anything as paid; payment is taken at checkout.</div>
</div></div></div></div>
@endsection
