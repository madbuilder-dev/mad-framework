@php
    $trigger = $trigger ?? '';   // HTML string for the trigger button
    $align   = $align   ?? 'start';   // start | end
    $class   = $class   ?? '';
    $menuClass = $align === 'end' ? 'mad-dropdown-end' : '';
@endphp
<div class="mad-dropdown {{ $class }}"
     style="position:relative;display:inline-block;"
     x-data="{ open: false }"
     @click.outside="open = false">
    <div @click="open = !open">
        {!! $trigger !!}
    </div>
    <div class="mad-dropdown-menu {{ $menuClass }}"
         x-show="open"
         x-cloak
         @click="open = false">
        {!! $slot !!}
    </div>
</div>
