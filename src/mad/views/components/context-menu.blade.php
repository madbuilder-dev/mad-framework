@php
    $class = $class ?? '';
@endphp
<div class="mad-context {{ $class }}"
     x-data="madContextMenu()"
     @contextmenu.prevent="openAt($event)"
     @click.outside="close()"
     @keydown.escape.window="close()">
    {!! $slot !!}
    <div class="mad-context-menu"
         x-show="open"
         x-cloak
         x-transition:enter="mad-context-enter"
         x-transition:enter-start="mad-context-enter-start"
         x-transition:enter-end="mad-context-enter-end"
         x-transition:leave="mad-context-leave"
         x-transition:leave-start="mad-context-leave-start"
         x-transition:leave-end="mad-context-leave-end"
         :style="menuStyle()"
         @click="close()"
         role="menu">
        {!! $menu !!}
    </div>
</div>
