@props(['label' => '', 'name' => '', 'hint' => '', 'error' => '', 'required' => false, 'class' => ''])
@php
    $hasError = !empty($error);
@endphp

<div class="mad-field {{ $class }}">
    <label class="mad-label">
        {{ $label }}
        @if($required)
            <span class="mad-required" aria-hidden="true">*</span>
        @endif
    </label>

    {!! $slot !!}

    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
