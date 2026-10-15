@extends(auth()->check() ? 'layouts.app' : 'layouts.guest')
@section('title', 'Too many requests')
@section('content')
<div class="text-center py-4"><div class="display-4 text-muted">429</div><h2 class="h4">Too many requests</h2>
<p class="text-muted">{{ $exception->getMessage() ?: 'Please slow down and try again shortly.' }}</p>
<a class="btn btn-primary" href="{{ url('/') }}">Back to dashboard</a></div>
@endsection
