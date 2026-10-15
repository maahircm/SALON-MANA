@props(['name', 'label', 'options' => [], 'value' => null, 'placeholder' => null])
@php $id = 'f_'.str_replace(['[', ']', '.'], '_', $name); @endphp
<div class="mb-3">
    <label for="{{ $id }}" class="form-label">{{ $label }}</label>
    <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->class(['form-select', 'is-invalid' => $errors->has($name)]) }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $k => $v)<option value="{{ $k }}" @selected((string) old($name, $value) === (string) $k)>{{ $v }}</option>@endforeach
    </select>
    @error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
