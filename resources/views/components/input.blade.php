@props(['name', 'label', 'type' => 'text', 'value' => null, 'help' => null])
@php $id = $attributes->get('id', 'f_'.str_replace(['[', ']', '.'], '_', $name)); @endphp
<div class="mb-3">
    <label for="{{ $id }}" class="form-label">{{ $label }}@if ($attributes->has('required')) <span class="text-danger" aria-hidden="true">*</span>@endif</label>
    <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
        {{ $attributes->except('id')->class(['form-control', 'is-invalid' => $errors->has($name)]) }}>
    @if ($help)<div class="form-text">{{ $help }}</div>@endif
    @error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
