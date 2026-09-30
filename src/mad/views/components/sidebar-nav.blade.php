@php
    $default = $default ?? '';
    $action  = $action  ?? '';
    $class   = $class   ?? '';
    $style   = $style   ?? '';
@endphp
<div class="mad-sidebar-nav {{ $class }}" @if($style) style="{{ $style }}" @endif
     x-data="{ activePanel: '{{ $default }}' }">
    {!! $slot !!}
</div>
