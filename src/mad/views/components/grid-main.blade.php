{{-- <mad-grid-main> — content slot pareado com sidebar-style <mad-grid-filters>. --}}
{{-- Mesmo comportamento de <mad-dashboard-main>; alias semantico pra listagens.   --}}
@php
    $class = $class ?? '';
@endphp
<main class="mad-gridf-main mad-dashf-main {{ $class }}" style="order:3;min-width:0;">
    {!! $slot !!}
</main>
