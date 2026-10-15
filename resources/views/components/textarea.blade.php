@props(['name', 'label', 'value' => null, 'rows' => 3])
<div class="mb-3">
    <label for="f_{{ $name }}" class="form-label">{{ $label }}</label>
    <textarea id="f_{{ $name }}" name="{{ $name }}" rows="{{ $rows }}" {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }}>{{ old($name, $value) }}</textarea>
    @error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
