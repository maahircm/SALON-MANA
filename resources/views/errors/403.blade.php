@extends(auth()->check() ? 'layouts.app' : 'layouts.guest')
@section('title', 'Unauthorized')
@section('content')
<div class="text-center py-4"><div class="display-4 text-muted">403</div><h2 class="h4">Unauthorized</h2>
<p class="text-muted">{{ $exception->getMessage() ?: 'You do not have permission to access this page. If you believe this is a mistake, contact an administrator.' }}</p>
<a class="btn btn-primary" href="{{ url('/') }}">Back to dashboard</a></div>
@endsection
