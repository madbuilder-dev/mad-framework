@php
    $trigger = $trigger ?? '';   // HTML string for the trigger button
    $align   = $align   ?? 'start';   // start | end
    $class   = $class   ?? '';
    $menuClass = $align === 'end' ? 'mad-dropdown-end' : '';
    // position="left|center|right" — lado do dropdown dentro do <mad-form-actions>
    // (`align` aqui é o lado em que o MENU abre, outra coisa).
    $position  = $position ?? '';
    $posClass  = in_array($position, ['left', 'center', 'right'], true) ? " mad-pos-{$position}" : '';
@endphp
<div class="mad-dropdown{{ $posClass }} {{ $class }}"
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
