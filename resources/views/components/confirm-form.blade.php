@props(['action', 'method' => 'post', 'message' => 'Are you sure?', 'label' => 'Submit', 'class' => 'btn btn-sm btn-outline-danger'])
<form method="post" action="{{ $action }}" data-confirm="{{ $message }}" class="d-inline">
    @csrf
    @if (! in_array(strtolower($method), ['get', 'post']))@method($method)@endif
    {{ $slot }}
    <button type="submit" class="{{ $class }}">{{ $label }}</button>
</form>
