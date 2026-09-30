@php
    $span  = (int)($span ?? 1);
    $class = $class ?? '';
    $style = $span > 1 ? "grid-column:span {$span};" : '';
@endphp
<div @if($style) style="{{ $style }}" @endif class="{{ $class }}">
    {!! $slot !!}
</div>
