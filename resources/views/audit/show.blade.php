@extends('layouts.app')
@section('title', 'Audit entry #'.$log->id)
@section('content')
<div class="card"><div class="card-body">
    <dl class="row mb-0">
        <dt class="col-sm-3">When</dt><dd class="col-sm-9">{{ $log->created_at->format(config('salon.date_format').' '.config('salon.time_format').':s') }}</dd>
        <dt class="col-sm-3">Actor</dt><dd class="col-sm-9">{{ $log->user?->name ?? $log->actor_label ?? 'system' }}</dd>
        <dt class="col-sm-3">Action</dt><dd class="col-sm-9"><code>{{ $log->action }}</code></dd>
        <dt class="col-sm-3">Entity</dt><dd class="col-sm-9">{{ $log->entity_type }} {{ $log->entity_id }}</dd>
        <dt class="col-sm-3">Correlation ID</dt><dd class="col-sm-9"><code>{{ $log->correlation_id }}</code></dd>
        <dt class="col-sm-3">Reason</dt><dd class="col-sm-9">{{ $log->reason ?? '—' }}</dd>
        <dt class="col-sm-3">IP / agent</dt><dd class="col-sm-9">{{ $log->ip_address }} <span class="text-muted small">{{ $log->user_agent }}</span></dd>
        <dt class="col-sm-3">Before</dt><dd class="col-sm-9"><pre class="mb-0">{{ json_encode($log->before, JSON_PRETTY_PRINT) }}</pre></dd>
        <dt class="col-sm-3">After</dt><dd class="col-sm-9"><pre class="mb-0">{{ json_encode($log->after, JSON_PRETTY_PRINT) }}</pre></dd>
    </dl>
</div></div>
<a class="btn btn-light mt-3" href="{{ route('audit.index') }}">Back</a>
@endsection
