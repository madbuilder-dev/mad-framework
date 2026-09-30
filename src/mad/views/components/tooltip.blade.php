@php
    $content  = $content  ?? '';
    $position = $position ?? 'top';   // top | bottom | left | right
    $class    = $class    ?? '';
@endphp
<div class="mad-tooltip-wrap {{ $class }}"
     x-data="{ tt: false }"
     @mouseenter="tt = true"
     @mouseleave="tt = false"
     @focusin="tt = true"
     @focusout="tt = false">
    {!! $slot !!}
    <div class="mad-tooltip mad-tooltip-{{ $position }}" x-show="tt" x-cloak>{{ $content }}</div>
</div>
