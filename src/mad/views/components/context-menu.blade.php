@php
    $class = $class ?? '';
@endphp
{{-- O menu vai para o <body> (x-teleport) e abre em position:fixed no ponto do
     clique: dentro de um container com rolagem ele era cortado pelo overflow
     (fw#133). `mad-ui` leva tema e fonte junto. O @click.outside do wrapper
     continua fechando ao clicar fora; um clique num item (que agora fica "fora"
     do wrapper) executa a ação e fecha, como antes. --}}
<div class="mad-context {{ $class }}"
     x-data="madContextMenu()"
     @contextmenu.prevent="openAt($event)"
     @click.outside="close()"
     @keydown.escape.window="close()">
    {!! $slot !!}
    <template x-teleport="body">
    <div class="mad-context-menu mad-ui"
         x-ref="menu"
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
    </template>
</div>
