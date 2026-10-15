@extends('layouts.app')
@section('title', 'Message delivery log')
@section('content')
@unless ($smsDelivers)<x-alert type="warning">The SMS driver is <strong>log</strong>: text messages are written to the application log and are <strong>not delivered</strong> to phones. They appear below as “Logged”. Install an SMS gateway driver to deliver them.</x-alert>@endunless
<div class="row g-3 mb-3">@foreach (['queued' => 'Queued', 'sent' => 'Sent', 'logged' => 'Logged (not delivered)', 'failed' => 'Failed'] as $status => $label)<div class="col-6 col-lg-3"><x-kpi-card :label="$label" :value="$counts[$status] ?? 0" icon="bi-envelope" /></div>@endforeach</div>
<div class="card"><div class="card-header"><form class="d-flex flex-wrap gap-2" method="get">
    <select class="form-select form-select-sm" style="width:auto" name="status" aria-label="Status"><option value="">Any status</option>@foreach (['queued', 'sending', 'sent', 'logged', 'failed', 'skipped'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select>
    <select class="form-select form-select-sm" style="width:auto" name="type" aria-label="Type"><option value="">Any type</option>@foreach (\App\Models\NotificationLog::TYPES as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select>
    <select class="form-select form-select-sm" style="width:auto" name="channel" aria-label="Channel"><option value="">Any channel</option><option value="email" @selected(request('channel') === 'email')>Email</option><option value="sms" @selected(request('channel') === 'sms')>SMS</option></select>
    <input class="form-control form-control-sm" style="width:auto" type="date" name="from" value="{{ request('from') }}" aria-label="From"><input class="form-control form-control-sm" style="width:auto" type="date" name="to" value="{{ request('to') }}" aria-label="To"><button class="btn btn-sm btn-outline-secondary">Filter</button></form></div>
    <x-table :headers="['When', 'Type', 'Channel', 'To', 'Status', 'Attempts', 'Detail', '']">
    @forelse ($logs as $l)<tr><td>{{ $l->created_at->format(config('salon.date_format').' '.config('salon.time_format')) }}</td><td>{{ \App\Models\NotificationLog::TYPES[$l->type] ?? $l->type }}</td><td>{{ strtoupper($l->channel) }}</td><td>{{ $l->maskedDestination() }}</td>
        <td><span class="badge text-bg-{{ ['sent' => 'success', 'logged' => 'info', 'failed' => 'danger', 'queued' => 'warning', 'sending' => 'warning', 'skipped' => 'secondary'][$l->status] ?? 'secondary' }}">{{ $l->status === 'logged' ? 'Logged, not delivered' : ucfirst($l->status) }}</span></td>
        <td>{{ $l->attempts }}</td><td class="small text-muted">{{ $l->last_error ?? ($l->sent_at ? 'at '.$l->sent_at->format(config('salon.time_format')) : '') }}</td>
        <td class="text-end">@if (in_array($l->status, ['failed', 'sending']))<form method="post" action="{{ route('notification-logs.retry', $l) }}">@csrf<button class="btn btn-sm btn-outline-primary">Retry</button></form>@endif</td></tr>
    @empty<tr><td colspan="8"><x-empty-state icon="bi-envelope" title="No messages yet" /></td></tr>@endforelse</x-table>
    <x-pagination :paginator="$logs" /></div>
@endsection
