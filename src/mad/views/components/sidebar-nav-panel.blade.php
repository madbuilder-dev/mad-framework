@php
    $name  = $name  ?? '';
    $class = $class ?? '';
    $style = $style ?? '';
@endphp
<div class="mad-sidebar-nav-panel {{ $class }}" @if($style) style="{{ $style }}" @endif
     x-show="activePanel === '{{ $name }}'"
     x-cloak>
    {!! $slot !!}
</div>
