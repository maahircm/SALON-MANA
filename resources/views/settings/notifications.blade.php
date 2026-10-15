@extends('layouts.app')
@section('title', 'Notification settings')
@section('content')
<x-alert type="info">Email is sent with the configured mailer (<strong>{{ $mailer }}</strong>). SMS driver: <strong>{{ $smsDriver }}</strong>. Messages are sent by the queue worker.
    @if ($mailer === 'log') <br>The mailer is “log”: emails are only written to the application log until real SMTP settings are added to the .env file.@endif
    @if ($smsDriver === 'log') <br>The SMS driver is “log”: texts are logged, not delivered.@endif</x-alert>
<form method="post" action="{{ route('notification-settings.update') }}" class="row g-4">@csrf @method('PUT')
<div class="col-lg-4"><div class="card h-100"><div class="card-header">Channels</div><div class="card-body">
    @foreach ($channels as $key => $c)<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="channels[{{ $key }}]" value="1" id="ch_{{ $key }}" @checked(old("channels.$key", $c['on']))><label class="form-check-label" for="ch_{{ $key }}">{{ $c['label'] }}</label></div>@endforeach
</div></div></div>
<div class="col-lg-4"><div class="card h-100"><div class="card-header">Automatic messages</div><div class="card-body">
    @foreach ($events as $key => $e)<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="events[{{ $key }}]" value="1" id="ev_{{ $key }}" @checked(old("events.$key", $e['on']))><label class="form-check-label" for="ev_{{ $key }}">{{ $e['label'] }}</label></div>@endforeach
    <div class="form-text">Birthday greetings go only to customers who gave marketing consent. Booking, reminder and payment messages are service messages.</div>
</div></div></div>
<div class="col-lg-4"><div class="card h-100"><div class="card-header">Timing</div><div class="card-body">
    <x-input name="reminder_hours_before" label="Appointment reminder (hours before)" type="number" min="1" max="336" :value="$reminderHours" required />
    <x-input name="payment_reminder_after_days" label="First payment reminder after (days)" type="number" min="1" max="90" :value="$afterDays" required />
    <x-input name="payment_reminder_repeat_days" label="Repeat every (days)" type="number" min="1" max="90" :value="$repeatDays" required />
    <x-input name="payment_reminder_max" label="Maximum reminders per invoice" type="number" min="1" max="10" :value="$max" required />
</div></div></div>
<div class="col-12"><button class="btn btn-primary">Save settings</button> <a class="btn btn-light" href="{{ route('notification-logs.index') }}">Delivery log</a></div></form>
@endsection
