@php
    $height  = \Mad\Support\CssUnits::length((string) ($height ?? ''), '16px');
    $width   = \Mad\Support\CssUnits::length((string) ($width  ?? ''), '100%');
    $rounded = $rounded ?? '';   // sm | md | lg | full
    $class   = $class   ?? '';
    $roundedClass = $rounded ? " mad-rounded-{$rounded}" : '';
@endphp
<div class="mad-skeleton{{ $roundedClass }} {{ $class }}"
     style="height:{{ $height }};width:{{ $width }};"
     aria-hidden="true"></div>
