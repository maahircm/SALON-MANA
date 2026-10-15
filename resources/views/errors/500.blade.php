@extends(auth()->check() ? 'layouts.app' : 'layouts.guest')
@section('title', 'Something went wrong')
@section('content')
<div class="text-center py-4"><div class="display-4 text-muted">500</div><h2 class="h4">Something went wrong</h2>
<p class="text-muted">{{ $exception->getMessage() ?: 'An unexpected error occurred. The incident has been logged.' }}</p>
<a class="btn btn-primary" href="{{ url('/') }}">Back to dashboard</a></div>
@endsection
