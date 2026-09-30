@php
    $class = $class ?? '';
    $align = $align ?? 'left';
    $style = $style ?? '';
@endphp

<div class="mad-form-actions {{ $align === 'right' ? 'mad-form-actions-right' : ($align === 'center' ? 'mad-form-actions-center' : '') }} {{ $class }}" @if($style) style="{{ $style }}" @endif>
    {!! $slot !!}
</div>
