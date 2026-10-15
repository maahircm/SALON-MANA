@extends(auth()->check() ? 'layouts.app' : 'layouts.guest')
@section('title', 'Maintenance')
@section('content')
<div class="text-center py-4"><div class="display-4 text-muted">503</div><h2 class="h4">Maintenance</h2>
<p class="text-muted">{{ $exception->getMessage() ?: 'The system is temporarily unavailable. Please try again soon.' }}</p>
<a class="btn btn-primary" href="{{ url('/') }}">Back to dashboard</a></div>
@endsection
