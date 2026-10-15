@extends(auth()->check() ? 'layouts.app' : 'layouts.guest')
@section('title', 'Session expired')
@section('content')
<div class="text-center py-4"><div class="display-4 text-muted">419</div><h2 class="h4">Session expired</h2>
<p class="text-muted">{{ $exception->getMessage() ?: 'Your session expired. Reload the page and try again.' }}</p>
<a class="btn btn-primary" href="{{ url('/') }}">Back to dashboard</a></div>
@endsection
