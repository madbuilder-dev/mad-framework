@php
    $size  = $size  ?? '';   // sm | lg
    $class = $class ?? '';
    $sizeClass = $size ? " mad-spinner-{$size}" : '';
@endphp
<div class="mad-spinner{{ $sizeClass }} {{ $class }}" role="status" aria-label="Carregando..."></div>
