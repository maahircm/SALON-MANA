@extends('layouts.app')
@section('title', 'Booking settings')
@section('content')
<form method="post" action="{{ route('settings.booking.update') }}" class="row g-4">@csrf @method('PUT')
<div class="col-lg-6"><div class="card"><div class="card-header">Opening hours</div><div class="card-body">
    @foreach ($weekdays as $d => $name)
    <div class="row g-2 align-items-center mb-2"><div class="col-3">{{ $name }}</div>
        <div class="col-3"><input class="form-control form-control-sm" type="time" name="hours[{{ $d }}][open]" value="{{ old("hours.$d.open", $hours[$d]['open']) }}" aria-label="{{ $name }} opens"></div>
        <div class="col-3"><input class="form-control form-control-sm" type="time" name="hours[{{ $d }}][close]" value="{{ old("hours.$d.close", $hours[$d]['close']) }}" aria-label="{{ $name }} closes"></div>
        <div class="col-3"><div class="form-check"><input type="hidden" name="closed[{{ $d }}]" value="0"><input class="form-check-input" type="checkbox" name="closed[{{ $d }}]" value="1" id="closed{{ $d }}" @checked(old("closed.$d", $hours[$d]['closed']))><label class="form-check-label" for="closed{{ $d }}">Closed</label></div></div></div>
    @endforeach
    <div class="form-text">Times use the salon timezone ({{ config('app.timezone') }}).</div>
</div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header">Booking &amp; cancellation policy</div><div class="card-body">
    <x-input name="buffer_minutes" label="Buffer between appointments (minutes)" type="number" min="0" :value="$policy['buffer_minutes']" help="Gap kept free before and after each booking for the same stylist / chair." />
    <x-select name="slot_minutes" label="Time-slot interval (minutes)" :options="[5 => 5, 10 => 10, 15 => 15, 20 => 20, 30 => 30, 60 => 60]" :value="$policy['slot_minutes']" />
    <x-input name="max_advance_days" label="Maximum days booked in advance" type="number" min="1" :value="$policy['max_advance_days']" />
    <x-input name="reminder_hours_before" label="Reminder lead time (hours before)" type="number" min="1" :value="$policy['reminder_hours_before']" />
    <x-input name="cancellation_notice_hours" label="Cancellation notice required (hours)" type="number" min="0" :value="$policy['cancellation_notice_hours']" />
    <x-select name="late_cancellation_action" label="Late cancellations" :options="['flag' => 'Allow, but flag as late', 'block' => 'Block (manager override needed)']" :value="$policy['late_cancellation_action']" />
    <div class="form-check"><input class="form-check-input" type="checkbox" name="allow_stylist_overlap" value="1" id="overlap" @checked(old('allow_stylist_overlap', $policy['allow_stylist_overlap']))><label class="form-check-label" for="overlap">Allow a stylist to have overlapping appointments</label></div>
</div></div>
<div class="mt-3"><button class="btn btn-primary" type="submit">Save settings</button></div></div></form>
@endsection
